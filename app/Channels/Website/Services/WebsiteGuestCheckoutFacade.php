<?php

namespace App\Channels\Website\Services;

use App\Models\GuestCheckout;
use App\Models\Product;
use App\Models\User;
use App\Services\Core\Guest\GuestCheckoutService;
use App\Services\Core\Store\StoreContext;

/**
 * هم‌الگو با بقیه‌ی Facadeهای کانال Website: صفر منطق، فقط صدا زدن
 * GuestCheckoutService (Master 2.7 §3).
 */
class WebsiteGuestCheckoutFacade
{
    public function __construct(protected GuestCheckoutService $guest) {}

    public function start(Product $product, StoreContext $store, string $email, ?string $name = null, ?string $phone = null, ?GuestCheckout $replacing = null): GuestCheckout
    {
        return $this->guest->start($product, $store, $email, $name, $phone, $replacing);
    }

    public function findActive(string $token, StoreContext $store): ?GuestCheckout
    {
        return $this->guest->findActive($token, $store);
    }

    public function detectCollision(GuestCheckout $guest): ?User
    {
        return $this->guest->detectCollision($guest);
    }

    public function consume(GuestCheckout $guest, ?User $by = null): bool
    {
        return $this->guest->consume($guest, $by);
    }

    public function discard(GuestCheckout $guest): bool
    {
        return $this->guest->discard($guest);
    }
}
