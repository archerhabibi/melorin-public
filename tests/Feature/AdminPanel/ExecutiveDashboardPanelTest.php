<?php

namespace Tests\Feature\AdminPanel;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Executive\AttentionAlerts;
use App\Filament\Widgets\Executive\OperationsOverview;
use App\Filament\Widgets\Executive\RecentOrders;
use App\Filament\Widgets\Executive\RevenueTrendChart;
use App\Filament\Widgets\Executive\ServerHealth;
use App\Filament\Widgets\Executive\StatsOverview;
use App\Filament\Widgets\Executive\TopResellers;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B7.1 — لایه‌ی نمایش داشبورد اجرایی ادمین (Filament). منطق در ExecutiveDashboardServiceTest تست شده؛
 * این‌جا فقط: رندر واقعی صفحه/ویجت‌ها، دسترسی، فیلتر نامعتبر، جایگزینی داشبورد قدیمی و لینک‌های «نیازمند توجه».
 */
class ExecutiveDashboardPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actingAsAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function direct(int $price, string $status = 'account_created', ?string $at = null, string $channel = 'website'): Order
    {
        return Order::factory()->create([
            'sales_channel' => $channel,
            'main_price' => $price,
            'status' => $status,
            'created_at' => $at ?? now()->toDateTimeString(),
        ]);
    }

    private function resell(Reseller $reseller, int $price, int $cost): Order
    {
        return Order::factory()->create([
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => $cost,
            'customers_price' => $price,
            'status' => 'account_created',
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    /* ------------------------------ صفحه ------------------------------ */

    #[Test]
    public function the_dashboard_page_renders_over_http_for_an_admin(): void
    {
        $this->direct(123000);
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin, 'admin')->get('/admin');

        $response->assertOk();
        $response->assertSee('داشبورد اجرایی');
        $response->assertSee('بازه‌ی زمانی');
    }

    #[Test]
    public function the_dashboard_is_not_reachable_without_an_admin_session(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    #[Test]
    public function an_invalid_period_in_the_url_does_not_break_the_dashboard(): void
    {
        $admin = $this->actingAsAdmin();

        $this->actingAs($admin, 'admin')->get('/admin?filters[period]=%27%3B%20drop%20table')->assertOk();
    }

    #[Test]
    public function the_old_default_dashboard_and_legacy_widgets_are_replaced_not_duplicated(): void
    {
        $pages = Filament::getPanel('admin')->getPages();

        $this->assertContains(Dashboard::class, $pages);
        $this->assertNotContains(\Filament\Pages\Dashboard::class, $pages);
        $this->assertFalse(class_exists('App\\Filament\\Widgets\\SalesOverviewWidget'));
        $this->assertFalse(class_exists('App\\Filament\\Widgets\\SalesChartWidget'));
    }

    #[Test]
    public function the_page_lists_the_seven_widgets_in_order(): void
    {
        $widgets = (new Dashboard)->getWidgets();

        $this->assertSame([
            AttentionAlerts::class,
            StatsOverview::class,
            OperationsOverview::class,
            RevenueTrendChart::class,
            ServerHealth::class,
            TopResellers::class,
            RecentOrders::class,
        ], $widgets);
    }

    /* ------------------------------ KPI ------------------------------ */

    #[Test]
    public function stats_show_sales_and_platform_revenue_separately(): void
    {
        $reseller = Reseller::factory()->create();
        $this->direct(100000);
        $this->resell($reseller, 150000, 120000);
        $this->actingAsAdmin();

        Livewire::test(StatsOverview::class, ['filters' => ['period' => '30d']])
            ->assertSee('250,000')   // فروش
            ->assertSee('220,000')   // درآمد پلتفرم
            ->assertSee('نرخ شکست سفارش');
    }

    #[Test]
    public function stats_survive_a_bogus_period_value(): void
    {
        $this->direct(123000);
        $this->actingAsAdmin();

        Livewire::test(StatsOverview::class, ['filters' => ['period' => 'not-a-period']])
            ->assertOk()
            ->assertSee('123,000');
    }

    #[Test]
    public function stats_reflect_the_selected_period(): void
    {
        $this->direct(111000, at: now()->subDays(10)->toDateTimeString());
        $this->actingAsAdmin();

        Livewire::test(StatsOverview::class, ['filters' => ['period' => 'today']])->assertDontSee('111,000');
        Livewire::test(StatsOverview::class, ['filters' => ['period' => '30d']])->assertSee('111,000');
    }

    #[Test]
    public function stats_never_show_an_infinite_percentage_without_a_comparison_basis(): void
    {
        $this->direct(100000);
        $this->actingAsAdmin();

        Livewire::test(StatsOverview::class, ['filters' => ['period' => 'today']])
            ->assertSee('بدون مبنای مقایسه')
            ->assertDontSee('∞');
    }

    #[Test]
    public function the_failure_rate_is_shown_with_one_decimal(): void
    {
        $this->direct(100000);
        $this->direct(100000);
        $this->direct(100000, 'failed');
        $this->actingAsAdmin();

        // ۱ از ۳ = ۳۳٫۳٪
        Livewire::test(StatsOverview::class, ['filters' => ['period' => '30d']])->assertSee('33.3٪');
    }

    #[Test]
    public function operations_overview_shows_live_position_regardless_of_the_period(): void
    {
        ServerPanel::factory()->create(['health_status' => 'down', 'capacity' => 10, 'active_accounts_count' => 4]);
        $this->actingAsAdmin();

        Livewire::test(OperationsOverview::class)
            ->assertOk()
            ->assertSee('وضعیت لحظه‌ای')
            ->assertSee('سرورهای فعال')
            ->assertSee('1 از کار افتاده')
            ->assertSee('بدهی نمایندگان');
    }

    /* ------------------------------ نیازمند توجه ------------------------------ */

    #[Test]
    public function attention_widget_lists_alerts_with_working_links(): void
    {
        $this->direct(1000, 'provision_failed');
        Payment::query()->create([
            'user_id' => User::factory()->create()->id,
            'payment_method_id' => PaymentMethod::factory()->create()->id,
            'amount' => 50000,
            'purpose' => 'wallet_charge',
            'status' => 'pending',
        ]);
        Ticket::factory()->create(['status' => 'open']);
        ServerPanel::factory()->create(['health_status' => 'down']);
        $this->actingAsAdmin();

        Livewire::test(AttentionAlerts::class)
            ->assertSee('سفارش‌های پرداخت‌شده‌ی بدون سرویس')
            ->assertSeeHtml('data-alert="attention_orders"')
            ->assertSeeHtml('data-alert="pending_payments"')
            ->assertSeeHtml('data-alert="servers_down"')
            ->assertSeeHtml('data-alert="open_tickets"')
            ->assertSeeHtml('tableFilters')
            ->assertSee('50,000')
            ->assertDontSeeHtml('data-alert="none"');
    }

    #[Test]
    public function attention_links_open_the_matching_filtered_lists(): void
    {
        $this->direct(1000, 'provision_failed');
        $admin = $this->actingAsAdmin();

        $html = Livewire::test(AttentionAlerts::class)->html();

        $this->assertStringContainsString('status', $html);
        $this->assertStringContainsString('provision_failed', $html);

        // مقصد لینک‌ها واقعاً بالا می‌آید (فیلتر وضعیت سفارش‌های ادمین وجود دارد)
        $this->actingAs($admin, 'admin')
            ->get(\App\Filament\Resources\OrderResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'provision_failed']]]))
            ->assertOk();
    }

    #[Test]
    public function attention_widget_says_all_clear_when_nothing_needs_care(): void
    {
        $this->actingAsAdmin();

        Livewire::test(AttentionAlerts::class)->assertSeeHtml('data-alert="none"');
    }

    /* ------------------------------ نمودار و جدول‌ها ------------------------------ */

    #[Test]
    public function the_trend_chart_renders_one_label_per_day_of_the_period(): void
    {
        $this->direct(100000);
        $this->actingAsAdmin();

        $component = Livewire::test(RevenueTrendChart::class, ['filters' => ['period' => '7d']]);
        $component->assertOk();

        $data = (fn () => $this->getData())->call($component->instance());

        $this->assertCount(7, $data['labels']);
        $this->assertCount(2, $data['datasets']);
        $this->assertSame(100000 / \App\Support\Money::factor(), array_sum($data['datasets'][0]['data']));
    }

    #[Test]
    public function server_health_lists_active_servers_with_problems_first(): void
    {
        $healthy = ServerPanel::factory()->create(['name' => 'srv-healthy', 'health_status' => 'healthy']);
        $down = ServerPanel::factory()->create(['name' => 'srv-down', 'health_status' => 'down']);
        $off = ServerPanel::factory()->create(['name' => 'srv-off', 'status' => 'inactive']);
        $this->actingAsAdmin();

        Livewire::test(ServerHealth::class)
            ->assertCanSeeTableRecords([$down, $healthy], inOrder: true)
            ->assertCanNotSeeTableRecords([$off]);
    }

    #[Test]
    public function top_resellers_widget_shows_ranking_or_an_empty_message(): void
    {
        $this->actingAsAdmin();

        Livewire::test(TopResellers::class)->assertSeeHtml('data-top-resellers="none"');

        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $this->resell($reseller, 150000, 120000);

        Livewire::test(TopResellers::class)
            ->assertSeeHtml('data-top-resellers="list"')
            ->assertSeeHtml('data-reseller="'.$reseller->id.'"')
            ->assertSee('arial')
            ->assertSee('120,000')
            ->assertSee('30,000');
    }

    #[Test]
    public function recent_orders_span_every_store_but_hide_test_accounts(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $mine = $this->direct(1000);
        $theirs = $this->resell($reseller, 2000, 1500);
        $test = $this->direct(0, 'account_created', channel: 'test_account');
        $this->actingAsAdmin();

        Livewire::test(RecentOrders::class)
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->assertCanNotSeeTableRecords([$test])
            ->assertSee('arial')
            ->assertSee('اصلی');
    }

    #[Test]
    public function recent_orders_table_is_capped(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->direct(1000);
        }
        $this->actingAsAdmin();

        $component = Livewire::test(RecentOrders::class);

        $this->assertCount(8, $component->instance()->getTableRecords());
    }

    #[Test]
    public function the_existing_dashboard_widgets_test_scenario_still_renders(): void
    {
        $this->actingAsAdmin();
        $this->direct(50000, 'paid', now()->toDateTimeString());

        Livewire::test(StatsOverview::class)->assertSuccessful();
        Livewire::test(RevenueTrendChart::class)->assertSuccessful();
    }
}
