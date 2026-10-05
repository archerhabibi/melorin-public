<?php

namespace App\Services\Core\Customer;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * پیگیری سفارش مشتری (B4.5). خالص و فقط‌خواندنی: وضعیت را تغییر نمی‌دهد و پولی جابه‌جا نمی‌کند؛
 * وضعیت واقعی همچنان توسط Purchase/Provisioning/Refund نوشته می‌شود و این‌جا فقط «روایت» می‌شود.
 *
 * فیلتر فهرست با «گروه» انجام می‌شود (در جریان / تحویل‌شده / نیازمند رسیدگی / بسته‌شده)، نه وضعیت خام،
 * تا مشتری با اصطلاح داخلی (provisioning، …) روبه‌رو نشود.
 */
final class OrderTrackingService
{
    public const GROUP_ACTIVE = 'active';

    public const GROUP_DELIVERED = 'delivered';

    public const GROUP_ATTENTION = 'attention';

    public const GROUP_CLOSED = 'closed';

    /** @var array<string, list<string>> */
    private const GROUPS = [
        self::GROUP_ACTIVE => [Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_PROVISIONING],
        self::GROUP_DELIVERED => [Order::STATUS_ACCOUNT_CREATED],
        self::GROUP_ATTENTION => [Order::STATUS_PROVISION_FAILED],
        self::GROUP_CLOSED => [Order::STATUS_FAILED, Order::STATUS_REFUNDED],
    ];

    public static function groupLabels(): array
    {
        return [
            self::GROUP_ACTIVE => 'در جریان',
            self::GROUP_DELIVERED => 'تحویل‌شده',
            self::GROUP_ATTENTION => 'نیازمند رسیدگی',
            self::GROUP_CLOSED => 'بسته‌شده',
        ];
    }

    /** مقدار نامعتبر ⇒ null (همه)؛ هرگز خطا نمی‌دهد. */
    public function group(mixed $value): ?string
    {
        return is_string($value) && isset(self::GROUPS[$value]) ? $value : null;
    }

    /** سفارش‌های یک عضویت در یک Context (reseller_id=null ⇒ Main)، تازه‌ترین اول. */
    public function paginate(int $customerAccountId, ?int $resellerId, ?string $group, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->scoped($customerAccountId, $resellerId)->with('product')->latest('id');

        if ($group !== null) {
            $query->whereIn('status', self::GROUPS[$group]);
        }

        return $query->paginate($perPage);
    }

    /**
     * شمار هر گروه با یک کوئری (بدون N+1).
     *
     * @return array<string, int> کلیدها: total + چهار گروه
     */
    public function counts(int $customerAccountId, ?int $resellerId): array
    {
        $byStatus = $this->scoped($customerAccountId, $resellerId)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $counts = ['total' => (int) $byStatus->sum()];

        foreach (self::GROUPS as $group => $statuses) {
            $counts[$group] = (int) collect($statuses)->sum(fn (string $s) => (int) ($byStatus[$s] ?? 0));
        }

        return $counts;
    }

    public function track(Order $order): OrderTracking
    {
        $renewal = $order->isRenewal();
        $deliverLabel = $renewal ? 'اعمال تمدید' : 'ساخت سرویس';

        // وضعیت هر مرحله بر اساس وضعیت سفارش؛ ترتیب: ثبت ← پرداخت ← ساخت/تمدید ← تحویل.
        [$states, $headline, $message, $tone] = match ($order->status) {
            Order::STATUS_PENDING => [
                ['done', 'current', 'upcoming', 'upcoming'],
                'در انتظار پرداخت',
                'سفارش ثبت شده و منتظر تکمیل پرداخت از کیف‌پول است.',
                'info',
            ],
            Order::STATUS_PAID => [
                ['done', 'done', 'current', 'upcoming'],
                'پرداخت انجام شد',
                $renewal ? 'تمدید سرویس شما در صف اجراست.' : 'ساخت سرویس شما در صف اجراست و کمی بعد شروع می‌شود.',
                'info',
            ],
            Order::STATUS_PROVISIONING => [
                ['done', 'done', 'current', 'upcoming'],
                $renewal ? 'در حال اعمال تمدید' : 'در حال ساخت سرویس',
                'سفارش شما در حال انجام است؛ معمولاً چند لحظه بیشتر طول نمی‌کشد.',
                'info',
            ],
            Order::STATUS_ACCOUNT_CREATED => [
                ['done', 'done', 'done', 'done'],
                $renewal ? 'تمدید انجام شد' : 'سرویس شما آماده است',
                $renewal ? 'سرویس شما با موفقیت تمدید شد.' : 'سرویس ساخته و تحویل شد؛ جزئیات اتصال را در صفحه‌ی سرویس ببینید.',
                'success',
            ],
            Order::STATUS_PROVISION_FAILED => [
                ['done', 'done', 'failed', 'upcoming'],
                'نیازمند رسیدگی',
                'پرداخت شما انجام شده ولی '.($renewal ? 'تمدید' : 'ساخت سرویس').' کامل نشد. نگران نباشید؛ سفارش شما ثبت و پیگیری می‌شود و مبلغ از بین نمی‌رود.',
                'danger',
            ],
            Order::STATUS_FAILED => [
                ['done', 'failed', 'upcoming', 'upcoming'],
                'سفارش ناموفق',
                'پرداخت این سفارش انجام نشد و سرویسی ساخته نشد. در صورت نیاز می‌توانید دوباره خرید کنید.',
                'danger',
            ],
            Order::STATUS_REFUNDED => [
                ['done', 'done', 'upcoming', 'upcoming'],
                'مبلغ بازگردانده شد',
                'سفارش لغو و مبلغ آن به کیف‌پول شما بازگردانده شد.',
                'neutral',
            ],
            default => [
                ['done', 'current', 'upcoming', 'upcoming'],
                Order::statusLabels()[$order->status] ?? (string) $order->status,
                'وضعیت این سفارش در حال بررسی است.',
                'neutral',
            ],
        };

        $labels = ['ثبت سفارش', 'پرداخت', $deliverLabel, 'تحویل'];
        $steps = [];
        foreach ($labels as $i => $label) {
            $steps[] = ['label' => $label, 'state' => $states[$i]];
        }

        $retryAt = $order->status === Order::STATUS_PROVISION_FAILED ? $order->next_provision_retry_at : null;

        return new OrderTracking(
            steps: $steps,
            headline: $headline,
            message: $message,
            tone: $tone,
            inProgress: in_array($order->status, self::GROUPS[self::GROUP_ACTIVE], true) && $order->status !== Order::STATUS_PENDING,
            needsSupport: $order->needsAttention(),
            isRenewal: $renewal,
            autoRetry: $retryAt !== null,
            nextRetryAt: $retryAt,
        );
    }

    /** مبلغ نهایی همین سفارش از دید مشتری: Main ⇒ main_price · نماینده ⇒ customers_price (بند ۶). */
    public function amount(Order $order): int
    {
        return (int) ($order->isResellerContext() ? $order->customers_price : $order->main_price);
    }

    private function scoped(int $customerAccountId, ?int $resellerId): Builder
    {
        return Order::query()
            ->where('customer_account_id', $customerAccountId)
            ->when(
                $resellerId === null,
                fn (Builder $q) => $q->whereNull('reseller_id'),
                fn (Builder $q) => $q->where('reseller_id', $resellerId),
            );
    }
}
