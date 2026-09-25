<?php

namespace App\Channels\Website\Services;

use App\DataTransferObjects\GatewayInitiationResult;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Core\Store\StoreContext;

/**
 * فاز W2 بند ۵ (ادامه): Zarinpal (Direct Payment) و Card-to-Card.
 * طبق بند ۶۳ Roadmap («همه از قبل در Core پیاده و تست شده‌اند؛ Website
 * فقط UI آن‌ها را می‌سازد») و طبق خودِ PaymentService::initiate() که
 * فقط purpose='wallet_charge' می‌پذیرد (بند ۱۶: «خرید هیچ‌وقت Payment
 * نمی‌سازد»): این فاسید صرفاً شارژ کیف‌پول را آغاز می‌کند. تکمیل خریدِ
 * واقعی همچنان با WebsitePurchaseFacade و بعد از شارژ موفق، توسط خودِ
 * کاربر (برگشت به Checkout) انجام می‌شود.
 */
class WebsiteChargeFacade
{
    public function __construct(protected PaymentService $payments) {}

    /** @return array{payment: Payment, initiation: GatewayInitiationResult} */
    public function charge(User $user, PaymentMethod $method, float $amount, StoreContext $store): array
    {
        return $this->payments->initiate(
            user: $user,
            method: $method,
            amount: $amount,
            purpose: 'wallet_charge',
            reseller: $store->isReseller() ? $store->reseller : null,
            walletOwnerType: 'user',
        );
    }
}
