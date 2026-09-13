<?php

use App\Channels\ResellerBot\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/**
 * یک مسیر برای همه‌ی ربات‌های نمایندگی — نه یک route جدا به‌ازای هر
 * نماینده. {slug} همان webhook_slug است، نه bot_token واقعی (ر.ک.
 * کامنت‌های WebhookController و migration مربوطه).
 */
Route::post('/reseller-bot/webhook/{slug}', WebhookController::class)
    ->name('reseller-bot.webhook');
