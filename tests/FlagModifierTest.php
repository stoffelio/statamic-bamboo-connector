<?php

namespace Stoffelio\BambooConnector\Tests;

use PHPUnit\Framework\Attributes\Test;
use Stoffelio\BambooConnector\Modifiers\Flag;

class FlagModifierTest extends TestCase
{
    #[Test]
    public function it_turns_a_country_code_into_its_flag()
    {
        $this->assertSame('🇹🇭', $this->render('TH'));
        $this->assertSame('🇬🇧', $this->render('gb'));
        $this->assertSame('🇺🇸', $this->render('US'));
    }

    #[Test]
    public function it_gives_nothing_for_anything_that_is_not_a_country_code()
    {
        foreach (['', 'THA', 'x', '66', 'T1'] as $input) {
            $this->assertSame('', $this->render($input), $input.' is not a country code');
        }
    }

    private function render(string $value): string
    {
        return (new Flag)->index($value, [], []);
    }
}
