<?php

namespace App\Filament\Widgets\Finance;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ResellerResource;
use App\Models\Order;
use App\Services\Admin\Dashboard\ExecutiveAlert;
use App\Services\Admin\Finance\GlobalFinanceService;
use Filament\Widgets\Widget;

/**
 * B7.3 — «سلامت مالی»: ناترازی دفتر کیف‌پول‌ها، کیف‌پول منفی مشتری، نماینده‌ی بالای سقف بدهی،
 * پول گرفته‌شده‌ی بدون سرویس و رسیدهای کهنه. از وضعیت زنده می‌آید (ذخیره نمی‌شود).
 */
class FinanceHealth extends Widget
{
    protected static string $view = 'filament.widgets.finance.finance-health';

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $service = app(GlobalFinanceService::class);

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
            ExecutiveAlert::TARGET_RESELLERS => ResellerResource::getUrl('index'),
            default => null,
        };
    }
}
