<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_admin();
if ((string)($_SESSION['admin_role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Solo la cuenta administradora puede crear motorizados.');
}
redirect('admin/motorizados.php');
