<?php

namespace App\Filament\Reseller\Resources\CampaignResource\Pages;

use App\Filament\Reseller\Resources\CampaignResource;
use App\Filament\Reseller\Resources\CampaignResource\Widgets\CampaignSummary;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'سابقه‌ی کمپین‌های پیام';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'نتیجه‌ی پیام‌های همگانی شما. پیام جدید را از «پیام همگانی» بفرستید؛ این صفحه فقط مشاهده است.';
    }

    protected function getHeaderWidgets(): array
    {
        return [CampaignSummary::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
