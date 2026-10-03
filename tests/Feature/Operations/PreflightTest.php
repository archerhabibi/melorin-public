<?php

namespace Tests\Feature\Operations;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use App\Services\Ops\CheckResult;
use App\Services\Ops\Preflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز ۹ — Preflight (config / runtime / data).
 * مرجع: docs/history/PHASE-9-STAGING.md
 */
class PreflightTest extends TestCase
{
    use RefreshDatabase;

    private function statusOf(array $results, string $name): string
    {
        foreach ($results as $c) {
            if ($c->name === $name) {
                return $c->status;
            }
        }

        $this->fail("check {$name} not found");
    }

    // ---------- config ----------

    #[Test]
    public function a_correct_production_config_has_no_failures(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://shop.test',
            'session.secure' => true, 'telegram.bots.main.token' => '1:A', 'telegram.webhook_secret' => 's3cret',
            'queue.default' => 'database', 'cache.default' => 'file', 'mail.default' => 'smtp',
            'services.zarinpal.sandbox' => false,
        ]);

        $failed = array_filter((new Preflight)->config(), fn (CheckResult $c) => $c->status === CheckResult::FAIL);

        $this->assertSame([], array_map(fn ($c) => $c->name, array_values($failed)));
    }

    #[Test]
    public function every_dangerous_production_setting_is_reported_as_a_failure(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => true, 'app.url' => 'http://shop.test',
            'telegram.bots.main.token' => null, 'telegram.webhook_secret' => null,
            'queue.default' => 'sync', 'cache.default' => 'array', 'mail.default' => 'log',
            'services.zarinpal.sandbox' => true,
        ]);

        $r = (new Preflight)->config();

        foreach (['app_debug', 'app_url_https', 'telegram_main_bot_token', 'telegram_webhook_secret',
            'queue_driver', 'cache_store', 'mail_driver', 'zarinpal_sandbox'] as $name) {
            $this->assertSame(CheckResult::FAIL, $this->statusOf($r, $name), $name);
        }
    }

    #[Test]
    public function staging_must_use_the_zarinpal_sandbox_and_https_session_cookies(): void
    {
        config(['app.env' => 'staging', 'app.url' => 'https://stg.test', 'session.secure' => null,
            'services.zarinpal.sandbox' => false]);

        $r = (new Preflight)->config();

        $this->assertSame(CheckResult::FAIL, $this->statusOf($r, 'zarinpal_sandbox'));
        $this->assertSame(CheckResult::FAIL, $this->statusOf($r, 'session_secure_cookie'));

        config(['services.zarinpal.sandbox' => true, 'session.secure' => true]);
        $r = (new Preflight)->config();
        $this->assertSame(CheckResult::OK, $this->statusOf($r, 'zarinpal_sandbox'));
        $this->assertSame(CheckResult::OK, $this->statusOf($r, 'session_secure_cookie'));
    }

    // ---------- runtime ----------

    #[Test]
    public function runtime_is_healthy_on_a_fully_migrated_database(): void
    {
        $r = (new Preflight)->runtime();

        foreach (['database', 'cache', 'storage', 'migrations', 'currency_lock'] as $name) {
            $this->assertSame(CheckResult::OK, $this->statusOf($r, $name), $name);
        }
    }

    #[Test]
    public function a_pending_migration_makes_runtime_fail(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $this->assertSame(CheckResult::FAIL, $this->statusOf((new Preflight)->runtime(), 'migrations'));
    }

    #[Test]
    public function the_scheduler_heartbeat_is_warned_when_missing_or_stale_and_ok_when_fresh(): void
    {
        Cache::forget(Preflight::HEARTBEAT_KEY);
        $this->assertSame(CheckResult::WARN, $this->statusOf((new Preflight)->runtime(), 'scheduler'));

        Cache::put(Preflight::HEARTBEAT_KEY, now()->subMinutes(10)->timestamp, 900);
        $this->assertSame(CheckResult::WARN, $this->statusOf((new Preflight)->runtime(), 'scheduler'));

        Cache::put(Preflight::HEARTBEAT_KEY, now()->timestamp, 900);
        $this->assertSame(CheckResult::OK, $this->statusOf((new Preflight)->runtime(), 'scheduler'));
    }

    #[Test]
    public function the_heartbeat_and_the_integrity_monitor_are_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($e) => $e->description === 'melorin-scheduler-heartbeat'));
        $integrity = $events->first(fn ($e) => $e->description === 'melorin-data-integrity');
        $this->assertNotNull($integrity);
        $this->assertStringContainsString('melorin:preflight --group=data --log', $integrity->command);
    }

    // ---------- data ----------

    #[Test]
    public function a_clean_database_has_no_data_findings(): void
    {
        $bad = array_filter((new Preflight)->data(), fn (CheckResult $c) => $c->status !== CheckResult::OK);

        $this->assertSame([], array_map(fn ($c) => $c->name, array_values($bad)));
    }

    #[Test]
    public function ledger_drift_is_detected_both_ways(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->walletForContext($user, StoreContext::main());
        app(WalletService::class)->charge($user, 50000);

        $this->assertSame(CheckResult::OK, $this->statusOf((new Preflight)->data(), 'ledger_matches_balance'));

        // دست‌کاری مستقیم موجودی بدون گردش (دقیقاً همان چیزی که نباید رخ دهد)
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 99999]);

        $r = (new Preflight)->data();
        $this->assertSame(CheckResult::FAIL, $this->statusOf($r, 'ledger_matches_balance'));
        $this->assertSame(CheckResult::FAIL, $this->statusOf($r, 'last_balance_after_matches'));
    }

    #[Test]
    public function a_confirmed_wallet_charge_without_a_credit_is_a_failure(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create();
        DB::table('payments')->insert([
            'user_id' => $user->id, 'payment_method_id' => $method->id, 'amount' => 10000,
            'purpose' => 'wallet_charge', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(CheckResult::FAIL, $this->statusOf((new Preflight)->data(), 'confirmed_payments_credited'));
    }

    #[Test]
    public function stuck_and_overdue_orders_are_warned_but_fresh_ones_are_not(): void
    {
        $fresh = Order::factory()->create(['status' => Order::STATUS_PROVISIONING]);
        $this->assertSame(CheckResult::OK, $this->statusOf((new Preflight)->data(), 'orders_not_stuck'));

        $stuck = Order::factory()->create(['status' => Order::STATUS_PROVISIONING]);
        DB::table('orders')->where('id', $stuck->id)->update(['updated_at' => now()->subHour()]);
        $this->assertSame(CheckResult::WARN, $this->statusOf((new Preflight)->data(), 'orders_not_stuck'));

        $failed = Order::factory()->create(['status' => Order::STATUS_PROVISION_FAILED]);
        DB::table('orders')->where('id', $failed->id)->update(['next_provision_retry_at' => now()->subHour()]);
        $r = (new Preflight)->data();
        $this->assertSame(CheckResult::WARN, $this->statusOf($r, 'retries_not_overdue'));
        $this->assertSame(CheckResult::WARN, $this->statusOf($r, 'provision_failed_orders'));
        $this->assertNotNull($fresh);
    }

    #[Test]
    public function an_active_reseller_without_a_webhook_secret_is_warned(): void
    {
        $reseller = Reseller::factory()->create(['webhook_slug' => 'shop-a']);
        $reseller->forceFill(['webhook_secret' => null])->save();

        $this->assertSame(CheckResult::WARN, $this->statusOf((new Preflight)->data(), 'resellers_have_webhook_secret'));

        $reseller->ensureWebhookSecret();
        $this->assertSame(CheckResult::OK, $this->statusOf((new Preflight)->data(), 'resellers_have_webhook_secret'));
    }

    // ---------- command ----------

    #[Test]
    public function the_command_exits_non_zero_on_a_failure_and_zero_otherwise(): void
    {
        config(['telegram.webhook_secret' => null]);
        $this->artisan('melorin:preflight', ['--group' => 'config'])->assertExitCode(1);

        config(['app.env' => 'testing', 'telegram.webhook_secret' => 'x', 'telegram.bots.main.token' => '1:A']);
        $this->artisan('melorin:preflight', ['--group' => 'data'])->assertExitCode(0);
    }

    #[Test]
    public function strict_mode_turns_warnings_into_failures(): void
    {
        Order::factory()->create(['status' => Order::STATUS_PROVISION_FAILED]);

        $this->artisan('melorin:preflight', ['--group' => 'data'])->assertExitCode(0);
        $this->artisan('melorin:preflight', ['--group' => 'data', '--strict' => true])->assertExitCode(1);
    }

    #[Test]
    public function an_unknown_group_is_rejected(): void
    {
        $this->artisan('melorin:preflight', ['--group' => 'nope'])->assertExitCode(2);
    }

    #[Test]
    public function the_log_option_writes_failures_for_alerting(): void
    {
        Log::spy();
        Order::factory()->create(['status' => Order::STATUS_PROVISION_FAILED]);

        $this->artisan('melorin:preflight', ['--group' => 'data', '--log' => true]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($m, $ctx) => $m === 'melorin_preflight_warn' && $ctx['name'] === 'provision_failed_orders')
            ->once();
    }
}
