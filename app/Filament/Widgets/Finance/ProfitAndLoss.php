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
 * B7.3 — سود و زیان بازه (از سفارش‌های فروش قطعی): فروش، درآمد پلتفرم، سود نمایندگان، هزینه‌ی معرفی،
 * سود خالص تخمینی و بازگشت وجه سفارش‌ها. همه‌ی منطق در GlobalFinanceService است؛ این‌جا فقط نمایش.
 */
class ProfitAndLoss extends BaseWidget
{
    use FormatsTrendStats;
    use InteractsWithPageFilters;

    protected ?string $heading = 'سود و زیان';

    protected static ?int $sort = 20;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $period = DashboardPeriod::fromInput($this->filters['period'] ?? null);
        $summary = app(GlobalFinanceService::class)->summary($period);
        $c = $summary->current;

        $margin = $c->platformNetMarginPercent();
        $share = $c->supplySharePercent();

        return [
            $this->trendStat('فروش', Money::format($c->sales), $summary->salesChange(), $period, number_format($c->orders).' سفارش قطعی')
                ->color('success'),
            $this->trendStat(
                'درآمد پلتفرم',
                Money::format($c->platformRevenue),
                $summary->platformRevenueChange(),
                $period,
                'مستقیم '.Money::format($c->directRevenue).' · تأمین نمایندگی '.Money::format($c->supplyRevenue()).($share !== null ? ' ('.$share.'٪)' : ''),
            )->color('success'),
            Stat::make('سود نمایندگان ('.$period->label().')', Money::format($c->resellerMargin()))
                ->description('فروش − درآمد پلتفرم؛ درآمد ما نیست')
                ->color('gray'),
            $this->trendStat(
                'هزینه‌ی معرفی',
                Money::format($c->referralCost()),
                $summary->referralCostChange(),
                $period,
                'کمیسیون '.Money::format($c->commissions).' · پاداش '.Money::format($c->referralBonuses),
            )->color($c->referralCost() > 0 ? 'warning' : 'gray'),
            $this->trendStat(
                'سود خالص تخمینی پلتفرم',
                Money::format($c->platformNet()),
                $summary->platformNetChange(),
                $period,
                $margin !== null ? 'حاشیه '.$margin.'٪ از درآمد پلتفرم' : null,
            )->color($c->platformNet() < 0 ? 'danger' : 'success'),
            Stat::make('بازگشت وجه سفارش‌ها ('.$period->label().')', Money::format($c->orderRefunds))
                ->description(number_format($c->refundedOrders).' سفارش · سهم پلتفرم؛ این سفارش‌ها در فروش قطعی نیستند')
                ->color($c->orderRefunds > 0 ? 'warning' : 'gray'),
        ];
    }
}
