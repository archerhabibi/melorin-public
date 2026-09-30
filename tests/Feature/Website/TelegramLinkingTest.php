<?php

namespace Tests\Feature\Website;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پچ 3.2.5 (ساخت) + 3.2.10 (Review امنیتی، افزودن state ضد-CSRF) -
 * فاز W3 بند 5 (نیمه‌ی دوم): Telegram-linking.
 * مرجع: docs/PHASE-W3-PART3-TELEGRAM-LINKING.md,
 *       docs/PHASE-W6-PART5-TELEGRAM-REVIEW.md
 */
class TelegramLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected string $botToken = 'test-bot-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.bots.main.token' => $this->botToken]);
        config(['telegram.bots.main.username' => 'MelorinTestBot']);
    }

    /** دقیقا همان الگوریتمی که TelegramLoginVerifier پیاده کرده. */
    protected function validPayload(array $overrides = []): array
    {
        $data = array_merge([
            'id' => 555000111,
            'first_name' => 'Ali',
            'auth_date' => time(),
        ], $overrides);

        $checkString = collect($data)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secretKey = hash('sha256', $this->botToken, true);
        $data['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return $data;
    }

    /** state معتبر را در Session قرار می‌دهد و به payload اضافه می‌کند - شبیه‌سازی رفتار واقعی. */
    protected function withValidState(array $payload): array
    {
        $state = 'test-state-value';
        $this->withSession(['telegram_link_state' => $state]);
        $payload['state'] = $state;

        return $payload;
    }

    #[Test]
    public function a_logged_in_user_can_link_a_valid_telegram_account(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);

        $response = $this->actingAs($user)->get(
            route('website.identity.telegram.callback', $this->withValidState($this->validPayload()))
        );

        $response->assertRedirect(route('website.identity.complete-profile.show'));
        $this->assertEquals(555000111, $user->fresh()->telegram_id);
    }

    #[Test]
    public function a_tampered_hash_is_rejected(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);
        $payload = $this->validPayload();
        $payload['id'] = 999999999;

        $this->actingAs($user)
            ->get(route('website.identity.telegram.callback', $this->withValidState($payload)))
            ->assertSessionHasErrors('telegram');

        $this->assertNull($user->fresh()->telegram_id);
    }

    #[Test]
    public function an_expired_auth_date_is_rejected(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);
        $payload = $this->validPayload(['auth_date' => time() - 100000]);

        $this->actingAs($user)
            ->get(route('website.identity.telegram.callback', $this->withValidState($payload)))
            ->assertSessionHasErrors('telegram');

        $this->assertNull($user->fresh()->telegram_id);
    }

    #[Test]
    public function linking_a_telegram_account_already_owned_by_someone_else_is_rejected_not_merged(): void
    {
        User::factory()->create(['telegram_id' => 555000111]);
        $currentUser = User::factory()->create(['telegram_id' => null]);

        $response = $this->actingAs($currentUser)->get(
            route('website.identity.telegram.callback', $this->withValidState($this->validPayload()))
        );

        $response->assertSessionHasErrors('telegram');
        $this->assertNull($currentUser->fresh()->telegram_id);
    }

    #[Test]
    public function guests_cannot_use_the_telegram_callback(): void
    {
        $this->get(route('website.identity.telegram.callback', $this->validPayload()))
            ->assertRedirect(route('website.login'));
    }

    // --- پچ 3.2.10: تست‌های یافته‌ی اصلی Review (ضد Login/Link CSRF) ---

    #[Test]
    public function a_callback_with_no_session_state_is_rejected_even_with_a_valid_signature(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);

        // این دقیقا سناریوی حمله است: یک لینک معتبر و امضاشده (مثلا
        // کپی‌شده از Session یک نفر دیگر) بدون state متناظر در همین
        // Session ارسال می‌شود.
        $payload = $this->validPayload();
        $payload['state'] = 'whatever-the-attacker-put-here';

        $response = $this->actingAs($user)->get(
            route('website.identity.telegram.callback', $payload)
        );

        $response->assertSessionHasErrors('telegram');
        $this->assertNull($user->fresh()->telegram_id);
    }

    #[Test]
    public function a_callback_with_a_mismatched_state_is_rejected(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);
        $this->withSession(['telegram_link_state' => 'the-real-state']);

        $payload = $this->validPayload();
        $payload['state'] = 'a-different-state';

        $response = $this->actingAs($user)->get(
            route('website.identity.telegram.callback', $payload)
        );

        $response->assertSessionHasErrors('telegram');
        $this->assertNull($user->fresh()->telegram_id);
    }

    #[Test]
    public function the_state_is_single_use_and_cannot_be_replayed(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);
        $payload = $this->withValidState($this->validPayload());

        // اولین استفاده: موفق.
        $this->actingAs($user)->get(route('website.identity.telegram.callback', $payload));
        $this->assertEquals(555000111, $user->fresh()->telegram_id);

        // همان state دوباره (مثلا با Refresh مرورگر) - دیگر در Session نیست.
        $secondUser = User::factory()->create(['telegram_id' => null]);
        $response = $this->actingAs($secondUser)->get(
            route('website.identity.telegram.callback', $payload)
        );

        $response->assertSessionHasErrors('telegram');
        $this->assertNull($secondUser->fresh()->telegram_id);
    }
}
