<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Money — تنها نقطه‌ی تبدیل، نمایش و محاسبه‌ی مبلغ در Melorin .
 *
 * قاعده‌ی نهایی:
 *
 *   Core      → فقط int (Minor Unit)، بدون هیچ برچسب ارز
 *   UI        → Money::format() / Money::number()
 *   Input     → Money::parse() / parseLoose() / parseOrZero() / toMinor()
 *   Gateway   → Money::toRial() (تبدیل صریح؛ ارز نامعتبر = Exception)
 *
 * «Minor Unit»: عدد صحیحی که decimals ارز را در خود دارد. برای تومان
 * (decimals=0) هر ۱ تومان = ۱؛ برای دلار (decimals=2) هر ۱ دلار = ۱۰۰.
 *
 * ورودی‌های کاربر/فرم/ربات همیشه به «واحد اصلی ارز» نوشته می‌شوند
 * (۱۰۰۰۰ تومان، 5.50 دلار) و همین‌جا به Minor Unit تبدیل می‌شوند.
 * اعشارِ بیش از decimals ارز **رد** می‌شود، نه گرد (گردکردنِ بی‌صدا ممنوع).
 *
 * هیچ مسیری از این کلاس با float محاسبه نمی‌کند.
 */
final class Money
{
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** جداکننده‌های هزارگان که از ورودی حذف می‌شوند */
    private const GROUP_SEPARATORS = [',', '٬', '،', ' ', "\u{00A0}", "\u{200C}"];

    /** حداکثر تعداد رقم Minor Unit (کمتر از PHP_INT_MAX = ۹٫۲×۱۰¹۸) */
    private const MAX_DIGITS = 18;

    // ──────────────────────────────────────────────────────────────
    //  متادیتای ارز (از config('melorin.currency'))
    // ──────────────────────────────────────────────────────────────

    public static function code(): string
    {
        return strtoupper((string) config('melorin.currency.code', 'IRT'));
    }

    public static function label(): string
    {
        return (string) config('melorin.currency.label', 'تومان');
    }

    public static function decimals(): int
    {
        return max(0, min(8, (int) config('melorin.currency.decimals', 0)));
    }

    /** تعداد Minor Unit در هر ۱ واحد اصلی (۱ برای تومان، ۱۰۰ برای دلار) */
    public static function factor(): int
    {
        return 10 ** self::decimals();
    }

    /** مقدار step برای input[type=number] (تومان: «1»، دلار: «0.01») */
    public static function inputStep(): string
    {
        $d = self::decimals();

        return $d === 0 ? '1' : '0.'.str_repeat('0', $d - 1).'1';
    }

    // ──────────────────────────────────────────────────────────────
    //  ورودی: واحد اصلی (متن کاربر) → Minor Unit
    // ──────────────────────────────────────────────────────────────

    /**
     * ورودی کاربر/فرم/JSON (به واحد اصلی ارز) → Minor Unit.
     * نامعتبر، اعشارِ بیش از decimals، یا سرریز → null.
     *
     * - ارقام فارسی/عربی و جداکننده‌ی هزارگان (`,` `٬` `،` فاصله) پذیرفته می‌شود.
     * - «50.000» برای decimals=0 رد می‌شود (مبهم است: پنجاه یا پنجاه‌هزار؟).
     *   فقط صفرهای اضافه‌ی حداکثر دو رقمی («50.0»، «50.00») پذیرفته می‌شود.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::scaleWhole($value);
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        if (! is_string($value)) {
            return null;
        }

        $s = strtr(trim($value), self::DIGITS);
        $s = str_replace(self::GROUP_SEPARATORS, '', $s);
        $s = str_replace('٫', '.', $s);

        if (! preg_match('/^(-?)(\d*)(?:\.(\d+))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }

        $negative = $m[1] === '-';
        $whole = $m[2];
        $fraction = $m[3] ?? '';
        $decimals = self::decimals();

        if (strlen($fraction) > $decimals) {
            $extra = substr($fraction, $decimals);

            // فقط صفرِ اضافه (حداکثر دو رقم) مجاز است؛ هر چیز دیگر → رد، نه گرد.
            if (strlen($extra) > 2 || trim($extra, '0') !== '') {
                return null;
            }

            $fraction = substr($fraction, 0, $decimals);
        }

        $digits = ltrim($whole.str_pad($fraction, $decimals, '0'), '0');

        if ($digits === '') {
            return 0;
        }

        if (strlen($digits) > self::MAX_DIGITS) {
            return null;
        }

        return $negative ? -((int) $digits) : (int) $digits;
    }

    /** int به «واحد اصلی» → Minor Unit با کنترل سرریز */
    private static function scaleWhole(int $major): ?int
    {
        $limit = intdiv(PHP_INT_MAX, self::factor());

        return ($major > $limit || $major < -$limit) ? null : $major * self::factor();
    }

