<?php

namespace App\Channels\ResellerBot;

use Illuminate\Support\ServiceProvider;

class ResellerBotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/reseller-bot.php'));
    }
}
