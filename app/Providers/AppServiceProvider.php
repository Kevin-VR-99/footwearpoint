<?php

namespace App\Providers;

use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Notificacion\Push\EnviadorPushFirebase;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Push por Firebase (TG-135). Las pruebas lo reemplazan por uno falso.
        $this->app->bind(EnviadorPush::class, EnviadorPushFirebase::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