    /**
     * پیام آزاد کاربر در ربات («50,000 تومان»، «۵۰۰۰۰»): هر کاراکتر غیرعددی
     * نادیده گرفته می‌شود (علامت منفی هم)، ولی اعشارِ نامعتبر → null.
     */
    public static function parseLoose(string $text): ?int
    {
        $s = strtr($text, self::DIGITS);
        $s = str_replace('٫', '.', $s);
        $s = trim(preg_replace('/[^0-9.]/', '', $s) ?? '', '.');

        return $s === '' ? null : self::parse($s);
    }

    /** مثل parse ولی نامعتبر را «۰» می‌دهد (مسیر پیام ربات؛ بعدش با حداقل شارژ مقایسه می‌شود) */
    public static function parseOrZero(mixed $value): int
    {
        if (is_string($value)) {
            return self::parseLoose($value) ?? 0;
        }

        return self::parse($value) ?? 0;
    }

    /**
     * مثل parse ولی نامعتبر → Exception. برای مسیرهایی که قبلاً با Validator
     * اعتبارسنجی شده‌اند (فرم Filament/وب).
     */
    public static function toMinor(mixed $major): int
    {
        $minor = self::parse($major);

        if ($minor === null) {
            throw new InvalidArgumentException('مبلغ نامعتبر: '.(is_scalar($major) ? (string) $major : gettype($major)));
        }

        return $minor;
    }

