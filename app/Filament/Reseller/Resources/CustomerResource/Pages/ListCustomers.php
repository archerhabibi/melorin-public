<?php

namespace App\Filament\Reseller\Resources\CustomerResource\Pages;

use App\Filament\Reseller\Resources\CustomerResource;
use App\Filament\Reseller\Resources\CustomerResource\Widgets\CustomerSummary;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderWidgets(): array
    {
        return [CustomerSummary::class];
    }
}
