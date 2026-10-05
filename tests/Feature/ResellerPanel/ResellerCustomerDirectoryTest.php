<?php

namespace Tests\Feature\ResellerPanel;

use App\Models\Account;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Resellers\Customers\CustomerSegment;
use App\Services\Resellers\Customers\ResellerCustomerDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.2 — سرویس مدیریت مشتریان نماینده (Core): فقط‌خواندنی، Scope‌شده به همین فروشگاه، بدون N+1، مبالغ int.
 */
class ResellerCustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    private const NOW = '2026-10-07 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::NOW);
        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function directory(): ResellerCustomerDirectory
    {
        return app(ResellerCustomerDirectory::class);
    }

    private function sale(Reseller $reseller, User $customer, int $price = 1000, int $cost = 600, string $status = 'account_created', string $at = '2026-10-05 09:00:00', bool $renewal = false): Order
    {
        return Order::factory()->create([
            'user_id' => $customer->id,
            'customer_account_id' => $this->accountIn($customer, $reseller)->id,
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => $cost,
            'customers_price' => $price,
            'status' => $status,
            'renews_account_id' => $renewal ? 999 : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function service(Reseller $reseller, User $customer, string $expiresAt, array $attributes = []): Account
    {
        return Account::factory()->create(array_merge([
            'user_id' => $customer->id,
            'customer_account_id' => $this->accountIn($customer, $reseller)->id,
            'expires_at' => $expiresAt,
        ], $attributes));
    }

    private function row(Reseller $reseller, User $customer): ?User
    {
        return $this->directory()->query($reseller)->whereKey($customer->id)->first();
    }

    /** @return list<int> */
    private function ids(Reseller $reseller, CustomerSegment $segment): array
    {
        return $this->directory()->applySegment($this->directory()->query($reseller), $reseller, $segment)
            ->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /* ------------------------------ جداسازی ------------------------------ */

    #[Test]
    public function only_members_of_this_store_are_listed_and_disabled_ones_are_included_with_their_status(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $active = $this->memberOf($reseller);
        $disabled = $this->memberOf($reseller);
        $this->accountIn($disabled, $reseller)->update(['status' => 'disabled']);
        $stranger = $this->memberOf($other);
        $mainOnly = User::factory()->create();
        $this->accountIn($mainOnly, null);

        $rows = $this->directory()->query($reseller)->get()->keyBy('id');

        $this->assertEqualsCanonicalizing([$active->id, $disabled->id], $rows->keys()->all());
        $this->assertSame('active', $rows[$active->id]->membership_status);
        $this->assertSame('disabled', $rows[$disabled->id]->membership_status);
        $this->assertFalse($rows->has($stranger->id));
        $this->assertFalse($rows->has($mainOnly->id));
    }

    #[Test]
    public function a_soft_deleted_membership_is_not_a_customer(): void
    {
        $reseller = Reseller::factory()->create();
        $gone = $this->memberOf($reseller);
        $this->accountIn($gone, $reseller)->delete();

        $this->assertNull($this->row($reseller, $gone));
        $this->assertNull($this->directory()->profile($reseller, $gone));
    }

    #[Test]
    public function a_customer_of_two_stores_only_shows_this_stores_orders_services_and_wallet(): void
    {
        $a = Reseller::factory()->create();
        $b = Reseller::factory()->create();
        $both = $this->memberOf($a);
        $this->accountIn($both, $b);

        $this->sale($a, $both, 1000, 600);
        $this->sale($b, $both, 5000, 3000);
        $this->service($a, $both, '2026-11-01 00:00:00');
        $this->service($b, $both, '2026-11-02 00:00:00');
        Wallet::query()->create(['user_id' => $both->id, 'store_type' => 'reseller', 'reseller_id' => $a->id, 'balance' => 111]);
        Wallet::query()->create(['user_id' => $both->id, 'store_type' => 'reseller', 'reseller_id' => $b->id, 'balance' => 999]);
        Wallet::query()->create(['user_id' => $both->id, 'store_type' => 'main', 'balance' => 7777]);

        $row = $this->row($a, $both);

        $this->assertSame(1, (int) $row->orders_count);
        $this->assertSame(1000, (int) $row->total_spent);
        $this->assertSame(1, (int) $row->active_services_count);
        $this->assertSame(111, (int) $row->wallet_balance);

        $profile = $this->directory()->profile($a, $both);
        $this->assertSame(111, $profile->walletBalance);
        $this->assertSame(1000, $profile->revenue);
        $this->assertCount(1, $profile->services);
        $this->assertCount(1, $profile->recentOrders);
    }

    #[Test]
    public function the_profile_of_a_non_member_is_null(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $this->assertNull($this->directory()->profile($reseller, $this->memberOf($other)));
        $this->assertNull($this->directory()->profile($reseller, User::factory()->create()));
    }

    /* ------------------------------ اعداد ------------------------------ */

    #[Test]
    public function only_settled_orders_count_as_spending_and_the_values_are_integers(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);

        $this->sale($reseller, $customer, 1000, 600, 'account_created', '2026-10-01 10:00:00');
        $this->sale($reseller, $customer, 2000, 1200, 'paid', '2026-10-06 10:00:00', renewal: true);
        $this->sale($reseller, $customer, 9000, 5000, 'pending');
        $this->sale($reseller, $customer, 9000, 5000, 'failed');
        $this->sale($reseller, $customer, 9000, 5000, 'refunded');
        $this->sale($reseller, $customer, 9000, 5000, 'provision_failed');

        $row = $this->row($reseller, $customer);

        $this->assertSame(2, (int) $row->orders_count);
        $this->assertSame(3000, (int) $row->total_spent);
        $this->assertSame('2026-10-06 10:00:00', CarbonImmutable::parse($row->last_order_at)->toDateTimeString());

        $profile = $this->directory()->profile($reseller, $customer);
        $this->assertSame(2, $profile->orders);
        $this->assertSame(1, $profile->renewals);
        $this->assertSame(3000, $profile->revenue);
        $this->assertSame(1200, $profile->profit);
        // تاریخچه‌ی سفارش همه‌ی وضعیت‌ها را نشان می‌دهد (پیگیری)؛ فقط جمع‌ها قطعی‌اند.
        $this->assertCount(6, $profile->recentOrders);
    }

    #[Test]
    public function wallet_balance_is_read_without_creating_a_wallet(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $before = Wallet::query()->count();

        $row = $this->row($reseller, $customer);
        $this->directory()->summary($reseller);
        $this->directory()->profile($reseller, $customer);

        $this->assertSame(0, (int) $row->wallet_balance);
        $this->assertSame($before, Wallet::query()->count(), 'خواندن فهرست نباید Wallet بسازد');
    }

    #[Test]
    public function active_services_exclude_test_expired_disabled_and_other_stores_and_report_the_next_expiry(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $this->accountIn($customer, $other);

        $this->service($reseller, $customer, '2026-10-20 00:00:00');
        $this->service($reseller, $customer, '2026-10-09 00:00:00');                        // نزدیک‌ترین
        $this->service($reseller, $customer, '2026-12-01 00:00:00', ['is_test' => true]);
        $this->service($reseller, $customer, '2026-10-01 00:00:00');                        // منقضی
        $this->service($reseller, $customer, '2026-12-01 00:00:00', ['status' => 'disabled']);
        $this->service($other, $customer, '2026-10-08 00:00:00');                           // نماینده‌ی دیگر

        $row = $this->row($reseller, $customer);

        $this->assertSame(2, (int) $row->active_services_count);
        $this->assertSame('2026-10-09 00:00:00', CarbonImmutable::parse($row->next_expiry_at)->toDateTimeString());
    }

    /* ------------------------------ بخش‌ها ------------------------------ */

    #[Test]
    public function segments_answer_who_to_contact(): void
    {
        $reseller = Reseller::factory()->create();

        $active = $this->memberOf($reseller);       // سرویس فعال دور
        $expiring = $this->memberOf($reseller);     // ۳ روز مانده
        $lapsed = $this->memberOf($reseller);       // فقط سرویس تمام‌شده
        $churnedStatus = $this->memberOf($reseller); // status=expired
        $newbie = $this->memberOf($reseller);       // هیچ خریدی
        $mixed = $this->memberOf($reseller);        // یک فعال + یک تمام‌شده

        $this->service($reseller, $active, '2027-01-01 00:00:00');
        $this->service($reseller, $expiring, '2026-10-10 00:00:00');
        $this->service($reseller, $lapsed, '2026-10-01 00:00:00');
        $this->service($reseller, $churnedStatus, '2027-01-01 00:00:00', ['status' => 'expired']);
        $this->service($reseller, $mixed, '2027-01-01 00:00:00');
        $this->service($reseller, $mixed, '2026-09-01 00:00:00');

        foreach ([$active, $expiring, $lapsed, $churnedStatus, $mixed] as $customer) {
            $this->sale($reseller, $customer);
        }

        Wallet::query()->create(['user_id' => $newbie->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 5000]);
        Wallet::query()->create(['user_id' => $active->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 0]);

        $sorted = fn (array $users) => collect($users)->pluck('id')->sort()->values()->all();

        $this->assertSame($sorted([$active, $expiring, $mixed]), $this->ids($reseller, CustomerSegment::ActiveService));
        $this->assertSame($sorted([$expiring]), $this->ids($reseller, CustomerSegment::Expiring));
        $this->assertSame($sorted([$lapsed, $churnedStatus]), $this->ids($reseller, CustomerSegment::NeedsRenewal));
        $this->assertSame($sorted([$newbie]), $this->ids($reseller, CustomerSegment::NeverBought));
        $this->assertSame($sorted([$newbie]), $this->ids($reseller, CustomerSegment::HasBalance));
    }

    #[Test]
    public function expiring_uses_a_closed_seven_day_boundary_and_never_counts_already_expired(): void
    {
        $reseller = Reseller::factory()->create();
        $edge = $this->memberOf($reseller);
        $beyond = $this->memberOf($reseller);
        $past = $this->memberOf($reseller);

        $this->service($reseller, $edge, '2026-10-14 12:00:00');   // دقیقاً ۷ روز بعد
        $this->service($reseller, $beyond, '2026-10-14 12:00:01');
        $this->service($reseller, $past, '2026-10-07 11:59:59');

        $this->assertSame([$edge->id], $this->ids($reseller, CustomerSegment::Expiring));
    }

    #[Test]
    public function an_unknown_segment_or_status_input_is_ignored_not_an_error(): void
    {
        $this->assertNull(CustomerSegment::fromInput('nonsense'));
        $this->assertNull(CustomerSegment::fromInput(['x']));
        $this->assertNull(CustomerSegment::fromInput(null));
        $this->assertSame(CustomerSegment::Expiring, CustomerSegment::fromInput('expiring'));

        $reseller = Reseller::factory()->create();
        $this->memberOf($reseller);
        $query = $this->directory()->query($reseller);

        $this->assertSame(1, $this->directory()->applyMembershipStatus($query, $reseller, 'drop table')->count());
        $this->assertSame(0, $this->directory()->applyMembershipStatus($this->directory()->query($reseller), $reseller, 'blocked')->count());
    }

    /* ------------------------------ خلاصه ------------------------------ */

    #[Test]
    public function summary_uses_the_same_definitions_as_the_segments(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $a = $this->memberOf($reseller);
        $b = $this->memberOf($reseller);
        $c = $this->memberOf($reseller);
        $this->accountIn($c, $reseller)->update(['status' => 'blocked']);
        $this->memberOf($other);

        $this->service($reseller, $a, '2026-10-09 00:00:00');
        $this->service($reseller, $b, '2026-10-01 00:00:00');
        $this->sale($reseller, $a);
        $this->sale($reseller, $b);

        Wallet::query()->create(['user_id' => $a->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 400]);
        Wallet::query()->create(['user_id' => $b->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 600]);
        Wallet::query()->create(['user_id' => $this->memberOf($other)->id, 'store_type' => 'reseller', 'reseller_id' => $other->id, 'balance' => 9999]);

        $summary = $this->directory()->summary($reseller);

        $this->assertSame(3, $summary->total);
        $this->assertSame(2, $summary->active);
        $this->assertSame(1, $summary->inactive);
        $this->assertSame(3, $summary->newInLast30Days);
        $this->assertSame(1, $summary->withActiveService);
        $this->assertSame(1, $summary->expiring);
        $this->assertSame(1, $summary->needsRenewal);
        $this->assertSame(1, $summary->neverBought);
        $this->assertSame(1000, $summary->totalWalletBalance);

        $this->assertSame($summary->expiring, count($this->ids($reseller, CustomerSegment::Expiring)));
        $this->assertSame($summary->needsRenewal, count($this->ids($reseller, CustomerSegment::NeedsRenewal)));
    }

    #[Test]
    public function new_customers_window_is_thirty_days(): void
    {
        $reseller = Reseller::factory()->create();
        $old = $this->memberOf($reseller);
        $this->accountIn($old, $reseller)->forceFill(['created_at' => '2026-09-01 00:00:00'])->save();
        $this->memberOf($reseller);

        $this->assertSame(1, $this->directory()->summary($reseller)->newInLast30Days);
    }

    /* ------------------------------ کارایی ------------------------------ */

    #[Test]
    public function the_listing_query_count_does_not_depend_on_the_number_of_customers(): void
    {
        $reseller = Reseller::factory()->create();

        $count = function () use ($reseller): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->directory()->query($reseller)->limit(50)->get()->each(fn ($u) => [
                $u->wallet_balance, $u->orders_count, $u->total_spent, $u->active_services_count,
            ]);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach (range(1, 2) as $_) {
            $c = $this->memberOf($reseller);
            $this->sale($reseller, $c);
            $this->service($reseller, $c, '2026-11-01 00:00:00');
        }
        $small = $count();

        foreach (range(1, 8) as $_) {
            $c = $this->memberOf($reseller);
            $this->sale($reseller, $c);
            $this->service($reseller, $c, '2026-11-01 00:00:00');
        }

        $this->assertSame($small, $count());
        $this->assertSame(1, $small);
    }

    #[Test]
    public function the_summary_runs_a_constant_number_of_queries(): void
    {
        $reseller = Reseller::factory()->create();
        $this->memberOf($reseller);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->directory()->summary($reseller);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(1, 6) as $_) {
            $c = $this->memberOf($reseller);
            $this->sale($reseller, $c);
            $this->service($reseller, $c, '2026-10-09 00:00:00');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->directory()->summary($reseller);
        $this->assertSame($small, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    #[Test]
    public function reading_never_writes_to_the_database(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $this->sale($reseller, $customer);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->directory()->query($reseller)->get();
        $this->directory()->summary($reseller);
        $this->directory()->profile($reseller, $customer);
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query']))->count();
        DB::disableQueryLog();

        $this->assertSame(0, $writes);
    }
}
