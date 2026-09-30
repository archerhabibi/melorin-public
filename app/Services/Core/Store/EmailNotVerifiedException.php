<?php

namespace App\Services\Core\Store;

use App\Services\Core\Purchase\PurchaseNotAllowedException;

/**
 * Master 2.7 G11 — کاربر Email ثبت‌کرده ولی هنوز Verify نکرده و می‌خواهد
 * Purchase یا Wallet Charge انجام دهد. از PurchaseNotAllowedException ارث
 * می‌برد تا هر Channelی که آن را از قبل مدیریت می‌کند، پیام فارسی را نشان دهد.
 */
class EmailNotVerifiedException extends PurchaseNotAllowedException
{
    public function __construct(string $message = 'برای خرید یا شارژ کیف‌پول ابتدا ایمیل خود را تأیید کنید.')
    {
        parent::__construct($message);
    }
}
