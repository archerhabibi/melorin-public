<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Admin\Finance\GlobalFinanceService;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B7.3 — وضعیت مالی لحظه‌ای (مستقل از بازه): تعهد به مشتریان، اعتبار و مطالبات نمایندگان، خالص تعهد،
 * کیف‌پول فروشگاه‌های نمایندگان، رسیدهای در انتظار، پول گرفته‌شده‌ی تحویل‌نشده و تراز دفتر.
 */
class BalanceSheet extends BaseWidget
{
    protected ?string $heading = 'وضعیت مالی لحظه‌ای';

    /**
     * Avoid dispatching Filament's internal __lazyLoad hook to the parent
     * page when running older Livewire/Filament combinations.
     */
    protected static bool $isLazy = false;

    protected static ?int $sort = 40;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $p = app(GlobalFinanceService::class)->position();

        $debtNote = collect([
            $p->resellersOverLimit > 0 ? number_format($p->resellersOverLimit).' بالای سقف' : null,
            $p->resellersOutOfCredit > 0 ? number_format($p->resellersOutOfCredit).' بدون اعتبار' : null,
        ])->filter()->implode(' · ');

        return [
            Stat::make('موجودی کیف‌پول مشتریان', Money::format($p->customerBalances))
                ->description(number_format($p->customerWallets).' کیف‌پول دارای موجودی · تعهد پلتفرم')
                ->color('gray'),
            Stat::make('اعتبار پیش‌پرداخت نمایندگان', Money::format($p->resellerCredit))
                ->description('موجودی مثبت کیف‌پول اصلی صاحبان نماینده')
                ->color('gray'),
            Stat::make('مطالبات از نمایندگان', Money::format($p->resellerDebt))
                ->description($debtNote !== '' ? $debtNote : 'هیچ نماینده‌ای بالای سقف یا بدون اعتبار نیست')
                ->color($p->resellersOverLimit > 0 ? 'danger' : ($p->resellersOutOfCredit > 0 ? 'warning' : 'gray')),
            Stat::make('خالص تعهد پلتفرم', Money::format($p->netObligation()))
                ->description('موجودی مشتریان + اعتبار نمایندگان − مطالبات')
                ->color('primary'),
            Stat::make('کیف‌پول فروشگاه‌های نمایندگان', Money::format($p->resellerStoreBalances))
                ->description('تعهد خود نمایندگان به مشتریانشان، نه پلتفرم')
                ->color('gray'),
            Stat::make('رسیدهای در انتظار', Money::format($p->pendingReceiptsAmount))
                ->description(number_format($p->pendingReceipts).' رسید در صف پلتفرم'.($p->staleReceipts > 0 ? ' · '.number_format($p->staleReceipts).' کهنه' : ''))
                ->color($p->staleReceipts > 0 ? 'danger' : ($p->pendingReceipts > 0 ? 'warning' : 'gray')),
            Stat::make('پول گرفته‌شده، سرویس تحویل‌نشده', Money::format($p->undeliveredAmount()))
                ->description(number_format($p->failedDeliveries).' ناموفق در ساخت · '.number_format($p->inDelivery).' در حال تحویل')
                ->color($p->failedDeliveries > 0 ? 'warning' : 'gray'),
            Stat::make('تراز دفتر کیف‌پول‌ها', $p->ledgerBalanced() ? 'تراز' : number_format($p->unbalancedWallets).' ناتراز')
                ->description($p->ledgerBalanced()
                    ? 'موجودی همه‌ی کیف‌پول‌ها = مجموع گردش'
                    : 'اختلاف خالص '.Money::format($p->ledgerDrift))
                ->color($p->ledgerBalanced() ? 'success' : 'danger'),
        ];
    }
}
