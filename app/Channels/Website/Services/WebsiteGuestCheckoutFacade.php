<?php

namespace App\Channels\Website\Services;

use App\Models\GuestCheckout;
use App\Models\Product;
use App\Services\Core\Guest\GuestCheckoutService;
use App\Services\Core\Store\StoreContext;

/**
 * فاز W3 بند ۱ — هم‌الگو با بقیه‌ی Facadeهای کانال Website: صفر منطق،
 * فقط صدا زدن GuestCheckoutService.
 */
class WebsiteGuestCheckoutFacade
{
    public function __construct(protected GuestCheckoutService $guest) {}

    public function start(Product $product, StoreContext $store, string $name, string $phone, ?string $email): GuestCheckout
    {
        return $this->guest->start($product, $store, $name, $phone, $email);
    }

    public function findActive(string $token, StoreContext $store): ?GuestCheckout
    {
        return $this->guest->findActive($token, $store);
    }
}
