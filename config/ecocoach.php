<?php

// Eco-Coach (penjelas data berbasis LLM). API key HANYA di server — jangan taruh di jejak-wangi-app.
// Kosongkan LLM_PROVIDER untuk memakai template cadangan saja (tanpa AI, tanpa internet).
return [
    // anthropic | openai (openai = endpoint apa pun yang kompatibel format OpenAI)
    'provider' => env('LLM_PROVIDER'),
    'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
    'openai_api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('LLM_BASE_URL'),
    'model' => env('LLM_MODEL'),
    'timeout_ms' => (int) env('LLM_TIMEOUT_MS', 15000),
];
