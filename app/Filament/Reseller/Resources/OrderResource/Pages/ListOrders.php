<?php

namespace App\Filament\Reseller\Resources\OrderResource\Pages;

use App\Filament\Reseller\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
