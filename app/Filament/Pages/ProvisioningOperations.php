<?php

namespace App\Filament\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\ServerPanelResource;
use App\Models\Order;
use App\Models\ServerPanel;
use App\Services\Core\Panels\PanelDriverFactory;
use App\Services\Core\Panels\SupportsServerStatus;
use App\Services\Core\Provisioning\FailedOrderRecovery;
use App\Services\Core\Provisioning\RecoveryResult;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class ProvisioningOperations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'زیرساخت';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.provisioning-operations';

    protected static ?string $title = 'عملیات Provisioning';

    protected static ?string $navigationLabel = 'عملیات Provisioning';

    public function getPanels(): Collection
    {
        return ServerPanel::query()
            ->withCount(['accounts as active_accounts_count' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get();
    }

    public function getQueue(): Collection
    {
        return Order::query()
            ->with(['user:id,full_name,email', 'product:id,name'])
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_PAID,
                Order::STATUS_PROVISIONING,
                Order::STATUS_PROVISION_FAILED,
            ])
            ->where(function ($query) {
                $query->whereIn('status', [Order::STATUS_PROVISIONING, Order::STATUS_PROVISION_FAILED])
                    ->orWhere(function ($query) {
                        $query->where('status', Order::STATUS_PAID)
                            ->where(function ($query) {
                                $query->whereNull('next_provision_retry_at')
                                    ->orWhere('next_provision_retry_at', '<=', now());
                            });
                    });
            })
            ->orderByRaw("case status when 'provision_failed' then 0 when 'provisioning' then 1 when 'paid' then 2 else 3 end")
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();
    }

    public function getNodeStatus(ServerPanel $panel): array
    {
        try {
            $driver = PanelDriverFactory::make($panel->panel_type);

            if (! $driver instanceof SupportsServerStatus) {
                return ['state' => 'unsupported', 'label' => 'پشتیبانی نمی‌شود'];
            }

            return ['state' => 'healthy', 'label' => 'سالم', 'details' => $driver->getServerStatus($panel)];
        } catch (Throwable $exception) {
            return ['state' => 'down', 'label' => 'خطا', 'details' => ['error' => $exception->getMessage()]];
        }
    }

    public function retry(int $orderId): void
    {
        $order = Order::query()->findOrFail($orderId);

        if ($order->status !== Order::STATUS_PROVISION_FAILED) {
            Notification::make()->title('سفارش دیگر در صف retry نیست')->warning()->send();

            return;
        }

        try {
            $result = app(FailedOrderRecovery::class)->retry($order, force: true);
            $notification = Notification::make()->title('تلاش مجدد Provisioning: '.$result->message);

            $result->status === RecoveryResult::SUCCEEDED ? $notification->success() : $notification->danger();
            $notification->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('تلاش مجدد ناموفق بود')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function panelUrl(ServerPanel $panel): string
    {
        return ServerPanelResource::getUrl('edit', ['record' => $panel]);
    }

    public static function orderUrl(Order $order): string
    {
        return OrderResource::getUrl('view', ['record' => $order]);
    }

    public static function statusLabel(string $status): string
    {
        return Order::statusLabels()[$status] ?? $status;
    }
}
