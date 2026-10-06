<?php

namespace App\Filament\Reseller\Resources\CommissionResource\Pages;

use App\Filament\Reseller\Resources\CommissionResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewCommission extends ViewRecord
{
    protected static string $resource = CommissionResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'کمیسیون سفارش #'.$this->record->order_id;
    }
}
