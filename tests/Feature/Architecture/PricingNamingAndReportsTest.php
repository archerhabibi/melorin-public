<?php

namespace Tests\Feature\Architecture;

use App\Filament\Pages\Reports;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * فاز ۱۲ — هماهنگی نهایی UI/Handler با مدل سه‌قیمتی (سند معماری بند ۱۲، ۵۷ و ۵۹).
 */
class PricingNamingAndReportsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * بند ۵۷: «No legacy pricing name remains.» — این‌بار به‌صورت تست دائمی،
     * نه یک بار git grep. Migrationهای تاریخی عمداً بیرون از دامنه‌اند.
     */
    #[Test]
    public function no_legacy_pricing_name_remains_in_code_views_or_tests(): void
    {
        $pattern = '/\b(base_price|core_price|sold_price|custom_price|corePrice|soldPrice|'
            .'sellingPriceForReseller|sellingPrice|selling_price|setSellingPrice)\b/';

        $self = realpath(__FILE__);
        $offenders = [];

        foreach (['app', 'resources', 'routes', 'config', 'tests', 'database/factories', 'database/seeders'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file->isFile() || realpath($file->getPathname()) === $self) {
                    continue;
                }

                if (! preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
                    continue;
                }

                if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $m)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' → '.$m[1];
                }
            }
        }

        $this->assertSame([], $offenders, "نام قیمتِ Legacy باقی مانده:\n".implode("\n", $offenders));
    }

    #[Test]
    public function the_reports_summary_computes_without_error_and_uses_the_three_price_model(): void
    {
        // پیش‌تر getSummary() از متغیرهای تعریف‌نشده‌ی $from/$to استفاده می‌کرد
        // و کل صفحه‌ی گزارشات با ErrorException از کار می‌افتاد.
        $reseller = Reseller::factory()->create();

        Order::factory()->create([
            'status' => Order::STATUS_ACCOUNT_CREATED,
            'main_price' => 12, 'reseller_price' => null, 'customers_price' => null,
        ]);
        Order::factory()->create([
            'status' => Order::STATUS_ACCOUNT_CREATED,
            'reseller_id' => $reseller->id,
            'main_price' => null, 'reseller_price' => 10, 'customers_price' => 14,
        ]);

        $page = new Reports;
        $page->data = ['range' => 'today', 'from' => today()->toDateString(), 'to' => today()->toDateString()];

        $summary = $page->getSummary();

        $this->assertEquals(26, $summary['revenue']);        // 12 + 14
        $this->assertEquals(22, $summary['supply_cost']);    // 12 + 10
        $this->assertEquals(4, $summary['reseller_margin']); // 14 - 10
        $this->assertEquals(14, $summary['reseller_revenue']);
        $this->assertEquals(12, $summary['direct_revenue']);
        $this->assertArrayHasKey('pending_payments', $summary);
    }

    #[Test]
    public function only_wallet_charge_payments_can_be_initiated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PaymentService::class)->initiate(
            User::factory()->create(),
            PaymentMethod::factory()->create(),
            100000,
            'order',
        );
    }
}
