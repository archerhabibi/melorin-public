<?php

namespace App\Channels\Website\Support;

/**
 * فاز W3 بند ۵ (نیمه‌ی دوم) + بخش ۹.۳ — Telegram-linking.
 *
 * پیاده‌سازی رسمی الگوریتم Telegram Login Widget
 * (https://core.telegram.org/widgets/login#checking-authorization):
 *
 *   data_check_string = تمام فیلدهای query (به‌جز hash)، به‌صورت
 *     "key=value"، مرتب‌شده بر اساس نام کلید، با \n به هم وصل‌شده
 *   secret_key = SHA256(bot_token) — خروجی raw binary، نه hex
 *   hash محاسبه‌شده = HMAC-SHA256(data_check_string, secret_key)
 *   معتبر است اگر و فقط اگر hash محاسبه‌شده == hash دریافتی (hash_equals)
 *
 * **دقیقاً همان نکته‌ای که Roadmap صریح منع کرده**: نسخه‌ی VPNMarket
 * یک HMAC جعلی داشت (احتمالاً بدون secret واقعی یا بدون hash_equals
 * ثابت‌زمانی) و `user_id` را بدون اعتبارسنجی امضا قبول می‌کرد. اینجا:
 * (الف) secret واقعاً از bot_token واقعی ساخته می‌شود، (ب) با
 * `hash_equals` (مقایسه‌ی ثابت‌زمانی، در برابر Timing Attack) چک
 * می‌شود، (ج) `auth_date` هم چک می‌شود تا یک callback URL قدیمی
 * دوباره Replay نشود.
 */
class TelegramLoginVerifier
{
    /** بیشترین عمر مجاز یک callback (ثانیه) — طبق توصیه‌ی خودِ Telegram در مستندات ویجت. */
    protected const MAX_AUTH_AGE_SECONDS = 86400;

    /**
     * @param  array<string, mixed>  $payload  کل query params برگشتی از ویجت (id, first_name, ..., hash, auth_date)
     */
    public function verify(array $payload, string $botToken): bool
    {
        if (empty($payload['hash']) || empty($payload['auth_date']) || empty($payload['id'])) {
            return false;
        }

        $receivedHash = (string) $payload['hash'];
        $data = $payload;
        unset($data['hash']);

        ksort($data);

        $checkString = collect($data)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode("\n");

        $secretKey = hash('sha256', $botToken, true);
        $computedHash = hash_hmac('sha256', $checkString, $secretKey);

        if (! hash_equals($computedHash, $receivedHash)) {
            return false;
        }

        $age = time() - (int) $payload['auth_date'];

        return $age >= 0 && $age <= self::MAX_AUTH_AGE_SECONDS;
    }
}
