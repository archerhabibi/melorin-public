#!/usr/bin/env bash
#
# update-git.sh (v4.1) — Melorin Production Updater
#
# جریان:
# GitHub
#   ↓
# working-tree check
#   ↓
# fetch + تشخیص update
#   ↓
# dry-run
#   ↓
# Maintenance Mode
#   ↓
# DB Backup
#   ↓
# Git Pull
#   ↓
# Composer
#   ↓
# Migration
#   ↓
# Cache
#   ↓
# PHP-FPM / Queue / Nginx
#   ↓
# HTTP Health Check
#   ├── سالم → php artisan up
#   └── خراب → Rollback Code + DB
#
# ویژگی‌ها:
# - جلوگیری از آپدیت روی local changes
# - dry-run
# - backup کامل DB
# - Maintenance Mode امن
# - rollback خودکار code + database
# - ثبت migration قبل/بعد
# - تشخیص تغییر composer.json / composer.lock
# - HTTP health check
# - عدم rollback صرفاً به‌خاطر restart service
# - لاگ کامل هر اجرا
#

set -Eeuo pipefail

# ============================================================================
# 1. Arguments
# ============================================================================

DRY_RUN=0
ARGS=()

for arg in "$@"; do
    if [[ "$arg" == "--dry-run" ]]; then
        DRY_RUN=1
    else
        ARGS+=("$arg")
    fi
done

APP_DIR="${ARGS[0]:-/var/www/melorin}"
# REF می‌تواند یک tag مشخص (مثل v2.4.8) یا نام یک برنچ باشد. قبلاً این
# آرگومان BRANCH نام داشت و همیشه پیش‌فرضش "main" بود — یعنی این اسکریپت
# اصلاً راهی برای رفتن به یک نسخه‌ی مشخص نداشت (برخلاف install.sh که
# «Version/tag to install» را می‌پرسد). حالا اگر خالی بماند، درست مثل
# install.sh، آخرین release گیت‌هاب resolve می‌شود (نه همیشه main).
REF="${ARGS[1]:-}"

REPO_API="https://api.github.com/repos/archerhabibi/melorin"

# ============================================================================
# 2. Basic checks
# ============================================================================

if [[ $EUID -ne 0 ]]; then
    echo "خطا: این اسکریپت باید با root یا sudo اجرا شود." >&2
    exit 1
fi

if [[ ! -f "$APP_DIR/artisan" ]]; then
    echo "خطا: Laravel project در ${APP_DIR} پیدا نشد." >&2
    exit 1
fi

if [[ ! -d "$APP_DIR/.git" ]]; then
    echo "خطا: ${APP_DIR} یک Git repository نیست." >&2
    exit 1
fi

cd "$APP_DIR"

# این اسکریپت همیشه با root اجرا می‌شود (چک بالا) ولی مالکیت $APP_DIR
# می‌تواند از یک اجرای موفق قبلی (بخش ۲۴: chown -R www-data) به www-data
# تغییر کرده باشد — روی Git >= 2.35.2 این باعث می‌شود همین اولین دستور
# Git بعدی (بخش ۹، git status) با خطای
# «fatal: detected dubious ownership in repository» متوقف شود. چون
# APP_DIR توسط همان کسی که این اسکریپت را با root اجرا کرده مشخص شده،
# نگرانیِ امنیتیِ safe.directory (جلوگیری از اجرای Git روی یک ریپوی
# ناشناس) اینجا صدق نمی‌کند.
git config --global --add safe.directory "$APP_DIR"

# ============================================================================
# 3. Logging
# ============================================================================

LOG_DIR="/root/melorin-backups/logs"
mkdir -p "$LOG_DIR"

TIMESTAMP="$(date +%Y-%m-%d_%H-%M-%S)"
LOG_FILE="${LOG_DIR}/update_${TIMESTAMP}.log"

exec > >(tee -a "$LOG_FILE") 2>&1

echo "======================================================================"
echo " Melorin Update v4.1"
echo "======================================================================"

if [[ $DRY_RUN -eq 1 ]]; then
    echo " حالت: DRY-RUN"
    echo " هیچ تغییری روی سیستم اعمال نمی‌شود."
fi

echo " مسیر: ${APP_DIR}"
echo " نسخه/برنچ درخواستی: ${REF:-(خالی → آخرین release)}"
echo " لاگ:  ${LOG_FILE}"
echo "======================================================================"
echo ""

