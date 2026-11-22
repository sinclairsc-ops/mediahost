<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

/** Текущая роль в сессии (admin может управлять пользователями) */
function require_admin(): void
{
    require_auth();
    if (($_SESSION['admin_role'] ?? '') !== 'admin') {
        json_error('Недостаточно прав (требуется роль admin)', 403);
    }
}

switch ($action) {
    case 'list':
        require_admin();
        $out = array_map(
            fn($u) => ['login' => $u['login'], 'role' => $u['role'] ?? 'editor', 'created' => $u['created'] ?? null],
            store_read('users.json')
        );
        json_out($out);
        break;

    case 'add': // создать учётную запись {login, password, role}
        require_admin();
        require_post();
        require_csrf();
        $d = request_json();
        try {
            Auth::addUser((string)($d['login'] ?? ''), (string)($d['password'] ?? ''), (string)($d['role'] ?? 'editor'));
        } catch (Throwable $e) {
            admin_log('user_add_failed', ['login' => $d['login'] ?? '', 'error' => $e->getMessage()]);
            json_error($e->getMessage());
        }
        admin_log('user_add', ['login' => $d['login'], 'role' => $d['role'] ?? 'editor']);
        json_out(['ok' => true], 201);
        break;

    case 'set-password': // смена пароля
        // без current_password — может выполнить только администратор для любого пользователя;
        // обычный пользователь обязан подтвердить старый пароль.
        require_auth();
        require_post();
        require_csrf();
        $d = request_json();
        $me = (string)Auth::user();
        $target = (string)($d['login'] ?? $me);
        $isAdmin = (($_SESSION['admin_role'] ?? '') === 'admin');
        if (!$isAdmin && !hash_equals($me, $target)) {
            json_error('Можно менять только свой пароль', 403);
        }
        $users = store_read('users.json');
        foreach ($users as $i => $u) {
            if (!hash_equals($u['login'], $target)) {
                continue;
            }
            if (!$isAdmin) {
                if (!password_verify((string)($d['current_password'] ?? ''), $u['passhash'])) {
                    admin_log('password_change_denied', ['login' => $target, 'reason' => 'wrong current password']);
                    json_error('Неверный текущий пароль', 403);
                }
            }
            try {
                Auth::setPassword($users, $i, (string)($d['new_password'] ?? ''));
            } catch (Throwable $e) {
                json_error($e->getMessage());
            }
            store_write('users.json', $users);
            admin_log('password_change', ['login' => $target, 'by_admin' => $isAdmin && $target !== $me]);
            json_out(['ok' => true]);
        }
        json_error('Пользователь не найден', 404);
        break;

    case 'set-role':
        require_admin();
        require_post();
        require_csrf();
        $d = request_json();
        $role = (string)($d['role'] ?? '');
        if (!in_array($role, ['admin', 'editor'], true)) {
            json_error('Роль должна быть admin или editor');
        }
        $users = store_read('users.json');
        foreach ($users as $i => $u) {
            if (hash_equals($u['login'], (string)($d['login'] ?? ''))) {
                $users[$i]['role'] = $role;
                store_write('users.json', $users);
                admin_log('user_role_set', ['login' => $u['login'], 'role' => $role]);
                json_out(['ok' => true]);
            }
        }
        json_error('Пользователь не найден', 404);
        break;

    case 'delete':
        require_admin();
        require_post();
        require_csrf();
        $login = (string)(request_json()['login'] ?? '');
        if (hash_equals((string)Auth::user(), $login)) {
            json_error('Нельзя удалить собственную учётную запись');
        }
        $users = store_read('users.json');
        $left = array_values(array_filter($users, fn($u) => !hash_equals($u['login'], $login)));
        if (count($left) === count($users)) {
            json_error('Пользователь не найден', 404);
        }
        if (!array_filter($left, fn($u) => ($u['role'] ?? '') === 'admin')) {
            json_error('В системе должен остаться хотя бы один администратор', 409);
        }
        store_write('users.json', $left);
        admin_log('user_delete', ['login' => $login]);
        json_out(['ok' => true]);
        break;

    default:
        json_error('Unknown action', 404);
}
