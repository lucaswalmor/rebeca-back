<?php

return [

    'base_url' => env('EVOLUTION_BASE_URL', ''),

    'api_key' => env('EVOLUTION_API_KEY', ''),

    'webhook_base_url' => env('EVOLUTION_WEBHOOK_BASE_URL', env('APP_URL')),

    'timeout' => (int) env('EVOLUTION_TIMEOUT', 30),

];
