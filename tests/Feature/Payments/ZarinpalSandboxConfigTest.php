<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Core\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ZARINPAL_SANDBOX (config/services.php) قبلاً تعریف شده بود ولی
 * ZarinpalGateway فقط payment_methods.settings.sandbox را می‌خواند؛
 * یعنی متغیر env بی‌اثر بود. اینجا اولویت‌ها قفل می‌شوند.
 */
class ZarinpalSandboxConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function start(PaymentMethod $method): ?string
    {
        Http::fake([
            '*/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'A00000000000000000000000000999999'],
            ], 200),
        ]);

        ['initiation' => $result] = app(PaymentService::class)->initiate(
            User::factory()->create(), $method, 150000, 'wallet_charge'
        );

        return $result->redirectUrl;
    }

    protected function methodWith(array $settingsOverride): PaymentMethod
    {
        $method = PaymentMethod::factory()->zarinpal()->create();
        $settings = $method->settings;
        unset($settings['sandbox']);
        $method->update(['settings' => array_merge($settings, $settingsOverride)]);

        return $method->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.zarinpal.callback_url', 'https://melorin.test/payments/zarinpal/callback');
    }

    #[Test]
    public function the_env_config_enables_sandbox_when_the_method_does_not_specify_it(): void
    {
        config()->set('services.zarinpal.sandbox', true);

        $url = $this->start($this->methodWith([]));

        $this->assertStringContainsString('sandbox.zarinpal.com', $url);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sandbox.zarinpal.com'));
    }

    #[Test]
    public function production_is_the_default_when_nothing_is_configured(): void
    {
        config()->set('services.zarinpal.sandbox', false);

        $url = $this->start($this->methodWith([]));

        $this->assertStringContainsString('www.zarinpal.com', $url);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.zarinpal.com'));
    }

    #[Test]
    public function the_payment_method_setting_overrides_the_env_config(): void
    {
        config()->set('services.zarinpal.sandbox', true);

        $url = $this->start($this->methodWith(['sandbox' => false]));

        $this->assertStringContainsString('www.zarinpal.com', $url);
    }
}
