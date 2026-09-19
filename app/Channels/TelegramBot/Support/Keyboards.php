<?php

namespace App\Channels\TelegramBot\Support;

/**
 * منوهای ربات اصلی — دقیقاً مطابق بند ۳.۱ سند نیازمندی.
 *
 * نکته‌ی حیاتیِ رفع‌شده (باگ تکرارشونده): این کلاس قبلاً از
 * Telegram\Bot\Keyboard\Keyboard (کلاس خودِ SDK) استفاده می‌کرد. برای
 * کیبوردهایی که در یک foreach ساخته می‌شوند (categoryList, productList,
 * serverList, paymentMethods)، متد ->row() داخل حلقه به‌صورت یک
 * statement جدا صدا زده می‌شد (بدون $keyboard = $keyboard->row(...)).
 * چون این متد یک نمونه‌ی *جدید* برمی‌گرداند نه این‌که خودِ آبجکت را
 * تغییر بدهد، هر ردیفی که در حلقه اضافه می‌شد گم می‌شد و کیبورد نهایی
 * همیشه خالی برمی‌گشت. یک آرایه‌ی PHP خالی وقتی به رشته تبدیل شود
 * (چون SDK با (string) $params['reply_markup'] این کار را می‌کند)
 * چیزی جز رشته‌ی نامعتبر "Array" یا JSON نامعتبر تولید نمی‌کند و تلگرام
 * دقیقاً با این خطا آن را رد می‌کند:
 *   «Bad Request: object expected as reply markup»
 * و کل جریان (مثلاً «خرید اکانت» یا «شارژ کیف پول») با شکست ۵۰۰ متوقف
 * می‌شد — بدون این‌که کاربر هیچ پیامی ببیند («هیچ اتفاقی نمی‌افتد»).
 * این باگ دقیقاً یک‌بار دیگر هم در یک نسخه‌ی قبلی رفع و بعداً (احتمالاً
 * در بازنویسی مجددِ این فایل توسط یک ابزار/نشست دیگر) دوباره برگشته بود.
 *
 * رفع نهایی و قطعی: این کلاس دیگر اصلاً از کلاس Keyboard خودِ SDK
 * استفاده نمی‌کند — هر متد مستقیماً خودش رشته‌ی JSON نهایی و آماده
 * (طبق ساختار استاندارد reply_markup تلگرام) را برمی‌گرداند. بنابراین
 * صرف‌نظر از این‌که SDK چطور reply_markup را به رشته تبدیل می‌کند
 * ((string) روی یک رشته‌ی از قبل آماده، خودِ همان رشته را برمی‌گرداند)،
 * و صرف‌نظر از نسخه‌ی نصب‌شده‌ی SDK، نتیجه همیشه یک JSON معتبر است.
 * همه‌ی call siteها (grep شد) مستقیم 'reply_markup' => Keyboards::xxx()
 * هستند، پس نیازی به تغییر در جای دیگری از کدبیس نیست.
 */
class Keyboards
{
    /**
     * $testAccountEnabled: طبق سند ۰۷ («Incomplete Feature → Hidden، نه
     * پیام به‌زودی»)، وقتی اکانت تست در تنظیمات ادمین فعال/usable نیست،
     * دکمه‌اش اصلاً در منو نشان داده نمی‌شود.
     */
    public static function mainMenu(bool $testAccountEnabled = false): string
    {
        $rows = [
            ['🛒 خرید اکانت', '🔍 استعلام و تمدید اکانت'],
            ['💰 کیف پول و شارژ حساب', '👤 حساب کاربری'],
            $testAccountEnabled
                ? ['🎁 دعوت از دوستان', '🧪 دریافت اکانت تست']
                : ['🎁 دعوت از دوستان'],
            ['📜 قوانین خرید و آموزش', '🎧 پشتیبانی'],
            ['🤖 درخواست ربات نماینده و همکاری'],
        ];

        return self::encode(['keyboard' => $rows, 'resize_keyboard' => true]);
    }

    /** لیست دسته‌بندی‌ها (سبد فروش) به‌صورت این‌لاین کیبورد */
    public static function categoryList(iterable $categories): string
    {
        $rows = [];

        foreach ($categories as $category) {
            $rows[] = [[
                'text' => $category->name,
                'callback_data' => "buy:category:{$category->id}",
            ]];
        }

        return self::encode(['inline_keyboard' => $rows]);
    }

    /** لیست محصولات/تعرفه‌های یک دسته‌بندی */
    public static function productList(iterable $products): string
    {
        $rows = [];

        foreach ($products as $product) {
            $label = sprintf(
                '%s — %s تومان (%s روز%s)',
                $product->name,
                number_format((float) $product->main_price),
                $product->duration_days,
                $product->traffic_gb ? ", {$product->traffic_gb} گیگ" : ''
            );

            $rows[] = [[
                'text' => $label,
                'callback_data' => "buy:product:{$product->id}",
            ]];
        }

        $rows[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'buy:back_to_categories']];

        return self::encode(['inline_keyboard' => $rows]);
    }

    /**
     * لیست سرورهای مجاز یک دسته‌بندی، برای حالت انتخاب دستی (بند ۶.۱).
     * value کال‌بک به‌صورت «شناسه‌محصول_شناسه‌سرور» است، چون routeCallback
     * فقط تا سومین «:» می‌شکند و همان یک تکه‌ی آخر باید هر دو شناسه را
     * حمل کند.
     */
    public static function serverList(iterable $panels, int $productId): string
    {
        $rows = [];

        foreach ($panels as $panel) {
            $rows[] = [[
                'text' => $panel->name,
                'callback_data' => "buy:server:{$productId}_{$panel->id}",
            ]];
        }

        $rows[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'buy:back_to_categories']];

        return self::encode(['inline_keyboard' => $rows]);
    }

    public static function walletTopupAmounts(): string
    {
        return self::encode(['inline_keyboard' => [
            [
                ['text' => '۲۰۰,۰۰۰ تومان', 'callback_data' => 'wallet:amount:200000'],
                ['text' => '۵۰۰,۰۰۰ تومان', 'callback_data' => 'wallet:amount:500000'],
            ],
            [
                ['text' => '۱,۰۰۰,۰۰۰ تومان', 'callback_data' => 'wallet:amount:1000000'],
                ['text' => '✏️ مبلغ دلخواه', 'callback_data' => 'wallet:amount:custom'],
            ],
        ]]);
    }

    public static function paymentMethods(iterable $methods): string
    {
        $rows = [];

        foreach ($methods as $method) {
            $rows[] = [[
                'text' => $method->name,
                'callback_data' => "wallet:method:{$method->id}",
            ]];
        }

        return self::encode(['inline_keyboard' => $rows]);
    }

    public static function accountActions(int $accountId): string
    {
        return self::encode(['inline_keyboard' => [
            [
                ['text' => '♻️ تمدید', 'callback_data' => "account:renew:{$accountId}"],
                ['text' => '📱 دریافت کانفیگ / QR', 'callback_data' => "account:config:{$accountId}"],
            ],
        ]]);
    }

    private static function encode(array $markup): string
    {
        return json_encode($markup, JSON_UNESCAPED_UNICODE);
    }
}
