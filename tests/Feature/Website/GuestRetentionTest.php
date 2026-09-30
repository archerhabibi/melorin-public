<?php

namespace Tests\Feature\Website;

use App\Models\GuestCheckout;
use App\Services\Core\Guest\GuestCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * فاز ۴ — Guest Retention (Master 2.7 G9، D-4؛ DATA-RETENTION.md؛ شکاف C11):
 * حذف فیزیکی ۶۰ روز پس از انقضا/مصرف.
 */
class GuestRetentionTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function makeGuest(string $status, $expiresAt, $updatedAt, string $email): GuestCheckout
    {
        $product = $this->productForRetention ??= $this->makeSellableProduct();

        $guest = GuestCheckout::create([
            'token' => str()->random(48), 'product_id' => $product->id, 'guest_email' => $email,
            'status' => $status, 'expires_at' => $expiresAt,
        ]);

        // updated_at را مستقیم می‌نویسیم (Eloquent آن را دست می‌زند).
        GuestCheckout::query()->whereKey($guest->id)->update(['updated_at' => $updatedAt]);

        return $guest;
    }

    protected $productForRetention = null;

    #[Test]
    public function it_deletes_consumed_and_expired_rows_older_than_sixty_days_and_keeps_the_rest(): void
    {
        $old = now()->subDays(61);
        $recent = now()->subDays(59);

        $oldConsumed = $this->makeGuest('consumed', $old->copy()->subHour(), $old, 'a@example.test');
        $oldExpired = $this->makeGuest('expired', $old, $old, 'b@example.test');
        $oldPending = $this->makeGuest('pending', $old, $old, 'c@example.test'); // pending گذشته از expires_at
        $recentConsumed = $this->makeGuest('consumed', $recent->copy()->subHour(), $recent, 'd@example.test');
        $recentExpired = $this->makeGuest('expired', $recent, $recent, 'e@example.test');
        $livePending = $this->makeGuest('pending', now()->addMinutes(30), now(), 'f@example.test');

        $deleted = app(GuestCheckoutService::class)->pruneExpired();

        $this->assertEquals(3, $deleted);
        foreach ([$oldConsumed, $oldExpired, $oldPending] as $gone) {
            $this->assertDatabaseMissing('guest_checkouts', ['id' => $gone->id]);
        }
        foreach ([$recentConsumed, $recentExpired, $livePending] as $kept) {
            $this->assertDatabaseHas('guest_checkouts', ['id' => $kept->id]);
        }
    }

    #[Test]
    public function it_writes_a_one_line_audit_with_the_deleted_count(): void
    {
        $old = now()->subDays(90);
        $this->makeGuest('expired', $old, $old, 'a@example.test');

        app(GuestCheckoutService::class)->pruneExpired();

        $this->assertDatabaseHas('audit_logs', ['action' => 'system.guest_checkouts_pruned']);
    }

    #[Test]
    public function the_artisan_command_runs_and_reports_the_count(): void
    {
        $old = now()->subDays(90);
        $this->makeGuest('consumed', $old, $old, 'a@example.test');

        $this->artisan('guest:prune')->expectsOutput('deleted=1')->assertExitCode(0);

        $this->assertDatabaseCount('guest_checkouts', 0);
    }

    #[Test]
    public function the_command_is_scheduled_daily(): void
    {
        Artisan::call('list'); // اطمینان از لود شدن routes/console.php

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'guest:prune'));

        $this->assertCount(1, $events);
        $this->assertEquals('30 3 * * *', $events->first()->expression);
    }

    #[Test]
    public function consuming_is_atomic_so_a_second_consume_is_a_noop(): void
    {
        $guest = $this->makeGuest('pending', now()->addMinutes(30), now(), 'a@example.test');
        $service = app(GuestCheckoutService::class);

        $this->assertTrue($service->consume($guest));
        $this->assertFalse($service->consume($guest));
        $this->assertEquals('consumed', $guest->fresh()->status);
    }
}
