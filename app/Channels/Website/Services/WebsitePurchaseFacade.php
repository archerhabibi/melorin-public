<?php

namespace App\Channels\Website\Services;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Models\Product;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Store\StoreContext;

/**
 * «Payment: Wallet Payment». هم‌الگو با WebsiteWalletFacade و
 * WebsiteCatalogFacade —
 * هیچ تصمیم مالی/امنیتی‌ای اینجا گرفته نمی‌شود، فقط صدا زدن مستقیم
 * PurchaseService::purchase() که خودش Guard، PriceSnapshot، تراکنش
 * اتمیک و Provisioning را مدیریت می‌کند (بند ۱۴ بلوپرینت: «تنها نقطه‌ی
 * ورود خرید در کل سیستم»).
 *
 * idempotencyKey را کنترلر از توکن فرم Checkout می‌گیرد (
 * «idempotencyKey از الگوی موجود Bot/Core استفاده شود»)، نه اینکه این
 * لایه خودش چیزی بسازد.
 */
class WebsitePurchaseFacade
{
    public function __construct(protected PurchaseService $purchase) {}

    public function purchaseWithWallet(
        CustomerAccount $customer,
        Product $product,
        StoreContext $store,
        string $idempotencyKey,
    ): Account {
        return $this->purchase->purchase(
            customer: $customer,
            product: $product,
            store: $store,
            salesChannel: 'website',
            idempotencyKey: $idempotencyKey,
        );
    }
}
