<?php

namespace App\Http\Controllers\Ops;

use App\Services\Ops\CheckResult;
use App\Services\Ops\Preflight;
use Illuminate\Http\JsonResponse;

/**
 * Readiness (فاز ۹). `/up` (Laravel) فقط Liveness است: «پردازش PHP زنده است».
 * این Endpoint می‌گوید «سرویس واقعاً می‌تواند خدمت بدهد»؛ اگر DB/Cache/Storage خراب
 * یا Migration عقب باشد ۵۰۳ برمی‌گرداند (Load Balancer/Health Check در update-git.sh).
 *
 * عمومی است ⇒ فقط نام و وضعیت بررسی‌ها را برمی‌گرداند؛ هرگز `detail` (مسیر، خطا،
 * شناسه‌ی رکورد، نام Migration) را نمی‌دهد. Scheduler/Queue فقط «warn» هستند و ۵۰۳ نمی‌سازند.
 */
class HealthController
{
    public function __invoke(Preflight $preflight): JsonResponse
    {
        $checks = $preflight->runtime();

        $failed = collect($checks)->contains(fn (CheckResult $c) => $c->status === CheckResult::FAIL);
        $warned = collect($checks)->contains(fn (CheckResult $c) => $c->status === CheckResult::WARN);

        $version = is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'unknown';

        return response()->json([
            'status' => $failed ? 'fail' : ($warned ? 'degraded' : 'ok'),
            'version' => $version,
            'checks' => collect($checks)->mapWithKeys(fn (CheckResult $c) => [$c->name => $c->status])->all(),
        ], $failed ? 503 : 200)->header('Cache-Control', 'no-store');
    }
}