# ============================================================================
# 4. Global state
# ============================================================================

CURRENT_COMMIT=""
REMOTE_COMMIT=""
NEW_COMMIT=""

BACKUP_DIR=""

MAINTENANCE_ACTIVE=0
ROLLBACK_RUNNING=0

MAINT_SECRET=""
COOKIE_JAR=""

# ============================================================================
# 5. Read .env
# ============================================================================

read_env_value() {
    local key="$1"

    if [[ ! -f "$APP_DIR/.env" ]]; then
        return 0
    fi

    grep -E "^${key}=" "$APP_DIR/.env" 2>/dev/null \
        | tail -n1 \
        | cut -d'=' -f2- \
        | sed 's/^"//; s/"$//'
}

DB_HOST_ENV="$(read_env_value DB_HOST)"
DB_HOST_ENV="${DB_HOST_ENV:-127.0.0.1}"

DB_PORT_ENV="$(read_env_value DB_PORT)"
DB_PORT_ENV="${DB_PORT_ENV:-3306}"

DB_DATABASE_ENV="$(read_env_value DB_DATABASE)"
DB_USERNAME_ENV="$(read_env_value DB_USERNAME)"
DB_PASSWORD_ENV="$(read_env_value DB_PASSWORD)"

APP_URL_ENV="$(read_env_value APP_URL)"

# ============================================================================
# 6. Cleanup
# ============================================================================

cleanup() {
    if [[ -n "${COOKIE_JAR:-}" && -f "$COOKIE_JAR" ]]; then
        rm -f "$COOKIE_JAR" || true
    fi
}

# ============================================================================
# 7. Rollback
# ============================================================================

rollback_all() {

    if [[ "$ROLLBACK_RUNNING" -eq 1 ]]; then
        return
    fi

    ROLLBACK_RUNNING=1

    echo ""
    echo "======================================================================"
    echo " !!! UPDATE FAILED — ROLLBACK STARTED"
    echo "======================================================================"

    # ------------------------------------------------------------------------
    # Disable traps while rollback is running
    # ------------------------------------------------------------------------

    trap - ERR INT TERM EXIT

    # ------------------------------------------------------------------------
    # Restore code
    # ------------------------------------------------------------------------

    if [[ -n "$CURRENT_COMMIT" ]]; then

        echo ""
        echo "==> برگرداندن کد به commit قبلی:"
        echo "    ${CURRENT_COMMIT}"

        git reset --hard "$CURRENT_COMMIT" || true
    fi

    # ------------------------------------------------------------------------
    # Restore Composer dependencies
    # ------------------------------------------------------------------------

    if command -v composer >/dev/null 2>&1; then

        echo ""
        echo "==> بازسازی Composer dependencies"

        export COMPOSER_ALLOW_SUPERUSER=1

        composer install \
            --no-dev \
            --optimize-autoloader \
            --no-interaction \
            --quiet \
            || true
    fi

    # ------------------------------------------------------------------------
    # Restore database
    # ------------------------------------------------------------------------

    if [[ -n "$BACKUP_DIR" && -f "${BACKUP_DIR}/database.sql.gz" ]]; then

        echo ""
        echo "==> بازگردانی دیتابیس"

        if gunzip -c "${BACKUP_DIR}/database.sql.gz" \
            | MYSQL_PWD="$DB_PASSWORD_ENV" mysql \
                -h "$DB_HOST_ENV" \
                -P "$DB_PORT_ENV" \
                -u "$DB_USERNAME_ENV" \
                "$DB_DATABASE_ENV"
        then
            echo "    دیتابیس با موفقیت restore شد ✅"
        else
            echo ""
            echo "‼️ هشدار بسیار مهم:"
            echo "    restore خودکار دیتابیس شکست خورد."
            echo ""
            echo "    فایل backup:"
            echo "    ${BACKUP_DIR}/database.sql.gz"
        fi
    fi

    # ------------------------------------------------------------------------
    # Laravel cache rebuild
    # ------------------------------------------------------------------------

    echo ""
    echo "==> بازسازی Laravel cache"

    php artisan config:clear || true
    php artisan cache:clear || true
    php artisan route:clear || true
    php artisan view:clear || true

    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true

    # ------------------------------------------------------------------------
    # Permissions
    # ------------------------------------------------------------------------

    chown -R www-data:www-data "$APP_DIR" || true

    find "$APP_DIR/storage" \
        -type d \
        -exec chmod 775 {} \; \
        2>/dev/null || true

    find "$APP_DIR/bootstrap/cache" \
        -type d \
        -exec chmod 775 {} \; \
        2>/dev/null || true

    # ------------------------------------------------------------------------
    # Bring site back online
    # ------------------------------------------------------------------------

    if [[ "$MAINTENANCE_ACTIVE" -eq 1 ]]; then

        echo ""
        echo "==> خروج از Maintenance Mode"

        php artisan up || true

        MAINTENANCE_ACTIVE=0

        echo "    سایت دوباره آنلاین شد."
    fi

    cleanup

    echo ""
    echo "======================================================================"
    echo " ROLLBACK FINISHED"
    echo "======================================================================"

    if [[ -n "$CURRENT_COMMIT" ]]; then
        echo " Commit قبلی: ${CURRENT_COMMIT}"
    fi

    if [[ -n "$BACKUP_DIR" ]]; then
        echo " Backup: ${BACKUP_DIR}/database.sql.gz"
    fi

    echo " Log: ${LOG_FILE}"
    echo "======================================================================"
}

