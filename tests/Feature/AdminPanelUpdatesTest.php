<?php

namespace Tests\Feature;

use App\Channels\TelegramBot\Handlers\MiscHandler;
use App\Filament\Pages\PurchaseRulesSettings;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Widgets\SalesChartWidget;
use App\Filament\Widgets\SalesOverviewWidget;
use App\Models\Admin;
use App\Models\BotContentSetting;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

class AdminPanelUpdatesTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /** @test */
    public function user_is_bot_admin_reflects_the_configured_telegram_admin_ids(): void
    {
        config(['telegram.admin_ids' => ['111', '222']]);

        $admin = User::factory()->create(['telegram_id' => 111]);
        $customer = User::factory()->create(['telegram_id' => 333]);

        $this->assertTrue($admin->isBotAdmin());
        $this->assertFalse($customer->isBotAdmin());
    }

    /** @test */
    public function admin_can_filter_the_user_list_by_bot_admin_role(): void
    {
        config(['telegram.admin_ids' => ['111']]);
        $this->actingAsAdmin();

        $botAdmin = User::factory()->create(['telegram_id' => 111]);
        $customer = User::factory()->create(['telegram_id' => 222]);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$botAdmin, $customer])
            ->filterTable('role', 'admin')
            ->assertCanSeeTableRecords([$botAdmin])
            ->assertCanNotSeeTableRecords([$customer])
            ->filterTable('role', 'customer')
            ->assertCanSeeTableRecords([$customer])
            ->assertCanNotSeeTableRecords([$botAdmin]);
    }

    /**
     * قبل از این، بعد از ارتقای یک کاربر به نماینده، در لیست کاربران
     * هم‌چنان با برچسب «مشتری» نمایش داده می‌شد — هیچ نشانه‌ای نبود که
     * این کاربر دیگر فقط یک مشتری عادی نیست.
     */
    /** @test */
    public function a_promoted_users_role_shows_as_reseller_not_customer(): void
    {
        $this->actingAsAdmin();

        $reseller = Reseller::factory()->create();
        $resellerOwner = $reseller->user;
        $plainCustomer = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$resellerOwner, $plainCustomer])
            ->filterTable('role', 'reseller')
            ->assertCanSeeTableRecords([$resellerOwner])
            ->assertCanNotSeeTableRecords([$plainCustomer]);
    }

    /** @test */
    public function purchase_rules_default_to_the_previous_hardcoded_text_until_an_admin_saves_something(): void
    {
        $this->fakeTelegram();

        $default = BotContentSetting::current()->purchaseRulesText();
        $this->assertStringContainsString('بازگشت وجه', $default);

        app(MiscHandler::class); // فقط اطمینان از این‌که resolve بدون خطا انجام می‌شود
    }

    /** @test */
    public function admin_can_edit_the_purchase_rules_text_from_the_panel(): void
    {
        $this->actingAsAdmin();

        Livewire::test(PurchaseRulesSettings::class)
            ->fillForm(['purchase_rules_text' => 'متن جدید قوانین خرید — تست.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('متن جدید قوانین خرید — تست.', BotContentSetting::current()->purchaseRulesText());
    }

    /** @test */
    public function dashboard_widgets_render_successfully_with_seeded_orders(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        Order::factory()->for($user)->create([
            'status' => 'paid',
            'sold_price' => 50000,
            'created_at' => today(),
        ]);

        Livewire::test(SalesOverviewWidget::class)->assertSuccessful();
        Livewire::test(SalesChartWidget::class)->assertSuccessful();
    }
}
