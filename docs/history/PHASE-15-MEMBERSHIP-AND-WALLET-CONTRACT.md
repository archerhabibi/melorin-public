# فاز ۱۵ — Rule 12 (عضویت چندنمایندگی) و حذف ستون‌های legacy جدول `wallets`

مرجع سند معماری: Rule 12، بند ۵، ۶، ۴۳؛ و بند ۲۱ (ساختار نهایی Wallet).
ادامه‌ی فاز ۱۳ (Wallet) و فاز ۱۴ (تست‌ها، که Rule 12 را «Known gap» ثبت کرده بود).

## بخش الف — Rule 12: «یک User می‌تواند Customer چند Reseller باشد»
### مشکل
سرویس‌ها آماده بودند (CustomerAccount) ولی کانال‌ها به `users.reseller_id` **تک‌مقداری** وابسته بودند:
| جا | رفتار قبلی |
|---|---|
| ربات نماینده `StartHandler` | کاربرِ نماینده‌ی دیگر را رد می‌کرد |
| `ResellerCustomerService` | `assign` برای مشتریِ نماینده‌ی دیگر خطا می‌داد |
| `Reseller::customers()`، `CustomerResource`، آمار پنل | از `users.reseller_id` می‌خواندند |
| `BroadcastService` / صفحه‌ی پیام همگانی نماینده | مخاطب از `users.reseller_id` |
| `WalletService::normalizeOwner(User)` | Context را از `users.reseller_id` حدس می‌زد — کاربرِ بدون /start موجودی **Main** را در ربات نماینده می‌دید |
| `AccountService::purchase` | در فروشگاه نماینده بی‌قید عضو می‌ساخت (بند ۶: Scope باید *قبل از* ساخت CustomerAccount چک شود) |

### راه‌حل
- **عضویت = `CustomerAccount`** (user، store). هیچ ستونِ تک‌مقداری روی User مبنای Scope نیست.
- `IdentityService::isActiveMember()`؛ `ResellerCustomerService` روی CustomerAccount بازنویسی شد:
  `assign` (idempotent، عضو نمایندگان دیگر مجاز)، `remove` = `status=disabled` (Wallet حفظ می‌شود)،
  `customersQuery/ownsCustomer` فقط اعضای فعال همان فروشگاه.
- `Reseller::customers()` → `belongsToMany` روی `customer_accounts`.
- ربات نماینده: `/start` عضویت همان فروشگاه را می‌سازد؛ عضویت غیرفعال با پیام مشخص رد می‌شود (بی‌صدا فعال نمی‌شود)؛
  معرف باید عضو فعال همان فروشگاه باشد؛ موجودی با `WalletService::balanceIn($user, StoreContext)` خوانده می‌شود.
- ربات اصلی: وب‌هوک عضویت Main را می‌سازد (Idempotent).
- `WalletService`: `User` بدون Context صریح **همیشه Main** است (نه حدس از users.reseller_id).
- `AccountService::purchase`: در فروشگاه نماینده عضویت موجود لازم است (`ResellerScopeViolationException` در غیر این‌صورت)؛ در Main خودکار.
- مخاطب پیام همگانی: نماینده = اعضای فعال همان فروشگاه؛ Main = عضو فعال Main یا کاربر بدون هیچ عضویت (مشتریِ صرفاً نمایندگان مخاطب Main نیست).
- پنل نماینده: لیست مشتریان با عضویت، موجودی در Context همان نماینده، شمارنده‌ی مشتریان با `customers()` جدید.
- `User::reseller()` حذف و `reseller_id` از `$fillable` برداشته شد. **خودِ ستون `users.reseller_id` هنوز در جدول است** (بلااستفاده)
  چون Migration تاریخی backfill (`2026_09_18_000003/4`) و تستش به آن نیاز دارند.

