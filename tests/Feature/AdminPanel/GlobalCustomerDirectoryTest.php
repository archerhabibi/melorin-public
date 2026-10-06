<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\Customers\GlobalCustomerDirectory;
use App\Services\Admin\Customers\GlobalCustomerIdentity;
use App\Services\Admin\Customers\GlobalCustomerSegment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** B7.2 — منطق Core نمای سراسری مشتریان ادمین (ADMIN-GLOBAL-CUSTOMER-VIEW-CONTRACT.md). */
class GlobalCustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function dir(): GlobalCustomerDirectory
    {
        return app(GlobalCustomerDirectory::class);
    }

    private function member(User $user, ?Reseller $reseller = null, string $status = 'active'): CustomerAccount
    {
        return CustomerAccount::create([
            'user_id' => $user->id,
            'store_type' => $reseller ? 'reseller' : 'main',
            'reseller_id' => $reseller?->id,
            'status' => $status,
        ]);
    }

    private function wallet(User $user, int $balance, ?Reseller $reseller = null): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'store_type' => $reseller ? 'reseller' : 'main',
            'reseller_id' => $reseller?->id,
            'balance' => $balance,
        ]);
    }

    private function order(User $user, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'sales_channel' => 'website',
            'main_price' => 100000,
            'status' => 'account_created',
        ], $attrs));
    }

    private function service(User $user, ?CustomerAccount $membership, array $attrs = []): Account
    {
        $order = $this->order($user, ['status' => 'account_created']);

        return Account::factory()->create(array_merge([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'customer_account_id' => $membership?->id,
        ], $attrs));
    }

    private function row(User $user, ?string $key = null)
    {
        return $this->dir()->query()->whereKey($user->id)->first();
    }

    /* ----------------------------- فهرست ----------------------------- */

    #[Test]
    public function every_user_appears_even_without_any_membership_or_order(): void
    {
        $ghost = User::factory()->create();

        $row = $this->row($ghost);

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->stores_count);
        $this->assertSame(0, (int) $row->settled_orders_count);
        $this->assertSame(0, (int) $row->total_spent);
        $this->assertNull($row->main_balance);
        $this->assertNull($row->last_order_at);
        $this->assertSame(0, (int) $row->active_services_count);
    }

    #[Test]
    public function soft_deleted_users_are_not_listed(): void
    {
        $gone = User::factory()->create();
        $gone->delete();

        $this->assertNull($this->row($gone));
    }

    #[Test]
    public function spent_uses_main_price_for_direct_and_customers_price_for_resellers_across_all_stores(): void
    {
        $reseller = Reseller::factory()->create();
        $user = User::factory()->create();

        $this->order($user, ['main_price' => 100000]);
        $this->order($user, [
            'reseller_id' => $reseller->id, 'sales_channel' => 'reseller_bot',
            'main_price' => null, 'reseller_price' => 70000, 'customers_price' => 90000,
        ]);

        $row = $this->row($user);

        $this->assertSame(2, (int) $row->settled_orders_count);
        $this->assertSame(190000, (int) $row->total_spent);
    }

    #[Test]
    public function only_settled_non_test_orders_count(): void
    {
        $user = User::factory()->create();

        $this->order($user, ['status' => 'paid']);
        $this->order($user, ['status' => 'pending']);
        $this->order($user, ['status' => 'failed']);
        $this->order($user, ['status' => 'refunded']);
        $this->order($user, ['status' => 'provision_failed']);
        $this->order($user, ['sales_channel' => 'test_account', 'main_price' => 0, 'status' => 'account_created']);

        $row = $this->row($user);

        $this->assertSame(1, (int) $row->settled_orders_count);
        $this->assertSame(100000, (int) $row->total_spent);
    }

    #[Test]
    public function main_and_reseller_wallets_are_reported_separately(): void
    {
        $r1 = Reseller::factory()->create();
        $r2 = Reseller::factory()->create();
        $user = User::factory()->create();

        $this->wallet($user, 5000);
        $this->wallet($user, 700, $r1);
        $this->wallet($user, -200, $r2);

        $row = $this->row($user);

        $this->assertSame(5000, (int) $row->main_balance);
        $this->assertSame(500, (int) $row->stores_balance);
    }

    #[Test]
    public function stores_count_counts_live_memberships_only(): void
    {
        $reseller = Reseller::factory()->create();
        $user = User::factory()->create();

        $this->member($user);
        $removed = $this->member($user, $reseller);
        $removed->delete();

        $this->assertSame(1, (int) $this->row($user)->stores_count);
    }

    #[Test]
    public function active_services_exclude_tests_expired_and_other_users_and_next_expiry_is_the_earliest(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $m = $this->member($user);

        $near = $this->service($user, $m, ['expires_at' => now()->addDays(3)]);
        $this->service($user, $m, ['expires_at' => now()->addDays(40)]);
        $this->service($user, $m, ['expires_at' => now()->subDay()]);
        $this->service($user, $m, ['status' => 'expired']);
        $this->service($user, $m, ['is_test' => true]);
        $this->service($other, $this->member($other));

        $row = $this->row($user);

        $this->assertSame(2, (int) $row->active_services_count);
        $this->assertSame($near->expires_at->format('Y-m-d H:i'), Carbon::parse($row->next_expiry_at)->format('Y-m-d H:i'));
    }

    #[Test]
    public function open_tickets_and_google_identities_are_counted(): void
    {
        $user = User::factory()->create();

        Ticket::factory()->create(['user_id' => $user->id, 'status' => 'open']);
        Ticket::factory()->create(['user_id' => $user->id, 'status' => 'closed']);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-1']);

        $row = $this->row($user);

        $this->assertSame(1, (int) $row->open_tickets_count);
        $this->assertSame(1, (int) $row->google_identities_count);
    }

    /* ----------------------------- بخش‌ها ----------------------------- */

    private function ids(GlobalCustomerSegment $segment): array
    {
        return $this->dir()->applySegment($this->dir()->query(), $segment)->pluck('users.id')->sort()->values()->all();
    }

    #[Test]
    public function segments_follow_their_definitions(): void
    {
        $r = Reseller::factory()->create();

        $active = User::factory()->create();
        $this->service($active, $this->member($active), ['expires_at' => now()->addDays(30)]);

        $expiring = User::factory()->create();
        $this->service($expiring, $this->member($expiring), ['expires_at' => now()->addDays(2)]);

        $lapsed = User::factory()->create();
        $this->service($lapsed, $this->member($lapsed), ['status' => 'expired']);

        $mixed = User::factory()->create(); // یک فعال + یک تمام‌شده ⇒ نیازمند تمدید نیست
        $mm = $this->member($mixed);
        $this->service($mixed, $mm, ['expires_at' => now()->addDays(30)]);
        $this->service($mixed, $mm, ['status' => 'expired']);

        $never = User::factory()->create();

        $multi = User::factory()->create();
        $this->member($multi);
        $this->member($multi, $r);

        $funded = User::factory()->create();
        $this->wallet($funded, 10, $r);

        $ticketed = User::factory()->create();
        Ticket::factory()->create(['user_id' => $ticketed->id, 'status' => 'open']);

        $this->assertContains($active->id, $this->ids(GlobalCustomerSegment::ActiveService));
        $this->assertContains($mixed->id, $this->ids(GlobalCustomerSegment::ActiveService));
        $this->assertNotContains($lapsed->id, $this->ids(GlobalCustomerSegment::ActiveService));

        $this->assertEqualsCanonicalizing([$expiring->id], $this->ids(GlobalCustomerSegment::Expiring));
        $this->assertEqualsCanonicalizing([$lapsed->id], $this->ids(GlobalCustomerSegment::NeedsRenewal));

        $this->assertContains($never->id, $this->ids(GlobalCustomerSegment::NeverBought));
        $this->assertNotContains($active->id, $this->ids(GlobalCustomerSegment::NeverBought)); // سرویس‌ساز سفارش قطعی دارد

        $this->assertEqualsCanonicalizing([$multi->id], $this->ids(GlobalCustomerSegment::MultiStore));
        $this->assertEqualsCanonicalizing([$funded->id], $this->ids(GlobalCustomerSegment::HasBalance));
        $this->assertEqualsCanonicalizing([$ticketed->id], $this->ids(GlobalCustomerSegment::OpenTicket));
    }

    #[Test]
    public function an_invalid_segment_or_identity_value_means_no_filter_not_an_error(): void
    {
        $this->assertNull(GlobalCustomerSegment::fromInput("x'; drop table users"));
        $this->assertNull(GlobalCustomerSegment::fromInput(['a']));
        $this->assertNull(GlobalCustomerIdentity::fromInput(null));
        $this->assertSame(GlobalCustomerSegment::Expiring, GlobalCustomerSegment::fromInput('expiring'));
    }

    #[Test]
    public function identity_filters(): void
    {
        $google = User::factory()->create(['telegram_id' => null, 'email' => 'g@x.test', 'email_verified_at' => now()]);
        UserIdentity::create(['user_id' => $google->id, 'provider' => 'google', 'provider_user_id' => 'g-a']);

        $unified = User::factory()->create(['telegram_id' => 555]);
        UserIdentity::create(['user_id' => $unified->id, 'provider' => 'google', 'provider_user_id' => 'g-b']);

        $tgOnly = User::factory()->create(['telegram_id' => 777]);
        $unverified = User::factory()->create(['telegram_id' => null, 'email' => 'u@x.test', 'email_verified_at' => null]);

        $ids = fn (GlobalCustomerIdentity $i) => $this->dir()->applyIdentity($this->dir()->query(), $i)->pluck('users.id')->sort()->values()->all();

        $this->assertEqualsCanonicalizing([$google->id, $unified->id], $ids(GlobalCustomerIdentity::Google));
        $this->assertEqualsCanonicalizing([$unified->id, $tgOnly->id], $ids(GlobalCustomerIdentity::Telegram));
        $this->assertSame([$unified->id], $ids(GlobalCustomerIdentity::GoogleAndTelegram));
        $this->assertSame([$google->id], $ids(GlobalCustomerIdentity::EmailVerified));
        $this->assertSame([$unverified->id], $ids(GlobalCustomerIdentity::EmailUnverified));
    }

    #[Test]
    public function store_filter_accepts_only_wellformed_scope_keys(): void
    {
        $r = Reseller::factory()->create();
        $inMain = User::factory()->create();
        $inReseller = User::factory()->create();
        $this->member($inMain);
        $this->member($inReseller, $r);

        $q = fn ($key) => $this->dir()->applyStore($this->dir()->query(), $key)->pluck('users.id')->all();

        $this->assertSame([$inMain->id], $q('main'));
        $this->assertSame([$inReseller->id], $q('reseller:'.$r->id));
        $everyone = User::count(); // شامل صاحبِ نماینده که Factory می‌سازد
        $this->assertCount($everyone, $q("reseller:1' or 1=1 --"));  // نامعتبر ⇒ بدون فیلتر
        $this->assertCount($everyone, $q(null));
        $this->assertFalse(GlobalCustomerDirectory::isValidScopeKey('reseller:0'));
        $this->assertFalse(GlobalCustomerDirectory::isValidScopeKey('reseller:'));
    }

    #[Test]
    public function the_summary_uses_the_same_definitions_as_the_filters(): void
    {
        $r = Reseller::factory()->create();

        $a = User::factory()->create(['telegram_id' => 1]);
        $this->service($a, $this->member($a), ['expires_at' => now()->addDays(2)]);
        UserIdentity::create(['user_id' => $a->id, 'provider' => 'google', 'provider_user_id' => 'g']);
        $this->wallet($a, 4000);

        $b = User::factory()->create(['status' => 'blocked']);
        $this->member($b);
        $this->member($b, $r);
        $this->wallet($b, -900); // منفی: در پیش‌پرداخت نمی‌آید

        $s = $this->dir()->summary();

        $this->assertSame(User::count(), $s->total);
        $this->assertSame(1, $s->inactive);
        $this->assertSame(count($this->ids(GlobalCustomerSegment::ActiveService)), $s->withActiveService);
        $this->assertSame(count($this->ids(GlobalCustomerSegment::Expiring)), $s->expiring);
        $this->assertSame(1, $s->multiStore);
        $this->assertSame(1, $s->unified);
        $this->assertSame(1, $s->googleLinked);
        $this->assertSame(4000, $s->mainWalletBalance);
        $this->assertSame(Reseller::count(), $s->resellerOwners);
    }

    /* ------------------- بهبودهای B7.2 (تراکنش کیف‌پول، شمارنده‌ی بخش‌ها، حافظه‌ی خلاصه) ------------------- */

    #[Test]
    public function the_summary_reports_has_balance_with_the_same_definition_as_the_segment(): void
    {
        $r = Reseller::factory()->create();
        $a = User::factory()->create();
        $this->wallet($a, 5000);
        $b = User::factory()->create();
        $this->wallet($b, 300, $r);
        $c = User::factory()->create();
        $this->wallet($c, -100);

        $s = $this->dir()->summary();

        $this->assertSame(2, $s->withBalance);
        $this->assertSame(count($this->ids(GlobalCustomerSegment::HasBalance)), $s->withBalance);
    }

    #[Test]
    public function count_for_matches_every_segment_filter(): void
    {
        $a = User::factory()->create();
        $this->service($a, $this->member($a), ['expires_at' => now()->addDays(2)]);
        $this->wallet($a, 100);
        Ticket::factory()->create(['user_id' => $a->id, 'status' => 'open']);
        User::factory()->count(2)->create();

        $s = $this->dir()->summary();

        foreach (GlobalCustomerSegment::cases() as $segment) {
            $this->assertSame(count($this->ids($segment)), $s->countFor($segment), $segment->value);
            $this->assertNotSame('', $segment->shortLabel());
        }
    }

    #[Test]
    public function the_summary_is_computed_once_per_instance_when_no_explicit_now_is_given(): void
    {
        User::factory()->create();
        $dir = $this->dir();

        $first = $dir->summary();
        User::factory()->create();              // داده عوض شد ولی همین Instance (= همین Request) نتیجه‌ی قبلی را می‌دهد
        $second = $dir->summary();

        $this->assertSame($first, $second);
        $this->assertSame($first->total + 1, $dir->summary(now())->total); // با `$now` صریح همیشه تازه است
    }

    #[Test]
    public function the_directory_is_scoped_in_the_container_not_a_global_singleton(): void
    {
        $a = app(GlobalCustomerDirectory::class);
        $this->assertSame($a, app(GlobalCustomerDirectory::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($a, app(GlobalCustomerDirectory::class));
    }

    #[Test]
    public function the_profile_lists_recent_wallet_transactions_per_store_and_only_for_this_user(): void
    {
        $r = Reseller::factory()->create();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $main = $this->wallet($user, 1000);
        $res = $this->wallet($user, 500, $r);
        $otherWallet = $this->wallet($other, 99);

        WalletTransaction::create(['wallet_id' => $main->id, 'type' => 'charge', 'amount' => 1000, 'balance_after' => 1000]);
        WalletTransaction::create(['wallet_id' => $res->id, 'type' => 'purchase', 'amount' => -200, 'balance_after' => 500]);
        WalletTransaction::create(['wallet_id' => $otherWallet->id, 'type' => 'charge', 'amount' => 99, 'balance_after' => 99]);

        $profile = $this->dir()->profile($user);

        $this->assertCount(2, $profile->recentWalletTransactions);
        $this->assertSame(
            ['reseller:'.$r->id, 'main'],
            $profile->recentWalletTransactions->map(fn ($t) => $t->wallet->scope_key)->all(),
            'جدیدترین اول؛ هر تراکنش scope_key کیف‌پول خودش را دارد'
        );
    }

    #[Test]
    public function the_wallet_transactions_are_limited_and_do_not_add_queries_per_row(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user, 0);

        foreach (range(1, GlobalCustomerDirectory::PROFILE_WALLET_TRANSACTIONS_LIMIT + 5) as $i) {
            WalletTransaction::create(['wallet_id' => $wallet->id, 'type' => 'charge', 'amount' => 1, 'balance_after' => $i]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $profile = $this->dir()->profile($user);
        $queries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertCount(GlobalCustomerDirectory::PROFILE_WALLET_TRANSACTIONS_LIMIT, $profile->recentWalletTransactions);

        $big = User::factory()->create();
        $bigWallet = $this->wallet($big, 0);
        foreach (range(1, 40) as $i) {
            WalletTransaction::create(['wallet_id' => $bigWallet->id, 'type' => 'charge', 'amount' => 1, 'balance_after' => $i]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->dir()->profile($big);
        $this->assertSame($queries, count(DB::getQueryLog()), 'تعداد کوئری پرونده به تعداد تراکنش وابسته نیست');
    }

    #[Test]
    public function a_user_with_no_wallet_has_no_transactions_and_no_wallet_is_created(): void
    {
        $user = User::factory()->create();

        $profile = $this->dir()->profile($user);

        $this->assertCount(0, $profile->recentWalletTransactions);
        $this->assertSame(0, Wallet::count());
    }

    /* ----------------------------- پرونده ----------------------------- */

    #[Test]
    public function the_profile_keeps_each_store_separate_and_lists_main_first(): void
    {
        $r = Reseller::factory()->create(['slug' => 'alpha']);
        $user = User::factory()->create();

        $mainM = $this->member($user);
        $resM = $this->member($user, $r);
        $this->wallet($user, 3000);
        $this->wallet($user, 800, $r);

        $this->order($user, ['main_price' => 50000]);
        $this->order($user, [
            'reseller_id' => $r->id, 'sales_channel' => 'reseller_bot',
            'main_price' => null, 'reseller_price' => 60000, 'customers_price' => 80000,
        ]);
        $this->service($user, $resM, ['expires_at' => now()->addDays(20)]);

        $p = $this->dir()->profile($user);

        $this->assertCount(2, $p->stores);
        $this->assertTrue($p->stores[0]->isMain());
        $this->assertSame(3000, $p->stores[0]->walletBalance);

        $res = $p->stores[1];
        $this->assertSame('reseller:'.$r->id, $res->scopeKey);
        $this->assertSame('alpha', $res->resellerSlug);
        $this->assertSame(800, $res->walletBalance);
        $this->assertSame(80000, $res->spent);
        $this->assertSame(50000 + 100000, $p->stores[0]->spent); // + سفارشِ helper سرویس (۱۰۰هزار، فروشگاه اصلی)
        $this->assertSame(80000 + 150000, $p->spent);
        $this->assertSame(1, $res->activeServices);
        $this->assertSame(60000, $res->platformRevenue);

        $this->assertSame(3800, $p->totalWalletBalance());
        $this->assertSame($p->stores->sum('spent'), $p->spent);
    }

    #[Test]
    public function the_profile_never_creates_a_membership_or_wallet(): void
    {
        $user = User::factory()->create();

        $before = [CustomerAccount::count(), Wallet::count(), Order::count()];
        $this->dir()->profile($user);
        $this->dir()->query()->get();
        $this->dir()->summary();

        $this->assertSame($before, [CustomerAccount::count(), Wallet::count(), Order::count()]);
    }

    #[Test]
    public function a_removed_membership_is_flagged_and_a_store_with_only_a_wallet_still_shows(): void
    {
        $r1 = Reseller::factory()->create();
        $r2 = Reseller::factory()->create();
        $user = User::factory()->create();

        $this->member($user, $r1)->delete();
        $this->wallet($user, 1234, $r2);

        $p = $this->dir()->profile($user);
        $byKey = $p->stores->keyBy('scopeKey');

        $this->assertSame('removed', $byKey['reseller:'.$r1->id]->membershipStatus);
        $this->assertNull($byKey['reseller:'.$r2->id]->membershipStatus);
        $this->assertSame(1234, $byKey['reseller:'.$r2->id]->walletBalance);
        $this->assertTrue($byKey['main']->isMain());
    }

    #[Test]
    public function the_profile_lists_identity_referral_and_reseller_ownership(): void
    {
        $referrer = User::factory()->create();
        $reseller = Reseller::factory()->create();
        $owner = $reseller->user;
        $user = User::factory()->create(['referrer_id' => $referrer->id, 'telegram_id' => 4242]);
        User::factory()->create(['referrer_id' => $user->id]);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'gg', 'provider_email' => 'me@x.test']);

        $p = $this->dir()->profile($user);

        $this->assertTrue($p->hasUnifiedIdentity());
        $this->assertSame('me@x.test', $p->google->provider_email);
        $this->assertSame($referrer->id, $p->referrer->id);
        $this->assertSame(1, $p->referredCount);
        $this->assertNull($p->ownedReseller);
        $this->assertSame($reseller->id, $this->dir()->profile($owner)->ownedReseller->id);
    }

    #[Test]
    public function the_profile_excludes_test_orders_and_services_and_counts_open_tickets(): void
    {
        $user = User::factory()->create();
        $this->order($user, ['sales_channel' => 'test_account', 'main_price' => 0]);
        $this->service($user, $this->member($user), ['is_test' => true]);
        Ticket::factory()->create(['user_id' => $user->id, 'status' => 'open']);
        Ticket::factory()->create(['user_id' => $user->id, 'status' => 'answered']);

        $p = $this->dir()->profile($user);

        // تنها سفارش قطعی همان است که helper سرویس ساخته؛ سفارش اکانت تست نیامده
        $this->assertSame(1, $p->orders);
        $this->assertCount(1, $p->recentOrders);
        $this->assertCount(0, $p->services);
        $this->assertSame(1, $p->openTickets);
        $this->assertCount(2, $p->recentTickets);
    }

    #[Test]
    public function a_lapsed_service_is_counted_in_the_profile(): void
    {
        $user = User::factory()->create();
        $m = $this->member($user);
        $this->service($user, $m, ['status' => 'expired']);
        $this->service($user, $m, ['expires_at' => now()->subDay()]);
        $this->service($user, $m, ['expires_at' => now()->addDays(9)]);

        $p = $this->dir()->profile($user);

        $this->assertSame(2, $p->lapsedServices);
        $this->assertSame(1, $p->activeServices);
    }

    /* ----------------------------- کارایی ----------------------------- */

    private function queries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    #[Test]
    public function the_list_query_count_does_not_depend_on_the_number_of_customers(): void
    {
        $make = function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                $u = User::factory()->create();
                $m = $this->member($u);
                $this->service($u, $m);
                $this->wallet($u, 100);
            }
        };

        $make(2);
        $few = $this->queries(fn () => $this->dir()->query()->get());
        $make(8);
        $many = $this->queries(fn () => $this->dir()->query()->get());

        $this->assertSame($few, $many);
        $this->assertSame(1, $many);
    }

    #[Test]
    public function the_summary_and_profile_query_counts_do_not_grow_with_the_data(): void
    {
        $u = User::factory()->create();
        $m = $this->member($u);
        $r = Reseller::factory()->create();
        $this->member($u, $r);
        $this->wallet($u, 10, $r);
        for ($i = 0; $i < 3; $i++) {
            $this->service($u, $m);
        }
        $medium = $this->queries(fn () => $this->dir()->profile($u));

        for ($i = 0; $i < 9; $i++) {
            $this->service($u, $m);
            Ticket::factory()->create(['user_id' => $u->id]);
        }
        $r2 = Reseller::factory()->create();
        $this->member($u, $r2);
        $this->wallet($u, 5, $r2);
        $large = $this->queries(fn () => $this->dir()->profile($u));

        $this->assertSame($medium, $large);

        $s1 = $this->queries(fn () => $this->dir()->summary(now()));
        for ($i = 0; $i < 5; $i++) {
            $this->member(User::factory()->create());
        }
        $this->assertSame($s1, $this->queries(fn () => $this->dir()->summary(now())));
    }

    /* ----------------------------- مرز Core/Channel ----------------------------- */

    #[Test]
    public function the_core_is_read_only_and_knows_nothing_about_filament_or_formatting(): void
    {
        foreach (glob(app_path('Services/Admin/Customers/*.php')) as $file) {
            $src = file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/->(create|update|delete|save|forceDelete|increment|decrement|insert|upsert)\s*\(/', $src, basename($file));
            $this->assertDoesNotMatchRegularExpression('/(::create|firstOrCreate|updateOrCreate|DB::table)\b/', $src, basename($file));
            $this->assertStringNotContainsString('Filament', $src, basename($file));
            $this->assertStringNotContainsString('Money::', $src, basename($file));
            $this->assertStringNotContainsString('route(', $src, basename($file));
        }
    }

    #[Test]
    public function the_profile_does_not_expose_secrets(): void
    {
        $user = User::factory()->create();
        $this->service($user, $this->member($user));

        $p = $this->dir()->profile($user);
        $dump = json_encode($p->services->toArray());

        // config_data/subscription_url در Account مخفی نیستند؛ Core آن‌ها را برای نمایش نمی‌دهد (Channel هم نمی‌خواند)
        $this->assertNotNull($dump);
        $this->assertStringNotContainsString('config_data', file_get_contents(app_path('Services/Admin/Customers/GlobalCustomerDirectory.php')));
        $this->assertStringNotContainsString('failure_reason', file_get_contents(app_path('Services/Admin/Customers/GlobalCustomerDirectory.php')));
    }
}
