#!/usr/bin/env bash
# فاز ۹ — Smoke Test جعبه‌سیاه روی Staging (از بیرون، از مسیر واقعی Tunnel/Nginx).
# استفاده:  scripts/staging/smoke.sh https://staging.example.com [reseller-slug]
# فقط درخواست GET/POST بی‌ضرر می‌فرستد؛ هیچ داده‌ای نمی‌سازد. Exit 0 = همه PASS.
set -u

BASE="${1:-}"
SLUG="${2:-}"
[ -z "$BASE" ] && { echo "usage: $0 BASE_URL [reseller-slug]"; exit 2; }
BASE="${BASE%/}"

pass=0; fail=0; warn=0
ok()   { printf '  \033[32mPASS\033[0m %s\n' "$1"; pass=$((pass+1)); }
wrn()  { printf '  \033[33mWARN\033[0m %s\n' "$1"; warn=$((warn+1)); }
bad()  { printf '  \033[31mFAIL\033[0m %s\n' "$1"; fail=$((fail+1)); }
code() { curl -s -o /dev/null -m 20 -w '%{http_code}' "$@"; }
hdrs() { curl -s -o /dev/null -m 20 -D - "$@" | tr -d '\r'; }

expect_code() { # desc expected(regex) curl-args...
  local desc="$1" exp="$2"; shift 2
  local got; got="$(code "$@")"
  if [[ "$got" =~ ^($exp)$ ]]; then ok "$desc ($got)"; else bad "$desc — انتظار $exp، دریافت $got"; fi
}

echo "== Liveness / Readiness"
expect_code "/up" "200" "$BASE/up"
ready="$(curl -s -m 20 "$BASE/health/ready")"
if echo "$ready" | grep -q '"status":"\(ok\|degraded\)"'; then ok "/health/ready → $(echo "$ready" | grep -o '"status":"[a-z]*"')"; else bad "/health/ready ناسالم: $ready"; fi
# Heartbeat هر دقیقه نوشته می‌شود؛ بلافاصله بعد از Deploy ممکن است تا ۶۰ ثانیه غایب باشد.
echo "$ready" | grep -q '"scheduler":"ok"' && ok "scheduler heartbeat ok" || bad "scheduler heartbeat ok نیست (کران schedule:run هست؟ تا ۶۰ ثانیه بعد از Deploy طبیعی است)"
echo "$ready" | grep -o '"version":"[^"]*"'

echo "== Security headers (S-08) روی صفحه‌ی اصلی"
H="$(hdrs "$BASE/")"
for h in "x-content-type-options: nosniff" "x-frame-options" "referrer-policy" "permissions-policy" "content-security-policy"; do
  echo "$H" | grep -qi "^$h" && ok "header $h" || bad "header $h غایب"
done
if [[ "$BASE" == https://* ]]; then
  echo "$H" | grep -qi '^strict-transport-security' && ok "HSTS روی HTTPS" || bad "HSTS روی HTTPS غایب (TRUSTED_PROXIES/X-Forwarded-Proto را بررسی کنید)"
fi
echo "$H" | grep -qi '^server:.*[0-9]\.[0-9]' && bad "نسخه‌ی Server در هدر فاش می‌شود" || ok "نسخه‌ی Server فاش نمی‌شود"
echo "$H" | grep -qi '^x-powered-by' && wrn "X-Powered-By فعال است (php.ini: expose_php=Off)" || ok "X-Powered-By غایب"

echo "== صفحات عمومی"
expect_code "/ (website.home)" "200" "$BASE/"
expect_code "/register" "200" "$BASE/register"
expect_code "/admin/login" "200|302" "$BASE/admin/login"

echo "== Fail-closed وب‌هوک‌ها (S-01/S-02)"
expect_code "main webhook بدون Secret" "403|404" -X POST -H 'Content-Type: application/json' -d '{"update_id":1}' "$BASE/telegram/webhook/0:invalid"
expect_code "reseller webhook ناشناس" "403|404" -X POST -H 'Content-Type: application/json' -d '{"update_id":1}' "$BASE/reseller-bot/webhook/__no_such_slug__"
if [ -n "$SLUG" ]; then
  expect_code "reseller webhook ($SLUG) بدون Secret" "403" -X POST -H 'Content-Type: application/json' -d '{"update_id":1}' "$BASE/reseller-bot/webhook/$SLUG"
fi

echo "== Callback جعلی زرین‌پال (S-03) — نباید ۵۰۰ بدهد و نباید وضعیتی عوض کند"
expect_code "NOK با Authority جعلی" "404" "$BASE/payment/zarinpal/callback?payment_id=1&Authority=FORGED&Status=NOK"
expect_code "OK با Authority جعلی" "404" "$BASE/payment/zarinpal/callback?payment_id=1&Authority=FORGED&Status=OK"

echo "== ایزولاسیون فروشگاه"
expect_code "فروشگاه ناموجود" "404" "$BASE/store/__no_such_store__"
[ -n "$SLUG" ] && expect_code "فروشگاه $SLUG" "200" "$BASE/store/$SLUG"

echo "== مسیرهای حساس نباید از بیرون خوانده شوند"
for p in /.env /.git/config /storage/logs/laravel.log /composer.json /melorin-backup.sql /webhook.json; do
  c="$(code "$BASE$p")"
  # ۳xx (ریدایرکت به لاگین) نشت نیست؛ فقط ۲xx یعنی فایل واقعاً سرو می‌شود.
  [ "$c" = "000" ] && { bad "$p — اتصال برقرار نشد"; continue; }
  [[ "$c" =~ ^(2[0-9][0-9])$ ]] && bad "$p → $c (فایل حساس از بیرون خوانده می‌شود!)" || ok "$p → $c"
done

echo
echo "نتیجه: PASS=$pass FAIL=$fail WARN=$warn"
[ "$fail" -eq 0 ]
