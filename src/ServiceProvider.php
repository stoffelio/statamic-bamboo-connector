<?php

namespace Stoffelio\BambooConnector;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $formConnectors = [
        BambooConnector::class,
    ];

    protected $vite = [
        'input' => ['resources/js/cp.js'],
        'publicDirectory' => 'resources/dist',
    ];

    public function bootAddon()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bamboo.php', 'bamboo');

        $this->publishes([
            __DIR__.'/../config/bamboo.php' => config_path('bamboo.php'),
        ], 'bamboo-config');
    }
}
