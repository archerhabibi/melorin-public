<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Objects\User;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * فاز ۹ — /health/ready (Readiness) و رفع O-6 (PII در لاگ تلگرام).
 */
class HealthEndpointTest extends TestCase
{
    use FakesTelegram, RefreshDatabase;

    #[Test]
    public function readiness_is_ok_and_reports_the_release_version(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertOk()->assertJsonPath('version', trim(file_get_contents(base_path('VERSION'))));
        $this->assertContains($response->json('status'), ['ok', 'degraded']);
        $this->assertSame('ok', $response->json('checks.database'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function readiness_is_unavailable_when_a_migration_is_pending(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.migrations', 'fail');
    }

    #[Test]
    public function the_public_response_never_leaks_details_or_paths(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $body = $this->getJson('/health/ready')->getContent();

        $this->assertStringNotContainsString('create_baseline_schema', $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('detail', $body);
        $this->assertStringNotContainsString('Migration', $body);
    }

    #[Test]
    public function readiness_does_not_create_a_session_or_set_cookies(): void
    {
        $response = $this->getJson('/health/ready');

        $this->assertEmpty($response->headers->getCookies());
    }

    #[Test]
    public function the_telegram_diagnostic_log_does_not_contain_pii_unless_debug_is_on(): void
    {
        $this->fakeTelegram()->shouldReceive('getMe')->andReturn(new User(['id' => 1, 'is_bot' => true, 'first_name' => 'Bot', 'username' => 'melorin_bot']));
        config(['telegram.bots.main.token' => '123:ABC', 'telegram.webhook_secret' => 'sec', 'app.debug' => false]);
        Log::spy();

        $update = ['update_id' => 900, 'message' => [
            'message_id' => 1, 'date' => time(),
            'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Secretname'],
            'chat' => ['id' => 777, 'type' => 'private'],
            'text' => 'private text',
        ]];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'sec')->postJson('/telegram/webhook/123:ABC', $update)->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return $message === 'telegram_update_resolved'
                && ! array_key_exists('text_or_data', $context)
                && ! array_key_exists('from_first_name', $context)
                && $context['from_id'] === 777;
        })->once();
    }
}
