<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Reseller\Resources\CustomerResource;
use App\Filament\Reseller\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Reseller\Resources\CustomerResource\Widgets\CustomerSummary;
use App\Filament\Reseller\Widgets\AttentionAlerts;
use App\Models\Account;
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
 * B5.2 — لایه‌ی نمایش مدیریت مشتریان نماینده. منطق در ResellerCustomerDirectoryTest تست شده؛
 * این‌جا: رندر واقعی، جداسازی، فیلتر/مرتب‌سازی/جست‌وجو، صفحه‌ی پرونده و لینک داشبورد.
 */
class ResellerCustomerPanelTest extends TestCase
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

    private function sale(Reseller $reseller, User $customer, int $price = 1000, string $status = 'account_created'): Order
    {
        return Order::factory()->create([
            'user_id' => $customer->id,
            'customer_account_id' => $this->accountIn($customer, $reseller)->id,
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => (int) ($price / 2),
            'customers_price' => $price,
            'status' => $status,
        ]);
    }

    private function service(Reseller $reseller, User $customer, int $days): Account
    {
        return Account::factory()->create([
            'user_id' => $customer->id,
            'customer_account_id' => $this->accountIn($customer, $reseller)->id,
            'expires_at' => now()->addDays($days),
        ]);
    }

    #[Test]
    public function the_list_and_a_customer_profile_render_over_http(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $customer = $this->memberOf($reseller, ['full_name' => 'علی رضایی', 'email' => 'ali@example.test']);
        $this->sale($reseller, $customer, 123000);
        $this->service($reseller, $customer, 20);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/customers')
            ->assertOk()
            ->assertSee('علی رضایی')
            ->assertSee('123,000');

        $this->actingAs($owner, 'reseller')->get('/arial/customers/'.$customer->id)
            ->assertOk()
            ->assertSee('علی رضایی')
            ->assertSee('ali@example.test')
            ->assertSee('123,000');
    }

    #[Test]
    public function another_resellers_customer_profile_is_not_found(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $other = Reseller::factory()->create(['slug' => 'bravo']);
        $stranger = $this->memberOf($other, ['full_name' => 'غریبه']);
        $mainOnly = User::factory()->create();
        $this->accountIn($mainOnly, null);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/customers/'.$stranger->id)->assertNotFound();
        $this->actingAs($owner, 'reseller')->get('/arial/customers/'.$mainOnly->id)->assertNotFound();
    }

    #[Test]
    public function the_list_shows_only_this_stores_numbers_for_a_shared_customer(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $shared = $this->memberOf($reseller);
        $this->accountIn($shared, $other);

        $this->sale($reseller, $shared, 1111);
        $this->sale($other, $shared, 9999999);
        $this->actingAsReseller($reseller);

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$shared])
            ->assertSee('1,111')
            ->assertDontSee('9,999,999');
    }

    #[Test]
    public function every_sortable_column_sorts_without_error(): void
    {
        $reseller = Reseller::factory()->create();
        $first = $this->memberOf($reseller);
        $second = $this->memberOf($reseller);
        $this->sale($reseller, $first, 1000);
        $this->sale($reseller, $second, 5000);
        $this->service($reseller, $second, 3);
        Wallet::query()->create(['user_id' => $first->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 800]);
        $this->actingAsReseller($reseller);

        foreach (['membership_status', 'active_services_count', 'next_expiry_at', 'wallet_balance', 'orders_count', 'total_spent', 'last_order_at', 'joined_at'] as $column) {
            Livewire::test(ListCustomers::class)->sortTable($column)->assertOk();
        }

        Livewire::test(ListCustomers::class)
            ->sortTable('total_spent', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true);

        Livewire::test(ListCustomers::class)
            ->sortTable('wallet_balance', 'desc')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true);
    }

    #[Test]
    public function search_finds_by_name_email_phone_and_telegram_within_this_store_only(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $byName = $this->memberOf($reseller, ['full_name' => 'سارا احمدی']);
        $byMail = $this->memberOf($reseller, ['email' => 'findme@example.test']);
        $byTelegram = $this->memberOf($reseller, ['telegram_id' => '555123']);
        $stranger = $this->memberOf($other, ['full_name' => 'سارا احمدی', 'email' => 'findme-other@example.test']);
        $this->actingAsReseller($reseller);

        Livewire::test(ListCustomers::class)->searchTable('سارا')
            ->assertCanSeeTableRecords([$byName])->assertCanNotSeeTableRecords([$byMail, $stranger]);
        Livewire::test(ListCustomers::class)->searchTable('findme@example')
            ->assertCanSeeTableRecords([$byMail])->assertCanNotSeeTableRecords([$byName, $stranger]);
        Livewire::test(ListCustomers::class)->searchTable('555123')
            ->assertCanSeeTableRecords([$byTelegram])->assertCanNotSeeTableRecords([$byName]);
    }

    #[Test]
    public function segment_and_membership_filters_work_and_ignore_garbage(): void
    {
        $reseller = Reseller::factory()->create();
        $expiring = $this->memberOf($reseller);
        $calm = $this->memberOf($reseller);
        $blocked = $this->memberOf($reseller);
        $this->accountIn($blocked, $reseller)->update(['status' => 'blocked']);
        $this->service($reseller, $expiring, 2);
        $this->service($reseller, $calm, 90);
        $this->actingAsReseller($reseller);

        Livewire::test(ListCustomers::class)
            ->filterTable('segment', 'expiring')
            ->assertCanSeeTableRecords([$expiring])
            ->assertCanNotSeeTableRecords([$calm, $blocked]);

        Livewire::test(ListCustomers::class)
            ->filterTable('segment', 'never_bought')
            ->assertCanSeeTableRecords([$expiring, $calm, $blocked]);

        Livewire::test(ListCustomers::class)
            ->filterTable('membership', 'blocked')
            ->assertCanSeeTableRecords([$blocked])
            ->assertCanNotSeeTableRecords([$expiring, $calm]);

        Livewire::test(ListCustomers::class, [])
            ->set('tableFilters.segment.value', "x' OR 1=1 --")
            ->assertOk()
            ->assertCanSeeTableRecords([$expiring, $calm, $blocked]);
    }

    #[Test]
    public function an_expiring_services_url_from_the_dashboard_opens_the_filtered_list(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $near = $this->memberOf($reseller, ['full_name' => 'نزدیک انقضا']);
        $far = $this->memberOf($reseller, ['full_name' => 'دور از انقضا']);
        $this->service($reseller, $near, 2);
        $this->service($reseller, $far, 90);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/customers?tableFilters[segment][value]=expiring')
            ->assertOk()
            ->assertSee('نزدیک انقضا')
            ->assertDontSee('دور از انقضا');
    }

    #[Test]
    public function the_dashboard_expiring_alert_now_links_to_the_customers_filtered_by_that_segment(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $this->service($reseller, $customer, 2);
        $this->actingAsReseller($reseller);

        $url = CustomerResource::getUrl('index', ['tableFilters' => ['segment' => ['value' => 'expiring']]]);

        Livewire::test(AttentionAlerts::class)
            ->assertSeeHtml('data-alert="expiring_services"')
            ->assertSeeHtml(e($url));
    }

    #[Test]
    public function the_summary_widget_shows_this_stores_numbers_and_stays_off_the_dashboard(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $a = $this->memberOf($reseller);
        $this->memberOf($reseller);
        $this->memberOf($other);
        Wallet::query()->create(['user_id' => $a->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 424242]);
        Wallet::query()->create(['user_id' => $this->memberOf($other)->id, 'store_type' => 'reseller', 'reseller_id' => $other->id, 'balance' => 777777]);
        $this->actingAsReseller($reseller);

        Livewire::test(CustomerSummary::class)
            ->assertSee('424,242')
            ->assertDontSee('777,777');

        $this->assertNotContains(CustomerSummary::class, Filament::getPanel('reseller')->getWidgets());
    }

    #[Test]
    public function opening_the_list_and_a_profile_creates_no_wallet(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $customer = $this->memberOf($reseller);
        $owner = $this->actingAsReseller($reseller)->fresh();
        $before = Wallet::query()->count();

        $this->actingAs($owner, 'reseller')->get('/arial/customers')->assertOk();
        $this->actingAs($owner, 'reseller')->get('/arial/customers/'.$customer->id)->assertOk();

        $this->assertSame($before, Wallet::query()->count());
    }

    #[Test]
    public function the_resource_is_read_only(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $this->actingAsReseller($reseller);

        $this->assertFalse(CustomerResource::canCreate());
        $this->assertFalse(CustomerResource::canEdit($customer));
        $this->assertFalse(CustomerResource::canDelete($customer));
        $this->assertSame(['index', 'view'], array_keys(CustomerResource::getPages()));
    }

    #[Test]
    public function an_empty_store_shows_a_friendly_empty_state(): void
    {
        $this->actingAsReseller(Reseller::factory()->create());

        Livewire::test(ListCustomers::class)->assertOk()->assertSee('هنوز مشتری‌ای ندارید');
    }
}
