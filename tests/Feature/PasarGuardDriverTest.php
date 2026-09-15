<?php

namespace Tests\Feature;

use App\DataTransferObjects\PanelAccountRequest;
use App\Models\ServerPanel;
use App\Services\Core\Panels\PanelDriverFactory;
use App\Services\Core\Panels\PasarGuardDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasarGuardDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function makePanel(): ServerPanel
    {
        return ServerPanel::factory()->create(['panel_type' => 'pasarguard']);
    }

    #[Test]
    public function factory_resolves_pasarguard_to_its_own_driver(): void
    {
        $this->assertInstanceOf(PasarGuardDriver::class, PanelDriverFactory::make('pasarguard'));
    }

    #[Test]
    public function it_authenticates_and_creates_an_account(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response([
                'username' => 'melorin_test',
                'subscription_url' => 'https://sub.example.com/x',
            ], 200),
        ]);

        $panel = $this->makePanel();
        $driver = new PasarGuardDriver;

        $result = $driver->createAccount($panel, new PanelAccountRequest(
            username: 'melorin_test',
            trafficBytes: 10 * 1024 ** 3,
            expireTimestamp: now()->addDays(30)->timestamp,
        ));

        $this->assertTrue($result->success);
        $this->assertEquals('https://sub.example.com/x', $result->subscriptionUrl);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/admin/token');
        });
    }

    #[Test]
    public function it_forwards_group_ids_when_provided(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test'], 200),
        ]);

        $panel = $this->makePanel();
        $driver = new PasarGuardDriver;

        $driver->createAccount($panel, new PanelAccountRequest(
            username: 'melorin_test',
            trafficBytes: 0,
            expireTimestamp: 0,
            extra: ['group_ids' => [1, 2]],
        ));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/user')
                && ($request['group_ids'] ?? null) === [1, 2];
        });
    }

    #[Test]
    public function failed_creation_returns_a_failure_result_without_throwing(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['detail' => 'username already exists'], 409),
        ]);

        $panel = $this->makePanel();
        $driver = new PasarGuardDriver;

        $result = $driver->createAccount($panel, new PanelAccountRequest(
            username: 'melorin_test',
            trafficBytes: 0,
            expireTimestamp: 0,
        ));

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
    }

    #[Test]
    public function delete_account_calls_the_correct_endpoint(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user/melorin_test' => Http::response([], 200),
        ]);

        $panel = $this->makePanel();
        $driver = new PasarGuardDriver;

        $result = $driver->deleteAccount($panel, 'melorin_test');

        $this->assertTrue($result->success);
        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE' && str_contains($request->url(), '/api/user/melorin_test');
        });
    }
}
