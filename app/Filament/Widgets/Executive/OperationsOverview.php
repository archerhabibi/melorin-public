<?php

namespace App\Filament\Widgets\Executive;

use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B7.1 — وضعیت لحظه‌ای پلتفرم (مستقل از بازه): سرویس‌ها، کاربران، رسیدهای در انتظار، سرورها و تعهدات مالی.
 */
class OperationsOverview extends BaseWidget
{
    protected ?string $heading = 'وضعیت لحظه‌ای';

    protected static ?int $sort = 25;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $service = app(ExecutiveDashboardService::class);
        $position = $service->position();
        $resellerQueue = $service->resellerQueuePendingPayments();

        $capacity = $position->capacityUsagePercent();
        $serverNotes = collect([
            $position->serversDown > 0 ? $position->serversDown.' از کار افتاده' : null,
            $position->serversDegraded > 0 ? $position->serversDegraded.' کند' : null,
            $position->serversFull > 0 ? $position->serversFull.' پر' : null,
            $capacity !== null ? 'مصرف ظرفیت '.$capacity.'٪' : null,
        ])->filter()->implode(' · ');

        $paymentNote = Money::format($position->pendingPaymentsAmount)
            .($resellerQueue > 0 ? ' · '.$resellerQueue.' رسید دیگر در صف نمایندگان' : '');

        return [
            Stat::make('سرویس‌های فعال', number_format($position->activeServices))
                ->description(number_format($position->expiringServices).' مورد تا '.ExecutiveDashboardService::EXPIRING_DAYS.' روز آینده منقضی می‌شود')
                ->color('primary'),
            Stat::make('کاربران فعال', number_format($position->activeUsers))
                ->description(number_format($position->activeResellers).' نماینده‌ی فعال')
                ->color('primary'),
            Stat::make('رسیدهای در انتظار', number_format($position->pendingPayments))
                ->description($paymentNote)
                ->color($position->stalePayments > 0 ? 'danger' : ($position->pendingPayments > 0 ? 'warning' : 'gray')),
            Stat::make('سرورهای فعال', number_format($position->activeServers))
                ->description($serverNotes !== '' ? $serverNotes : 'ظرفیتی تعریف نشده')
                ->color($position->serversDown > 0 ? 'danger' : ($position->serversDegraded > 0 || $position->serversFull > 0 ? 'warning' : 'success')),
            Stat::make('پیش‌پرداخت نزد پلتفرم', Money::format($position->prepaidBalance))
                ->description('جمع موجودی مثبت کیف‌پول‌های اصلی')
                ->color('gray'),
            Stat::make('بدهی نمایندگان', Money::format($position->resellerDebt))
                ->description($position->resellersOutOfCredit > 0
                    ? number_format($position->resellersOutOfCredit).' نماینده بدون اعتبار'
                    : 'هیچ نماینده‌ای بدون اعتبار نیست')
                ->color($position->resellersOutOfCredit > 0 ? 'warning' : ($position->resellerDebt > 0 ? 'gray' : 'success')),
        ];
    }
}
