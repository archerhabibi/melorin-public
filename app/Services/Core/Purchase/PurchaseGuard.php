<?php

namespace App\Services\Core\Purchase;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\ProductNotSellableException;
use App\Exceptions\ResellerScopeViolationException;
use App\Models\CustomerAccount;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Services\Core\ServerSelection\ServerSelectionStrategy;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use App\Services\Resellers\ResellerPricingService;

/**
 * بند ۱۵ بلوپرینت — تمام بررسی‌های پیش از خرید، در یک جا.
 *
 * چرا یک کلاس جدا و نه چند if داخل PurchaseService: این قوانین باید از
 * چند مسیر مختلف (ربات اصلی، ربات نماینده، سایت، پنل) فراخوانی شوند و
 * دقیقاً همان‌جایی که یکی از مسیرها یکی از چک‌ها را فراموش کند، یک راه
 * دور زدن ساخته می‌شود. تاریخچه‌ی همین پروژه این را ثابت کرده: تا نسخه‌ی
 * ۳.۰.۷، چک سبد فروش فقط در UI بود و یک callback دست‌ساز می‌توانست از
 * سبدِ بسته خرید کند.
 *
 * اصل: UI هیچ‌وقت مرز امنیتی نیست.
 */
class PurchaseGuard
{
    public function __construct(
        protected WalletService $wallet,
        protected ResellerPricingService $resellerPricing,
        protected ServerSelectionStrategy $serverSelection,
    ) {}

    public function assertContextAllowed(
        CustomerAccount $customer,
        StoreContext $store,
    ): void {
        $this->assertStoreOperational($store);
        $this->assertCustomerAllowed($customer, $store);
    }

    /**
     * تمام دروازه‌ها. اگر هر کدام رد شود، استثنا پرتاب می‌شود و هیچ
     * تغییر مالی‌ای رخ نمی‌دهد.
     *
     * @throws PurchaseNotAllowedException
     * @throws InsufficientBalanceException
     */
    public function assertCanPurchase(
        CustomerAccount $customer,
        Product $product,
        StoreContext $store,
        PriceSnapshot $price,
        bool $usesWallet = true,
        ?ServerPanel $manualPanel = null,
        bool $checkServerAvailability = true,
    ): void {
        $this->assertStoreOperational($store);
        $this->assertCustomerAllowed($customer, $store);
        $this->assertProductAvailable($product, $store);
        $this->assertSaleLimitNotReached($product);

        // تمدید ($checkServerAvailability=false از RenewalService) روی
        // سرورِ *موجودِ* همان اکانت انجام می‌شود، نه یک سرورِ تازه‌انتخاب‌شده؛
        // پس اینجا معنا ندارد — از وقتی select() ظرفیت را هم می‌سنجد (بند
        // ۶۵)، اجرای این چک برای تمدید می‌توانست یک تمدیدِ کاملاً معتبر را
        // فقط به این دلیل که دسته‌بندی‌اش پر است رد کند.
        if ($checkServerAvailability) {
            $this->assertServerAvailable($product, $manualPanel);
        }

        if ($usesWallet && ! $price->isFree()) {
            $this->assertCustomerCanPay($customer, $price);
            $this->assertResellerWithinDebtLimit($store, $price);
        }
    }

    protected function assertStoreOperational(StoreContext $store): void
    {
        if (! $store->isOperational()) {
            // نمایندگی غیرفعال = هیچ محصولی از این فروشگاه قابل‌فروش
            // نیست. همان استثنایی پرتاب می‌شود که بقیه‌ی دلایل
            // «قابل‌فروش نبودن» می‌دهند، تا ربات/پنل یک مسیر واحد برای
            // پیام دادن داشته باشد.
            throw new ProductNotSellableException('این نمایندگی غیرفعال است.');
        }
    }

    /**
     * مشتری باید هم فعال باشد و هم واقعاً عضو همین فروشگاه.
     *
     * بررسی دوم همان چیزی است که جلوی دست‌کاری شناسه در callback را
     * می‌گیرد: یک CustomerAccount متعلق به نماینده‌ی A نباید بتواند از
     * فروشگاه نماینده‌ی B خرید کند، حتی اگر شناسه‌اش دستی جا زده شود.
     */
    protected function assertCustomerAllowed(CustomerAccount $customer, StoreContext $store): void
    {
        if (! $customer->isActive()) {
            throw new PurchaseNotAllowedException('حساب شما فعال نیست. لطفاً با پشتیبانی تماس بگیرید.');
        }

        $customerStore = StoreContext::fromReseller($customer->reseller);

        if (! $customerStore->equals($store)) {
            // بند ۱۵ و ۱۶ سند: هر عملیات مالی باید در Context خودش
            // بماند. عبور از این مرز یک نقض Scope است، نه یک خطای
            // معمولیِ خرید.
            throw new ResellerScopeViolationException('این حساب مشتری متعلق به این فروشگاه نیست.');
        }
    }

