<?php

namespace App\Filament\Reseller\Resources\CommissionResource\Pages;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CommissionResource;
use App\Filament\Reseller\Resources\CommissionResource\Widgets\CommissionSummary;
use App\Filament\Reseller\Resources\CommissionResource\Widgets\TopReferrers;
use App\Services\Resellers\Commissions\ResellerCommissionCenter;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListCommissions extends ListRecords
{
    use ResolvesCurrentReseller;

    protected static string $resource = CommissionResource::class;

    /** شرایط فعلیِ سراسری (فقط‌خواندنی): نماینده می‌بیند چه نرخی روی خریدهای آینده اعمال می‌شود. */
    public function getSubheading(): string|Htmlable|null
    {
        $terms = app(ResellerCommissionCenter::class)->terms();

        if (! $terms->commissionEnabled()) {
            return 'کمیسیون خرید زیرمجموعه فعلاً توسط مدیریت Melorin غیرفعال است.';
        }

        return 'نرخ فعلی کمیسیون: '.$terms->percentLabel().'٪ از مبلغ هر خرید زیرمجموعه'
            .($terms->validityDays > 0 ? ' · تا '.number_format($terms->validityDays).' روز پس از عضویت' : '')
            .' — این نرخ را مدیریت Melorin تعیین می‌کند و فقط روی خریدهای جدید اثر دارد؛ رکوردهای قبلی با نرخ زمان خرید ثبت شده‌اند.';
    }

    protected function getHeaderWidgets(): array
    {
        return [CommissionSummary::class, TopReferrers::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
