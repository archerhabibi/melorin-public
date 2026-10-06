<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Widgets\GlobalCustomerSummary;
use App\Services\Admin\Customers\GlobalCustomerDirectory;
use App\Services\Admin\Customers\GlobalCustomerSegment;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderWidgets(): array
    {
        return [GlobalCustomerSummary::class];
    }

    /**
     * تب‌های سریع بخش‌بندی (B7.2). تعریف هر بخش و شمارنده‌اش فقط در Core است (همان فیلتر «بخش مشتریان»)؛
     * شمارنده‌ها از `summary()` می‌آیند که در یک Request یک بار محاسبه می‌شود.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $directory = app(GlobalCustomerDirectory::class);
        $summary = $directory->summary();

        $tabs = ['all' => Tab::make('همه')->badge(number_format($summary->total))];

        foreach (GlobalCustomerSegment::cases() as $segment) {
            $tabs[$segment->value] = Tab::make($segment->shortLabel())
                ->badge(number_format($summary->countFor($segment)))
                ->modifyQueryUsing(fn (Builder $query): Builder => $directory->applySegment($query, $segment));
        }

        return $tabs;
    }
}
