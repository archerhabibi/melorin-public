<?php

use App\Support\CurrencyLock;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فاز ۵ — همه‌ی مبالغ به bigInteger (Minor Unit ارزِ config('melorin.currency')).
 *
 * ⚠️ IRREVERSIBLE DATA MIGRATION: قبل از اجرا Backup اجباری است و Rollback فقط با
 * Restore همان Backup انجام می‌شود (down() عمداً خطا می‌دهد).
 *
 * سخت‌گیری‌ها (پروژه هنوز منتشر نشده؛ پس سخت‌گیری رایگان است):
 *   ۱) داده‌ی موجود با مقدار اعشاری → Migration قبل از هر تغییر متوقف می‌شود.
 *      گرد کردن فقط با MELORIN_MONEY_ALLOW_ROUNDING=true (Half-Up، تصمیم آگاهانه).
 *   ۲) decimals > 0 روی دیتابیسِ دارای داده → متوقف می‌شود؛ مقدارهای قدیمی «تومان/واحد اصلی»
 *      بوده‌اند و تبدیل خودکارشان به Minor Unit مبهم است. (نصب تازه با decimals>0 مشکلی ندارد.)
 *   ۳) پایان موفق، «code:decimals» را در system_meta قفل می‌کند؛ بعد از آن تغییر ارز با
 *      CurrencyLockException رد می‌شود (App\Support\CurrencyLock).
 *
 * درصدها (commission_percent / commission_rate) پول نیستند و decimal می‌مانند؛ traffic_gb هم مالی نیست.
 *
 * SQLite (تست): decimal با affinity عددی مقدار صحیح را صحیح نگه می‌دارد؛ ALTER لازم نیست.
 */
return new class extends Migration
{
    /** table => [column => [nullable, default]] */
    private const COLUMNS = [
        'wallets' => ['balance' => [false, 0]],
        'wallet_transactions' => ['amount' => [false, null], 'balance_after' => [false, null]],
        'payments' => ['amount' => [false, null]],
        'products' => ['main_price' => [false, null], 'reseller_price' => [true, null]],
        'orders' => ['main_price' => [true, null], 'reseller_price' => [true, null], 'customers_price' => [true, null]],
        'reseller_product_prices' => ['customers_price' => [false, null]],
        'resellers' => ['debt_limit' => [false, 0]],
        'affiliate_settings' => ['customer_bonus_amount' => [false, 0], 'referrer_bonus_amount' => [false, 0]],
        'commissions' => ['amount' => [false, null], 'base_amount' => [true, null]],
    ];

    public function up(): void
    {
        $existingLock = CurrencyLock::stored();

        if ($existingLock !== null && $existingLock !== CurrencyLock::signature()) {
            // قفل قبلی با ارز دیگری ثبت شده؛ record() پیام استاندارد را می‌دهد، قبل از هر تغییر.
            CurrencyLock::record();
        }

        $grammar = DB::connection()->getQueryGrammar();
        $decimals = Money::decimals();
        $allowRounding = (bool) config('melorin.currency.allow_migration_rounding', false);

        $fractional = [];
        $hasData = false;

        foreach ($this->existingColumns() as $table => $columns) {
            foreach ($columns as $column) {
                $wrapped = $grammar->wrap($column);

                if (DB::table($table)->whereNotNull($column)->where($column, '<>', 0)->exists()) {
                    $hasData = true;
                }

                $count = DB::table($table)
                    ->whereNotNull($column)
                    ->whereRaw("{$wrapped} <> ROUND({$wrapped})")
                    ->count();

                if ($count > 0) {
                    $fractional[] = "{$table}.{$column}: {$count} ردیف با مقدار اعشاری";
                }
            }
        }

        if ($decimals > 0 && $hasData) {
            throw new RuntimeException(
                'Migration مبالغ متوقف شد: MELORIN_CURRENCY_DECIMALS='.$decimals.' ولی دیتابیس داده‌ی مالی دارد. '
                ."مقدارهای فعلی به واحد اصلیِ قدیمی ذخیره شده‌اند و تبدیل خودکارشان به Minor Unit مبهم است.\n"
                .'یا decimals=0 بگذارید، یا دیتابیس را خالی کنید، یا Migration داده‌ی صریح بنویسید. هیچ تغییری اعمال نشد.'
            );
        }

        if ($fractional !== [] && ! $allowRounding) {
            throw new RuntimeException(
                "Migration مبالغ متوقف شد: مقدار اعشاریِ غیرصفر وجود دارد؛ هیچ تغییری اعمال نشد.\n"
                .implode("\n", $fractional)."\n"
                .'گرد کردن Half-Up فقط با MELORIN_MONEY_ALLOW_ROUNDING=true (پس از بررسی و Backup).'
            );
        }

        if ($fractional !== []) {
            foreach ($this->existingColumns() as $table => $columns) {
                foreach ($columns as $column) {
                    DB::table($table)->whereNotNull($column)->update([
                        $column => DB::raw('ROUND('.$grammar->wrap($column).')'),
                    ]);
                }
            }
        }

        if (DB::getDriverName() !== 'sqlite') {
            foreach (self::COLUMNS as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                    foreach ($columns as $column => [$nullable, $default]) {
                        if (! Schema::hasColumn($table, $column)) {
                            continue;
                        }

                        $definition = $blueprint->bigInteger($column);
                        $nullable ? $definition->nullable() : $definition->nullable(false);

                        if ($default !== null) {
                            $definition->default($default);
                        }

                        $definition->change();
                    }
                });
            }
        }

        CurrencyLock::record();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'IRREVERSIBLE: تبدیل مبالغ به Integer Minor Unit با Restore از Backup برگردانده می‌شود، نه migrate:rollback.'
        );
    }

    /** @return array<string, list<string>> فقط جدول/ستون‌هایی که واقعاً وجود دارند */
    private function existingColumns(): array
    {
        $result = [];

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($columns) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $result[$table][] = $column;
                }
            }
        }

        return $result;
    }
};