# ============================================================================
# 8. Error / interrupt handlers
# ============================================================================

handle_error() {

    local exit_code=$?

    if [[ "$DRY_RUN" -eq 1 ]]; then
        exit "$exit_code"
    fi

    rollback_all

    exit "$exit_code"
}

handle_signal() {

    echo ""
    echo "‼️ دریافت سیگنال توقف. عملیات متوقف شد."

    rollback_all

    exit 130
}

trap handle_error ERR
trap handle_signal INT TERM
trap cleanup EXIT

# ============================================================================
# 9. Working tree
# ============================================================================

echo "==> بررسی working tree"

if [[ -n "$(git status --porcelain)" ]]; then

    echo ""
    echo "خطا: روی سرور تغییرات local ثبت‌نشده وجود دارد:"
    git status --short

    echo ""
    echo "برای ایمنی، آپدیت متوقف شد."

    exit 1
fi

echo "    working tree تمیز است ✅"
echo ""

# ============================================================================
# 10. Fetch GitHub
# ============================================================================

echo "==> بررسی GitHub"

CURRENT_COMMIT="$(git rev-parse HEAD)"

# قبلاً این بخش همیشه فرض می‌کرد REF یک برنچ است («git fetch origin
# "$BRANCH"» + «origin/${BRANCH}»)؛ برای یک tag این کار نمی‌کرد. حالا:
# ۱. اگر REF خالی بود، مثل install.sh آخرین release گیت‌هاب را می‌گیریم.
# ۲. tag ها را جدا fetch می‌کنیم (چون یک تگ زیرشاخه‌ی هیچ برنچی نیست).
# ۳. تلاش برای fetch مستقیم REF (برای حالتی که REF یک برنچ باشد).
# ۴. commit مقصد را هم از refs/tags و هم از origin/REF امتحان می‌کنیم.
if [[ -z "$REF" ]]; then
    echo "    تشخیص آخرین release در GitHub"
    REF="$(curl -fsSL "${REPO_API}/releases/latest" | jq -r '.tag_name' 2>/dev/null || true)"

    if [[ -z "$REF" || "$REF" == "null" ]]; then
        echo "    آخرین release پیدا نشد — برنچ main استفاده می‌شود."
        REF="main"
    fi

    echo "    نسخه: ${REF}"
fi

git fetch origin --tags --force --quiet
git fetch origin "$REF" --quiet 2>/dev/null || true

if git rev-parse -q --verify "refs/tags/${REF}" >/dev/null 2>&1; then
    REF_IS_TAG=1
    REMOTE_COMMIT="$(git rev-parse "refs/tags/${REF}")"
elif git rev-parse -q --verify "origin/${REF}" >/dev/null 2>&1; then
    REF_IS_TAG=0
    REMOTE_COMMIT="$(git rev-parse "origin/${REF}")"
else
    echo "خطا: نسخه/برنچ '${REF}' نه به‌عنوان tag و نه به‌عنوان برنچ روی GitHub پیدا نشد." >&2
    exit 1
fi

echo "    Current: ${CURRENT_COMMIT:0:12}"
echo "    Remote:  ${REMOTE_COMMIT:0:12} (${REF}$([[ $REF_IS_TAG -eq 1 ]] && echo ' — tag' || echo ' — branch'))"

# ============================================================================
# 11. No update
# ============================================================================

