# فاز ۱۳ — ساختار Wallet: `Wallet = User + StoreContext`

مرجع سند معماری: بند ۲۱–۲۸، ۴۹–۵۰، ۶۲ و Rule 5/6/7 (مراحل ۳ و ۴ ترتیب پیاده‌سازی).

## چرا این فاز لازم بود
| # | وضع قبلی | مشکل |
|---|---|---|
| ۱ | `wallets` polymorphic بود (`owner_type/owner_id`: CustomerAccount / Reseller / User قدیمی) | خلاف بند ۲۱؛ Context روی خود Wallet نبود |
| ۲ | «اعتبار نماینده» یک Wallet جدا با `owner=Reseller` بود | خلاف Rule 6 و بند ۲۵؛ `reseller_price` باید از Wallet صاحبِ نماینده **در Main** کسر شود |
| ۳ | Walletهای قدیمی کاربران (`owner=User`) فقط `customer_account_id` گرفته بودند و `WalletService` آن‌ها را نمی‌دید | **باگ داده:** موجودی کاربران قدیمی «صفر» دیده می‌شد و با اولین خرید یک Wallet خالی جدید ساخته می‌شد |
| ۴ | یکتایی روی `(owner_type, owner_id)` بود | بند ۲۷: یکتایی باید روی Context باشد و به NULL متکی نباشد |

## ساختار نهایی
```
wallets: id, user_id, store_type(main|reseller), reseller_id, scope_key, balance, timestamps
UNIQUE(user_id, scope_key)      scope_key = "main" | "reseller:{id}"
```
`scope_key` عمداً یک ستون ساده‌ی همیشه‌غیرNULL است (همان الگوی `customer_accounts`)، چون در MySQL
`UNIQUE(user_id, store_type, reseller_id)` برای Main (که `reseller_id=NULL` دارد) هیچ‌چیز را محافظت نمی‌کند.
مدل `Wallet` آن را خودکار پر می‌کند؛ INSERT خام باید خودش بسازد.

## Migrationها (سه مرحله‌ی کوچک و جدا)
1. `2026_09_22_000001_add_context_columns_to_wallets` — ستون‌های جدید؛ `owner_type/owner_id` nullable می‌شوند.
2. `2026_09_22_000002_backfill_and_merge_wallet_contexts` — نگاشت و ادغام داده (جزئیات پایین).
3. `2026_09_22_000003_enforce_wallet_context_uniqueness` — `UNIQUE(user_id, scope_key)`.

### نگاشت داده (Migration ۲)
| Wallet قدیمی | Wallet جدید |
|---|---|
| `owner=CustomerAccount` | (user، store) همان عضویت |
| `owner=User` | از `customer_account_id`؛ در نبودش از `users.reseller_id` |
| `owner=Reseller` | (صاحبِ نماینده، `main`, `null`) |

اگر چند Wallet به یک کلید برسند: موجودی‌ها با **عدد صحیح ریزترین واحد** جمع می‌شوند، تراکنش‌ها به Wallet بازمانده
منتقل می‌شود، و یک تراکنش `admin_adjust` به‌عنوان «نشانگر ادغام» ثبت می‌شود تا `balance == balance_after` آخرین تراکنش بماند.
**مجموع موجودی قبل و بعد باید دقیقاً برابر باشد**، وگرنه کل Migration rollback می‌شود. Walletهای غیرقابل‌نگاشت
(مثلاً عضویت مهمان یا نمایندگی حذف‌شده) دست‌نخورده می‌مانند و در لاگ (`wallet_context_backfill_unresolved`) گزارش می‌شوند.
Migration idempotent است.

### ⚠️ پیش از اجرا روی production
```
mysqldump ... wallets wallet_transactions > wallets-backup.sql
php artisan tinker --execute="echo DB::table('wallets')->sum('balance');"   # مجموع قبل
php artisan migrate
php artisan tinker --execute="echo DB::table('wallets')->sum('balance');"   # باید دقیقاً برابر باشد
```
ادغام برگشت‌پذیر نیست (`down()` عمداً no-op است)؛ فقط بکاپ برمی‌گرداند.

## ⚠️ پیامد کسب‌وکاری (تصمیم صریح سند)
موجودی **شخصیِ صاحبِ نماینده در Main** و **اعتبار نمایندگی‌اش** از این پس **یک موجودی**‌اند:
- شارژ اعتبار نماینده، موجودی Main او را بالا می‌برد و او می‌تواند با آن در Main هم خرید کند؛
- کف مجاز به عملیات وابسته است، نه Wallet: کسر `reseller_price` تا `-debt_limit` می‌رود، ولی خرید شخصیِ همان صاحب
  روی همان Wallet کف صفر دارد (نماینده‌ای که بدهکار است در Main خرید شخصی نمی‌کند تا شارژ کند).
- Wallet مشتریِ همان نماینده (Context خودِ نماینده) کاملاً جداست.
اگر این پیامد را نمی‌خواهید، این فاز را اعمال نکنید — بازگشت به Wallet جدا فقط با بکاپ ممکن است.

## کد
- `Wallet` — مدل جدید با `scopeKeyFor()`، رابطه‌های `user/reseller`، جلوگیری از Wallet نماینده‌ای بدون `reseller_id`.
- `WalletService` — `walletForContext(User, StoreContext)` (بند ۲۸)؛ `keyFor()` هر مالک را به `(user, store)` می‌رساند؛
  ساخت هم‌زمان با unique مدیریت می‌شود (اگر درخواست دیگری Wallet را ساخته باشد همان برمی‌گردد)؛ Wallet مهمان (بدون User) خطا می‌دهد.
  API عمومی (`credit/debit/balance/charge/...`) بدون تغییر ماند.
- `StoreContext::scopeKey()`.
- `User::wallet()` / `Reseller::wallet()` / `CustomerAccount::wallet()` از MorphOne به HasOne روی `(user_id, scope_key)`.
- پنل: ستون موجودی مشتری در پنل نماینده از `WalletService` خوانده می‌شود (رابطه‌ی `User::wallet()` فقط Main است)؛
  برچسب ستون پنل ادمین «موجودی Main».

## تست‌ها
`tests/Feature/Wallet/WalletContextStructureTest.php` (۱۱ تست): یکتایی per-Context، استقلال Walletها (مثال ۵۰۰/۲۰۰/۷۵۰/۱۰۰ سند)،
Wallet صاحبِ نماینده = Main Wallet، کف مجاز per-operation، unique در DB با `reseller_id=NULL`، Wallet مهمان،
ادغام قدیمی‌ها (موجودی، انتقال تراکنش، نشانگر، بی‌تغییری مجموع، غیرقابل‌نگاشت) و idempotency.
تغییر تست موجود: `ResellerPaymentApprovalTest` — تستِ «اعتبار نماینده جدا از Wallet شخصی مالک است» طبق Rule 6 معکوس شد.

## عمداً دست نخورد (مرحله‌ی «contract» بعدی)
- ستون‌های legacy `owner_type`, `owner_id`, `customer_account_id` هنوز در جدول‌اند (nullable و بلااستفاده). حذفشان
  با حذف تست‌های Migration تاریخی (`CustomerAccountBackfillTest`) که ردیف قدیمی‌شکل می‌سازند هم‌زمان باید انجام شود.
- `wallet_transactions` هنوز `user/store` را denormalize نمی‌کند؛ ردیابی از طریق `wallet_id` (join) ممکن است (بند ۳۳).
- `Payment.wallet_owner_type` (`user|reseller`) هنوز هست؛ معنایش «مقصد شارژ» است و حالا `reseller` یعنی Main Wallet صاحب.
