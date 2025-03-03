<?php

namespace LaravelEnso\LockableModels;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lockableModels.php', 'enso.lockableModels');

        $this->publishes([
            __DIR__.'/../config' => config_path('enso'),
        ], ['lockable-models-config', 'enso-config']);
    }
}
