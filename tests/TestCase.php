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
