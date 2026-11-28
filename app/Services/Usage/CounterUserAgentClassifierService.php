<?php

declare(strict_types=1);

namespace App\Services\Usage;

use RuntimeException;

final class CounterUserAgentClassifierService
{
    /** @param array{robots: list<string>, machines: list<string>} $rules */
    public function classify(?string $userAgent, array $rules): string
    {
        $agent = trim((string) $userAgent);
        $agent = $agent === '' ? 'no_user_agent' : $agent;
        foreach (['robots' => 'Robot', 'machines' => 'Machine'] as $list => $classification) {
            foreach ($rules[$list] as $pattern) {
                $matched = preg_match(CounterListService::regex($pattern), $agent);
                if ($matched === false) {
                    throw new RuntimeException('COUNTER classification failed.');
                }
                if ($matched === 1) {
                    return $classification;
                }
            }
        }

        return 'Regular';
    }
}
