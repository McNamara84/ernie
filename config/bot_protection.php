<?php

declare(strict_types=1);

$defaultAiUserAgents = implode(',', [
    'Amazonbot',
    'anthropic-ai',
    'Applebot-Extended',
    'Bytespider',
    'CCBot',
    'ChatGPT-User',
    'ClaudeBot',
    'Claude-User',
    'cohere-ai',
    'Diffbot',
    'FacebookBot',
    'Google-Extended',
    'GPTBot',
    'ImagesiftBot',
    'Meta-ExternalAgent',
    'omgili',
    'PerplexityBot',
    'YouBot',
]);

return [
    'enabled' => (bool) env('BOT_PROTECTION_ENABLED', env('APP_ENV') !== 'testing'),

    'ai_user_agents' => array_values(array_filter(array_map(
        static fn (string $userAgent): string => trim($userAgent),
        explode(',', (string) env('BOT_PROTECTION_AI_USER_AGENTS', $defaultAiUserAgents)),
    ))),

    'limits' => [
        'ai_bot_public_per_minute' => (int) env('BOT_PROTECTION_AI_BOT_PUBLIC_PER_MINUTE', 6),
        'public_landing_per_minute' => (int) env('BOT_PROTECTION_PUBLIC_LANDING_PER_MINUTE', 60),
        'public_landing_jsonld_per_minute' => (int) env('BOT_PROTECTION_PUBLIC_LANDING_JSONLD_PER_MINUTE', 30),
        'public_portal_per_minute' => (int) env('BOT_PROTECTION_PUBLIC_PORTAL_PER_MINUTE', 20),
    ],

    'landing_cache_ttl' => (int) env('BOT_PROTECTION_LANDING_CACHE_TTL', 600),

    'portal_cache_ttl' => (int) env('BOT_PROTECTION_PORTAL_CACHE_TTL', 120),

    'view_count_debounce_seconds' => (int) env('BOT_PROTECTION_VIEW_COUNT_DEBOUNCE_SECONDS', 3600),
];