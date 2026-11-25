<?php

namespace Stoffelio\BambooConnector\Tests;

use PHPUnit\Framework\Attributes\Test;

class ControlPanelAssetTest extends TestCase
{
    /**
     * Laravel's Vite helper reads the manifest from the build directory itself, while Vite
     * writes it to `.vite/` unless it is given a filename — and a manifest in the wrong
     * place only shows up as a 500 on the Connect screen.
     */
    #[Test]
    public function the_built_manifest_is_where_laravel_looks_for_it()
    {
        $this->assertFileExists(__DIR__.'/../resources/dist/build/manifest.json');

        $manifest = json_decode(file_get_contents(__DIR__.'/../resources/dist/build/manifest.json'), true);

        $this->assertArrayHasKey('resources/js/cp.js', $manifest);
        $this->assertFileExists(__DIR__.'/../resources/dist/build/'.$manifest['resources/js/cp.js']['file']);
    }
}
