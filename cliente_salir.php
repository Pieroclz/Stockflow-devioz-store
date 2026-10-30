<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
unset($_SESSION['customer_id'], $_SESSION['customer_name']);
session_regenerate_id(true);
flash('success', 'Sesión cerrada correctamente.');
redirect('mi_cuenta.php');
