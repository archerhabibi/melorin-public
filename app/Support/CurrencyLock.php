<?php

namespace App\Support;

use App\Exceptions\CurrencyLockException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * قفل ارز : «code:decimals» در لحظه‌ی Migration مبالغ در جدول
 * system_meta ثبت می‌شود و از آن به بعد هر مقدار متفاوتِ config با خطای صریح
 * رد می‌شود. مبالغ Minor Unit هستند؛ عوض‌کردن decimals روی داده‌ی موجود
 * (مثلاً ۱۰۰۰ → ۱۰.۰۰) یا عوض‌کردن ارز، موجودی همه را بی‌صدا تغییر می‌دهد.
 *
 * تغییر آگاهانه‌ی ارز فقط با «Backup → Migration داده‌ی صریح → به‌روزرسانی
 * ردیف قفل» ممکن است و کار این کلاس نیست.
 */
final class CurrencyLock
{
    public const TABLE = 'system_meta';

    public const KEY = 'currency_lock';

    /** دستورهای Artisan که برای اصلاح/بازیابی لازم‌اند و نباید قفل شوند */
    private const CONSOLE_ALLOWLIST = [
        'migrate', 'migrate:fresh', 'migrate:status', 'migrate:rollback', 'migrate:reset',
        'db:wipe', 'db:seed', 'config:clear', 'config:cache', 'cache:clear',
        'optimize', 'optimize:clear', 'package:discover', 'key:generate', 'list', 'help',
        'down', 'up', 'melorin:currency-lock', 'melorin:preflight',
    ];

    public static function signature(): string
    {
        return Money::code().':'.Money::decimals();
    }

    /** امضای ثبت‌شده؛ null اگر هنوز Migration اجرا نشده */
    public static function stored(): ?string
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        $value = DB::table(self::TABLE)->where('key', self::KEY)->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * ثبت قفل (فقط توسط Migration). اگر قفلی با امضای دیگر وجود دارد → Exception.
     */
    public static function record(): void
    {
        $signature = self::signature();
        $stored = self::stored();

        if ($stored !== null && $stored !== $signature) {
            throw new CurrencyLockException(self::mismatchMessage($stored, $signature));
        }

        if ($stored === null) {
            DB::table(self::TABLE)->insert([
                'key' => self::KEY,
                'value' => $signature,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forever(self::cacheKey($signature), true);
    }

    /**
     * بررسی سبک در Boot: بعد از اولین تأیید موفق، تا تغییر امضا فقط یک خواندن Cache.
     *
     * @throws CurrencyLockException
     */
    public static function verify(): void
    {
        if (self::skipForConsoleCommand()) {
            return;
        }

        $signature = self::signature();

        try {
            if (Cache::get(self::cacheKey($signature)) === true) {
                return;
            }

            $stored = self::stored();
        } catch (Throwable) {
            return; // دیتابیس/کش در دسترس نیست (مثلاً در composer install)؛ این‌جا جای قفل نیست
        }

        if ($stored === null) {
            return; // Migration مبالغ هنوز اجرا نشده
        }

        if ($stored !== $signature) {
            throw new CurrencyLockException(self::mismatchMessage($stored, $signature));
        }

        Cache::forever(self::cacheKey($signature), true);
    }

    private static function cacheKey(string $signature): string
    {
        return 'melorin.currency_lock.verified:'.$signature;
    }

    private static function skipForConsoleCommand(): bool
    {
        // وب و تست‌ها همیشه بررسی می‌شوند؛ فقط دستورهای اصلاح/بازیابی Artisan معاف‌اند.
        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            return false;
        }

        $command = (string) ($_SERVER['argv'][1] ?? '');

        return $command === '' || in_array($command, self::CONSOLE_ALLOWLIST, true);
    }

    private static function mismatchMessage(string $stored, string $current): string
    {
        return "قفل ارز: دیتابیس با «{$stored}» ساخته شده ولی تنظیم فعلی «{$current}» است. "
            .'MELORIN_CURRENCY_CODE و MELORIN_CURRENCY_DECIMALS را به مقدار قبلی برگردانید؛ '
            .'تغییر ارز روی دیتابیسِ دارای داده فقط با Backup و Migration صریح مجاز است.';
    }
}
