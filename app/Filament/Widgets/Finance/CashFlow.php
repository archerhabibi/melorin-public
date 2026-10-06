<?php

namespace App\Filament\Widgets\Finance;

use App\Filament\Widgets\Finance\Concerns\FormatsTrendStats;
use App\Services\Admin\Finance\GlobalFinanceService;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B7.3 — جریان نقد بازه (از Ledger کیف‌پول‌ها، بر پایه‌ی زمان تراکنش): ورودی پلتفرم، بازگشت پرداخت، خالص،
 * اصلاح دستی مدیر و شارژ فروشگاه‌های نمایندگان (جدا؛ پول پلتفرم نیست).
 */
class CashFlow extends BaseWidget
{
    use FormatsTrendStats;
    use InteractsWithPageFilters;

    protected ?string $heading = 'جریان نقد';

    protected static ?int $sort = 30;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $period = DashboardPeriod::fromInput($this->filters['period'] ?? null);
        $summary = app(GlobalFinanceService::class)->summary($period);
        $c = $summary->current;

        $manual = $c->manualAdjustments;

        return [
            $this->trendStat('ورودی نقد پلتفرم', Money::format($c->cashIn), $summary->cashInChange(), $period, number_format($c->cashInCount).' شارژ تأییدشده')
                ->color('success'),
            Stat::make('بازگشت پرداخت ('.$period->label().')', Money::format($c->paymentRefunds))
                ->description('پول شارژ که به پرداخت‌کننده برگشت')
                ->color($c->paymentRefunds > 0 ? 'warning' : 'gray'),
            $this->trendStat('خالص ورودی نقد', Money::format($c->netCashIn()), $summary->netCashInChange(), $period)
                ->color($c->netCashIn() < 0 ? 'danger' : 'success'),
            Stat::make('میانگین هر شارژ ('.$period->label().')', Money::format($c->averageCharge()))
                ->description('ورودی نقد ÷ تعداد شارژ')
                ->color('gray'),
            Stat::make('اصلاح دستی مدیر ('.$period->label().')', ($manual > 0 ? '+' : '').Money::format($manual))
                ->description('خالص افزایش/کاهش دستی موجودی‌ها؛ نه درآمد است نه هزینه')
                ->color($manual === 0 ? 'gray' : 'warning'),
            Stat::make('شارژ فروشگاه‌های نمایندگان ('.$period->label().')', Money::format($c->resellerStoreCashIn))
                ->description('کیف‌پول مشتریان نمایندگان (صف نمایندگان)؛ در ورودی پلتفرم نیست')
                ->color('gray'),
        ];
    }
}
