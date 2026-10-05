<?php

namespace Tests\Feature\Website;

use App\Models\Account;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Customer\OrderTrackingService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * B4.5 — Order Tracking. وضعیت واقعی را Purchase/Provisioning می‌نویسد؛ این فاز فقط «روایت» آن است:
 * مراحل و متن از Core (OrderTrackingService)، فیلتر گروهی فهرست، صفحه‌ی پیگیری با لینک سرویس/پشتیبانی.
 */
class OrderTrackingTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function customer(?Reseller $reseller = null): array
    {
        $user = User::factory()->create(['telegram_id' => null, 'email_verified_at' => now()]);
        $ctx = $reseller ? StoreContext::reseller($reseller) : StoreContext::main();

        return [$user, app(IdentityService::class)->resolveCustomerAccount($user, $ctx)];
    }

    protected function order(User $user, $customer, string $status, array $extra = []): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'customer_account_id' => $customer->id,
            'product_id' => Product::factory()->create(['name' => 'پلن طلایی'])->id,
            'main_price' => 150000,
            'status' => $status,
        ] + $extra);
    }

    protected function service(User $user, $customer, Order $order): Account
    {
        return Account::factory()->create([
            'user_id' => $user->id,
            'customer_account_id' => $customer->id,
            'order_id' => $order->id,
            'product_id' => $order->product_id,
        ]);
    }

    // ─── Core: روایت وضعیت ────────────────────────────────────────

    public static function statusProvider(): array
    {
        return [
            // status => [stateهای چهار مرحله، لحن، inProgress، needsSupport، مرحله‌ی جاری]
            'pending' => [Order::STATUS_PENDING, ['done', 'current', 'upcoming', 'upcoming'], 'info', false, false, 2],
            'paid' => [Order::STATUS_PAID, ['done', 'done', 'current', 'upcoming'], 'info', true, false, 3],
            'provisioning' => [Order::STATUS_PROVISIONING, ['done', 'done', 'current', 'upcoming'], 'info', true, false, 3],
            'delivered' => [Order::STATUS_ACCOUNT_CREATED, ['done', 'done', 'done', 'done'], 'success', false, false, 4],
            'provision_failed' => [Order::STATUS_PROVISION_FAILED, ['done', 'done', 'failed', 'upcoming'], 'danger', false, true, 3],
            'failed' => [Order::STATUS_FAILED, ['done', 'failed', 'upcoming', 'upcoming'], 'danger', false, false, 2],
            'refunded' => [Order::STATUS_REFUNDED, ['done', 'done', 'upcoming', 'upcoming'], 'neutral', false, false, 3],
        ];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function every_status_maps_to_steps_tone_and_flags(string $status, array $states, string $tone, bool $inProgress, bool $support, int $current): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($user, $customer, $status);

        $t = app(OrderTrackingService::class)->track($order);

        $this->assertSame($states, array_column($t->steps, 'state'));
        $this->assertSame($tone, $t->tone);
        $this->assertSame($inProgress, $t->inProgress);
        $this->assertSame($support, $t->needsSupport);
        $this->assertSame($current, $t->currentStep());
        $this->assertCount(4, $t->labels());
    }

    #[Test]
    public function a_stale_retry_timestamp_is_ignored_unless_the_order_is_actually_failed(): void
    {
        [$user, $customer] = $this->customer();
        $svc = app(OrderTrackingService::class);

        foreach ([Order::STATUS_PAID, Order::STATUS_PROVISIONING, Order::STATUS_ACCOUNT_CREATED, Order::STATUS_REFUNDED] as $status) {
            $t = $svc->track($this->order($user, $customer, $status, ['next_provision_retry_at' => now()->addHour()]));

            $this->assertFalse($t->autoRetry, $status);
            $this->assertNull($t->nextRetryAt, $status);
        }

        $failed = $svc->track($this->order($user, $customer, Order::STATUS_PROVISION_FAILED, ['next_provision_retry_at' => now()->addHour()]));
        $this->assertTrue($failed->autoRetry);
    }

    #[Test]
    public function an_unknown_status_degrades_to_a_neutral_message_instead_of_failing(): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($user, $customer, 'something_new');

        $t = app(OrderTrackingService::class)->track($order);

        $this->assertSame('neutral', $t->tone);
        $this->assertFalse($t->needsSupport);
    }

    #[Test]
    public function the_amount_follows_the_context_main_price_or_customers_price(): void
    {
        [$user, $customer] = $this->customer();
        $svc = app(OrderTrackingService::class);

        $main = $this->order($user, $customer, 'paid', ['main_price' => 150000]);
        $this->assertSame(150000, $svc->amount($main));

        $reseller = Reseller::factory()->create(['status' => 'active']);
        [$ru, $rc] = $this->customer($reseller);
        $resold = $this->order($ru, $rc, 'paid', ['reseller_id' => $reseller->id, 'main_price' => 150000, 'reseller_price' => 100000, 'customers_price' => 130000]);
        $this->assertSame(130000, $svc->amount($resold));
    }

    #[Test]
    public function group_filter_values_are_whitelisted_and_counts_are_correct_and_scoped(): void
    {
        [$user, $customer] = $this->customer();
        foreach (['pending', 'paid', 'provisioning', 'account_created', 'account_created', 'provision_failed', 'failed', 'refunded'] as $s) {
            $this->order($user, $customer, $s);
        }
        [$other, $otherCustomer] = $this->customer();
        $this->order($other, $otherCustomer, 'paid');

        $svc = app(OrderTrackingService::class);

        $this->assertNull($svc->group('bogus'));
        $this->assertNull($svc->group(['active']));
        $this->assertNull($svc->group(null));
        $this->assertSame('active', $svc->group('active'));

        $this->assertSame(
            ['total' => 8, 'active' => 3, 'delivered' => 2, 'attention' => 1, 'closed' => 2],
            $svc->counts($customer->id, null),
        );
        $this->assertSame(2, $svc->paginate($customer->id, null, 'delivered')->total());
        $this->assertSame(0, $svc->paginate($customer->id, 999, null)->total());
    }

    // ─── صفحه‌ی سفارش ─────────────────────────────────────────────

    #[Test]
    public function a_delivered_order_shows_steps_status_delivery_time_and_the_service_link(): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($user, $customer, Order::STATUS_ACCOUNT_CREATED);
        $service = $this->service($user, $customer, $order);

        $this->actingAs($user)->get(route('website.orders.show', $order->id))
            ->assertOk()
            ->assertSee('سرویس شما آماده است')
            ->assertSee('تحویل‌شده')
            ->assertSee('پلن طلایی')
            ->assertSee(Money::format(150000))
            ->assertSee('کیف‌پول')
            ->assertSee('زمان تحویل')
            ->assertSee('مشاهده‌ی سرویس و اتصال')
            ->assertSee(route('website.accounts.show', $service->id), false)
            ->assertDontSee('http-equiv="refresh"', false)
            ->assertDontSee('به‌زودی');
    }

    #[Test]
    public function an_in_progress_order_refreshes_itself_and_offers_a_manual_refresh(): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($user, $customer, Order::STATUS_PROVISIONING);

        $this->actingAs($user)->get(route('website.orders.show', $order->id))
            ->assertOk()
            ->assertSee('در حال ساخت سرویس')
            ->assertSee('<meta http-equiv="refresh" content="15">', false)
            ->assertSee('به‌روزرسانی وضعیت')
            ->assertDontSee('مشاهده‌ی سرویس و اتصال');
    }

    #[Test]
    public function a_pending_order_does_not_auto_refresh(): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($user, $customer, Order::STATUS_PENDING);

        $this->actingAs($user)->get(route('website.orders.show', $order->id))
            ->assertOk()
            ->assertSee('در انتظار پرداخت')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    #[Test]
    public function a_failed_provisioning_guides_to_support_without_leaking_the_internal_reason(): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($user, $customer, Order::STATUS_PROVISION_FAILED, [
            'failure_reason' => 'PANEL_SECRET_ERROR_xyz connection refused 10.0.0.5',
            'next_provision_retry_at' => now()->addMinutes(20),
        ]);

        $this->actingAs($user)->get(route('website.orders.show', $order->id))
            ->assertOk()
            ->assertSee('نیازمند رسیدگی')
            ->assertSee('مبلغ از بین نمی‌رود')
            ->assertSee('ثبت تیکت برای این سفارش')
            ->assertSee(route('website.tickets.create'), false)
            ->assertSee('#'.$order->id)
            ->assertSee('سامانه به‌صورت خودکار دوباره تلاش می‌کند')
            ->assertSee('(ناموفق)')
            ->assertDontSee('PANEL_SECRET_ERROR_xyz')
            ->assertDontSee('10.0.0.5')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    #[Test]
    public function a_failed_provisioning_without_a_scheduled_retry_does_not_promise_one(): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($user, $customer, Order::STATUS_PROVISION_FAILED, ['next_provision_retry_at' => null]);

        $this->actingAs($user)->get(route('website.orders.show', $order->id))
            ->assertOk()
            ->assertDontSee('سامانه به‌صورت خودکار دوباره تلاش می‌کند');
    }

    #[Test]
    public function a_failed_payment_order_offers_no_support_button_and_no_service(): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($user, $customer, Order::STATUS_FAILED);

        $this->actingAs($user)->get(route('website.orders.show', $order->id))
            ->assertOk()
            ->assertSee('سفارش ناموفق')
            ->assertDontSee('ثبت تیکت برای این سفارش')
            ->assertDontSee('مشاهده‌ی سرویس و اتصال');
    }

    #[Test]
    public function a_renewal_order_is_labelled_and_links_to_the_renewed_service(): void
    {
        [$user, $customer] = $this->customer();
        $original = $this->order($user, $customer, Order::STATUS_ACCOUNT_CREATED);
        $service = $this->service($user, $customer, $original);
        $renewal = $this->order($user, $customer, Order::STATUS_ACCOUNT_CREATED, ['renews_account_id' => $service->id]);

        $this->actingAs($user)->get(route('website.orders.show', $renewal->id))
            ->assertOk()
            ->assertSee('تمدید انجام شد')
            ->assertSee('تمدید سرویس')
            ->assertSee('اعمال تمدید')
            ->assertSee(route('website.accounts.show', $service->id), false)
            ->assertDontSee('زمان تحویل');
    }

    #[Test]
    public function a_service_that_belongs_to_someone_else_is_never_linked(): void
    {
        [$user, $customer] = $this->customer();
        [$stranger, $strangerCustomer] = $this->customer();
        $order = $this->order($user, $customer, Order::STATUS_ACCOUNT_CREATED);
        $foreign = $this->service($stranger, $strangerCustomer, $order); // داده‌ی ناسازگار عمدی

        $this->actingAs($user)->get(route('website.orders.show', $order->id))
            ->assertOk()
            ->assertDontSee(route('website.accounts.show', $foreign->id), false)
            ->assertDontSee('مشاهده‌ی سرویس و اتصال');
    }

    #[Test]
    public function other_peoples_and_other_stores_orders_are_still_404(): void
    {
        [$owner, $ownerCustomer] = $this->customer();
        $order = $this->order($owner, $ownerCustomer, Order::STATUS_PAID);
        [$stranger] = $this->customer();

        $this->actingAs($stranger)->get(route('website.orders.show', $order->id))->assertNotFound();

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $this->actingAs($owner)->get(route('website.store.orders.show', [$reseller->slug, $order->id]))->assertNotFound();
    }

    #[Test]
    public function a_reseller_store_order_keeps_every_link_inside_the_store(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        [$user, $customer] = $this->customer($reseller);
        $order = $this->order($user, $customer, Order::STATUS_PROVISION_FAILED, ['reseller_id' => $reseller->id, 'customers_price' => 130000, 'reseller_price' => 90000]);

        $this->actingAs($user)->get(route('website.store.orders.show', [$reseller->slug, $order->id]))
            ->assertOk()
            ->assertSee(Money::format(130000))
            ->assertSee(route('website.store.tickets.create', $reseller->slug), false)
            ->assertSee(route('website.store.orders.index', $reseller->slug), false)
            ->assertDontSee(route('website.tickets.create'), false)
            ->assertDontSee(Money::format(90000));
    }

    // ─── فهرست ────────────────────────────────────────────────────

    #[Test]
    public function the_list_shows_group_chips_with_counts_status_badges_and_renewal_tags(): void
    {
        [$user, $customer] = $this->customer();
        $a = $this->order($user, $customer, Order::STATUS_ACCOUNT_CREATED);
        $this->order($user, $customer, Order::STATUS_PROVISION_FAILED);
        $this->order($user, $customer, Order::STATUS_PAID, ['renews_account_id' => $this->service($user, $customer, $a)->id]);

        $this->actingAs($user)->get(route('website.orders.index'))
            ->assertOk()
            ->assertSee('همه')
            ->assertSee('در جریان')
            ->assertSee('نیازمند رسیدگی')
            ->assertSee('(3)')
            ->assertSee('سرویس شما آماده است')
            ->assertSee('نیازمند رسیدگی')
            ->assertSee('تمدید')
            ->assertSee(route('website.orders.show', $a->id), false)
            ->assertSee(Money::format(150000));
    }

    #[Test]
    public function the_group_filter_narrows_the_list_and_an_invalid_group_shows_everything(): void
    {
        [$user, $customer] = $this->customer();
        $failed = $this->order($user, $customer, Order::STATUS_PROVISION_FAILED);
        $paid = $this->order($user, $customer, Order::STATUS_PAID);

        $this->actingAs($user)->get(route('website.orders.index', ['group' => 'attention']))
            ->assertOk()
            ->assertSee(route('website.orders.show', $failed->id), false)
            ->assertDontSee(route('website.orders.show', $paid->id), false)
            ->assertSee('aria-current="true"', false);

        $this->actingAs($user)->get(route('website.orders.index', ['group' => 'nonsense']))
            ->assertOk()
            ->assertSee(route('website.orders.show', $failed->id), false)
            ->assertSee(route('website.orders.show', $paid->id), false);

        $this->actingAs($user)->get(route('website.orders.index', ['group' => ['x']]))->assertOk();
    }

    #[Test]
    public function the_list_has_distinct_empty_states_and_the_filter_survives_pagination(): void
    {
        [$user, $customer] = $this->customer();

        $this->actingAs($user)->get(route('website.orders.index'))
            ->assertOk()
            ->assertSee('هنوز سفارشی ثبت نشده است');

        for ($i = 0; $i < 17; $i++) {
            $this->order($user, $customer, Order::STATUS_ACCOUNT_CREATED);
        }

        $this->actingAs($user)->get(route('website.orders.index', ['group' => 'attention']))
            ->assertOk()
            ->assertSee('سفارشی با این وضعیت ندارید');

        $html = $this->actingAs($user)->get(route('website.orders.index', ['group' => 'delivered']))
            ->assertOk()->getContent();

        // لینکِ صفحه‌ی دوم باید فیلتر را حفظ کند (نه فقط چیپ‌های بالای صفحه).
        preg_match_all('/href="([^"]*[?&;]page=2[^"]*)"/', $html, $m);
        $this->assertNotEmpty($m[1], 'لینک صفحه‌ی ۲ پیدا نشد');
        $this->assertStringContainsString('group=delivered', html_entity_decode($m[1][0]));
    }

    #[Test]
    public function the_list_is_context_isolated_and_the_query_count_does_not_grow_with_rows(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        [$user, $customer] = $this->customer();
        $rc = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::reseller($reseller));
        $foreign = $this->order($user, $rc, Order::STATUS_PAID, ['reseller_id' => $reseller->id]);

        $this->order($user, $customer, Order::STATUS_PAID);
        $count = function () use ($user) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get(route('website.orders.index'))->assertOk()
                ->assertDontSee(route('website.store.orders.show', [Reseller::query()->first()->slug, 1]), false);

            return count(DB::getQueryLog());
        };

        $few = $count();
        for ($i = 0; $i < 8; $i++) {
            $this->order($user, $customer, Order::STATUS_ACCOUNT_CREATED);
        }
        $many = $count();

        $this->assertSame($few, $many, 'فهرست سفارش‌ها N+1 دارد');
        $this->actingAs($user)->get(route('website.orders.index'))->assertDontSee('#'.$foreign->id.' ·', false);
    }

    // ─── کامپوننت مراحل ───────────────────────────────────────────

    #[Test]
    public function the_steps_component_marks_a_failed_step_without_relying_on_colour(): void
    {
        $html = \Illuminate\Support\Facades\Blade::render(
            '<x-ui.steps :items="[\'الف\', \'ب\', \'ج\']" :current="2" :failed="3" />'
        );

        $this->assertStringContainsString('(ناموفق)', $html);
        $this->assertStringContainsString('مرحله‌ی 3 از 3', $html);
        $this->assertStringContainsString('text-danger', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="step"'));

        // بدون failed، رفتار B4.3 تغییر نکرده.
        $plain = \Illuminate\Support\Facades\Blade::render('<x-ui.steps :items="[\'الف\', \'ب\']" :current="2" />');
        $this->assertStringNotContainsString('(ناموفق)', $plain);
        $this->assertStringContainsString('مرحله‌ی 2 از 2', $plain);
    }
}
