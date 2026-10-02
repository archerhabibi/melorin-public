# فاز ۱۴ — مرحله‌ی ۱۳ سند: انتقال و تکمیل Testها به مدل نهایی

مرجع سند معماری: بند ۵۶ (Tests)، ۵۷ (Static Search)، ۵۹ مرحله‌ی ۱۳، و قوانین غیرقابل‌نقض ۱ تا ۱۸.
(شماره‌ی «فاز ۱۴» شماره‌ی پچ‌های پروژه است؛ محتوایش «مرحله‌ی ۱۳ سند» است.)

## Audit: پوشش موجود در برابر بند ۵۶
بیشتر بندها از فازهای ۹–۱۳ پوشش داشتند. یک ماتریس ردیابی (Rule → Test) پایین آمده و سه خلأ واقعی پیدا شد:
1. سناریوی مالیِ خودِ سند با اعداد بند ۵۱ (`12 / 10 / 14 / 16`) هیچ‌جا به‌صورت یک‌جا تست نشده بود، و هیچ تستی نبود که «Debit اول و دوم هر دو قابل‌انتساب به user/wallet/context/order/operation و زیر یک Operation‌اند» (بند ۳۳–۳۴) را بسنجد.
2. Rule 14 («فعال‌شدن Reseller User جدید نمی‌سازد») و Rule 13 («صاحب نماینده مشتری مستقیم Main می‌ماند») تست نداشتند.
3. نام‌های ممنوعه‌ی Wallet (بند ۴۹) فقط در نام چند متد تست و یک docblock مانده بود و هیچ نگهبانی نداشت.

## تست‌های جدید — `tests/Feature/Architecture/FinalModelSpecTest.php`
| تست | بند / Rule |
|---|---|
| `the_documents_own_financial_example_holds_for_main_reseller_a_and_reseller_c` | ۸، ۹، ۱۰، ۱۵–۱۷، ۵۱؛ Rule 2/3/4/9/10/11 |
| `both_reseller_debits_are_attributable_and_share_one_operation` | ۱۹، ۳۳، ۳۴؛ Rule 17 |
| `refunds_use_each_orders_price_snapshot_not_todays_prices` | ۳۸؛ Rule 16 |
| `a_customer_of_one_reseller_cannot_buy_through_another_and_nothing_is_debited` | ۶، ۴۳ |
| `a_reseller_owner_stays_a_direct_main_customer` | Rule 13 |
| `activating_a_reseller_creates_no_new_user_and_keeps_the_main_wallet` | Rule 1، 6، 14 |
| `no_forbidden_wallet_entity_name_remains` | ۴۹ (نگهبان دائمی) |
| `known_gap_...` (Skipped) | Rule 12 — پایین توضیح داده شده |
| `open_decision_...` (Skipped) | بند ۱۸ — پایین توضیح داده شده |

## ماتریس ردیابی بند ۵۶ (تست‌های موجود + جدید)
| بند ۵۶ | تست |
|---|---|
| Main customer pays `main_price` | `FinalModelSpec…::the_documents_own_financial_example…`، `ResellerPurchaseFinancialTest::main_bot_purchase_is_unaffected_and_still_debits_only_the_customer` |
| Customer pays `customers_price` / Reseller pays `reseller_price` | `ResellerPurchaseFinancialTest::purchase_debits_customer_by_customers_price_and_reseller_by_reseller_price`، `PurchaseFlowTest::a_reseller_purchase_debits_the_customer_and_the_reseller_in_one_operation` |
| Isolation (Main / A / B) | `StoreWalletAndOperationTest::wallets_of_the_same_person_in_different_stores…`، `WalletContextStructureTest::wallet_balances_of_different_contexts_are_independent` |
| Scope | `PurchaseFlowTest::a_customer_of_one_store_cannot_buy_from_another_store`، `ResellerPurchaseFinancialTest::customer_not_belonging_to_this_reseller…`، تست جدید Scope |
| Pricing (`customers_price >= reseller_price`) | `ResellerCoreServicesTest::customers_price_below_reseller_price_is_rejected` |
| Provisioning retry/refund | `tests/Feature/Provisioning/FailurePolicyTest.php` |
| Idempotency (Retry does not double debit) | `ProvisioningAndRenewalTest::a_retry_that_succeeds_recovers_the_order_without_charging_again`، `FailurePolicyTest::the_scheduled_retry_recovers…`، `PurchaseFlowTest::an_order_cannot_be_refunded_twice` |
| Refund (Main / customer / owner) | تست جدید Refund، `PurchaseFlowTest::refunding_a_reseller_order_gives_both_sides_their_money_back` |
| Static Search (بند ۵۷) | `PricingNamingAndReportsTest::no_legacy_pricing_name_remains…`، `FinalModelSpecTest::no_forbidden_wallet_entity_name_remains` |

## تمیزکاری
- نام ۵ متد تست با نام‌های ممنوعه‌ی Wallet به اصطلاح نهایی تغییر کرد (`…owners_main_wallet…`، `…customers_reseller_context_wallet…`)؛ منطق تست‌ها دست‌نخورده.
- کامنت‌های باقی‌مانده‌ی `Customers_price` (با C بزرگ) در ۱۰ فایل به `customers_price` اصلاح شد؛ رفتار کد تغییر نمی‌کند.

## ⚠️ دو یافته که به تصمیم/فاز جدا نیاز دارند (عمداً پیاده نشد)
### ۱) Rule 12 فقط در لایه‌ی سرویس برقرار است — ✅ در فاز ۱۵ رفع شد
«یک User می‌تواند Customer چند Reseller باشد.» در سرویس‌ها (`CustomerAccount`) برقرار است ولی کانال‌ها هنوز از `users.reseller_id` **تک‌مقداری** استفاده می‌کنند:
`ResellerBot\StartHandler` کاربرِ نماینده‌ی دیگر را رد می‌کند و `ResellerCustomerService::assign` هم همین‌طور؛
`WalletService` برای مالکِ `User` هم Context را از `users.reseller_id` می‌سازد. یعنی علی در عمل نمی‌تواند از ربات هر دو نماینده‌ی A و C خرید کند.
رفعش یک فاز جدا است (برداشتن وابستگی کانال‌ها و پنل به `users.reseller_id` و عبور همه‌چیز از `CustomerAccount`). تست Skipped `known_gap_…` تا آن زمان نشانگر آن است.

### ۲) تناقض داخل سند: بند ۱۸ ↔ Rule 2 و Rule 13 — ✅ حل شد
**تصمیم‌گیری و پیاده‌سازی در `docs/history/DECISION-CLAUSE-18-RESELLER-OWN-PURCHASE.md`.**
خلاصه: Context همچنان `main` می‌ماند (Rule 13)، ولی مبلغ `reseller_price` است (بند ۱۸). تست Skipped به
`a_reseller_owner_buying_directly_from_main_pays_reseller_price_not_main_price` تبدیل شد.

## اجرا
Migration ندارد. `php artisan test` — انتظار: همه سبز به‌جز ۲ تست Skipped (عمدی).
