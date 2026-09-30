<?php

namespace Tests\Feature\Website;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\GuestCheckout;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پچ 3.2.9 - فاز W6 بند 4: Audit.
 * مرجع: docs/PHASE-W6-PART4-AUDIT.md
 */
class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected string $botToken = 'test-bot-token-audit';

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.bots.main.token' => $this->botToken]);
    }

    protected function makeProduct(): Product
    {
        $category = Category::factory()->create(['status' => 'active']);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => 120000,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function creating_an_account_from_guest_checkout_is_audited_with_the_correct_actor(): void
    {
        $product = $this->makeProduct();
        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'Ali',
            'guest_phone' => '09121234567',
        ]);
        $guest = GuestCheckout::query()->firstOrFail();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.guest-checkout.purchase'));

        $user = User::query()->where('phone', '09121234567')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.guest_account_created',
            'actor_type' => 'customer',
            'actor_id' => $user->id,
            'target_type' => $user->getMorphClass(),
            'target_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_guest_collision_is_audited(): void
    {
        $existing = User::factory()->create(['phone' => '09121234599']);
        $product = $this->makeProduct();

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'Someone',
            'guest_phone' => '09121234599',
        ]);
        $guest = GuestCheckout::query()->firstOrFail();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.guest-checkout.purchase'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.guest_collision_detected',
            'target_id' => $existing->id,
        ]);
    }

    protected function validTelegramPayload(): array
    {
        $data = ['id' => 700000111, 'first_name' => 'Ali', 'auth_date' => time()];
        $checkString = collect($data)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secretKey = hash('sha256', $this->botToken, true);
        $data['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        // پچ ۳.۲.۱۰: از این پس callback تلگرام به یک state معتبر در
        // Session نیاز دارد (ضد Login/Link CSRF) - جزئیات در
        // docs/PHASE-W6-PART5-TELEGRAM-REVIEW.md.
        $state = 'audit-test-state';
        $this->withSession(['telegram_link_state' => $state]);
        $data['state'] = $state;

        return $data;
    }

    #[Test]
    public function linking_telegram_is_audited_with_the_customer_as_actor(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);

        $this->actingAs($user)->get(
            route('website.identity.telegram.callback', $this->validTelegramPayload())
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.telegram_linked',
            'actor_type' => 'customer',
            'actor_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_rejected_telegram_link_attempt_on_an_owned_account_is_audited(): void
    {
        User::factory()->create(['telegram_id' => 700000111]);
        $currentUser = User::factory()->create(['telegram_id' => null]);

        $this->actingAs($currentUser)->get(
            route('website.identity.telegram.callback', $this->validTelegramPayload())
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.telegram_link_rejected_owned_by_other',
            'actor_type' => 'customer',
            'actor_id' => $currentUser->id,
        ]);
    }

    #[Test]
    public function audit_service_resolves_a_website_customer_as_the_actor_when_not_explicit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');
        app(\App\Services\Core\AuditService::class)->record('test.action');

        $log = AuditLog::query()->latest('id')->first();
        $this->assertEquals('customer', $log->actor_type);
        $this->assertEquals($user->id, $log->actor_id);
    }
}
