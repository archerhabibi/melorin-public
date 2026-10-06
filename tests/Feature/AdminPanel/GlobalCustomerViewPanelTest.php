<?php

namespace Tests\Feature\AdminPanel;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ResellerResource;
use App\Filament\Resources\TicketResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\Widgets\GlobalCustomerSummary;
use App\Models\Account;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\Customers\GlobalCustomerDirectory;
use App\Services\Admin\Customers\GlobalCustomerSegment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** B7.2 — لایه‌ی نمایش نمای سراسری مشتری (Filament). منطق در GlobalCustomerDirectoryTest تست شده است. */
class GlobalCustomerViewPanelTest extends TestCase
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

    private function member(User $user, ?Reseller $reseller = null): CustomerAccount
    {
        return CustomerAccount::create([
            'user_id' => $user->id,
            'store_type' => $reseller ? 'reseller' : 'main',
            'reseller_id' => $reseller?->id,
            'status' => 'active',
        ]);
    }

    private function rich(): array
    {
        $reseller = Reseller::factory()->create(['slug' => 'parismobile']);
        $user = User::factory()->create([
            'full_name' => 'سارا احمدی', 'email' => 'sara@example.test', 'email_verified_at' => now(),
            'phone' => '09120000000', 'telegram_id' => 9001,
        ]);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-sara', 'provider_email' => 'sara@gmail.test']);
        $this->member($user);
        $resM = $this->member($user, $reseller);
        Wallet::create(['user_id' => $user->id, 'store_type' => 'main', 'balance' => 25000]);
        Wallet::create(['user_id' => $user->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 7000]);

        $order = Order::factory()->create([
            'user_id' => $user->id, 'reseller_id' => $reseller->id, 'customer_account_id' => $resM->id,
            'sales_channel' => 'reseller_bot', 'main_price' => null, 'reseller_price' => 60000, 'customers_price' => 90000,
            'status' => 'account_created',
        ]);
        Account::factory()->create([
            'user_id' => $user->id, 'order_id' => $order->id, 'customer_account_id' => $resM->id,
            'config_data' => json_encode(['secret' => 'VLESS-SECRET-LINK']),
            'subscription_url' => 'https://sub.example.test/SECRET-SUB-TOKEN',
        ]);
        Ticket::factory()->create(['user_id' => $user->id, 'subject' => 'مشکل اتصال', 'status' => 'open']);

        return [$user, $reseller];
    }

    /* ------------------------------ فهرست ------------------------------ */

    #[Test]
    public function the_list_page_renders_over_http_with_the_new_columns_and_summary(): void
    {
        [$user] = $this->rich();
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin, 'admin')->get(UserResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('سارا احمدی');
        $response->assertSee('مجموع خرید');   // ستون‌های جدید (ویجت خلاصه Lazy است و جدا تست می‌شود)
        $response->assertSee('بخش مشتریان');
    }

    #[Test]
    public function the_list_is_not_reachable_without_an_admin_session(): void
    {
        $this->get('/admin/users')->assertRedirect();
    }

    #[Test]
    public function the_list_shows_every_user_and_survives_sorting_on_every_computed_column(): void
    {
        $this->rich();
        User::factory()->count(3)->create();
        $this->actingAsAdmin();

        $columns = ['stores_count', 'active_services_count', 'next_expiry_at', 'main_balance', 'settled_orders_count',
            'total_spent', 'last_order_at', 'open_tickets_count', 'created_at'];

        foreach ($columns as $column) {
            foreach (['asc', 'desc'] as $dir) {
                Livewire::test(ListUsers::class)
                    ->sortTable($column, $dir)
                    ->assertSuccessful();
            }
        }

        Livewire::test(ListUsers::class)->assertCountTableRecords(User::count());
    }

    #[Test]
    public function search_finds_users_by_name_email_phone_and_telegram(): void
    {
        [$user] = $this->rich();
        $other = User::factory()->create(['full_name' => 'دیگری', 'email' => 'x@y.test', 'phone' => '09350000000']);
        $this->actingAsAdmin();

        foreach (['سارا', 'sara@example', '0912000', '9001'] as $term) {
            Livewire::test(ListUsers::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$user])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    #[Test]
    public function the_segment_store_and_identity_filters_work_from_the_ui(): void
    {
        [$user, $reseller] = $this->rich();
        $plain = User::factory()->create(['telegram_id' => null]);
        $this->actingAsAdmin();

        Livewire::test(ListUsers::class)
            ->filterTable('segment', 'multi_store')
            ->assertCanSeeTableRecords([$user])
            ->assertCanNotSeeTableRecords([$plain])
            ->resetTableFilters()
            ->filterTable('segment', 'open_ticket')
            ->assertCanSeeTableRecords([$user])
            ->assertCanNotSeeTableRecords([$plain])
            ->resetTableFilters()
            ->filterTable('store', 'reseller:'.$reseller->id)
            ->assertCanSeeTableRecords([$user])
            ->assertCanNotSeeTableRecords([$plain])
            ->resetTableFilters()
            ->filterTable('identity', 'google_and_telegram')
            ->assertCanSeeTableRecords([$user])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    #[Test]
    public function a_tampered_filter_value_is_ignored_not_an_error(): void
    {
        $user = User::factory()->create();
        $this->actingAsAdmin();

        $this->get('/admin/users?tableFilters[segment][value]=%27%3B%20drop&tableFilters[store][value]=reseller:1%27%20or%201=1&tableFilters[identity][value]=zzz')
            ->assertOk();

        Livewire::test(ListUsers::class)
            ->set('tableFilters.segment.value', "x'; drop table users")
            ->set('tableFilters.identity.value', 'nope')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$user]);
    }

    #[Test]
    public function the_existing_role_filter_and_row_actions_still_exist(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->assertTableActionExists('view')
            ->assertTableActionExists('edit')
            ->assertTableActionExists('adjustBalance')
            ->assertTableFilterExists('role')
            ->assertTableFilterExists('status')
            ->assertCanSeeTableRecords([$user]);
    }

    #[Test]
    public function the_summary_widget_renders(): void
    {
        $this->rich();
        $this->actingAsAdmin();

        Livewire::test(GlobalCustomerSummary::class)
            ->assertSee('کاربران')
            ->assertSee('عضو چند فروشگاه')
            ->assertSee('تیکت باز');
    }

    #[Test]
    public function the_summary_widget_is_registered_on_the_resource_and_the_list_page_only(): void
    {
        $this->assertContains(GlobalCustomerSummary::class, UserResource::getWidgets());

        $widgets = Filament::getPanel('admin')->getWidgets();
        $this->assertNotContains(GlobalCustomerSummary::class, $widgets);
    }

    /* ------------------------------ پرونده ------------------------------ */

    #[Test]
    public function the_profile_page_renders_over_http_with_every_section(): void
    {
        [$user] = $this->rich();
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin, 'admin')->get(UserResource::getUrl('view', ['record' => $user]));

        $response->assertOk();
        $response->assertSee('سارا احمدی');
        $response->assertSee('فروشگاه اصلی');
        $response->assertSee('نماینده: parismobile');
        $response->assertSee('sara@gmail.test');
        $response->assertSee('مشکل اتصال');
        $response->assertSee('هویت یکپارچه');
    }

    #[Test]
    public function the_profile_renders_for_a_brand_new_user_with_nothing(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin, 'admin')->get(UserResource::getUrl('view', ['record' => $user]));

        $response->assertOk();
        $response->assertSee('این کاربر هنوز سرویسی ندارد.');
        $response->assertSee('سفارشی ثبت نشده است.');
    }

    #[Test]
    public function the_profile_never_leaks_service_secrets_or_internal_failure_reasons(): void
    {
        [$user] = $this->rich();
        Order::factory()->create([
            'user_id' => $user->id, 'status' => 'provision_failed', 'failure_reason' => 'INTERNAL-PANEL-TRACE-XYZ',
        ]);
        $admin = $this->actingAsAdmin();

        $html = $this->actingAs($admin, 'admin')->get(UserResource::getUrl('view', ['record' => $user]))->assertOk()->getContent();

        $this->assertStringNotContainsString('VLESS-SECRET-LINK', $html);
        $this->assertStringNotContainsString('SECRET-SUB-TOKEN', $html);
        $this->assertStringNotContainsString('INTERNAL-PANEL-TRACE-XYZ', $html);
    }

    #[Test]
    public function viewing_a_profile_and_the_list_writes_nothing(): void
    {
        [$user] = $this->rich();
        $stranger = User::factory()->create();
        $admin = $this->actingAsAdmin();

        $count = fn () => [
            CustomerAccount::count(), Wallet::count(), Order::count(), Payment::count(),
            AuditLog::count(), WalletTransaction::count(),
        ];
        $before = $count();

        $this->actingAs($admin, 'admin')->get(UserResource::getUrl('view', ['record' => $stranger]))->assertOk();
        $this->actingAs($admin, 'admin')->get(UserResource::getUrl('view', ['record' => $user]))->assertOk();
        $this->actingAs($admin, 'admin')->get(UserResource::getUrl('index'))->assertOk();

        $this->assertSame($before, $count());
    }

    #[Test]
    public function the_profile_shows_pending_payments_with_store_and_purpose(): void
    {
        [$user, $reseller] = $this->rich();
        $method = PaymentMethod::create(['name' => 'کارت', 'type' => 'card_to_card', 'status' => 'active']);
        Payment::create([
            'user_id' => $user->id, 'payment_method_id' => $method->id, 'amount' => 12000,
            'purpose' => 'wallet_charge', 'status' => 'pending', 'reseller_id' => $reseller->id,
        ]);
        $admin = $this->actingAsAdmin();

        $this->actingAs($admin, 'admin')->get(UserResource::getUrl('view', ['record' => $user]))
            ->assertOk()
            ->assertSee('شارژ کیف‌پول')
            ->assertSee('در انتظار');
    }

    #[Test]
    public function a_soft_deleted_user_has_no_profile(): void
    {
        $user = User::factory()->create();
        $user->delete();
        $admin = $this->actingAsAdmin();

        $this->actingAs($admin, 'admin')->get(UserResource::getUrl('view', ['record' => $user->id]))->assertNotFound();
    }

    #[Test]
    public function the_view_page_component_renders_for_a_user(): void
    {
        [$user] = $this->rich();
        $this->actingAsAdmin();

        Livewire::test(ViewUser::class, ['record' => $user->id])->assertSuccessful();
    }

    #[Test]
    public function the_edit_page_still_loads_and_saving_does_not_write_computed_columns(): void
    {
        [$user] = $this->rich();
        $this->actingAsAdmin();

        Livewire::test(UserResource\Pages\EditUser::class, ['record' => $user->id])
            ->fillForm(['full_name' => 'سارای جدید', 'status' => 'disabled'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $user->fresh();
        $this->assertSame('سارای جدید', $fresh->full_name);
        $this->assertSame('disabled', $fresh->status);
    }

    #[Test]
    public function global_search_still_finds_users_without_running_the_list_subqueries(): void
    {
        [$user] = $this->rich();

        $results = UserResource::getGlobalSearchResults('sara@example');

        $this->assertCount(1, $results);
        $this->assertSame('سارا احمدی', (string) $results->first()->title);
    }

    #[Test]
    public function the_balance_adjust_action_is_unchanged_and_audited_by_wallet_service(): void
    {
        $user = User::factory()->create();
        $this->actingAsAdmin();

        Livewire::test(ListUsers::class)
            ->callTableAction('adjustBalance', $user, data: ['amount' => 5000, 'description' => 'تست B7.2'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(5000, (int) Wallet::where('user_id', $user->id)->where('scope_key', 'main')->value('balance'));
    }

    /* ------------------- بهبودهای B7.2: لینک پیمایشی، تراکنش کیف‌پول، تب‌ها ------------------- */

    #[Test]
    public function the_profile_links_to_orders_payments_tickets_resellers_and_the_referrer(): void
    {
        [$user, $reseller] = $this->rich();
        $referrer = User::factory()->create(['full_name' => 'معرف نمونه']);
        $user->update(['referrer_id' => $referrer->id]);
        $order = Order::where('user_id', $user->id)->first();
        $method = PaymentMethod::create(['name' => 'کارت', 'type' => 'card_to_card', 'status' => 'active']);
        $payment = Payment::create([
            'user_id' => $user->id, 'payment_method_id' => $method->id, 'amount' => 12000,
            'purpose' => 'wallet_charge', 'status' => 'pending', 'reseller_id' => $reseller->id,
        ]);
        $ticket = Ticket::where('user_id', $user->id)->first();
        $this->actingAsAdmin();

        $html = $this->get(UserResource::getUrl('view', ['record' => $user]))->assertOk()->getContent();

        $this->assertStringContainsString(OrderResource::getUrl('view', ['record' => $order->id]), $html);
        $this->assertStringContainsString(PaymentResource::getUrl('view', ['record' => $payment->id]), $html);
        $this->assertStringContainsString(TicketResource::getUrl('view', ['record' => $ticket->id]), $html);
        $this->assertStringContainsString(ResellerResource::getUrl('edit', ['record' => $reseller->id]), $html);
        $this->assertStringContainsString(UserResource::getUrl('view', ['record' => $referrer->id]), $html);
    }

    #[Test]
    public function the_main_store_has_no_reseller_link(): void
    {
        $user = User::factory()->create();
        $this->member($user);
        $this->actingAsAdmin();

        $html = $this->get(UserResource::getUrl('view', ['record' => $user]))->assertOk()->getContent();

        $this->assertStringNotContainsString('/admin/resellers/', $html);
    }

    #[Test]
    public function the_profile_shows_wallet_transactions_per_store_without_referral_bonus_text(): void
    {
        [$user, $reseller] = $this->rich();
        $main = Wallet::where('user_id', $user->id)->where('scope_key', 'main')->first();
        $res = Wallet::where('user_id', $user->id)->where('scope_key', 'reseller:'.$reseller->id)->first();
        WalletTransaction::create(['wallet_id' => $main->id, 'type' => 'charge', 'amount' => 25000, 'balance_after' => 25000, 'description' => 'شارژ آزمایشی']);
        WalletTransaction::create(['wallet_id' => $res->id, 'type' => 'purchase', 'amount' => -3000, 'balance_after' => 7000]);
        WalletTransaction::create([
            'wallet_id' => $main->id, 'type' => 'referral_bonus', 'amount' => 100, 'balance_after' => 25100,
            'description' => 'پاداش دعوت: عضویت نام-شخص-دیگر',
        ]);
        $this->actingAsAdmin();

        $html = $this->get(UserResource::getUrl('view', ['record' => $user]))->assertOk()->getContent();

        $this->assertStringContainsString('آخرین تراکنش‌های کیف‌پول', $html);
        $this->assertStringContainsString('شارژ کیف‌پول', $html);
        $this->assertStringContainsString('شارژ آزمایشی', $html);
        $this->assertStringContainsString('پاداش معرفی', $html);
        $this->assertStringNotContainsString('نام-شخص-دیگر', $html);
    }

    #[Test]
    public function the_profile_of_a_user_without_wallet_transactions_shows_an_empty_message(): void
    {
        $user = User::factory()->create();
        $this->actingAsAdmin();

        $this->get(UserResource::getUrl('view', ['record' => $user]))
            ->assertOk()
            ->assertSee('تراکنشی در کیف‌پول‌ها ثبت نشده است.');
    }

    #[Test]
    public function the_list_has_a_tab_per_segment_with_counts_from_the_core(): void
    {
        $this->rich();
        User::factory()->count(2)->create();
        $this->actingAsAdmin();

        $component = Livewire::test(ListUsers::class);
        $tabs = $component->instance()->getTabs();

        $this->assertSame(
            array_merge(['all'], array_map(fn ($c) => $c->value, GlobalCustomerSegment::cases())),
            array_keys($tabs)
        );
        $this->assertSame(number_format(User::count()), $tabs['all']->getBadge());
        $this->assertSame(
            number_format(app(GlobalCustomerDirectory::class)->summary()->countFor(GlobalCustomerSegment::NeverBought)),
            $tabs['never_bought']->getBadge()
        );
    }

    #[Test]
    public function selecting_a_tab_filters_the_table_with_the_segment_definition(): void
    {
        [$buyer] = $this->rich();
        $never = User::factory()->create(['full_name' => 'بدون خرید']);
        $this->actingAsAdmin();

        $ids = Livewire::test(ListUsers::class)
            ->set('activeTab', 'never_bought')
            ->assertCanSeeTableRecords([$never])
            ->assertCanNotSeeTableRecords([$buyer])
            ->instance()->getTableRecords()->pluck('id')->all();

        $this->assertContains($never->id, $ids);
        $this->assertNotContains($buyer->id, $ids);
    }

    #[Test]
    public function the_all_tab_and_an_unknown_tab_show_everyone(): void
    {
        [$buyer] = $this->rich();
        $never = User::factory()->create();
        $this->actingAsAdmin();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$buyer, $never]);

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'definitely-not-a-tab')
            ->assertSuccessful();
    }
}
