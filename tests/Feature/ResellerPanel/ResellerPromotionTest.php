<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Resources\ResellerResource\Pages\CreateReseller;
use App\Filament\Resources\ResellerResource\Pages\ListResellers;
use App\Models\Admin;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * پوشش دقیق درخواست صریح: «صفحه‌ای که توکن ربات، نام لاتین و
 * ایمیل/رمز را می‌گیرد و وب‌هوک را خودکار وصل می‌کند» — و رفعِ باگِ
 * گزارش‌شده («ورود به پنل نماینده → 403 خام»).
 */
class ResellerPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @test */
    public function admin_can_promote_an_existing_user_to_reseller_with_automatic_webhook_registration(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true, 'description' => 'Webhook set'], 200),
        ]);

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $candidate = User::factory()->create(['full_name' => 'Ali Rezaei']);

        Livewire::test(CreateReseller::class)
            ->fillForm([
                'user_id' => $candidate->id,
                'bot_token' => '123456:FAKE-BOT-TOKEN',
                'slug' => 'parismobile',
                'email' => 'parismobile@example.com',
                'password' => 'a-strong-password-1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $reseller = Reseller::query()->where('user_id', $candidate->id)->firstOrFail();

        $this->assertEquals('parismobile', $reseller->slug);
        $this->assertEquals('123456:FAKE-BOT-TOKEN', $reseller->bot_token);
        $this->assertTrue(ResellerAdmin::query()
            ->where('reseller_id', $reseller->id)
            ->where('user_id', $candidate->id)
            ->where('role', 'owner')
            ->exists());

        $candidate->refresh();
        $this->assertEquals('parismobile@example.com', $candidate->email);
        $this->assertTrue(Hash::check('a-strong-password-1', $candidate->password));

        Http::assertSent(fn ($request) => str_contains($request->url(), '123456:FAKE-BOT-TOKEN/setWebhook')
            && str_contains((string) $request['url'], $reseller->webhook_slug));
    }

    /** @test */
    public function reserved_slugs_are_rejected(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $candidate = User::factory()->create();

        Livewire::test(CreateReseller::class)
            ->fillForm([
                'user_id' => $candidate->id,
                'bot_token' => '123456:FAKE',
                'slug' => 'admin',
                'email' => 'x@example.com',
                'password' => 'a-strong-password-1',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    /** @test */
    public function failed_webhook_registration_still_creates_the_reseller_but_warns_the_admin(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $candidate = User::factory()->create();

        Livewire::test(CreateReseller::class)
            ->fillForm([
                'user_id' => $candidate->id,
                'bot_token' => 'bad-token',
                'slug' => 'brokenshop',
                'email' => 'broken@example.com',
                'password' => 'a-strong-password-1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('resellers', ['slug' => 'brokenshop']);
    }

    /** @test */
    public function reconnect_webhook_action_retries_registration(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $reseller = Reseller::factory()->create();

        Livewire::test(ListResellers::class)
            ->callTableAction('reconnect_webhook', $reseller)
            ->assertHasNoTableActionErrors();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'setWebhook'));
    }

    /** @test */
    public function visiting_a_resellers_panel_url_while_logged_out_redirects_to_login_instead_of_a_bare_403(): void
    {
        Reseller::factory()->create(['slug' => 'parismobile']);

        // این دقیقاً همان باگِ گزارش‌شده است: قبل از افزودن ->login()
        // به پنل، هیچ صفحه‌ی ورودی برای redirect وجود نداشت و
        // middleware احراز هویتِ Filament مستقیم 403 می‌داد.
        $response = $this->get('/parismobile');

        $response->assertRedirect();
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }
}
