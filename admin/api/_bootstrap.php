<?php
declare(strict_types=1);

/**
 * Общий bootstrap для всех API-эндпоинтов админки:
 * сессия, проверка авторизации, CSRF, JSON-ответы.
 */

require_once dirname(__DIR__, 2) . '/src/util.php';
require_once dirname(__DIR__, 2) . '/src/auth.php';

date_default_timezone_set(cfg()['timezone']);

Auth::startSession();

header('Content-Type: application/json; charset=utf-8');

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $code = 400): void
{
    json_out(['error' => $msg], $code);
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_error('POST required', 405);
    }
}

// CSRF: для write-операций (кроме логина) требуется X-CSRF-Token, совпадающий с сессией
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $sent)) {
        json_error('CSRF token mismatch', 403);
    }
}

function require_auth(): void
{
    if (!Auth::isAuthenticated()) {
        json_error('Unauthorized', 401);
    }
}

/** Валидация даты-времени "Y-m-d H:i" или ISO */
function valid_dt(string $s): ?string
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $s)
      ?: DateTimeImmutable::createFromFormat('Y-m-d', $s);
    if (!$d) {
        return null;
    }
    return $d->format('Y-m-d H:i');
}
