<?php

namespace App\Filament\Reseller\Resources\ReferralResource\Pages;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\ReferralResource;
use App\Filament\Reseller\Resources\ReferralResource\Widgets\ReferralSummary;
use App\Filament\Reseller\Resources\ReferralResource\Widgets\TopInviters;
use App\Services\Resellers\Commissions\ResellerCommissionCenter;
use App\Support\Money;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListReferrals extends ListRecords
{
    use ResolvesCurrentReseller;

    protected static string $resource = ReferralResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'معرفی مشتریان';
    }

    /** شرایط فعلیِ برنامه‌ی معرفی (سراسری، فقط‌خواندنی): پاداش ثابت و کمیسیون را مدیریت Melorin تعیین می‌کند. */
    public function getSubheading(): string|Htmlable|null
    {
        $t = app(ResellerCommissionCenter::class)->terms();
        $parts = [];

        if ($t->referrerBonus > 0 || $t->customerBonus > 0) {
            $parts[] = 'پاداش اولین خرید: معرف '.Money::format($t->referrerBonus).' · مشتری '.Money::format($t->customerBonus);
        }

        if ($t->commissionEnabled()) {
            $parts[] = 'کمیسیون هر خرید: '.$t->percentLabel().'٪';
        }

        return $parts === []
            ? 'برنامه‌ی پاداش و کمیسیون معرفی فعلاً توسط مدیریت Melorin غیرفعال است؛ معرفی‌ها همچنان ثبت می‌شوند.'
            : implode(' — ', $parts).' (تعیین با مدیریت Melorin؛ فقط روی خریدهای آینده اثر دارد)';
    }

    protected function getHeaderWidgets(): array
    {
        return [ReferralSummary::class, TopInviters::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
