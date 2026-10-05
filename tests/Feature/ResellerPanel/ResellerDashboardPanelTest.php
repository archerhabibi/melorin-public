<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Reseller\Pages\Dashboard;
use App\Filament\Reseller\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Reseller\Widgets\AttentionAlerts;
use App\Filament\Reseller\Widgets\RecentOrders;
use App\Filament\Reseller\Widgets\RevenueTrendChart;
use App\Filament\Reseller\Widgets\StatsOverview;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use App\Models\Wallet;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.1 — لایه‌ی نمایش داشبورد نماینده (Filament). منطق در ResellerDashboardServiceTest تست شده؛
 * این‌جا فقط: رندر واقعی ویجت‌ها، جداسازی نماینده‌ها، فیلتر نامعتبر، و لینک‌های «نیازمند توجه».
 */
class ResellerDashboardPanelTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('reseller'));
    }

    private function actingAsReseller(Reseller $reseller): User
    {
        $owner = $reseller->user;
        ResellerAdmin::query()->firstOrCreate(['reseller_id' => $reseller->id, 'user_id' => $owner->id], ['role' => 'owner']);

        $this->actingAs($owner, 'reseller');
        Filament::setTenant($reseller);

        return $owner;
    }

    private function sale(Reseller $reseller, int $price, int $cost, string $status = 'account_created', ?string $at = null): Order
    {
        $customer = $this->memberOf($reseller);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'customer_account_id' => $this->accountIn($customer, $reseller)->id,
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => $cost,
            'customers_price' => $price,
            'status' => $status,
            'created_at' => $at ?? now()->toDateTimeString(),
        ]);
    }

    #[Test]
    public function the_dashboard_page_renders_with_all_widgets_over_http(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $this->sale($reseller, 120000, 90000);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $response = $this->actingAs($owner, 'reseller')->get('/arial');

        $response->assertOk();
        $response->assertSee('بازه‌ی زمانی');
    }

    #[Test]
    public function an_invalid_period_in_the_url_does_not_break_the_dashboard(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial?filters[period]=%27%3B%20drop%20table')->assertOk();
    }

    #[Test]
    public function the_old_default_dashboard_is_replaced_not_duplicated(): void
    {
        $pages = Filament::getPanel('reseller')->getPages();

        $this->assertContains(Dashboard::class, $pages);
        $this->assertNotContains(\Filament\Pages\Dashboard::class, $pages);
    }

    #[Test]
    public function stats_show_this_resellers_numbers_only(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $this->sale($reseller, 123000, 100000);
        $this->sale($other, 777000, 100000);

        $this->actingAsReseller($reseller);

        Livewire::test(StatsOverview::class, ['filters' => ['period' => '30d']])
            ->assertSee('123,000')
            ->assertDontSee('777,000');
    }

    #[Test]
    public function stats_widget_survives_a_bogus_period_value(): void
    {
        $reseller = Reseller::factory()->create();
        $this->sale($reseller, 123000, 100000);
        $this->actingAsReseller($reseller);

        Livewire::test(StatsOverview::class, ['filters' => ['period' => 'not-a-period']])
            ->assertOk()
            ->assertSee('123,000');
    }

    #[Test]
    public function stats_reflect_the_selected_period(): void
    {
        $reseller = Reseller::factory()->create();
        $this->sale($reseller, 111000, 100000, at: now()->subDays(10)->toDateTimeString());
        $this->actingAsReseller($reseller);

        Livewire::test(StatsOverview::class, ['filters' => ['period' => 'today']])->assertDontSee('111,000');
        Livewire::test(StatsOverview::class, ['filters' => ['period' => '30d']])->assertSee('111,000');
    }

    #[Test]
    public function stats_flag_an_empty_credit_in_danger_color(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 0]);
        $this->actingAsReseller($reseller);

        // اعتبار ۰ و سقف بدهی ۰ ⇒ فروش جدید رد می‌شود
        Livewire::test(StatsOverview::class)->assertSee('اعتبار نماینده');
    }

    #[Test]
    public function attention_widget_lists_alerts_with_working_links(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 100000]);
        Wallet::query()->create(['user_id' => $reseller->user_id, 'store_type' => 'main', 'balance' => 500000]);
        $this->sale($reseller, 1000, 500, 'provision_failed');
        $this->actingAsReseller($reseller);

        Livewire::test(AttentionAlerts::class)
            ->assertSee('سفارش‌های نیازمند رسیدگی')
            ->assertSeeHtml('data-alert="attention_orders"')
            ->assertSeeHtml('tableFilters')
            ->assertDontSeeHtml('data-alert="none"');
    }

    #[Test]
    public function attention_widget_says_all_clear_when_nothing_needs_care(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 100000]);
        Wallet::query()->create(['user_id' => $reseller->user_id, 'store_type' => 'main', 'balance' => 500000]);
        $this->actingAsReseller($reseller);

        Livewire::test(AttentionAlerts::class)->assertSeeHtml('data-alert="none"');
    }

    #[Test]
    public function the_orders_filter_offers_the_attention_status_the_dashboard_links_to(): void
    {
        $reseller = Reseller::factory()->create();
        $attention = $this->sale($reseller, 1000, 500, 'provision_failed');
        $delivered = $this->sale($reseller, 1000, 500, 'account_created');
        $this->actingAsReseller($reseller);

        Livewire::test(ListOrders::class)
            ->filterTable('status', 'provision_failed')
            ->assertCanSeeTableRecords([$attention])
            ->assertCanNotSeeTableRecords([$delivered]);
    }

    #[Test]
    public function recent_orders_table_shows_only_this_resellers_orders(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $mine = $this->sale($reseller, 1000, 500);
        $theirs = $this->sale($other, 1000, 500);
        $this->actingAsReseller($reseller);

        Livewire::test(RecentOrders::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    #[Test]
    public function recent_orders_table_is_capped(): void
    {
        $reseller = Reseller::factory()->create();

        for ($i = 0; $i < 12; $i++) {
            $this->sale($reseller, 1000, 500);
        }

        $this->actingAsReseller($reseller);

        $component = Livewire::test(RecentOrders::class);

        // assertCountTableRecords() شمارنده‌ی کل را بدون limit می‌پرسد؛ آنچه واقعاً رندر می‌شود رکوردهای جدول است.
        $this->assertCount(8, $component->instance()->getTableRecords());
        $this->assertSame(8, substr_count($component->html(), 'fi-ta-row'));
    }

    #[Test]
    public function the_trend_chart_renders_one_label_per_day_of_the_period(): void
    {
        $reseller = Reseller::factory()->create();
        $this->sale($reseller, 100000, 70000);
        $this->actingAsReseller($reseller);

        $component = Livewire::test(RevenueTrendChart::class, ['filters' => ['period' => '7d']]);

        $component->assertOk();
        $data = (fn () => $this->getData())->call($component->instance());

        $this->assertCount(7, $data['labels']);
        $this->assertCount(7, $data['datasets'][0]['data']);
        $this->assertSame(100000, (int) array_sum($data['datasets'][0]['data']));
    }
}
