<?php

namespace App\Channels\ResellerBot\Support;

/**
 * دقیقاً هم‌الگو با App\Channels\TelegramBot\Support\Keyboards — همان
 * دلیل مستندشده در آن فایل: هرگز از Telegram\Bot\Keyboard\Keyboard
 * استفاده نمی‌شود، هر متد مستقیماً رشته‌ی JSON نهایی را برمی‌گرداند.
 *
 * منوی مشتری طبق بخش ۱۳ سند Melorin-Reseller-Platform-Spec؛ ردیف اضافه‌ی
 * «💰 شارژ حساب نماینده» طبق تصمیم صریح فقط برای owner اضافه می‌شود —
 * نه یک منوی کاملاً جدا (که معادل ساختن پنل مدیریت نماینده داخل ربات
 * می‌شد و طبق تصمیم صریح دیگر، فعلاً در Scope نیست).
 */
class Keyboards
{
    public static function mainMenu(bool $isOwner = false): string
    {
        $rows = [
            ['🛒 خرید اکانت', '📦 اکانت‌های من'],
            ['💰 شارژ حساب', '🎁 دعوت از دوستان'],
            ['📚 قوانین و آموزش', '🎧 پشتیبانی'],
        ];

        if ($isOwner) {
            $rows[] = ['💰 شارژ حساب نماینده'];
        }

        return self::encode(['keyboard' => $rows, 'resize_keyboard' => true]);
    }

    public static function categoryList(iterable $categories): string
    {
        $rows = [];

        foreach ($categories as $category) {
            $rows[] = [['text' => $category->name, 'callback_data' => "rbuy:category:{$category->id}"]];
        }

        return self::encode(['inline_keyboard' => $rows]);
    }

    /** فقط محصولاتی که خودِ نماینده فعال/قیمت‌گذاری کرده — قیمت نمایش‌داده‌شده customers_price است، نه main_price یا reseller_price */
    public static function productList(iterable $productsWithPrice): string
    {
        $rows = [];

        foreach ($productsWithPrice as $row) {
            [$product, $customersPrice] = $row;

            $label = sprintf(
                '%s — %s تومان (%s روز%s)',
                $product->name,
                number_format($customersPrice),
                $product->duration_days,
                $product->traffic_gb ? ", {$product->traffic_gb} گیگ" : ''
            );

            $rows[] = [['text' => $label, 'callback_data' => "rbuy:product:{$product->id}"]];
        }

        $rows[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'rbuy:back_to_categories']];

        return self::encode(['inline_keyboard' => $rows]);
    }

    public static function walletTopupAmounts(): string
    {
        return self::encode(['inline_keyboard' => [
            [
                ['text' => '۲۰۰,۰۰۰ تومان', 'callback_data' => 'rwallet:amount:200000'],
                ['text' => '۵۰۰,۰۰۰ تومان', 'callback_data' => 'rwallet:amount:500000'],
            ],
            [
                ['text' => '۱,۰۰۰,۰۰۰ تومان', 'callback_data' => 'rwallet:amount:1000000'],
                ['text' => '✏️ مبلغ دلخواه', 'callback_data' => 'rwallet:amount:custom'],
            ],
        ]]);
    }

    public static function resellerWalletTopupAmounts(): string
    {
        return self::encode(['inline_keyboard' => [
            [
                ['text' => '۱,۰۰۰,۰۰۰ تومان', 'callback_data' => 'rswallet:amount:1000000'],
                ['text' => '۵,۰۰۰,۰۰۰ تومان', 'callback_data' => 'rswallet:amount:5000000'],
            ],
            [['text' => '✏️ مبلغ دلخواه', 'callback_data' => 'rswallet:amount:custom']],
        ]]);
    }

    public static function paymentMethods(iterable $methods, string $prefix = 'rwallet'): string
    {
        $rows = [];

        foreach ($methods as $method) {
            $rows[] = [['text' => $method->name, 'callback_data' => "{$prefix}:method:{$method->id}"]];
        }

        return self::encode(['inline_keyboard' => $rows]);
    }

    public static function accountActions(int $accountId): string
    {
        return self::encode(['inline_keyboard' => [
            [
                ['text' => '♻️ تمدید', 'callback_data' => "raccount:renew:{$accountId}"],
                ['text' => '📱 دریافت کانفیگ / QR', 'callback_data' => "raccount:config:{$accountId}"],
            ],
        ]]);
    }

    /** دکمه‌های تایید/رد برای پیامی که به نماینده برای بررسی رسید مشتری فرستاده می‌شود */
    public static function paymentReview(int $paymentId): string
    {
        return self::encode(['inline_keyboard' => [
            [
                ['text' => '✅ تایید', 'callback_data' => "rpay:approve:{$paymentId}"],
                ['text' => '❌ رد', 'callback_data' => "rpay:reject:{$paymentId}"],
            ],
        ]]);
    }

    private static function encode(array $markup): string
    {
        return json_encode($markup, JSON_UNESCAPED_UNICODE);
    }
}