if [[ "$CURRENT_COMMIT" == "$REMOTE_COMMIT" ]]; then

    echo ""
    echo "✅ Melorin از قبل به‌روز است."
    echo ""

    exit 0
fi

# ============================================================================
# 12. Show update
# ============================================================================

echo ""
echo "==> Update موجود است"

git log \
    --oneline \
    "${CURRENT_COMMIT}..${REMOTE_COMMIT}" \
    | sed 's/^/    /'

echo ""

# ============================================================================
# 13. Composer change detection
# ============================================================================

if ! git diff --quiet \
    "${CURRENT_COMMIT}..${REMOTE_COMMIT}" \
    -- composer.json composer.lock
then

    echo "⚠️ composer.json یا composer.lock تغییر کرده است."
    echo "   Composer dependencies در زمان update مجدداً نصب خواهند شد."
    echo ""
fi

# ============================================================================
# 14. Dry run
# ============================================================================

if [[ "$DRY_RUN" -eq 1 ]]; then

    echo "======================================================================"
    echo " DRY-RUN COMPLETE"
    echo "======================================================================"

    echo ""
    echo "در اجرای واقعی مراحل زیر انجام می‌شوند:"
    echo ""
    echo "  1. Maintenance Mode"
    echo "  2. Database Backup"
    echo "  3. Git Pull"
    echo "  4. Composer Install"
    echo "  5. Database Migration"
    echo "  6. Laravel Cache"
    echo "  7. Service Restart"
    echo "  8. HTTP Health Check"
    echo "  9. php artisan up"
    echo ""

    exit 0
fi

# ============================================================================
# 15. Validate database configuration
# ============================================================================

if [[ -z "$DB_DATABASE_ENV" ]]; then
    echo "خطا: DB_DATABASE در .env پیدا نشد." >&2
    exit 1
fi

if [[ -z "$DB_USERNAME_ENV" ]]; then
    echo "خطا: DB_USERNAME در .env پیدا نشد." >&2
    exit 1
fi

# ============================================================================
# 16. Maintenance Mode
# ============================================================================

MAINT_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')"

echo "==> فعال‌سازی Maintenance Mode"

php artisan down \
    --secret="$MAINT_SECRET"

MAINTENANCE_ACTIVE=1

echo "    سایت وارد Maintenance Mode شد ✅"

if [[ -n "$APP_URL_ENV" ]]; then

    echo ""
    echo "    لینک bypass موقت:"
    echo "    ${APP_URL_ENV%/}/${MAINT_SECRET}"
fi

echo ""

# ============================================================================
# 17. Database backup
# ============================================================================

BACKUP_DIR="/root/melorin-backups/${TIMESTAMP}"

mkdir -p "$BACKUP_DIR"

echo "==> Backup دیتابیس"

if MYSQL_PWD="$DB_PASSWORD_ENV" mysqldump \
    -h "$DB_HOST_ENV" \
    -P "$DB_PORT_ENV" \
    -u "$DB_USERNAME_ENV" \
    "$DB_DATABASE_ENV" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    > "${BACKUP_DIR}/database.sql" \
    2> "${BACKUP_DIR}/mysqldump.log"
then

    gzip "${BACKUP_DIR}/database.sql"

    echo "    Backup موفق بود ✅"
    echo "    ${BACKUP_DIR}/database.sql.gz"

else

    echo "خطا: Database backup شکست خورد." >&2
    echo "Log: ${BACKUP_DIR}/mysqldump.log" >&2

    exit 1
fi

echo ""

# ============================================================================
# 18. Migration status BEFORE
# ============================================================================

echo "==> وضعیت migration قبل از update"

php artisan migrate:status || true

echo ""

# ============================================================================
# 19. Git Pull
# ============================================================================

echo "==> دریافت کد جدید از GitHub"

# قبلاً این‌جا همیشه «git checkout BRANCH && git pull --ff-only» بود که
# فقط برای یک برنچِ در-حال-حرکت معنا دارد؛ روی یک tag، «pull --ff-only»
# اصلاً مفهومی ندارد (تگ‌ها جلو نمی‌روند) و سرور را در حالت نامشخصی رها
# می‌کرد. حالا صریحاً بین این دو حالت تفاوت می‌گذاریم:
# - tag → checkout --detach روی خودِ تگ. detached HEAD اینجا یک خطا
#   نیست؛ دقیقاً همان چیزی است که یک دیپلویمنت مبتنی بر ریلیز باید
#   باشد (همان‌طور که install.sh هم برای یک MELORIN_REF مشخص همین کار
#   را می‌کند).
# - برنچ → یک برنچ محلی هم‌نام با همان commit ریموت (بدون نیاز به pull
#   جداگانه، چون REMOTE_COMMIT را از قبل در بخش ۱۰ فچ و resolve کردیم).
if [[ "$REF_IS_TAG" -eq 1 ]]; then
    git checkout --detach "refs/tags/${REF}"
