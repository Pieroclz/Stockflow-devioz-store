<?php
// Configuración pública sin secretos. En hosting configura variables de entorno
// o copia shopping_agent.local.example.php a shopping_agent.local.php.
$config = [
    'provider' => getenv('SHOPPING_AI_PROVIDER') ?: 'ollama', // ollama local; reglas si no responde
    'model' => getenv('SHOPPING_AI_MODEL') ?: 'llama3.2:3b',
    'api_key' => getenv('OPENAI_API_KEY') ?: '',
    'timeout' => 12,
    'ollama_url' => getenv('SHOPPING_OLLAMA_URL') ?: 'http://127.0.0.1:11434',
    'ollama_token' => getenv('SHOPPING_OLLAMA_TOKEN') ?: '',
    'daily_requests' => 100, // Límite global de intentos IA por día UTC.
    'max_age_hours' => 48, // Ofertas externas más antiguas quedan fuera.
];
$local = __DIR__ . '/shopping_agent.local.php';
if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) $config = array_replace($config, $overrides);
}
return $config;
