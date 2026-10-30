<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
unset($_SESSION['customer_order_id']);
redirect('pedido_login.php');

