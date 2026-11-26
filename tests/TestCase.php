<?php

namespace Stoffelio\BambooConnector\Tests;

use Statamic\Facades\Form;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Stoffelio\BambooConnector\ServiceProvider;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Every Statamic site has a layout and the follow-up page renders inside it, so the
     * tests need one too — otherwise they would prove the page renders somewhere no site
     * actually looks like.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $layout = resource_path('views/layout.antlers.html');

        @mkdir(dirname($layout), recursive: true);
        file_put_contents($layout, '<html><head><title>{{ title }}</title></head>'
            .'<body><header>Site chrome</header>{{ template_content }}</body></html>');
    }

    protected function form(array $fields, string $handle = 'booking')
    {
        return tap(Form::make($handle)->title('Booking')->formFields([
            'sections' => [['fields' => $fields]],
        ]))->save();
    }

    protected function field(string $handle, array $config)
    {
        return ['handle' => $handle, 'field' => $config];
    }

    protected function submission(array $fields, array $data, string $handle = 'booking')
    {
        return tap($this->form($fields, $handle)->makeSubmission()->data($data))->save();
    }
}
