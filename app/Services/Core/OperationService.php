<?php

namespace App\Services\Core;

use App\Models\Operation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * بند ۲۶ و ۴۸ بلوپرینت — اجرای «دقیقاً یک‌بار» برای عملیات حساس.
 *
 * استفاده:
 *
 *     $result = $operations->runOnce(
 *         key: "purchase:{$customerAccount->id}:{$product->id}:{$clickToken}",
 *         type: Operation::TYPE_PURCHASE,
 *         callback: fn (Operation $op) => $accountService->purchase(...),
 *     );
 *
 * اگر همان key دوباره بیاید، callback اصلاً اجرا نمی‌شود و نتیجه‌ی
 * ذخیره‌شده‌ی دفعه‌ی اول برمی‌گردد.
 *
 * یک هشدار مهم درباره‌ی مرز تراکنش (بند ۴۷): این سرویس عمداً callback
 * را داخل یک DB::transaction نمی‌پیچد. دلیلش این است که عملیات حساس ما
 * (خرید، Provisioning) وسط کارشان به API خارجی پنل VPN زنگ می‌زنند، و
 * یک تراکنش دیتابیس نمی‌تواند اکانتی را که روی پنل ساخته شده rollback
 * کند. مدیریت تراکنش داخلی وظیفه‌ی خودِ callback است؛ کار اینجا فقط
 * تضمین یکتایی اجراست.
 */
class OperationService
{
    /**
     * @template T
     *
     * @param  callable(Operation): T  $callback
     * @return T|mixed نتیجه‌ی callback، یا result_payload ذخیره‌شده اگر قبلاً اجرا شده
     */
    public function runOnce(string $key, string $type, callable $callback, array $payload = [])
    {
        $operation = $this->claim($key, $type, $payload);

        // قبلاً با موفقیت اجرا شده: همان نتیجه را برمی‌گردانیم، بدون
        // اجرای دوباره. این همان چیزی است که دو کسر از کیف‌پول را به یک
        // کسر تبدیل می‌کند.
        if ($operation->isCompleted()) {
            return $operation->result_payload;
        }

        // یک اجرای هم‌زمان دیگر همین حالا رویش کار می‌کند. اجرای موازی
        // یعنی همان کسر دوباره، پس متوقف می‌شویم.
        if ($operation->status === 'processing') {
            throw new \RuntimeException('این عملیات هم‌اکنون در حال پردازش است؛ لطفاً چند لحظه صبر کنید.');
        }

        $operation->markProcessing();

        try {
            $result = $callback($operation);

            $operation->markCompleted(
                $this->normalizeResult($result),
                $result instanceof \Illuminate\Database\Eloquent\Model ? $result : null,
            );

            return $result;
        } catch (\Throwable $e) {
            $operation->markFailed($e->getMessage());

            Log::warning('operation_failed', [
                'operation_id' => $operation->id,
                'type' => $type,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * ردیف عملیات را می‌گیرد یا می‌سازد. تکیه بر unique دیتابیس، نه بر
     * خواندنِ قبل از نوشتن — چون بین خواندن و نوشتن جا برای رقابت هست.
     */
    public function claim(string $key, string $type, array $payload = []): Operation
    {
        try {
            return Operation::create([
                'idempotency_key' => $key,
                'type' => $type,
                'status' => 'pending',
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException|QueryException) {
            $existing = Operation::where('idempotency_key', $key)->first();

            if (! $existing) {
                throw new \RuntimeException("ثبت عملیات با کلید {$key} ناموفق بود.");
            }

            return $existing;
        }
    }

    /**
     * عملیات شکست‌خورده‌ای که زمان تلاش مجددشان رسیده (بند ۲۵ — Retry).
     * توسط Jobهای پس‌زمینه‌ی فاز D مصرف می‌شود.
     */
    public function dueForRetry(string $type, int $maxAttempts = 3)
    {
        return Operation::query()
            ->where('type', $type)
            ->where('status', 'failed')
            ->where('attempts', '<', $maxAttempts)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->get();
    }

    protected function normalizeResult(mixed $result): array
    {
        if ($result instanceof \Illuminate\Database\Eloquent\Model) {
            return ['model' => $result->getMorphClass(), 'id' => $result->getKey()];
        }

        if (is_array($result)) {
            return $result;
        }

        return ['value' => $result];
    }
}
