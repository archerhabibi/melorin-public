<?php

namespace Tests\Feature\Provisioning;

use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Services\Core\Provisioning\StuckOrderWatchdog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** فاز ۹ (G-9-1) — نگهبان سفارش‌های گیرکرده در provisioning. */
class StuckOrderWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function stuckOrder(int $minutesAgo): Order
    {
        $order = Order::factory()->create(['status' => Order::STATUS_PROVISIONING, 'provision_attempts' => 1]);
        Order::whereKey($order->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $order->fresh();
    }

    #[Test]
    public function an_old_provisioning_order_becomes_provision_failed_without_a_retry_schedule(): void
    {
        $order = $this->stuckOrder(30);
        ProvisioningAttempt::create([
            'order_id' => $order->id, 'attempt_number' => 1,
            'status' => ProvisioningAttempt::STATUS_STARTED, 'started_at' => now()->subMinutes(30),
        ]);

        $summary = app(StuckOrderWatchdog::class)->run();

        $this->assertSame(1, $summary['marked_failed']);
        $order->refresh();
        $this->assertSame(Order::STATUS_PROVISION_FAILED, $order->status);
        $this->assertNull($order->next_provision_retry_at);
        $this->assertStringContainsString("melorin-order-{$order->id}", (string) $order->failure_reason);
        $this->assertSame(ProvisioningAttempt::STATUS_FAILED, $order->provisioningAttempts()->first()->status);
    }

    #[Test]
    public function a_fresh_provisioning_order_is_left_alone(): void
    {
        $order = $this->stuckOrder(2);

        $summary = app(StuckOrderWatchdog::class)->run();

        $this->assertSame(['recovered' => 0, 'marked_failed' => 0], $summary);
        $this->assertSame(Order::STATUS_PROVISIONING, $order->fresh()->status);
    }

    #[Test]
    public function orders_in_other_statuses_are_never_touched(): void
    {
        foreach ([Order::STATUS_PAID, Order::STATUS_ACCOUNT_CREATED, Order::STATUS_PROVISION_FAILED, Order::STATUS_REFUNDED] as $status) {
            $order = Order::factory()->create(['status' => $status]);
            Order::whereKey($order->id)->update(['updated_at' => now()->subHours(3)]);
        }

        $summary = app(StuckOrderWatchdog::class)->run();

        $this->assertSame(['recovered' => 0, 'marked_failed' => 0], $summary);
        $this->assertSame(0, Order::where('status', Order::STATUS_PROVISIONING)->count());
    }

    #[Test]
    public function an_order_that_already_has_an_account_is_recovered_not_failed(): void
    {
        $order = $this->stuckOrder(30);
        \App\Models\Account::factory()->create(['order_id' => $order->id, 'user_id' => $order->user_id, 'product_id' => $order->product_id]);

        $summary = app(StuckOrderWatchdog::class)->run();

        $this->assertSame(1, $summary['recovered']);
        $this->assertSame(Order::STATUS_ACCOUNT_CREATED, $order->fresh()->status);
    }

    #[Test]
    public function the_watchdog_command_runs_and_reports_counts(): void
    {
        $this->stuckOrder(45);

        $this->artisan('provisioning:recover-stuck')
            ->expectsOutput('recovered=0 marked_failed=1')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_watchdog_never_moves_money(): void
    {
        $src = file_get_contents(app_path('Services/Core/Provisioning/StuckOrderWatchdog.php'));

        foreach (['WalletService', 'RefundService', '->credit(', '->debit(', 'refundFailedOrder'] as $needle) {
            $this->assertStringNotContainsString($needle, $src);
        }
    }
}
