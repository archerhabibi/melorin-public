#!/usr/bin/env bash
# فاز ۹ — تمرین واقعی Backup → Restore → Verify (و اندازه‌گیری RTO).
# Backup فقط وقتی «ثابت‌شده» است که Restore شده و برنامه روی آن سالم باشد.
#
# استفاده (روی Staging، از ریشه‌ی پروژه):
#   scripts/staging/backup-restore-drill.sh [--keep]
#
# مبدأ فقط خوانده می‌شود (mysqldump --single-transaction). مقصد یک دیتابیس موقت با
# نام <DB>_restore_drill است که ساخته/پاک می‌شود؛ هیچ‌وقت دیتابیس مبدأ را لمس نمی‌کند.
set -euo pipefail

KEEP=0; [ "${1:-}" = "--keep" ] && KEEP=1
cd "$(dirname "$0")/../.."

[ -f .env ] || { echo ".env یافت نشد"; exit 2; }
envval() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_HOST="$(envval DB_HOST)"; DB_PORT="$(envval DB_PORT)"; DB_NAME="$(envval DB_DATABASE)"
DB_USER="$(envval DB_USERNAME)"; export MYSQL_PWD="$(envval DB_PASSWORD)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"
DRILL_DB="${DB_NAME}_restore_drill"
[ "$DRILL_DB" = "$DB_NAME" ] && { echo "نام مقصد نباید با مبدأ یکی باشد"; exit 2; }
[[ "$DRILL_DB" == *_restore_drill ]] || { echo "نام مقصد باید با _restore_drill تمام شود"; exit 2; }

MYSQL=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER")
DUMP=(mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --single-transaction --routines --triggers)
FILE="$(mktemp /tmp/melorin-drill-XXXXXX.sql.gz)"
cleanup() { rm -f "$FILE"; [ "$KEEP" -eq 1 ] || "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DRILL_DB\`" 2>/dev/null || true; }
trap cleanup EXIT

t0=$(date +%s)
echo "1) Backup از $DB_NAME ..."
"${DUMP[@]}" "$DB_NAME" | gzip > "$FILE"
t1=$(date +%s)
echo "   اندازه: $(du -h "$FILE" | cut -f1)  زمان: $((t1-t0))s"

echo "2) Restore در $DRILL_DB ..."
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DRILL_DB\`; CREATE DATABASE \`$DRILL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c "$FILE" | "${MYSQL[@]}" "$DRILL_DB"
t2=$(date +%s)
echo "   زمان Restore: $((t2-t1))s"

echo "3) مقایسه‌ی تعداد ردیف همه‌ی جدول‌ها ..."
tables="$("${MYSQL[@]}" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE'")"
bad=0
for t in $tables; do
  a="$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.\`$t\`")"
  b="$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM \`$DRILL_DB\`.\`$t\`")"
  if [ "$a" != "$b" ]; then
    # جدول‌های پرتغییر (session/cache/jobs) بین Backup و مقایسه می‌توانند عوض شوند
    case "$t" in sessions|cache|cache_locks|jobs|job_batches) echo "   ~ $t: $a vs $b (پرتغییر)";; *) echo "   ✗ $t: $a ≠ $b"; bad=1;; esac
  fi
done
sum_a="$("${MYSQL[@]}" -N -e "SELECT COALESCE(SUM(balance),0) FROM \`$DB_NAME\`.wallets")"
sum_b="$("${MYSQL[@]}" -N -e "SELECT COALESCE(SUM(balance),0) FROM \`$DRILL_DB\`.wallets")"
echo "   مجموع موجودی کیف‌پول‌ها: مبدأ=$sum_a  Restore=$sum_b"
[ "$sum_a" = "$sum_b" ] || bad=1

echo "4) اجرای Preflight (data+runtime) روی دیتابیس Restore‌شده ..."
if DB_DATABASE="$DRILL_DB" php artisan melorin:preflight --group=data; then :; else echo "   ✗ Preflight روی Restore شکست خورد"; bad=1; fi
t3=$(date +%s)

echo
echo "RTO (Backup→Restore→Verify) = $((t3-t0))s    [Restore به‌تنهایی: $((t2-t1))s]"
if [ "$bad" -eq 0 ]; then echo "نتیجه: ✅ Backup قابل‌بازیابی است"; else echo "نتیجه: ❌ Restore ناسالم"; exit 1; fi
