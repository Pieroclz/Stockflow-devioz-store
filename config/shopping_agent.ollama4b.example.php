<?php
// Copia este archivo como config/shopping_agent.local.php para activar Ollama 4B.
return [
    'provider' => 'ollama',
    'model' => 'qwen3:4b',
    'ollama_url' => 'http://127.0.0.1:11434',
    'ollama_token' => '',
    'timeout' => 25,
    'daily_requests' => 100,
];
// En hosting, localhost es EL HOSTING, no tu Mac. Usa un servidor propio con
// Ollama y proxy HTTPS autenticado; configura su URL y token aquí.
