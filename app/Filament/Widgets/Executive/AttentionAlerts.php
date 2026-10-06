<?php

namespace App\Filament\Widgets\Executive;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ResellerResource;
use App\Filament\Resources\ServerPanelResource;
use App\Filament\Resources\TicketResource;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Admin\Dashboard\ExecutiveAlert;
use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use Filament\Widgets\Widget;

/**
 * B7.1 — «نیازمند توجه»: سفارش‌های بدون سرویس، سرور از کار افتاده/کند/پر، رسیدهای در انتظار،
 * نمایندگان بدون اعتبار، تیکت‌های بدون پاسخ. از وضعیت زنده می‌آید (ذخیره نمی‌شود)؛ با رفع مشکل ناپدید می‌شود.
 */
class AttentionAlerts extends Widget
{
    protected static string $view = 'filament.widgets.executive.attention-alerts';

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $service = app(ExecutiveDashboardService::class);

        $alerts = collect($service->alerts($service->position()))
            ->map(fn (ExecutiveAlert $alert) => [
                'alert' => $alert,
                'url' => $this->urlFor($alert),
            ])
            ->all();

        return ['alerts' => $alerts];
    }

    /** Core فقط «هدف» می‌دهد؛ ساخت URL وظیفه‌ی Channel است. */
    private function urlFor(ExecutiveAlert $alert): ?string
    {
        return match ($alert->target) {
            ExecutiveAlert::TARGET_ATTENTION_ORDERS => OrderResource::getUrl('index', [
                'tableFilters' => ['status' => ['value' => Order::STATUS_PROVISION_FAILED]],
            ]),
            ExecutiveAlert::TARGET_PAYMENTS => PaymentResource::getUrl('index', [
                'tableFilters' => ['status' => ['value' => 'pending']],
            ]),
            ExecutiveAlert::TARGET_TICKETS => TicketResource::getUrl('index', [
                'tableFilters' => ['status' => ['value' => Ticket::STATUS_OPEN]],
            ]),
            ExecutiveAlert::TARGET_SERVERS => ServerPanelResource::getUrl('index'),
            ExecutiveAlert::TARGET_RESELLERS => ResellerResource::getUrl('index'),
            default => null,
        };
    }
}
