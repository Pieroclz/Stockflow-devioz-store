<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

redirect(is_admin() ? 'admin/index.php' : 'login.php');
