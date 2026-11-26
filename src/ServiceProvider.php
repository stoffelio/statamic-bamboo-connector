<?php

namespace Stoffelio\BambooConnector;

use Statamic\Events\SubmissionFinalized;
use Statamic\Providers\AddonServiceProvider;
use Stoffelio\BambooConnector\Listeners\SendFirstStep;

class ServiceProvider extends AddonServiceProvider
{
    protected $formConnectors = [
        BambooConnector::class,
    ];

    protected $listen = [
        SubmissionFinalized::class => [SendFirstStep::class],
    ];

    protected $viewNamespace = 'bamboo-connector';

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

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/bamboo-connector'),
        ], 'bamboo-views');
    }
}
