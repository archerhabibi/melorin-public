<?php

namespace App\Filament\Reseller\Resources\CustomerResource\Pages;

use App\Filament\Reseller\Resources\CustomerResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->full_name ?: ($this->record->email ?: 'مشتری #'.$this->record->id);
    }
}
