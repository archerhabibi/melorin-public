<?php

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guard دائمی مالی (Master Contract §4.1):
 *
 *   M5  float برای پول ممنوع است (فقط حجم/آمار/درصدِ نمایشی مجاز است).
 *   M2  Core بدون برچسب ارز؛ UI فقط از Money::format()/number() می‌نویسد.
 *   M6  «تومان» در کد/ویو hard-code نمی‌شود (فقط config + Money).
 */
class MoneyIntegerGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<array{0: string, 1: int, 2: string}> [path نسبی, شماره‌ی خط, متن خط] */
    protected function codeLines(string $directory, array $extensions = ['php']): iterable
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relative = str_replace('\\', '/', $relative);

            foreach (file($file->getPathname()) as $i => $line) {
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '{{--')) {
                    continue;
                }

                yield [$relative, $i + 1, $line];
            }
        }
    }

    #[Test]
    public function no_float_type_or_cast_remains_in_money_paths(): void
    {
        $offenders = [];

        foreach ($this->codeLines('app') as [$path, $number, $line]) {
            if (stripos($line, 'traffic') !== false) {
                continue; // حجم (GB) پول نیست
            }

            // محاسبات رنگ/کنتراست (WCAG) float طبیعی دارند و هیچ ربطی به پول ندارند.
            if (str_starts_with($path, 'app/Support/Branding/')) {
                continue;
            }

            if (preg_match('/\(float\)|\?float\b|:\s*float\b|\bfloat\s+\$|\bfloatval\(/', $line)) {
                $offenders[] = "{$path}:{$number} → ".trim($line);
            }
        }

        $this->assertSame([], $offenders, "float در مسیر مالی (M5):\n".implode("\n", $offenders));
    }

    #[Test]
    public function the_currency_label_is_never_hard_coded(): void
    {
        $offenders = [];
        $allowed = ['app/Support/Money.php']; // پیش‌فرض برچسب + پیام خطای درگاه

        foreach (['app' => ['php'], 'resources/views' => ['php']] as $directory => $extensions) {
            foreach ($this->codeLines($directory, $extensions) as [$path, $number, $line]) {
                if (in_array($path, $allowed, true)) {
                    continue;
                }

                if (preg_match('/تومان|\bToman\b/u', $line)) {
                    $offenders[] = "{$path}:{$number} → ".trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "«تومان» hard-code شده (M6)؛ از Money::format()/label() استفاده کنید:\n".implode("\n", $offenders));
    }

    #[Test]
    public function core_services_and_models_do_not_format_money_for_display(): void
    {
        $offenders = [];

        foreach (['app/Services', 'app/Models'] as $directory) {
            foreach ($this->codeLines($directory) as [$path, $number, $line]) {
                if (preg_match('/Money::(format|number|label)\b/', $line) || preg_match('/\bnumber_format\(/', $line)) {
                    if (stripos($line, 'traffic') !== false || stripos($line, 'GB') !== false) {
                        continue;
                    }

                    $offenders[] = "{$path}:{$number} → ".trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "نمایش مبلغ در Core (M2):\n".implode("\n", $offenders));
    }

    #[Test]
    public function money_columns_are_cast_to_integer_on_their_models(): void
    {
        $expected = [
            \App\Models\Wallet::class => ['balance'],
            \App\Models\WalletTransaction::class => ['amount', 'balance_after'],
            \App\Models\Payment::class => ['amount'],
            \App\Models\Product::class => ['main_price', 'reseller_price'],
            \App\Models\Order::class => ['main_price', 'reseller_price', 'customers_price'],
            \App\Models\ResellerProductPrice::class => ['customers_price'],
            \App\Models\Reseller::class => ['debt_limit'],
            \App\Models\AffiliateSetting::class => ['customer_bonus_amount', 'referrer_bonus_amount'],
            \App\Models\Commission::class => ['amount', 'base_amount'],
        ];

        foreach ($expected as $model => $columns) {
            $casts = (new $model)->getCasts();

            foreach ($columns as $column) {
                $this->assertSame('integer', $casts[$column] ?? null, "{$model}::{$column} باید integer باشد");
            }
        }
    }

    #[Test]
    public function percentages_are_not_money_and_stay_decimal(): void
    {
        $this->assertSame('decimal:2', (new \App\Models\AffiliateSetting)->getCasts()['commission_percent']);
        $this->assertSame('decimal:2', (new \App\Models\Commission)->getCasts()['commission_rate']);
    }
}
