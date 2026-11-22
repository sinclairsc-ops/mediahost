<?php
declare(strict_types=1);

/**
 * Утилиты: конфиг, транслитерация (slug), JSON-хранилище с блокировками,
 * логирование административных действий.
 */

function cfg(): array
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            $file = __DIR__ . '/config.php.example';
        }
        $config = require $file;
    }
    return $config;
}

function root_path(string $rel = ''): string
{
    return dirname(__DIR__) . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}

/**
 * Транслитерация русского текста в читаемое имя файла.
 * Пример: "Акция День рождения" -> "akciya-den-rozhdeniya"
 */
function slugify(string $text): string
{
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
        'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    $text = mb_strtolower(trim($text), 'UTF-8');
    $out = '';
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        if (isset($map[$ch])) {
            $out .= $map[$ch];
        } elseif (preg_match('/[a-z0-9]/', $ch)) {
            $out .= $ch;
        } else {
            $out .= '-';
        }
    }
    $out = preg_replace('/-+/', '-', $out);
    $out = trim($out, '-');
    if ($out === '') {
        $out = 'video';
    }
    // не более 80 символов, чтобы имя файла осталось читаемым
    return substr($out, 0, 80);
}

/** Сделать имя файла уникальным: добавляет -1, -2 ... если занято */
function unique_filename(string $dir, string $basename, string $ext): string
{
    $name = $basename . '.' . $ext;
    $i = 1;
    while (file_exists($dir . '/' . $name)) {
        $name = $basename . '-' . (++$i) . '.' . $ext;
    }
    return $name;
}

/**
 * Атомарное чтение JSON-файла хранилища.
 */
function store_read(string $file): array
{
    $path = root_path('data/' . $file);
    if (!is_file($path)) {
        return [];
    }
    $fp = fopen($path, 'rb');
    if (!$fp) {
        throw new RuntimeException("Cannot open $path");
    }
    flock($fp, LOCK_SH);
    $data = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $json = json_decode($data ?: '[]', true);
    return is_array($json) ? $json : [];
}

/**
 * Эксклюзивная запись JSON-файла с бэкапом предыдущей версии.
 */
function store_write(string $file, array $data): void
{
    $path = root_path('data/' . $file);
    @mkdir(dirname($path), 0775, true);
    $fp = fopen($path, 'cb');
    if (!$fp) {
        throw new RuntimeException("Cannot open $path for writing");
    }
    flock($fp, LOCK_EX);
    if (filesize($path) > 0) {
        copy($path, $path . '.bak');
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function new_id(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * Настройки конвертера (редактируются в админке, хранятся в data/settings.json).
 * Если файла нет — берутся значения по умолчанию из config.php ('convert_defaults').
 */
function convert_settings(): array
{
    $defaults = cfg()['convert_defaults'] ?? [];
    $saved = store_read('settings.json');
    return is_array($saved) && $saved ? array_merge($defaults, $saved) : $defaults;
}

function save_convert_settings(array $s): void
{
    store_write('settings.json', $s);
}

/** Человекочитаемое описание ошибки загрузки PHP */
function upload_err_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:   return 'файл превышает upload_max_filesize в php.ini';
        case UPLOAD_ERR_FORM_SIZE:  return 'файл превышает MAX_FILE_SIZE в форме';
        case UPLOAD_ERR_PARTIAL:    return 'файл загружен частично';
        case UPLOAD_ERR_NO_FILE:    return 'файл не передан (проверьте поле "file" формы)';
        case UPLOAD_ERR_NO_TMP_DIR: return 'на сервере нет временной директории (upload_tmp_dir)';
        case UPLOAD_ERR_CANT_WRITE: return 'не удалось записать файл на диск сервера';
        case UPLOAD_ERR_EXTENSION:  return 'загрузка прервана PHP-расширением';
        default: return 'код ошибки ' . $code;
    }
}

/**
 * Журнал действий администратора (append-only, JSON Lines).
 */
function admin_log(string $action, array $details = []): void
{
    $user = $_SESSION['admin_user'] ?? 'system';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $line = json_encode([
        'ts'      => date('c'),
        'user'    => $user,
        'ip'      => $ip,
        'action'  => $action,
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $dir = root_path('data/logs');
    @mkdir($dir, 0775, true);
    $file = $dir . '/admin-' . date('Y-m-d') . '.log';
    file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

function read_admin_logs(int $limit = 500): array
{
    $dir = root_path('data/logs');
    $files = glob($dir . '/admin-*.log');
    if (!$files) {
        return [];
    }
    rsort($files); // свежие раньше
    $lines = [];
    foreach ($files as $f) {
        $fh = fopen($f, 'rb');
        while (($l = fgets($fh)) !== false) {
            $l = trim($l);
            if ($l !== '') {
                $j = json_decode($l, true);
                if ($j) {
                    $lines[] = $j;
                }
            }
            if (count($lines) >= $limit) {
                break 2;
            }
        }
        fclose($fh);
    }
    return array_reverse($lines);
}
