<?php

declare(strict_types=1);

namespace App\Services\BotProtection;

use Illuminate\Http\Request;

class BotClassifierService
{
    public function isKnownAiBot(Request|string|null $requestOrUserAgent): bool
    {
        if (! (bool) config('bot_protection.enabled', true)) {
            return false;
        }

        $userAgent = $requestOrUserAgent instanceof Request
            ? $requestOrUserAgent->userAgent()
            : $requestOrUserAgent;

        if ($userAgent === null || trim($userAgent) === '') {
            return false;
        }

        $normalizedUserAgent = strtolower($userAgent);

        foreach ($this->aiUserAgentNeedles() as $needle) {
            if (str_contains($normalizedUserAgent, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    public function rateLimitKey(Request $request, string $surface): string
    {
        $classification = $this->isKnownAiBot($request) ? 'ai-bot' : 'public';
        $ipAddress = (string) $request->ip();

        return "{$surface}:{$classification}:{$ipAddress}";
    }

    /**
     * @return list<string>
     */
    private function aiUserAgentNeedles(): array
    {
        $configuredNeedles = config('bot_protection.ai_user_agents', []);

        if (! is_array($configuredNeedles)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $needle): string => is_string($needle) ? trim($needle) : '',
                $configuredNeedles,
            ),
            static fn (string $needle): bool => $needle !== '',
        ));
    }
}
