<?php

return [
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    // One API key per environment; each key has its own webhook secret.
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Reject webhooks whose signed timestamp is older than this many seconds.
    'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    // Markup applied on top of Pixelforge's cost, as a percentage.
    'markup_percent' => (int) env('PIXELFORGE_MARKUP_PERCENT', 25),
];
