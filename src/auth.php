<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/**
 * Простейшая авторизация по логину/паролю без СУБД.
 * data/users.json: [{login, passhash, role, created}]
 */
class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
            session_name('TVADMIN');
            session_start();
        }
    }

    public static function check(string $login, string $password): bool
    {
        $users = store_read('users.json');
        foreach ($users as $u) {
            if (!hash_equals($u['login'], $login)) {
                continue;
            }
            if (password_verify($password, $u['passhash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_user'] = $login;
                $_SESSION['admin_role'] = $u['role'] ?? 'editor';
                admin_log('login', ['login' => $login]);
                return true;
            }
            admin_log('login_failed', ['login' => $login]);
            return false;
        }
        admin_log('login_failed', ['login' => $login, 'reason' => 'unknown user']);
        return false;
    }

    public static function logout(): void
    {
        if (self::isAuthenticated()) {
            admin_log('logout', []);
        }
        $_SESSION = [];
        session_destroy();
    }

    public static function isAuthenticated(): bool
    {
        return isset($_SESSION['admin_user']);
    }

    public static function user(): ?string
    {
        return $_SESSION['admin_user'] ?? null;
    }

    /** Сменить пароль пользователю (массив пользователей передаётся по ссылке). */
    public static function setPassword(array &$users, int $index, string $newPassword): void
    {
        if (strlen($newPassword) < 8) {
            throw new RuntimeException('Пароль слишком короткий (минимум 8 символов)');
        }
        $users[$index]['passhash'] = password_hash($newPassword, PASSWORD_ARGON2ID);
        $users[$index]['pass_changed'] = date('c');
    }

    /** Создать пользователя через CLI: php src/auth.php add login password [role] */
    public static function addUser(string $login, string $password, string $role = 'admin'): void
    {
        $users = store_read('users.json');
        foreach ($users as $u) {
            if ($u['login'] === $login) {
                throw new RuntimeException("User $login already exists");
            }
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $login)) {
            throw new RuntimeException('Логин: 3–32 символа, латиница/цифры/._-');
        }
        if (!in_array($role, ['admin', 'editor'], true)) {
            throw new RuntimeException('Роль должна быть admin или editor');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException("Password too short (min 8 chars)");
        }
        $users[] = [
            'login'    => $login,
            'passhash' => password_hash($password, PASSWORD_ARGON2ID),
            'role'     => $role,
            'created'  => date('c'),
        ];
        store_write('users.json', $users);
    }
}

// CLI-режим: добавление пользователей
if (PHP_SAPI === 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $argv0 = $argv[1] ?? 'help';
    try {
        if ($argv0 === 'add' && isset($argv[2], $argv[3])) {
            Auth::addUser($argv[2], $argv[3], $argv[4] ?? 'admin');
            echo "OK\n";
        } else {
            echo "Usage: php src/auth.php add <login> <password> [role]\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        exit(1);
    }
}
