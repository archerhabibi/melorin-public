<?php

namespace App\Filament\Reseller\Resources\ProductResource\Pages;

use App\Filament\Reseller\Resources\ProductResource;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;
}
