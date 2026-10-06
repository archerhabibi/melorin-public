<?php

namespace App\Filament\Reseller\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CustomerResource;
use App\Filament\Reseller\Resources\OrderResource;
use App\Filament\Reseller\Resources\PaymentResource;
use App\Models\Order;
use App\Services\Resellers\Customers\CustomerSegment;
use App\Services\Resellers\Dashboard\ResellerAlert;
use App\Services\Resellers\Dashboard\ResellerDashboardService;
use Filament\Widgets\Widget;

/**
 * B5.1 — «نیازمند توجه»: اعتبار، شارژهای در انتظار تأیید، سفارش‌های ساخت‌ناموفق، سرویس‌های رو‌به‌انقضا.
 * فهرست از وضعیت زنده می‌آید (ذخیره نمی‌شود)؛ با رفع مشکل خودکار ناپدید می‌شود.
 */
class AttentionAlerts extends Widget
{
    use ResolvesCurrentReseller;

    /**
     * Avoid dispatching Filament's internal __lazyLoad hook to the parent
     * page when running older Livewire/Filament combinations.
     */
    protected static bool $isLazy = false;

    protected static string $view = 'filament.reseller.widgets.attention-alerts';

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $service = app(ResellerDashboardService::class);

        $alerts = collect($service->alerts($service->position(static::currentReseller())))
            ->map(fn (ResellerAlert $alert) => [
                'alert' => $alert,
                'url' => $this->urlFor($alert),
            ])
            ->all();

        return ['alerts' => $alerts];
    }

    /** Core فقط «هدف» می‌دهد؛ ساخت URL وظیفه‌ی Channel است. هدفِ بدون صفحه‌ی مقصد ⇒ بدون لینک. */
    private function urlFor(ResellerAlert $alert): ?string
    {
        return match ($alert->target) {
            ResellerAlert::TARGET_PAYMENTS => PaymentResource::getUrl('index'),
            ResellerAlert::TARGET_ATTENTION_ORDERS => OrderResource::getUrl('index', [
                'tableFilters' => ['status' => ['value' => Order::STATUS_PROVISION_FAILED]],
            ]),
            ResellerAlert::TARGET_EXPIRING_SERVICES => CustomerResource::getUrl('index', [
                'tableFilters' => ['segment' => ['value' => CustomerSegment::Expiring->value]],
            ]),
            default => null,
        };
    }
}
