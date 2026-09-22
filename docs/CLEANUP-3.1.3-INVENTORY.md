# پاک‌سازی و سبک‌سازی — نسخه‌ی 3.1.3 (Dead Code & Dependency Inventory)

پیش از شروع فازهای سند v2.1، یک پاک‌سازی **کنترل‌شده** انجام شد: هر مورد ابتدا با تحلیل ایستا
(بدون اجرای PHP) بررسی شد و فقط مواردِ «قطعاً بلااستفاده» حذف شد. معیار «بلااستفاده»: نامِ متد/کلاس/ثابت
هیچ‌جای پروژه (app، tests، routes، resources، config، database، اسکریپت‌ها) — **خارج از کامنت‌ها** — دیده نشود.
کدی که Filament/Laravel به‌صورت magic صدا می‌زند، یا در سند v2.1 صراحتاً برای فاز بعدی لازم است، حذف نشد.

## ۱) حذف‌شده (DELETE)
| مورد | دلیل |
|---|---|
| `app/Http/Controllers/Controller.php` | کلاس پایه‌ی boilerplate لاراول؛ هیچ Controllerی از آن ارث نمی‌برد |
| `tests/Unit/ExampleTest.php` + suite `Unit` در `phpunit.xml` | `assertTrue(true)` boilerplate |
| `database/factories/CustomerAccountFactory.php`، `ProtocolFactory.php` | هیچ تست/Seeder/کدی از آن‌ها استفاده نمی‌کرد |
| `resources/views/welcome.blade.php` (۱۷۶ خط) | صفحه‌ی پیش‌فرض Laravel با لوگو و فونت/تصویر خارجی؛ با صفحه‌ی مینیمالِ نام برنامه جایگزین شد (`/` همچنان ۲۰۰ می‌دهد) |
| `ResellerCustomerService::assertOwnsCustomer()`، `::membership()` | بلااستفاده |
| `ProvisioningSetting::retriesAutomatically()` | بلااستفاده |
| `OperationService::dueForRetry()` | مصرف‌کننده‌ای نداشت («Jobهای فاز D» هیچ‌وقت ساخته نشد)؛ زمان‌بندی retry از فاز ۱۱ روی خودِ Order است |
| زمان‌بندی `available_at` در `ProvisioningService::recordFailure` و پارامتر `Operation::markFailed($availableAt)` | بلااستفاده و اثرش هم بلافاصله توسط `OperationService::runOnce` بازنویسی می‌شد |
| `CustomerAccount::isResellerStore()`، `::storeLabel()` | بلااستفاده |
| `Reseller::hasWorkingWebhook()` | بلااستفاده |
| `Payment::walletOwner()` | بلااستفاده؛ و همان مسیرِ قدیمیِ «Wallet جداگانه برای User» بود که `PaymentService::resolveWalletOwner()` جایگزینش شد |
| `TelegramBot ConversationState::mergePayload()` | بلااستفاده |
| `use DB` در `ResellerBot/AccountsHandler`، `use CustomerAccount` در `ResellerCustomerService` | import بلااستفاده |

## ۲) کامنت‌ها و مستندات
- حذف کامنتِ «قبر»ِ `User::reseller()` (توضیح کدی که دیگر وجود ندارد).
- `PaymentService::resolveWalletOwner` : روایتِ باگ قدیمی (`owner_type=User`) با توضیح وضعیت فعلی جایگزین شد.
- `routes/admin.php`: کامنتی که می‌گفت «باید لود شود» (در حالی که لود می‌شود) اصلاح شد.
- `README.md`: راهنمای بروزرسانی به `update-git.sh` (که واقعاً وجود دارد) اشاره می‌کند، نه `update.sh` ناموجود.
- `.github/workflows/tests.yml`: branchِ منسوخِ `feature/ticket-admin-resource` حذف شد.
- `VERSION`: یک کاراکتر کنترلی (`\a`) که به‌جای «a» در نام یک تست نشسته بود اصلاح شد.

## ۳) رفع باگِ تست (نه کد تولید)
چهار شکستِ باقی‌مانده‌ی `FailurePolicyTest` (`docs/STAGE-15-FULL-TEST-AUDIT.md`) **باگ تست بود، نه باگ retry**:
`$order->update(['next_provision_retry_at' => now()->subMinute()])` روی مدلی کهنه انجام می‌شد و Eloquent فیلد datetime
را با دقت ثانیه با مقدار اصلیِ درون‌حافظه مقایسه می‌کند؛ دو فراخوانیِ پشت‌سرهم در یک ثانیه «بدون تغییر» حساب می‌شد و UPDATE
زده نمی‌شد، در حالی که زمانِ واقعی داخل DB (که handler بعد از هر شکست به آینده می‌برد) دست‌نخورده بود ⇒ تلاش دوم هیچ‌گاه
سررسید نمی‌شد و `provision_attempts` روی ۲ می‌ماند. رفع: helper `makeDue()` با UPDATE مستقیم. (اگر پس از این هنوز
تستی از این فایل قرمز بود، خروجی همان تست را بفرستید.)

