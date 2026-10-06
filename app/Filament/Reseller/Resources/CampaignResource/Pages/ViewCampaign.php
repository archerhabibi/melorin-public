<?php

namespace App\Filament\Reseller\Resources\CampaignResource\Pages;

use App\Filament\Reseller\Resources\CampaignResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'کمپین پیام #'.$this->record->id;
    }
}
