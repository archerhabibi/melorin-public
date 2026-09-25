<?php

use App\Channels\ResellerBot\ResellerBotServiceProvider;
use App\Channels\TelegramBot\TelegramBotServiceProvider;
use App\Channels\Website\WebsiteServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ResellerPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ResellerPanelProvider::class,
    CoreServiceProvider::class,
    TelegramBotServiceProvider::class,
    ResellerBotServiceProvider::class,
    WebsiteServiceProvider::class,
];
