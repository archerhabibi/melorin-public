<?php

namespace Tests\Feature\Architecture;

use Illuminate\Session\Middleware\StartSession;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز ۹ — ابزار عملیاتی هرگز نباید داده را تغییر دهد (هم‌راستا با Ledger فقط-افزودنی)
 * و Endpoint عمومی سلامت نباید جزئیات نشت دهد.
 */
class OpsReadOnlyGuardTest extends TestCase
{
    #[Test]
    public function the_preflight_service_and_command_never_write_to_the_database(): void
    {
        $forbidden = ['->insert(', '->update(', '->delete(', '->truncate(', '->upsert(', 'DB::statement', 'DB::unprepared',
            'Artisan::call', '->save(', '->forceFill(', '::create(', '->increment(', '->decrement('];

        foreach ([app_path('Services/Ops/Preflight.php'), app_path('Console/Commands/PreflightCommand.php')] as $file) {
            $src = file_get_contents($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $src, basename($file)." must stay read-only ({$needle})");
            }
        }
    }

    #[Test]
    public function the_health_controller_never_returns_check_details(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Ops/HealthController.php'));

        $this->assertStringNotContainsString('->detail', $src);
        $this->assertStringNotContainsString('toArray()', $src);
    }

    #[Test]
    public function the_readiness_route_has_no_session_and_is_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('health.ready');

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('throttle:60,1', $middleware);
        $this->assertNotContains('web', $middleware);
        $this->assertNotContains(StartSession::class, $middleware);
    }
}
