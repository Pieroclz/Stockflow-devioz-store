<?php
/**
 * Calibracion orientativa de ETA de StockFlow para pruebas locales.
 * NO es una consulta de trafico actual. Solo aplica un ajuste fijo a OSRM.
 * Ejemplo comparativo: 22 minutos observados / 12 minutos base = 1.83.
 * Revise varias rutas y franjas horarias antes de usarlo con clientes.
 */
return [
    'traffic_factor' => 1.83,
    'extra_minutes' => 0,
];
