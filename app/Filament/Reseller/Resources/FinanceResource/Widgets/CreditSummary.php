<?php

namespace App\Filament\Reseller\Resources\FinanceResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Services\Resellers\Finance\ResellerFinanceCenter;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** B5.5 — وضعیت لحظه‌ای اعتبار تأمین (مستقل از فیلترهای جدول). */
class CreditSummary extends BaseWidget
{
    use ResolvesCurrentReseller;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $c = app(ResellerFinanceCenter::class)->credit(static::currentReseller());

        return [
            Stat::make('اعتبار فعلی', Money::format($c->balance))
                ->description($c->isInDebt() ? 'بدهی فعلی: '.Money::format($c->debt()) : ($c->hasWallet ? 'موجودی کیف‌پول صاحب' : 'هنوز شارژ نشده'))
                ->color($c->isInDebt() ? 'warning' : 'success'),
            Stat::make('قدرت خرید', Money::format($c->purchasingPower()))
                ->description($c->isOutOfCredit() ? 'اعتبار تمام شده؛ فروش جدید رد می‌شود' : 'اعتبار + سقف بدهی')
                ->color($c->isOutOfCredit() ? 'danger' : 'primary'),
            Stat::make('سقف بدهی مجاز', Money::format($c->debtLimit))
                ->description($c->debtLimit > 0 ? 'تعیین‌شده توسط مدیریت Melorin' : 'بدهی مجاز نیست')
                ->color('gray'),
            Stat::make('شارژ در انتظار تأیید', Money::format($c->pendingChargeAmount))
                ->description(number_format($c->pendingChargeCount).' درخواست · تأیید با مدیریت Melorin')
                ->color($c->pendingChargeCount > 0 ? 'warning' : 'gray'),
        ];
    }
}