else
    git checkout -B "$REF" "origin/${REF}"
fi

NEW_COMMIT="$(git rev-parse HEAD)"

if [[ "$NEW_COMMIT" != "$REMOTE_COMMIT" ]]; then
    echo "خطا: checkout به commit مورد انتظار نرسید (${NEW_COMMIT:0:12} != ${REMOTE_COMMIT:0:12})." >&2
    exit 1
fi

echo ""
echo "    ${CURRENT_COMMIT:0:12} → ${NEW_COMMIT:0:12} ✅"
echo ""

# ============================================================================
# 20. Composer
# ============================================================================

export COMPOSER_ALLOW_SUPERUSER=1

echo "==> composer install"

composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

echo "    Composer OK ✅"
echo ""

# ============================================================================
# 20.5 Frontend build (Vite)
# ============================================================================
# public/build در .gitignore است؛ بدون این مرحله بعد از git pull، assetهای
# جدید ساخته نمی‌شوند و اگر manifest.json وجود نداشته باشد هر صفحه‌ی Website
# با ViteManifestNotFoundException می‌شکند (layout از @vite استفاده می‌کند).

echo "==> ساخت assetهای فرانت‌اند (npm run build)"

if [[ -f "$APP_DIR/package.json" ]]; then
    if command -v npm >/dev/null 2>&1; then
        if [[ -f "$APP_DIR/package-lock.json" ]]; then
            npm ci --no-audit --no-fund
        else
            npm install --no-audit --no-fund
        fi
        npm run build
        echo "    Build OK ✅"
    elif [[ -f "$APP_DIR/public/build/manifest.json" ]]; then
        echo "    ⚠️ npm نصب نیست؛ build قبلی (public/build) استفاده می‌شود."
    else
        echo "❌ npm نصب نیست و public/build/manifest.json هم وجود ندارد؛ سایت بدون build کار نمی‌کند."
        exit 1
    fi
else
    echo "    package.json وجود ندارد؛ رد شد."
fi
echo ""

# ============================================================================
# 21. Migration
# ============================================================================

echo "==> اجرای database migration"

php artisan migrate --force

echo "    Migration OK ✅"
echo ""

# ============================================================================
# 22. Migration status AFTER
# ============================================================================

echo "==> وضعیت migration بعد از update"

php artisan migrate:status || true

echo ""

# ============================================================================
# 23. Laravel cache
# ============================================================================

echo "==> بازسازی Laravel cache"

php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan filament:assets

echo "    Cache OK ✅"
echo ""

# ============================================================================
# 24. Permissions
# ============================================================================

echo "==> تنظیم permissions"

chown -R www-data:www-data "$APP_DIR"

find "$APP_DIR/storage" \
    -type d \
    -exec chmod 775 {} \;

find "$APP_DIR/bootstrap/cache" \
    -type d \
    -exec chmod 775 {} \;

echo "    Permissions OK ✅"
echo ""

# ============================================================================
# 25. Restart services
# ============================================================================

echo "==> ری‌استارت سرویس‌ها"

PHP_FPM_SERVICE="$(
    systemctl list-units \
        --full \
        --all \
        -t service \
        --no-legend 2>/dev/null \
    | awk '{print $1}' \
    | grep -E '^php[0-9.]+-fpm\.service$' \
    | head -n1 \
    || true
)"

if [[ -n "$PHP_FPM_SERVICE" ]]; then

    if systemctl restart "$PHP_FPM_SERVICE"; then
        echo "    ${PHP_FPM_SERVICE} OK ✅"
    else
        echo "    ⚠️ ${PHP_FPM_SERVICE} restart شکست خورد."
        echo "    rollback انجام نمی‌شود؛ Health Check ادامه پیدا می‌کند."
    fi

else

    echo "    ⚠️ PHP-FPM service پیدا نشد."
fi

# ------------------------------------------------------------------------