    /**
     * عمداً public است: PurchaseService باید بتواند sellability را
     * *پیش از* ساختن PriceSnapshot صدا بزند. اگر محصول اصلاً قابل‌فروش
     * نباشد، کاربر باید «این محصول قابل‌فروش نیست» بشنود، نه یک
     * InvalidArgumentException از دلِ محاسبه‌ی قیمت.
     */
    public function assertProductAvailable(Product $product, StoreContext $store): void
    {
        if ($store->isReseller()) {
            // assertSellable خودش همه‌ی لایه‌ها را می‌سنجد: فعال‌بودن
            // نمایندگی، محصول، سبد فروش، در دسترس بودن سبد برای
            // نمایندگان، فعال‌بودنش نزد همین نماینده، و وجود قیمت.
            $this->resellerPricing->assertSellable($store->reseller, $product);

            return;
        }

        if ($product->status !== 'active') {
            throw new PurchaseNotAllowedException('این محصول در حال حاضر فعال نیست.');
        }

        if (! $product->category || $product->category->status !== 'active') {
            throw new PurchaseNotAllowedException('سبد فروش این محصول فعال نیست.');
        }
    }

    /**
     * محدودیت تعداد فروش محصول (بند ۱۵ بلوپرینت / بند ۶۱ سند v2.1).
     *
     * این فقط یک پیش‌بررسیِ سریع و بدون قفل است — همان‌طور که مقدار
     * `units_sold` در لحظه‌ی خواندنش نشان می‌دهد، نه لحظه‌ی مصرفش؛ دو
     * درخواست هم‌زمان می‌توانند هر دو از همین‌جا عبور کنند. مرجعِ واقعی
     * و ضدِ Race، رزروِ اتمیکِ داخل تراکنشِ خرید است
     * (`PurchaseService::execute` → `UPDATE products SET units_sold = units_sold + 1
     * WHERE units_sold < sale_limit`)؛ همان‌جاست که واقعاً فروشِ بیش از
     * ظرفیت (Oversell) غیرممکن می‌شود، نه اینجا. این چک فقط برای رد سریع
     * و بدون لمسِ کیف‌پول است، دقیقاً مثل assertPending در PaymentService.
     */
    protected function assertSaleLimitNotReached(Product $product): void
    {
        if ($product->sale_limit !== null && $product->units_sold >= $product->sale_limit) {
            throw new PurchaseNotAllowedException('ظرفیت فروش این محصول تکمیل شده است.');
        }
    }

    /**
     * بند ۲۳ بلوپرینت — «Active Server + Available Capacity».
     *
     * این چک عمداً پیش از هر کسری انجام می‌شود: اگر هیچ سروری در دسترس
     * نباشد، بدترین کار این است که اول پول مشتری کسر شود و بعد بفهمیم
     * جایی برای ساخت اکانت نیست.
     *
     * از فاز A2 سند v2.1 (بند ۶۵)، `select()` سرورهایی که به `capacity`
     * رسیده‌اند را هم کنار می‌گذارد، پس این چک واقعاً «فعال و دارای
     * ظرفیت آزاد» را می‌سنجد. با این‌حال چیزی رزرو نمی‌کند — فقط پیش‌بررسی
     * سریع است؛ رزروِ واقعیِ ضدِ Race داخل `ProvisioningService::provision`
     * با `selectAndReserve()`/`reserveCapacitySlot()` انجام می‌شود.
     */
    protected function assertServerAvailable(Product $product, ?ServerPanel $manualPanel = null): void
    {
        // اگر پنل صریحاً انتخاب شده (ادمین/کانال)، باید همان یکی را
        // بسنجیم، نه این‌که select() کلِ دسته‌بندی را بگردد — چون ممکن
        // است همان پنلِ انتخاب‌شده ظرفیت آزاد داشته باشد درحالی‌که
        // «کم‌بارترینِ» دسته‌بندی از نگاه select() پر است، یا برعکس.
        if ($manualPanel) {
            $hasCapacity = $manualPanel->status === 'active'
                && ($manualPanel->capacity === null || $manualPanel->active_accounts_count < $manualPanel->capacity);

            if (! $hasCapacity) {
                throw new PurchaseNotAllowedException('پنل انتخاب‌شده فعال نیست یا به ظرفیت رسیده است.');
            }

            return;
        }

        if (! $this->serverSelection->select($product->category)) {
            throw new PurchaseNotAllowedException('در حال حاضر سرور فعال و دارای ظرفیت آزادی برای این محصول در دسترس نیست.');
        }
    }

    protected function assertCustomerCanPay(CustomerAccount $customer, PriceSnapshot $price): void
    {
        if ($this->wallet->getBalance($customer) < $price->customerDebit()) {
            throw new InsufficientBalanceException('موجودی کیف پول شما کافی نیست.');
        }
    }

    /**
     * بند ۲۰ بلوپرینت — Reseller Debt Guard.
     *
     *     new_balance = current_balance - reseller_price
     *     if new_balance < -debt_limit → مسدود
     *
     * در حالت مسدود، هیچ کسری از هیچ‌کدام از دو طرف انجام نمی‌شود و
     * هیچ اکانتی ساخته نمی‌شود. این چک عمداً اینجا (قبل از شروع تراکنش
     * مالی) است تا حالت عادیِ «اعتبار تمام شده» اصلاً به کسر-و-بازگشت
     * نرسد.
     */
    protected function assertResellerWithinDebtLimit(StoreContext $store, PriceSnapshot $price): void
    {
        if (! $store->isReseller()) {
            return;
        }

        $reseller = $store->reseller;
        // Rule 4 و Rule 6: مبنای کسر از نماینده همیشه reseller_price
        // است، هرگز customers_price.
        $newBalance = $this->wallet->getBalance($reseller) - $price->resellerDebit();

        if ($newBalance < $reseller->minimumBalance()) {
            throw new ResellerDebtLimitException(
                'اعتبار نمایندگی برای این خرید کافی نیست. لطفاً با پشتیبانی تماس بگیرید.'
            );
        }
    }
}