## ۴) نگه‌داشته‌شده (KEEP) — با دلیل
| مورد | دلیل |
|---|---|
| `PaymentStateMachine` (هیچ‌جا صدا زده نمی‌شود) | سند v2.1 بند ۵۳ / Phase 1: باید Single Source of Truth شود |
| `PanelDriverInterface::getAccount()` (سه Driver پیاده کرده‌اند، کسی صدا نمی‌زند) | بند ۷۸ (Traffic Synchronization) |
| ثابت‌های `Operation::TYPE_PROVISION/PAYMENT_CONFIRMATION/WEBHOOK` | بند ۱۱۳ (Outbox) و Phase 1/3 |
| رابطه‌های Eloquent بلااستفاده (`Reseller::botSetting/categorySettings/productPrices`، `User::commissionsEarned`، `Commission::referredUser`، `Payment::reviewer/reviewerReseller`، `Ticket::latestMessage`) | اعلانی‌اند و به ستون/FK واقعی می‌خورند؛ بند ۱۰۰ (Audit) ممکن است لازمشان کند |
| ابزار Frontend (`package.json`، Vite، Tailwind، `resources/js|css`) | هیچ View امروز استفاده نمی‌کند، ولی `install.sh` صراحتاً `npm ci && npm run build` اجرا می‌کند و Website (بند ۱۲۸) به آن نیاز دارد |
| `public/js|css/filament/*` | assetهای منتشرشده‌ی Filament لازمِ اجرا هستند |
| Migrationهای تاریخی | حذف/ویرایششان اجرای نصب تازه را می‌شکند |
| `docs/PHASE-*`، `STAGE-*`، `DECISION-*` | سابقه‌ی تصمیم‌ها |

## ۵) نیازمند بررسی (REVIEW) — عمداً دست نخورد
- `doctrine/dbal` در `composer.json`: هیچ ارجاعی در کد نیست (لاراول ۱۱ خودش change() را بومی انجام می‌دهد)؛ حذفش نیازمند `composer remove` + به‌روزرسانی `composer.lock` است که در این محیط ممکن نبود.
- `laravel/pail` و `laravel/sail` (dev): در اسکریپت‌ها دیده نشدند.
- ستون `operations.available_at`: دیگر نوشته یا خوانده نمی‌شود؛ حذفش یک Migration جدا می‌خواهد.
- ستون `users.reseller_id`: بلااستفاده (Rule 12) ولی Migration/تست تاریخی backfill هنوز به آن نیاز دارد.

## ۶) ⚠️ امنیت — اقدام دستی (خارج از Git)
فایل ZIP اشتراک‌گذاری‌شده شامل این موارد بود (هیچ‌کدام در Git نیستند و `.gitignore` جلوشان را می‌گیرد، ولی همراه ZIP بیرون رفته‌اند):
- `webhook.json`: **توکن واقعی ربات تلگرام + secret وب‌هوک**
- `.env`: `APP_KEY`، توکن ربات اصلی، secret وب‌هوک، شناسه‌ی ادمین‌ها
- `melorin-backup-2026-09-01.sql`: بکاپ دیتابیس (کاربران، ادمین‌ها، پرداخت‌ها، **تنظیمات ServerPanel**)
طبق بند ۱۱۶ سند («اگر Secretی Exposure داشته باشد باید Rotate شود»):
1. در BotFather برای ربات‌ها `/revoke` بزنید و توکن جدید بگیرید؛ `TELEGRAM_WEBHOOK_SECRET` را عوض و وب‌هوک را دوباره ست کنید.
2. رمز ادمین‌ها و اعتبارنامه‌ی ServerPanelها (Sanaei/Marzban/PasarGuard) را عوض کنید.
3. پیش از Rotate کردن `APP_KEY` بررسی کنید چه چیزی با آن رمز شده (مقادیر encrypted، سشن‌ها)؛ تغییرش بدون برنامه، داده‌ی رمزشده را ناخوانا می‌کند.
4. سه فایل بالا را از هر جایی که به اشتراک گذاشته شده (چت/ZIP/ایمیل) پاک کنید و برای اشتراک بعدی ZIP را بدون `.env`، `*.sql`، `webhook.json` و `.git/` بسازید.
