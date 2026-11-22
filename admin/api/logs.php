<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        require_auth();
        json_out(read_admin_logs(500));
        break;

    default:
        json_error('Unknown action', 404);
}
