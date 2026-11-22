<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'check': // GET: состояние сессии + CSRF-токен для формы логина/после входа
        json_out([
            'auth'  => Auth::isAuthenticated(),
            'user'  => Auth::user(),
            'role'  => $_SESSION['admin_role'] ?? null,
            'csrf'  => csrf_token(),
        ]);
        break;

    case 'login':
        require_post();
        $d = request_json();
        if (Auth::check((string)($d['login'] ?? ''), (string)($d['password'] ?? ''))) {
            json_out(['ok' => true, 'user' => Auth::user(), 'role' => $_SESSION['admin_role'] ?? null, 'csrf' => csrf_token()]);
        }
        usleep(500000); // замедлим неверные попытки
        json_error('Неверный логин или пароль', 401);
        break;

    case 'logout':
        require_post();
        require_csrf();
        Auth::logout();
        json_out(['ok' => true]);
        break;

    default:
        json_error('Unknown action', 404);
}