if command -v supervisorctl >/dev/null 2>&1; then

    if supervisorctl restart melorin-worker:* 2>/dev/null; then
        echo "    Melorin queue worker OK ✅"
    else
        echo "    ⚠️ melorin-worker در Supervisor پیدا نشد یا restart نشد."
    fi

else

    if php artisan queue:restart; then
        echo "    Queue restart signal ارسال شد ✅"
    else
        echo "    ⚠️ queue:restart شکست خورد."
    fi
fi

# ------------------------------------------------------------------------

if systemctl reload nginx 2>/dev/null; then
    echo "    nginx reload OK ✅"
else
    echo "    ⚠️ nginx reload شکست خورد."
fi

echo ""

# ============================================================================
# 26. Health check
# ============================================================================

echo "==> Health Check"

# ------------------------------------------------------------------------
# Laravel / DB
# ------------------------------------------------------------------------

if php artisan migrate:status > /dev/null; then

    echo "    Database / Laravel OK ✅"

else

    echo "خطا: Laravel/Database health check شکست خورد." >&2
    exit 1
fi

# ------------------------------------------------------------------------
# HTTP
# ------------------------------------------------------------------------

if [[ -n "$APP_URL_ENV" ]] && command -v curl >/dev/null 2>&1; then

    COOKIE_JAR="$(mktemp)"

    echo "    HTTP URL: ${APP_URL_ENV}"

    BYPASS_CODE="$(
        curl \
            -s \
            -o /dev/null \
            -w "%{http_code}" \
            -c "$COOKIE_JAR" \
            -L \
            --max-time 10 \
            -k \
            "${APP_URL_ENV%/}/${MAINT_SECRET}" \
            || true
    )"

    HOME_CODE="$(
        curl \
            -s \
            -o /dev/null \
            -w "%{http_code}" \
            -b "$COOKIE_JAR" \
            -L \
            --max-time 10 \
            -k \
            "${APP_URL_ENV%/}/" \
            || true
    )"

    rm -f "$COOKIE_JAR"
    COOKIE_JAR=""

    echo "    Maintenance bypass HTTP: ${BYPASS_CODE}"
    echo "    Home HTTP: ${HOME_CODE}"

    # Network failure
    if [[ "$BYPASS_CODE" == "000" || "$HOME_CODE" == "000" ]]; then

        echo ""
        echo "⚠️ نتوانستیم HTTP را از خود سرور بررسی کنیم."
        echo "   این مورد به‌تنهایی rollback را فعال نمی‌کند."
        echo "   بعد از update حتماً سایت را دستی بررسی کنید."

    # Application 5xx
    elif [[ "$HOME_CODE" =~ ^5[0-9][0-9]$ ]]; then

        echo ""
        echo "خطا: سایت HTTP ${HOME_CODE} برمی‌گرداند." >&2

        exit 1

    else

        echo ""
        echo "    HTTP Health Check OK ✅"
    fi

else

    echo "    ⚠️ APP_URL یا curl موجود نیست."
    echo "    HTTP Health Check انجام نشد."
fi

echo ""

# ============================================================================
# 27. Bring application online
# ============================================================================

echo "==> خروج از Maintenance Mode"

php artisan up

MAINTENANCE_ACTIVE=0

echo "    سایت آنلاین شد ✅"
echo ""

# ============================================================================
# 28. Final verification
# ============================================================================

echo "==> بررسی نهایی Git"

git status --short

FINAL_COMMIT="$(git rev-parse HEAD)"

echo ""
echo "    Commit نهایی: ${FINAL_COMMIT}"
echo ""

# ============================================================================
# 29. Disable rollback traps
# ============================================================================

trap - ERR INT TERM

# ============================================================================
# 30. Success
# ============================================================================

echo "======================================================================"
echo " UPDATE COMPLETED SUCCESSFULLY ✅"
echo "======================================================================"
echo ""
echo " Version:       v4.1"
echo " Commit:        ${FINAL_COMMIT:0:12}"
echo " Backup:        ${BACKUP_DIR}/database.sql.gz"
echo " Log:            ${LOG_FILE}"
echo ""
echo " بررسی دستی پیشنهادی:"
echo "   1. ورود به پنل ادمین"
echo "   2. بررسی کاربران"
echo "   3. بررسی Wallet"
echo "   4. بررسی Products"
echo "   5. تست خرید"
echo "   6. تست تمدید"
echo "   7. تست ربات Telegram"
echo ""
echo "======================================================================"