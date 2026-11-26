<?php

namespace Stoffelio\BambooConnector\Modifiers;

use Statamic\Modifiers\Modifier;

/**
 * The flag for an ISO 3166-1 alpha-2 code.
 *
 * Derived rather than looked up: a flag emoji is just its two letters written as regional
 * indicator symbols, so there is no table to keep in step with the country list.
 */
class Flag extends Modifier
{
    public function index($value, $params, $context)
    {
        $code = strtoupper(trim((string) $value));

        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }

        return collect(str_split($code))
            ->map(fn (string $letter) => mb_chr(0x1F1E6 + ord($letter) - ord('A'), 'UTF-8'))
            ->implode('');
    }
}