### تأثیر روی دیتای موجود
Migration ندارد. کاربران قدیمیِ نماینده که با `users.reseller_id` مشتری بودند، از قبل (Migration ۳ فاز CustomerAccount)
CustomerAccount دارند. کاربرانی که فقط `users.reseller_id` داشتند و CustomerAccount نه (بسیار بعید) در اولین /start عضو می‌شوند.

## بخش ب — حذف ستون‌های legacy جدول `wallets`
Migration `2026_09_23_000001_drop_legacy_wallet_owner_columns`:
- **نگهبان ایمنی:** اگر Walletی بدون `user_id` مانده باشد (نگاشت‌نشده در فاز ۱۳)، Migration **متوقف می‌شود** و خطا می‌دهد؛
  چون ردّ مالکشان فقط در همین ستون‌هاست و حذف برگشت‌ناپذیر است. (لاگ `wallet_context_backfill_unresolved`).
- سه مرحله‌ی جدا و ترتیبدار: `dropForeign` ← `dropUnique/dropIndex` ← `dropColumn`.
- `user_id`/`scope_key` عمداً nullable می‌مانند (تا تست Migration ادغام فاز ۱۳ که ردیف قدیمی‌شکل می‌سازد ممکن بماند)؛
  نگهبان اصلی مدل `Wallet` (خطا برای user_id خالی) و `UNIQUE(user_id, scope_key)` است.
- Migrationهای تاریخی (`…09_18_000004` و ادغام فاز ۱۳) با `Schema::hasColumn` محافظت شدند تا روی دیتابیس تازه و بعد از حذف کار کنند.
- `Wallet::$fillable` و docblock تمیز شد.

### پیش از اجرا روی production
```
mysqldump ... wallets wallet_transactions > backup.sql
php artisan tinker --execute="echo DB::table('wallets')->whereNull('user_id')->count();"   # باید 0 باشد
php artisan migrate
```

## تست‌ها
- جدید: `tests/Feature/Architecture/MembershipGuardTest.php` (نگهبان دائمی: بدون وابستگی به `users.reseller_id`، `User::reseller` حذف،
  ستون‌های legacy Wallet حذف)، تست واقعی Rule 12 در `FinalModelSpecTest` (جای تست Skipped)،
  `a_bare_user_owner_always_means_the_main_wallet`، وب‌هوک چندنماینده‌ای (کاربر عضو A و B)،
  عضویت غیرفعال، مشتریِ مشترک در لیست هر دو پنل و در پیام همگانی هر دو فروشگاه.
- مهاجرت‌شده به مدل عضویت: `ResellerPurchaseFinancialTest`، `ResellerSellabilityGuardTest`، `ResellerCoreServicesTest`،
  `ResellerPanelIsolationTest`، `P1P2HardeningTest` (broadcast)، `ResellerBroadcastTest`، `WebhookMultiTenancyTest`،
  `ResellerCategoryToggleTest`/`ResellerPriceFieldTest`/`ResellerPaymentApprovalTest`/`ResellerBotFlowsTest` (حذف `reseller_id` بی‌اثر).
  helper مشترک: `tests/Concerns/StoreMembers.php`.
- حذف‌شده: دو تست backfill کیف‌پول قدیمی در `CustomerAccountBackfillTest` (ستون‌شان دیگر نیست)؛
  تست‌های ادغام فاز ۱۳ ستون‌های legacy را فقط داخل تست و با rollback بازمی‌سازند.
- سه تستِ قدیمیِ «Scope» که برعکسِ قاعده‌ی جدید بودند بازنویسی شد (assign به نماینده‌ی دوم اکنون مجاز است).

## عمداً دست نخورد
- حذف ستون `users.reseller_id` (نیازمند حذف Migration/تست تاریخی backfill).
- `users.referrer_id` هنوز سراسری است (نه per-store) — موضوع مستقل Affiliate.
- تصمیم باز بند ۱۸ (نماینده‌ای که خودش از Main خرید می‌کند `reseller_price` بدهد یا `main_price`) — بدون تغییر.
