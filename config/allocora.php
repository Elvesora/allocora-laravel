<?php

declare(strict_types=1);

return [
    'api_key' => env('ALLOCORA_API_KEY'),
    'base_url' => env('ALLOCORA_BASE_URL', 'https://www.allocora.com/api/v1'),
    'timeout' => (float) env('ALLOCORA_TIMEOUT', 30),
    'connect_timeout' => (float) env('ALLOCORA_CONNECT_TIMEOUT', 5),
    'max_response_bytes' => (int) env('ALLOCORA_MAX_RESPONSE_BYTES', 10_485_760),
];
