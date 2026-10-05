<?php

namespace App\Filament\Reseller\Resources\ProductResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Services\Resellers\Products\ResellerProductCatalog;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B5.3 — خلاصه‌ی بالای فهرست محصولات. عددها از همان سرویس و همان تعریف وضعیتی می‌آیند که فیلتر جدول دارد.
 * (در پوشه‌ی Resource است، نه Widgets/ پنل، تا روی داشبورد دیده نشود.)
 */
class ProductSummary extends BaseWidget
{
    use ResolvesCurrentReseller;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $s = app(ResellerProductCatalog::class)->summary(static::currentReseller());

        return [
            Stat::make('محصولات در دسترس', number_format($s->total))
                ->description($s->blocked > 0 ? number_format($s->blocked).' محصول به‌علت سبد/نمایندگی بسته، قابل فروش نیست' : 'همه‌ی سبدها باز است')
                ->color($s->blocked > 0 ? 'danger' : 'primary'),
            Stat::make('در حال فروش', number_format($s->selling))
                ->description($s->total > 0 ? number_format((int) round($s->selling * 100 / $s->total)).'٪ از محصولات' : '—')
                ->color('success'),
            Stat::make('نیازمند اقدام', number_format($s->unpriced + $s->paused))
                ->description(number_format($s->unpriced).' بدون قیمت · '.number_format($s->paused).' غیرفعال')
                ->color($s->unpriced + $s->paused > 0 ? 'warning' : 'gray'),
            Stat::make('میانگین سود هر فروش', $s->averageProfit === null ? '—' : Money::format($s->averageProfit))
                ->description($s->zeroProfit > 0 ? number_format($s->zeroProfit).' محصول با سود صفر' : 'روی محصولات در حال فروش')
                ->color($s->zeroProfit > 0 ? 'warning' : 'gray'),
        ];
    }
}
