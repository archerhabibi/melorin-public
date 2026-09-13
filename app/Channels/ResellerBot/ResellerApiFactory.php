<?php

namespace App\Channels\ResellerBot;

use App\Models\Reseller;
use Telegram\Bot\Api;

/**
 * تنها دلیل وجود این کلاس، قابل‌تست‌کردنِ WebhookController است: چون
 * bot_token هر نماینده در Runtime مشخص می‌شود (نه در Config)، نمی‌شود
 * از Api::class که به‌صورت singleton توسط پکیج SDK ساخته می‌شود استفاده
 * کرد. `new Api(...)` مستقیم در کنترلر هم کار می‌کند، ولی در تست امکان
 * جایگزینی با یک Mock را نمی‌دهد؛ با این Factory، تست فقط همین یک متد
 * را bind می‌کند و بقیه‌ی مسیر (تشخیص نماینده از webhook_slug،
 * جایگزینی Api::class در کانتینر برای طول درخواست) واقعی باقی می‌ماند.
 */
class ResellerApiFactory
{
    public function make(Reseller $reseller): Api
    {
        return new Api($reseller->bot_token);
    }
}
