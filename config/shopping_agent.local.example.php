<?php
// COPIA a shopping_agent.local.php. Edita solo en el servidor; no compartas la clave.
return [
    'provider' => 'openai',
    'model' => '', // ID de un modelo de tu cuenta compatible con Responses + Structured Outputs.
    'api_key' => getenv('OPENAI_API_KEY') ?: '', // Clave API. Preferible OPENAI_API_KEY como variable de entorno.
    'daily_requests' => 100,
];
