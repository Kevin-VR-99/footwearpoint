<?php

namespace App\Providers;

use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Notificacion\Push\EnviadorPushFirebase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnviadorPush::class, EnviadorPushFirebase::class);
    }

    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}