<?php

namespace Tests\Feature\Resellers\Finance;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Core\WalletService;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Finance\ResellerFinanceCenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.5 — مرکز مالی نماینده (Core، فقط‌خواندنی): اعتبار، گردش اعتبار (فقط حرکت‌های مربوط به اعتبار این فروشگاه)،
 * جمع‌ها، صورت‌حساب و فقط‌خواندنی‌بودن.
 */
class ResellerFinanceCenterTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    private ResellerFinanceCenter $center;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->center = app(ResellerFinanceCenter::class);
        $this->wallets = app(WalletService::class);
    }

    private function reseller(int $debtLimit = 1000000): Reseller
    {
        return Reseller::factory()->create(['debt_limit' => $debtLimit]);
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

    /** هزینه‌ی تأمین مثل PurchaseService: Debit از Wallet صاحب با ارجاع به سفارش */
    private function supply(Reseller $reseller, Order $order, int $amount): WalletTransaction
    {
        return $this->wallets->debit($reseller, $amount, 'purchase', $order, "هزینه‌ی تأمین — سفارش #{$order->id}");
    }

    private function setCreatedAt(WalletTransaction $tx, string $when): WalletTransaction
    {
        DB::table('wallet_transactions')->where('id', $tx->id)->update(['created_at' => $when, 'updated_at' => $when]);

        return $tx->fresh();
    }

    #[Test]
    public function credit_reads_the_owners_main_wallet_without_creating_one(): void
    {
        $reseller = $this->reseller(50000);

        $before = Wallet::query()->count();
        $c = $this->center->credit($reseller);

        $this->assertSame($before, Wallet::query()->count(), 'خواندن اعتبار نباید Wallet بسازد');
        $this->assertFalse($c->hasWallet);
        $this->assertSame(0, $c->balance);
        $this->assertSame(50000, $c->purchasingPower());
        $this->assertFalse($c->isInDebt());
        $this->assertFalse($c->isOutOfCredit());
    }

    #[Test]
    public function credit_reports_balance_debt_and_purchasing_power(): void
    {
        $reseller = $this->reseller(100000);
        $this->wallets->credit($reseller, 30000, 'charge');

        $c = $this->center->credit($reseller);
        $this->assertTrue($c->hasWallet);
        $this->assertSame(30000, $c->balance);
        $this->assertSame(130000, $c->purchasingPower());
        $this->assertSame(0, $c->debt());

        $order = $this->order($reseller, $this->memberOf($reseller));
        $this->supply($reseller, $order, 80000);

        $c = $this->center->credit($reseller);
        $this->assertSame(-50000, $c->balance);
        $this->assertTrue($c->isInDebt());
        $this->assertSame(50000, $c->debt());
        $this->assertSame(50000, $c->purchasingPower());
        $this->assertFalse($c->isOutOfCredit());
    }

    #[Test]
    public function out_of_credit_at_exactly_the_debt_floor(): void
    {
        $reseller = $this->reseller(40000);
        $this->supply($reseller, $this->order($reseller, $this->memberOf($reseller)), 40000);

        $c = $this->center->credit($reseller);

        $this->assertSame(0, $c->purchasingPower());
        $this->assertTrue($c->isOutOfCredit());
    }

    #[Test]
    public function pending_owner_charges_are_counted_and_customer_charges_are_not(): void
    {
        $reseller = $this->reseller();
        $other = $this->reseller();
        $customer = $this->memberOf($reseller);
        $method = PaymentMethod::factory()->create();

        $mk = fn (array $a) => Payment::query()->create(array_merge([
            'user_id' => $customer->id, 'payment_method_id' => $method->id, 'amount' => 1000,
            'purpose' => 'wallet_charge', 'status' => 'pending', 'wallet_owner_type' => 'reseller', 'reseller_id' => $reseller->id,
        ], $a));

        $mk(['amount' => 5000]);
        $mk(['amount' => 7000]);
        $mk(['status' => 'confirmed', 'amount' => 99]);                       // تأییدشده
        $mk(['wallet_owner_type' => 'user', 'amount' => 11]);                  // شارژ مشتری
        $mk(['reseller_id' => $other->id, 'amount' => 123456]);                // نمایندهٔ دیگر

        $c = $this->center->credit($reseller);

        $this->assertSame(2, $c->pendingChargeCount);
        $this->assertSame(12000, $c->pendingChargeAmount);
    }

    #[Test]
    public function the_ledger_has_only_credit_movements_of_this_store(): void
    {
        $reseller = $this->reseller();
        $other = $this->reseller();
        $buyer = $this->memberOf($reseller);

        $charge = $this->wallets->credit($reseller, 500000, 'charge');
        $supply = $this->supply($reseller, $this->order($reseller, $buyer), 60000);
        $adjust = $this->wallets->adminAdjust($reseller, -1000, null, 'اصلاح');
        // سفارش نمایندهٔ دیگر روی همان کاربر/ولی Wallet خودش — نباید بیاید
        $this->supply($other, $this->order($other, $this->memberOf($other)), 777);
        // خرید شخصی صاحب در Main: همان Wallet ولی نه تأمین این فروشگاه
        $ownerMainOrder = $this->order(null, $reseller->user);
        $this->wallets->debit($reseller, 4000, 'purchase', $ownerMainOrder, 'خرید شخصی');

        $ids = $this->center->ledgerQuery($reseller)->pluck('wallet_transactions.id')->all();

        $this->assertEqualsCanonicalizing([$charge->id, $supply->id, $adjust->id], $ids);
    }

    #[Test]
    public function owners_personal_main_commission_and_bonus_never_appear(): void
    {
        $reseller = $this->reseller();
        $this->wallets->credit($reseller, 9000, 'commission', null, 'کمیسیون شخصی');
        $this->wallets->credit($reseller, 8000, 'referral_bonus', null, 'پاداش شخصی');
        $charge = $this->wallets->credit($reseller, 100, 'charge');

        $this->assertSame([$charge->id], $this->center->ledgerQuery($reseller)->pluck('wallet_transactions.id')->all());
    }

    #[Test]
    public function a_supply_transaction_pointing_at_a_foreign_order_is_excluded_even_with_matching_type(): void
    {
        $reseller = $this->reseller();
        $other = $this->reseller();
        // Debit روی Wallet صاحبِ این نماینده، ولی ارجاع به سفارش «نمایندهٔ دیگر»
        $foreignOrder = $this->order($other, $this->memberOf($other));
        $this->wallets->debit($reseller, 500, 'purchase', $foreignOrder, 'ارجاع بیگانه');

        $this->assertSame(0, $this->center->ledgerQuery($reseller)->count());
    }

    #[Test]
    public function only_purchase_and_refund_can_be_supply_even_if_they_reference_this_stores_order(): void
    {
        $reseller = $this->reseller();
        $order = $this->order($reseller, $this->memberOf($reseller));
        // کمیسیون/پاداشِ شخصی صاحب که (بعید ولی ممکن) به سفارش همین فروشگاه ارجاع دارد: «تأمین» نیست و نمی‌آید
        $this->wallets->credit($reseller, 700, 'commission', $order, 'کمیسیون با ارجاع سفارش');
        $this->wallets->credit($reseller, 800, 'referral_bonus', $order, 'پاداش با ارجاع سفارش');

        $this->assertSame(0, $this->center->ledgerQuery($reseller)->count());
    }

    #[Test]
    public function the_statement_owner_charges_ignore_non_positive_charge_rows(): void
    {
        $reseller = $this->reseller();
        $now = CarbonImmutable::now();
        $this->wallets->credit($reseller, 1000, 'charge');
        // سطر charge منفیِ دست‌کاری‌شده (از مسیر WalletService ساخته نمی‌شود؛ بازگشت پرداخت admin_adjust است)
        $tx = $this->wallets->credit($reseller, 300, 'charge');
        DB::table('wallet_transactions')->where('id', $tx->id)->update(['amount' => -300]);

        $this->assertSame(1000, $this->center->statement($reseller, DashboardPeriod::Last30Days, $now)->ownerCharges);
    }

    #[Test]
    public function reference_type_must_be_an_order(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $order = $this->order($reseller, $buyer);
        // ارجاع از نوع دیگر (User) با reference_id هم‌شماره‌ی یک سفارش این فروشگاه
        $tx = $this->wallets->debit($reseller, 500, 'purchase', $reseller->user, 'ارجاع غیرسفارش');
        DB::table('wallet_transactions')->where('id', $tx->id)->update(['reference_id' => $order->id]);

        $this->assertSame(0, $this->center->ledgerQuery($reseller)->count());
    }

    #[Test]
    public function a_reseller_without_a_wallet_has_an_empty_ledger_and_nothing_is_created(): void
    {
        $reseller = $this->reseller();
        $before = Wallet::query()->count();

        $this->assertSame(0, $this->center->ledgerQuery($reseller)->count());
        $this->assertTrue($this->center->ledgerTotals($this->center->ledgerQuery($reseller))->isEmpty());
        $this->assertSame($before, Wallet::query()->count());
    }

    #[Test]
    public function kind_and_direction_filters_split_and_unknown_values_are_ignored(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $charge = $this->wallets->credit($reseller, 500000, 'charge');
        $supply = $this->supply($reseller, $this->order($reseller, $buyer), 60000);
        $adjust = $this->wallets->adminAdjust($reseller, 2000, null, 'اصلاح');

        $q = fn (string $m, $v) => $this->center->{$m}($this->center->ledgerQuery($reseller), $v)->pluck('wallet_transactions.id')->all();

        $this->assertSame([$charge->id], $q('applyKind', 'charge'));
        $this->assertSame([$supply->id], $q('applyKind', 'supply'));
        $this->assertSame([$adjust->id], $q('applyKind', 'adjust'));
        $this->assertEqualsCanonicalizing([$charge->id, $adjust->id], $q('applyDirection', 'in'));
        $this->assertSame([$supply->id], $q('applyDirection', 'out'));

        foreach ([null, '', 'x', ['charge']] as $junk) {
            $this->assertCount(3, $q('applyKind', $junk));
            $this->assertCount(3, $q('applyDirection', $junk));
            $this->assertCount(3, $q('applyPeriod', $junk));
        }
    }

    #[Test]
    public function refunds_of_this_stores_orders_are_supply_movements(): void
    {
        $reseller = $this->reseller();
        $order = $this->order($reseller, $this->memberOf($reseller));
        $this->supply($reseller, $order, 60000);
        $refund = $this->wallets->credit($reseller, 60000, 'refund', $order, 'بازگشت');

        $supplyIds = $this->center->applyKind($this->center->ledgerQuery($reseller), 'supply')->pluck('wallet_transactions.id')->all();

        $this->assertContains($refund->id, $supplyIds);
        $t = $this->center->ledgerTotals($this->center->ledgerQuery($reseller));
        $this->assertSame(60000, $t->supplySpent);
        $this->assertSame(60000, $t->supplyRefunded);
        $this->assertSame(0, $t->netSupplyCost());
    }

    #[Test]
    public function ledger_totals_are_correct_and_signed_adjust_is_net(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $this->wallets->credit($reseller, 500000, 'charge');
        $this->wallets->credit($reseller, 100000, 'charge');
        $this->supply($reseller, $this->order($reseller, $buyer), 60000);
        $this->supply($reseller, $this->order($reseller, $buyer), 40000);
        $this->wallets->adminAdjust($reseller, 5000, null, 'a');
        $this->wallets->adminAdjust($reseller, -2000, null, 'b');

        $t = $this->center->ledgerTotals($this->center->ledgerQuery($reseller));

        $this->assertSame(6, $t->count);
        $this->assertSame(605000, $t->credited);
        $this->assertSame(102000, $t->debited);
        $this->assertSame(100000, $t->supplySpent);
        $this->assertSame(600000, $t->charged);
        $this->assertSame(3000, $t->adjustedNet);
        $this->assertSame(503000, $t->netChange());
    }

    #[Test]
    public function totals_follow_the_filtered_query_ignoring_order_limit_and_eager_loads(): void
    {
        $reseller = $this->reseller();
        $this->wallets->credit($reseller, 100, 'charge');
        $this->wallets->credit($reseller, 200, 'charge');
        $this->wallets->credit($reseller, 300, 'charge');

        $tableQuery = $this->center->ledgerQuery($reseller)->orderBy('amount')->limit(1)->offset(1);

        $this->assertSame(600, $this->center->ledgerTotals($tableQuery)->charged);
        $this->assertSame(200, $this->center->ledgerTotals($this->center->applyDirection($this->center->ledgerQuery($reseller)->where('wallet_transactions.amount', 200), 'in'))->charged);
    }

    #[Test]
    public function the_period_filter_is_half_open(): void
    {
        $reseller = $this->reseller();
        $now = CarbonImmutable::parse('2026-10-05 12:00:00');
        $a = $this->setCreatedAt($this->wallets->credit($reseller, 1, 'charge'), '2026-10-05 00:00:00');
        $b = $this->setCreatedAt($this->wallets->credit($reseller, 2, 'charge'), '2026-10-04 23:59:59');
        $c = $this->setCreatedAt($this->wallets->credit($reseller, 3, 'charge'), '2026-10-06 00:00:00');

        $ids = fn ($p) => $this->center->applyPeriod($this->center->ledgerQuery($reseller), $p, $now)->pluck('wallet_transactions.id')->all();

        $this->assertSame([$a->id], $ids('today'));
        $this->assertContains($b->id, $ids('7d'));
        $this->assertNotContains($c->id, $ids('7d'));
    }

    #[Test]
    public function the_ledger_query_count_is_one_for_totals_and_independent_of_rows(): void
    {
        $reseller = $this->reseller();
        for ($i = 0; $i < 8; $i++) {
            $this->wallets->credit($reseller, 100, 'charge');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->center->ledgerTotals($this->center->ledgerQuery($reseller));
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $n);
    }

    /* ---------------------------------------------------------------- صورت‌حساب */

    #[Test]
    public function the_statement_uses_the_dashboard_settled_definition_and_integer_math(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);

        $this->order($reseller, $buyer, 100000, 60000, 'account_created');
        $this->order($reseller, $buyer, 50000, 30000, 'paid');
        $this->order($reseller, $buyer, 999999, 1, 'refunded');         // فروش قطعی نیست
        $this->order($reseller, $buyer, 999999, 1, 'provision_failed'); // فروش قطعی نیست
        $this->order(null, $buyer, 777777, 0);                          // Main

        $s = $this->center->statement($reseller, DashboardPeriod::Last30Days);

        $this->assertSame(2, $s->orders);
        $this->assertSame(150000, $s->revenue);
        $this->assertSame(90000, $s->supplyCost);
        $this->assertSame(60000, $s->grossProfit());
        $this->assertSame(40, $s->grossMarginPercent());
    }

    #[Test]
    public function the_statement_is_scoped_to_this_store_only(): void
    {
        $reseller = $this->reseller();
        $other = $this->reseller();
        $this->order($other, $this->memberOf($other), 888888, 1);

        $s = $this->center->statement($reseller, DashboardPeriod::Last30Days);

        $this->assertSame(0, $s->orders);
        $this->assertSame(0, $s->revenue);
        $this->assertTrue($s->isEmpty());
        $this->assertNull($s->grossMarginPercent());
    }

    #[Test]
    public function gifts_come_from_this_stores_customer_wallets_and_reduce_net_profit(): void
    {
        $reseller = $this->reseller();
        $other = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $otherBuyer = $this->memberOf($other);
        $this->order($reseller, $buyer, 100000, 60000);

        $mine = $this->accountIn($buyer, $reseller);
        $this->wallets->credit($mine, 3000, 'commission', null, 'کمیسیون');
        $this->wallets->credit($mine, 2000, 'referral_bonus', null, 'پاداش');
        $this->wallets->credit($mine, 50000, 'charge', null, 'شارژ');
        // نمایندهٔ دیگر و فروشگاه Main: نباید شمرده شوند
        $this->wallets->credit($this->accountIn($otherBuyer, $other), 99999, 'commission', null, 'دیگری');
        $this->wallets->credit($this->accountIn($buyer, null), 88888, 'commission', null, 'Main');

        $s = $this->center->statement($reseller, DashboardPeriod::Last30Days);

        $this->assertSame(3000, $s->commissionsGiven);
        $this->assertSame(2000, $s->bonusesGiven);
        $this->assertSame(5000, $s->giftsGiven());
        $this->assertSame(50000, $s->customerCharges);
        $this->assertSame(40000, $s->grossProfit());
        $this->assertSame(35000, $s->netProfit());
        $this->assertSame(35, $s->netMarginPercent());
    }

    #[Test]
    public function net_profit_can_be_negative_and_margin_rounds_symmetrically(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $this->order($reseller, $buyer, 3, 0);
        $this->wallets->credit($this->accountIn($buyer, $reseller), 5, 'commission', null, 'x');

        $s = $this->center->statement($reseller, DashboardPeriod::Last30Days);

        $this->assertSame(-2, $s->netProfit());
        // -2/3 = -66.67% ⇒ -67 (گرد متقارن)، سود ناخالص ۱۰۰٪
        $this->assertSame(-67, $s->netMarginPercent());
        $this->assertSame(100, $s->grossMarginPercent());
    }

    #[Test]
    public function owner_charges_in_the_statement_are_only_positive_charges_in_the_period(): void
    {
        $reseller = $this->reseller();
        $now = CarbonImmutable::now();

        $this->wallets->credit($reseller, 200000, 'charge');
        $old = $this->wallets->credit($reseller, 777000, 'charge');
        $this->setCreatedAt($old, $now->subDays(90)->format('Y-m-d H:i:s'));
        $this->wallets->adminAdjust($reseller, 5000, null, 'اصلاح'); // شارژ نیست

        $s = $this->center->statement($reseller, DashboardPeriod::Last30Days, $now);

        $this->assertSame(200000, $s->ownerCharges);
    }

    #[Test]
    public function customer_wallet_balances_are_the_stores_liability_and_not_period_bound(): void
    {
        $reseller = $this->reseller();
        $other = $this->reseller();
        $a = $this->memberOf($reseller);
        $b = $this->memberOf($reseller);
        $this->wallets->credit($this->accountIn($a, $reseller), 40000, 'charge');
        $this->wallets->credit($this->accountIn($b, $reseller), 2500, 'charge');
        $this->wallets->credit($this->accountIn($this->memberOf($other), $other), 999999, 'charge');
        $this->wallets->credit($this->accountIn($a, null), 123456, 'charge'); // Main

        $s = $this->center->statement($reseller, DashboardPeriod::Today);

        $this->assertSame(42500, $s->customerWalletBalances);
    }

    #[Test]
    public function the_statement_period_boundaries_are_half_open(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $now = CarbonImmutable::parse('2026-10-05 12:00:00');

        $in = $this->order($reseller, $buyer, 100, 10);
        $edgeOut = $this->order($reseller, $buyer, 200, 20);
        DB::table('orders')->where('id', $in->id)->update(['created_at' => '2026-10-05 00:00:00']);
        DB::table('orders')->where('id', $edgeOut->id)->update(['created_at' => '2026-10-06 00:00:00']);

        $s = $this->center->statement($reseller, DashboardPeriod::Today, $now);

        $this->assertSame(1, $s->orders);
        $this->assertSame(100, $s->revenue);
    }

    #[Test]
    public function the_statement_uses_a_fixed_number_of_queries(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        foreach (range(1, 5) as $_) {
            $this->order($reseller, $buyer);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->center->statement($reseller, DashboardPeriod::Last30Days);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(4, $n);
    }

    #[Test]
    public function the_service_never_writes(): void
    {
        $reseller = $this->reseller();
        $buyer = $this->memberOf($reseller);
        $this->supply($reseller, $this->order($reseller, $buyer), 1000);

        $count = fn () => [Wallet::query()->count(), WalletTransaction::query()->count(), DB::table('audit_logs')->count(), Order::query()->count(), Payment::query()->count()];
        $before = $count();
        $balance = Wallet::query()->where('user_id', $reseller->user_id)->value('balance');

        $this->center->credit($reseller);
        $q = $this->center->ledgerQuery($reseller);
        $this->center->ledgerTotals($q);
        $this->center->statement($reseller, DashboardPeriod::Last30Days);

        $this->assertSame($before, $count());
        $this->assertSame($balance, Wallet::query()->where('user_id', $reseller->user_id)->value('balance'));
    }
}
