<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Reseller\Resources\FinanceResource;
use App\Filament\Reseller\Resources\FinanceResource\Pages\ListFinance;
use App\Filament\Reseller\Resources\FinanceResource\Widgets\CreditSummary;
use App\Filament\Reseller\Resources\FinanceResource\Widgets\LedgerSummary;
use App\Filament\Reseller\Resources\FinanceResource\Widgets\StatementPanel;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Core\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\PaginationState;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.5 — لایه‌ی نمایش مرکز مالی نماینده. منطق در ResellerFinanceCenterTest تست شده؛
 * این‌جا: رندر واقعی، جداسازی، فیلتر/جست‌وجو/مرتب‌سازی، ویجت‌ها و فقط‌مشاهده‌بودن.
 */
class ResellerFinancePanelTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('reseller'));
        $this->wallets = app(WalletService::class);
    }

    /** نشت static صفحه‌بندی Livewire به تست‌های بعدی را می‌بندد (همان دلیل ResellerCommissionPanelTest). */
    protected function tearDown(): void
    {
        PaginationState::resolveUsing($this->app);
        Paginator::defaultView('pagination::tailwind');
        Paginator::defaultSimpleView('pagination::simple-tailwind');

        parent::tearDown();
    }

    private function reseller(array $attributes = []): Reseller
    {
        return Reseller::factory()->create(array_merge(['debt_limit' => 1000000], $attributes));
    }

    private function actingAsReseller(Reseller $reseller): User
    {
        $owner = $reseller->user;
        ResellerAdmin::query()->firstOrCreate(['reseller_id' => $reseller->id, 'user_id' => $owner->id], ['role' => 'owner']);

        $this->actingAs($owner, 'reseller');
        Filament::setTenant($reseller);

        return $owner;
    }

    private function order(?Reseller $reseller, User $buyer, int $price = 100000, int $supply = 60000, string $status = 'account_created'): Order
    {
        return Order::factory()->create([
            'user_id' => $buyer->id,
            'customer_account_id' => $this->accountIn($buyer, $reseller)->id,
            'reseller_id' => $reseller?->id,
            'sales_channel' => $reseller ? 'reseller_bot' : 'main_bot',
            'main_price' => $reseller ? null : $price,
            'reseller_price' => $reseller ? $supply : null,
            'customers_price' => $reseller ? $price : null,
            'status' => $status,
        ]);
    }

    private function supply(Reseller $reseller, Order $order, int $amount): WalletTransaction
    {
        return $this->wallets->debit($reseller, $amount, 'purchase', $order, "هزینه‌ی تأمین محصول — سفارش #{$order->id}");
    }

    #[Test]
    public function the_page_renders_over_http_with_ledger_rows(): void
    {
        $reseller = $this->reseller(['slug' => 'arial']);
        $this->wallets->credit($reseller, 500000, 'charge', null, 'شارژ دستی ۵۰۰');
        $this->supply($reseller, $this->order($reseller, $this->memberOf($reseller)), 60000);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/finance')
            ->assertOk()
            ->assertSee('مرکز مالی')
            ->assertSee('هزینه‌ی تأمین محصول')
            ->assertSee('+500,000')
            ->assertSee('-60,000')
            ->assertSee('440,000');
    }

    #[Test]
    public function other_resellers_and_owner_personal_purchases_are_never_listed(): void
    {
        $reseller = $this->reseller(['slug' => 'arial']);
        $other = $this->reseller(['slug' => 'bravo']);
        $this->wallets->credit($other, 987654, 'charge', null, 'شارژ غریبه');
        $this->supply($other, $this->order($other, $this->memberOf($other)), 31337);
        $this->wallets->debit($reseller, 4242, 'purchase', $this->order(null, $reseller->user), 'خرید شخصی صاحب');
        $this->wallets->credit($reseller, 100, 'charge', null, 'شارژ خودی');
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/finance')
            ->assertOk()
            ->assertSee('شارژ خودی')
            ->assertDontSee('شارژ غریبه')
            ->assertDontSee('987,654')
            ->assertDontSee('31,337')
            ->assertDontSee('خرید شخصی صاحب')
            ->assertDontSee('4,242');
    }

    #[Test]
    public function opening_the_page_never_writes(): void
    {
        $reseller = $this->reseller(['slug' => 'arial']);
        $owner = $this->actingAsReseller($reseller)->fresh();
        $before = [Wallet::query()->count(), WalletTransaction::query()->count(), DB::table('audit_logs')->count()];

        $this->actingAs($owner, 'reseller')->get('/arial/finance')->assertOk()->assertSee('هنوز حرکتی در اعتبار شما ثبت نشده');

        $this->assertSame($before, [Wallet::query()->count(), WalletTransaction::query()->count(), DB::table('audit_logs')->count()]);
    }

    #[Test]
    public function filters_scope_and_tampering_are_safe(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $charge = $this->wallets->credit($reseller, 500000, 'charge');
        $supply = $this->supply($reseller, $this->order($reseller, $buyer), 60000);
        $adjust = $this->wallets->adminAdjust($reseller, 2000, null, 'اصلاح');
        $other = $this->reseller();
        $foreign = $this->wallets->credit($other, 5, 'charge');
        $this->actingAsReseller($reseller);

        Livewire::test(ListFinance::class)
            ->assertCanSeeTableRecords([$charge, $supply, $adjust])
            ->assertCanNotSeeTableRecords([$foreign])
            ->filterTable('kind', 'supply')->assertCanSeeTableRecords([$supply])->assertCanNotSeeTableRecords([$charge, $adjust])
            ->resetTableFilters()
            ->filterTable('kind', 'charge')->assertCanSeeTableRecords([$charge])->assertCanNotSeeTableRecords([$supply, $adjust])
            ->resetTableFilters()
            ->filterTable('direction', 'out')->assertCanSeeTableRecords([$supply])->assertCanNotSeeTableRecords([$charge, $adjust])
            ->resetTableFilters()
            ->filterTable('direction', 'in')->assertCanSeeTableRecords([$charge, $adjust])->assertCanNotSeeTableRecords([$supply])
            ->resetTableFilters()
            ->filterTable('period', 'last_month')->assertCanNotSeeTableRecords([$charge, $supply, $adjust]);

        $lw = Livewire::test(ListFinance::class);
        foreach (['kind', 'direction', 'period'] as $filter) {
            $lw->set("tableFilters.{$filter}.value", 'hacked\' OR 1=1 --')->assertSuccessful()->assertCanSeeTableRecords([$charge, $supply, $adjust]);
        }
    }

    #[Test]
    public function search_by_description_treats_wildcards_literally(): void
    {
        $reseller = $this->reseller();
        $a = $this->wallets->credit($reseller, 100, 'charge', null, 'شارژ کارت‌به‌کارت');
        $b = $this->wallets->credit($reseller, 200, 'charge', null, 'شارژ درگاه');
        $this->actingAsReseller($reseller);

        Livewire::test(ListFinance::class)
            ->searchTable('کارت')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->searchTable('%')->assertCanNotSeeTableRecords([$a, $b])
            ->searchTable('_')->assertCanNotSeeTableRecords([$a, $b]);
    }

    #[Test]
    public function every_sortable_column_sorts_and_orders_correctly(): void
    {
        $reseller = $this->reseller();
        $low = $this->wallets->credit($reseller, 100, 'charge');
        $high = $this->wallets->credit($reseller, 900, 'charge');
        $this->actingAsReseller($reseller);

        foreach (['created_at', 'amount', 'balance_after'] as $column) {
            Livewire::test(ListFinance::class)->sortTable($column)->assertSuccessful();
            Livewire::test(ListFinance::class)->sortTable($column, 'desc')->assertSuccessful();
        }

        Livewire::test(ListFinance::class)
            ->sortTable('amount', 'desc')->assertCanSeeTableRecords([$high, $low], inOrder: true)
            ->sortTable('amount', 'asc')->assertCanSeeTableRecords([$low, $high], inOrder: true);
    }

    #[Test]
    public function the_kind_column_labels_each_row(): void
    {
        $reseller = $this->reseller();
        $charge = $this->wallets->credit($reseller, 500, 'charge');
        $supply = $this->supply($reseller, $this->order($reseller, $this->memberOf($reseller)), 100);
        $adjust = $this->wallets->adminAdjust($reseller, 5, null, 'x');
        $this->actingAsReseller($reseller);

        Livewire::test(ListFinance::class)
            ->assertTableColumnStateSet('kind', 'شارژ اعتبار', record: $charge->getKey())
            ->assertTableColumnStateSet('kind', 'هزینه‌ی تأمین سفارش', record: $supply->getKey())
            ->assertTableColumnStateSet('kind', 'اصلاح توسط مدیر', record: $adjust->getKey());
    }

    #[Test]
    public function the_credit_widget_shows_balance_debt_and_out_of_credit(): void
    {
        $reseller = $this->reseller(['debt_limit' => 40000]);
        $this->actingAsReseller($reseller);

        Livewire::test(CreditSummary::class)->assertSee('هنوز شارژ نشده')->assertSee('40,000');

        $this->wallets->credit($reseller, 10000, 'charge');
        Livewire::test(CreditSummary::class)->assertSee('10,000')->assertSee('50,000');

        $this->supply($reseller, $this->order($reseller, $this->memberOf($reseller)), 50000);
        Livewire::test(CreditSummary::class)->assertSee('بدهی فعلی')->assertSee('-40,000')->assertSee('اعتبار تمام شده');
    }

    #[Test]
    public function the_ledger_widget_follows_the_table_filters(): void
    {
        $reseller = $this->reseller();
        $this->wallets->credit($reseller, 123000, 'charge');
        $this->supply($reseller, $this->order($reseller, $this->memberOf($reseller)), 45000);
        $this->actingAsReseller($reseller);

        Livewire::test(LedgerSummary::class)->assertSee('123,000')->assertSee('45,000');

        Livewire::test(LedgerSummary::class, ['tableFilters' => ['kind' => ['value' => 'supply']]])
            ->assertSee('45,000')
            ->assertDontSee('123,000');
    }

    #[Test]
    public function the_statement_panel_defaults_to_30_days_and_follows_the_period_filter(): void
    {
        $reseller = $this->reseller();
        $this->order($reseller, $this->memberOf($reseller), 100000, 60000);
        $this->actingAsReseller($reseller);

        Livewire::test(StatementPanel::class)
            ->assertSee('۳۰ روز اخیر')
            ->assertSee('100,000')
            ->assertSee('60,000')
            ->assertSee('40,000')
            ->assertSee('40٪');

        Livewire::test(StatementPanel::class, ['tableFilters' => ['period' => ['value' => 'last_month']]])
            ->assertSee('ماه گذشته')
            ->assertSee('در این بازه فروش یا حرکت مالی‌ای ثبت نشده است');

        // مقدار دست‌کاری‌شده ⇒ به پیش‌فرض برمی‌گردد، نه خطا
        Livewire::test(StatementPanel::class, ['tableFilters' => ['period' => ['value' => 'x\' or 1=1']]])
            ->assertSuccessful()->assertSee('۳۰ روز اخیر');
    }

    #[Test]
    public function the_statement_panel_shows_gifts_net_profit_and_liabilities(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $this->order($reseller, $buyer, 100000, 60000);
        $account = $this->accountIn($buyer, $reseller);
        $this->wallets->credit($account, 3000, 'commission', null, 'c');
        $this->wallets->credit($account, 2000, 'referral_bonus', null, 'b');
        $this->actingAsReseller($reseller);

        Livewire::test(StatementPanel::class)
            ->assertSeeInOrder(['سود ناخالص', '40,000', 'کمیسیون', '3,000', 'پاداش', '2,000', 'سود خالص', '35,000'])
            ->assertSee('5,000');
    }

    #[Test]
    public function the_panel_is_read_only_and_has_no_money_movement_pages(): void
    {
        $reseller = $this->reseller();
        $tx = $this->wallets->credit($reseller, 100, 'charge');
        $this->actingAsReseller($reseller);

        $this->assertFalse(FinanceResource::canCreate());
        $this->assertFalse(FinanceResource::canEdit($tx));
        $this->assertFalse(FinanceResource::canDelete($tx));
        $this->assertSame(['index'], array_keys(FinanceResource::getPages()));
    }

    #[Test]
    public function the_list_query_count_does_not_grow_with_rows(): void
    {
        $reseller = $this->reseller();
        $this->actingAsReseller($reseller);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListFinance::class)->assertSuccessful();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->wallets->credit($reseller, 100, 'charge');
        $few = $count();
        for ($i = 0; $i < 8; $i++) {
            $this->wallets->credit($reseller, 100, 'charge');
        }

        $this->assertSame($few, $count());
    }
}