    /**
     * مقدار خوانده‌شده از DB/State که **از قبل Minor Unit است** (int یا رشته‌ی
     * عددیِ صحیح) → int. هیچ ضرب/تقسیمی انجام نمی‌شود. نامعتبر → ۰.
     */
    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,'.self::MAX_DIGITS.'}(?:\.0+)?$/', trim($value))) {
            return (int) trim($value);
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 9.0E15) {
            return (int) $value;
        }

        return 0;
    }

    // ──────────────────────────────────────────────────────────────
    //  خروجی: Minor Unit → متن (فقط UI)
    // ──────────────────────────────────────────────────────────────

    /** عدد گروه‌بندی‌شده بدون برچسب ارز («1,250.50» یا «10,000») */
    public static function number(int $minor): string
    {
        $negative = $minor < 0;
        $abs = $negative ? -$minor : $minor;
        $factor = self::factor();

        $whole = (string) intdiv($abs, $factor);
        $text = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        if (self::decimals() > 0) {
            $text .= '.'.str_pad((string) ($abs % $factor), self::decimals(), '0', STR_PAD_LEFT);
        }

        return ($negative ? '-' : '').$text;
    }

    /** نمایش کامل برای UI و پیام‌ها: عدد + برچسب ارز («10,000 تومان»، «$10.50») */
    public static function format(int $minor): string
    {
        $number = self::number($minor);
        $label = self::label();

        if ($label === '') {
            return $number;
        }

        // برچسب حرفی (تومان، USD) با فاصله؛ نماد (‎$ € £‎) بدون فاصله
        $gap = preg_match('/^\p{L}+$/u', $label) ? ' ' : '';

        return config('melorin.currency.symbol_position', 'after') === 'before'
            ? $label.$gap.$number
            : $number.$gap.$label;
    }

    /** Minor Unit → رشته‌ی عددی واحد اصلی بدون جداکننده، برای value/min فرم‌ها («1000»، «10.50») */
    public static function toMajorString(int $minor): string
    {
        $negative = $minor < 0;
        $abs = $negative ? -$minor : $minor;
        $factor = self::factor();

        $text = (string) intdiv($abs, $factor);

        if (self::decimals() > 0) {
            $text .= '.'.str_pad((string) ($abs % $factor), self::decimals(), '0', STR_PAD_LEFT);
        }

        return ($negative ? '-' : '').$text;
    }

    // ──────────────────────────────────────────────────────────────
    //  محاسبه (فقط عدد صحیح)
    // ──────────────────────────────────────────────────────────────

    /**
     * درصد از یک مبلغ، Half-Up، بدون float.
     * $percent مثل '2.5' یا '2.50' (نرخ است نه پول؛ حداکثر دو رقم اعشار معنادار).
     */
    public static function percentOf(int $amount, string|int $percent): int
    {
        $basisPoints = self::basisPoints($percent);
        $negative = $amount < 0;
        $abs = $negative ? -$amount : $amount;

        $result = intdiv($abs * $basisPoints + 5000, 10000);

        return $negative ? -$result : $result;
    }

    /** نرخ درصدی ('2.5') → صدم درصد (250). بیش از دو رقم اعشارِ غیرصفر یا نامعتبر → Exception */
    public static function basisPoints(string|int $percent): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2})0*)?$/', trim((string) $percent), $m)) {
            throw new InvalidArgumentException('درصد نامعتبر: '.$percent);
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    // ──────────────────────────────────────────────────────────────
    //  تنظیمات شارژ (از config؛ مقادیر config به واحد اصلی ارزند)
    // ──────────────────────────────────────────────────────────────

    /** حداقل شارژ به Minor Unit. $kind: 'customer' | 'supply' */
    public static function minTopup(string $kind = 'customer'): int
    {
        $key = $kind === 'supply' ? 'supply_min' : 'min';
        $raw = (string) config('melorin.currency.topup.'.$key, '0');
        $minor = self::parse($raw);

        // پیکربندی غلط (مثلاً اعشار برای ارز بدون اعشار) نباید بی‌صدا به «حداقل = ۰» تبدیل شود.
        if ($minor === null) {
            throw new InvalidArgumentException("melorin.currency.topup.{$key} نامعتبر است: ".$raw);
        }

        return $minor;
    }

    /**
     * دکمه‌های پیشنهادیِ شارژ به Minor Unit. $kind: 'customer' | 'supply'.
     * مقدارِ نامعتبر یا غیرمثبت بی‌صدا حذف می‌شود.
     *
     * @return list<int>
     */
    public static function topupPresets(string $kind = 'customer'): array
    {
        $key = $kind === 'supply' ? 'supply_presets' : 'presets';
        $raw = config('melorin.currency.topup.'.$key, '');
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        $presets = [];

        foreach ($items as $item) {
            $minor = self::parse(is_string($item) ? trim($item) : $item);

            if ($minor !== null && $minor > 0) {
                $presets[] = $minor;
            }
        }

        return $presets;
    }

    // ──────────────────────────────────────────────────────────────
    //  درگاه پرداخت (تبدیل صریح)
    // ──────────────────────────────────────────────────────────────

    /**
     * Minor Unit → ریال برای درگاه‌های ایرانی. فقط IRT (×۱۰) و IRR (×۱) با
     * decimals=0 مجازند؛ هر ارز دیگر Exception است (تبدیل ضمنی ممنوع).
     */
    public static function toRial(int $minor): int
    {
        $code = self::code();

        if (self::decimals() !== 0 || ! in_array($code, ['IRT', 'IRR'], true)) {
            throw new RuntimeException(
                'تبدیل به ریال فقط با ارز تومان (IRT) یا ریال (IRR) بدون اعشار ممکن است؛ ارز فعلی: '.$code
            );
        }

        return $code === 'IRR' ? $minor : $minor * 10;
    }
}
