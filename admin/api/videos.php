<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

/**
 * Валидация периода активности ролика: {start,end} в формате "Y-m-d H:i" (или дата).
 */
function parse_video_period(array $d): array
{
    $start = valid_dt((string)($d['start'] ?? ''));
    $end   = valid_dt((string)($d['end'] ?? ''));
    if (!$start || !$end) {
        json_error('Неверный формат дат. Ожидается "ГГГГ-ММ-ДД ЧЧ:ММ" или "ГГГГ-ММ-ДД"');
    }
    // если указана только дата — конец считаем концом этих суток
    if (strlen((string)$d['end']) === 10) {
        $end = date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00';
    }
    if ($start >= $end) {
        json_error('Дата окончания должна быть позже даты начала');
    }
    return ['start' => $start, 'end' => $end];
}

/** Найти индекс ролика в массиве по id */
function find_video_index(array $videos, string $id): int
{
    foreach ($videos as $i => $v) {
        if ($v['id'] === $id) {
            return $i;
        }
    }
    json_error('Ролик не найден', 404);
}

switch ($action) {
    case 'status':
        // Очередь конвертации + статусы «не готовых» роликов — для живого обновления таблицы.
        require_auth();
        $pending = [];
        foreach (store_read('videos.json') as $v) {
            if (($v['status'] ?? 'ready') !== 'ready') {
                $pending[] = ['id' => $v['id'], 'file' => $v['file'], 'status' => $v['status']];
            }
        }
        json_out([
            'queue'   => store_read('queue.json'),
            'pending' => $pending,
            'server_time' => date('c'),
        ]);
        break;

    case 'list':
        require_auth();
        json_out(store_read('videos.json'));
        break;

    case 'get':
        require_auth();
        $id = $_GET['id'] ?? '';
        foreach (store_read('videos.json') as $v) {
            if ($v['id'] === $id) {
                json_out($v);
            }
        }
        json_error('Not found', 404);
        break;

    case 'upload':
        // multipart/form-data: file, title, type(main|additional), duration(для изображений)
        require_auth();
        require_post();
        require_csrf();

        $errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            // Диагностика типичной проблемы: POST-запрос пришёл без multipart-тела.
            // Наиболее частые причины: запрос отправлен без FormData (например, JSON/fetch с телом-строкой),
            // превышены лимиты php.ini upload_max_filesize/post_max_size или включено ограничение extension.
            $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
            $isMultipart = str_contains($ct, 'multipart/form-data');
            $bytesRecv = 0;
            if (isset($_SERVER['CONTENT_LENGTH'])) {
                $bytesRecv = (int)$_SERVER['CONTENT_LENGTH'];
            }

            $diag = [
                'post_keys'      => array_keys($_POST),
                'files_keys'     => array_keys($_FILES),
                'content_type'   => $ct,
                'is_multipart'   => $isMultipart,
                'content_length' => $bytesRecv,
                'php_upload_max' => (string)ini_get('upload_max_filesize'),
                'php_post_max'   => (string)ini_get('post_max_size'),
                'error_code'     => (int)$errCode,
            ];
            admin_log('upload_failed', $diag);

            $msg = 'Файл не загружен: ' . upload_err_message((int)$errCode);
            if (!$isMultipart && $errCode === UPLOAD_ERR_NO_FILE) {
                $msg .= '. Ожидается multipart/form-data с полем "file". Проверьте, что в JavaScript для отправки используется FormData.';
            } elseif ($errCode === UPLOAD_ERR_INI_SIZE) {
                $msg .= sprintf('. Превышен upload_max_filesize=%s или post_max_size=%s на сервере.', ini_get('upload_max_filesize'), ini_get('post_max_size'));
            } elseif ($errCode === UPLOAD_ERR_NO_FILE && $isMultipart && $bytesRecv > 0) {
                // Тело запроса обрезано: PHP не смог разобрать multipart (post_max_size меньше тела) — $_POST/$_FILES пусты.
                $msg .= sprintf(
                    '. Тело запроса (%d МБ) превышает лимит post_max_size=%s на сервере: PHP проигнорировал все поля формы.'
                    . ' Увеличьте post_max_size и upload_max_filesize (см. README, раздел «Лимиты загрузки»), перезапустите PHP-FPM/Apache и повторите загрузку.',
                    intdiv($bytesRecv, 1024 * 1024),
                    (string)ini_get('post_max_size')
                );
            }
            json_error($msg, 400);
        }
        $tmp   = $_FILES['file']['tmp_name'];
        $orig  = basename($_FILES['file']['name']);
        $ext   = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $title = trim((string)($_POST['title'] ?? pathinfo($orig, PATHINFO_FILENAME)));
        $type  = in_array($_POST['type'] ?? '', ['main', 'additional', 'splash'], true) ? $_POST['type'] : 'main';
        // для заставок уточняем назначение: недельный санитарный день или техзакрытие
        $splashRole = null;
        if ($type === 'splash') {
            $splashRole = $_POST['splash_role'] ?? '';
            if (!in_array($splashRole, ['splash_weekday', 'splash_tech'], true)) {
                json_error('Для заставки укажите назначение: «санитарный день недели» или «техническое закрытие»');
            }
            if ($splashRole === 'splash_weekday') {
                // ролик санитарного дня недели может быть только один
                foreach (store_read('videos.json') as $v0) {
                    if (($v0['splash_role'] ?? '') === 'splash_weekday') {
                        json_error('Ролик-заставка санитарных дней уже существует («' . $v0['title'] . '»). Замените файл в его карточке либо сначала удалите его.', 409);
                    }
                }
            }
        }

        $isVideo = in_array($ext, cfg()['allowed_video_extensions'], true);
        $isImage = in_array($ext, cfg()['allowed_image_extensions'], true);
        if (!$isVideo && !$isImage) {
            admin_log('upload_rejected', ['name' => $orig, 'ext' => $ext]);
            json_error("Недопустимый тип файла: .$ext. Можно загружать видео (" . implode(', ', cfg()['allowed_video_extensions'])
                . ") или изображения (" . implode(', ', cfg()['allowed_image_extensions']) . ")."
                . " Все файлы конвертируются в mp4.", 400);
        }
        if ($_FILES['file']['size'] > cfg()['max_upload_size']) {
            admin_log('upload_failed', ['name' => $orig, 'reason' => 'too large', 'size' => $_FILES['file']['size']]);
            json_error('Файл слишком большой', 400);
        }
        if (!is_uploaded_file($tmp)) {
            admin_log('upload_failed', ['name' => $orig, 'reason' => 'not an uploaded file']);
            json_error('Ошибка загрузки (файл не является загруженным)', 400);
        }

        $id  = new_id();
        $slug = slugify($title);
        $storedSource = "src_{$id}_{$orig}"; // как лежит в uploads (безопасное имя)
        $destDir = root_path('uploads');
        @mkdir($destDir, 0775, true);
        if (!move_uploaded_file($tmp, $destDir . '/' . $storedSource)) {
            admin_log('upload_failed', ['name' => $orig, 'reason' => 'move_uploaded_file failed',
                'uploads_dir' => $destDir, 'writable' => is_writable($destDir) ? 'yes' : 'no']);
            json_error('Не удалось сохранить загруженный файл в uploads/ (проверьте права на каталог)', 500);
        }

        // итоговое имя готового ролика: всегда .mp4, читаемое (транслит названия)
        $mediaDir = root_path('media');
        @mkdir($mediaDir, 0775, true);
        $finalName = unique_filename($mediaDir, $slug, 'mp4');

        $duration = null;
        if ($isImage) {
            // для изображения спрашиваем длительность будущего ролика (по умолчанию — из настроек, обычно 60 с)
            $duration = (int)($_POST['duration'] ?? convert_settings()['duration_image']);
            $duration = max(3, min(3600, $duration));
        }

        $video = [
            'id'         => $id,
            'title'      => $title,
            'slug'       => $slug,
            'file'       => $finalName,          // media/<file> — всегда mp4
            'type'       => $type,               // основной / дополнительный / заставка
            'kind'       => $isImage ? 'image' : 'video',
            'splash_role' => $splashRole,        // splash_weekday | splash_tech (для type=splash)
            'status'     => 'queued',            // queued -> converting -> ready | error
            'duration'   => null,
            'filesize'   => null,
            'req_duration' => $duration,
            'schedule'   => [],                  // периоды активности [{id,start,end}]
            'created'    => date('c'),
            'error'      => null,
        ];
        $videos = store_read('videos.json');
        $videos[] = $video;
        store_write('videos.json', $videos);

        // задача в очередь
        $queue = store_read('queue.json');
        $queue[] = [
            'video_id' => $id,
            'source'   => $storedSource,
            'filename' => $finalName,
            'kind'     => $isImage ? 'image' : 'video',
            'duration' => $duration,
            'added'    => date('c'),
        ];
        store_write('queue.json', $queue);

        admin_log('video_upload', ['id' => $id, 'title' => $title, 'orig' => $orig, 'file' => $finalName,
            'kind' => $video['kind'], 'type' => $type, 'duration_req' => $duration,
            'size' => $_FILES['file']['size']]);
        json_out(['ok' => true, 'video' => $video], 201);
        break;

    case 'update':
        require_auth();
        require_post();
        require_csrf();
        $d  = request_json();
        $id = (string)($d['id'] ?? '');
        $videos = store_read('videos.json');
        $i = find_video_index($videos, $id);
        $changed = [];
        foreach (['title'] as $f) {
            if (isset($d[$f]) && trim((string)$d[$f]) !== '' && $d[$f] !== $videos[$i][$f]) {
                $changed[$f] = trim((string)$d[$f]);
                $videos[$i][$f] = $changed[$f];
            }
        }
        if (isset($d['type'])) {
            $type = (string)$d['type'];
            if (!in_array($type, ['main', 'additional', 'splash'], true)) {
                json_error('Недопустимый тип ролика');
            }
            $role = isset($d['splash_role']) ? (string)$d['splash_role'] : null;
            if ($type === 'splash') {
                if (!in_array((string)($videos[$i]['splash_role'] ?? $role), ['splash_weekday', 'splash_tech'], true)) {
                    json_error('Для заставки укажите назначение: splash_weekday или splash_tech');
                }
            }
            if ($type !== $videos[$i]['type'] || ($role !== null && $role !== ($videos[$i]['splash_role'] ?? null))) {
                $changed['type'] = $type;
                $videos[$i]['type'] = $type;
                if ($role !== null) {
                    $changed['splash_role'] = $role;
                    $videos[$i]['splash_role'] = $role;
                }
            }
        }
        store_write('videos.json', $videos);
        admin_log('video_update', ['id' => $id, 'changes' => $changed]);
        json_out(['ok' => true, 'video' => $videos[$i]]);
        break;

    // ---------- расписание ролика: список периодов активности ----------
    case 'periods':
        require_auth();
        $id = $_GET['id'] ?? '';
        foreach (store_read('videos.json') as $v) {
            if ($v['id'] === $id) {
                json_out(['id' => $id, 'schedule' => $v['schedule'] ?? []]);
            }
        }
        json_error('Not found', 404);
        break;

    case 'period-add':
        require_auth();
        require_post();
        require_csrf();
        $d  = request_json();
        $p  = parse_video_period($d);
        $videos = store_read('videos.json');
        $i = find_video_index($videos, (string)($d['id'] ?? ''));
        $p['id'] = new_id();
        $videos[$i]['schedule'][] = $p;
        usort($videos[$i]['schedule'], fn($a, $b) => strcmp($a['start'], $b['start']));
        store_write('videos.json', $videos);
        admin_log('video_period_add', ['video_id' => $videos[$i]['id'], 'title' => $videos[$i]['title'], 'period' => $p]);
        json_out(['ok' => true, 'video' => $videos[$i]]);
        break;

    case 'period-update':
        require_auth();
        require_post();
        require_csrf();
        $d = request_json();
        $p = parse_video_period($d);
        $videos = store_read('videos.json');
        $i = find_video_index($videos, (string)($d['id'] ?? ''));
        foreach ($videos[$i]['schedule'] as $j => $old) {
            if ($old['id'] === ($d['periodId'] ?? '')) {
                $p['id'] = $old['id'];
                $from = $old;
                $videos[$i]['schedule'][$j] = $p;
                usort($videos[$i]['schedule'], fn($a, $b) => strcmp($a['start'], $b['start']));
                store_write('videos.json', $videos);
                admin_log('video_period_update', ['video_id' => $videos[$i]['id'], 'title' => $videos[$i]['title'], 'from' => $from, 'to' => $p]);
                json_out(['ok' => true, 'video' => $videos[$i]]);
            }
        }
        json_error('Период не найден', 404);
        break;

    case 'period-delete':
        require_auth();
        require_post();
        require_csrf();
        $d = request_json();
        $videos = store_read('videos.json');
        $i = find_video_index($videos, (string)($d['id'] ?? ''));
        $before = count($videos[$i]['schedule'] ?? []);
        $removed = null;
        foreach ((array)($videos[$i]['schedule'] ?? []) as $per) {
            if ($per['id'] === ($d['periodId'] ?? '')) {
                $removed = $per;
            }
        }
        $videos[$i]['schedule'] = array_values(array_filter(
            (array)($videos[$i]['schedule'] ?? []),
            fn($per) => $per['id'] !== ($d['periodId'] ?? '')
        ));
        store_write('videos.json', $videos);
        admin_log('video_period_delete', ['video_id' => $videos[$i]['id'], 'title' => $videos[$i]['title'], 'period' => $removed]);
        json_out(['ok' => true, 'deleted' => $before - count($videos[$i]['schedule'])]);
        break;

    case 'rename-file':
        // переименовать готовый файл ролика (например, после уточнения названия)
        require_auth();
        require_post();
        require_csrf();
        $d = request_json();
        $id = (string)($d['id'] ?? '');
        $newSlug = slugify((string)($d['slug'] ?? ''));
        $videos = store_read('videos.json');
        $i = find_video_index($videos, $id);
        $v = $videos[$i];
        $old = root_path('media/' . $v['file']);
        $newName = unique_filename(root_path('media'), $newSlug, 'mp4');
        $new = root_path('media/' . $newName);
        if (!is_file($old)) {
            json_error('Файл ролика не найден', 404);
        }
        if (!rename($old, $new)) {
            json_error('Не удалось переименовать файл', 500);
        }
        $videos[$i]['file'] = $newName;
        $videos[$i]['slug'] = $newSlug;
        store_write('videos.json', $videos);
        admin_log('video_rename', ['id' => $id, 'from' => $v['file'], 'to' => $newName]);
        json_out(['ok' => true, 'video' => $videos[$i]]);
        break;

    // ---------- замена файла готового ролика (пере-конвертация) ----------
    case 'replace':
        // multipart: id, file (видео или изображение), duration (для изображений)
        require_auth();
        require_post();
        require_csrf();

        $errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            admin_log('upload_failed', ['context' => 'video_replace', 'error_code' => (int)$errCode,
                'files_keys' => array_keys($_FILES), 'post_keys' => array_keys($_POST)]);
            json_error('Файл не загружен: ' . upload_err_message((int)$errCode), 400);
        }
        $id = (string)($_POST['id'] ?? '');
        $videos = store_read('videos.json');
        $i = find_video_index($videos, $id);
        $v = $videos[$i];

        $orig = basename($_FILES['file']['name']);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $isVideo = in_array($ext, cfg()['allowed_video_extensions'], true);
        $isImage = in_array($ext, cfg()['allowed_image_extensions'], true);
        if (!$isVideo && !$isImage) {
            admin_log('upload_rejected', ['name' => $orig, 'ext' => $ext, 'context' => 'video_replace']);
            json_error("Недопустимый тип файла: .$ext (можно видео или изображение; всё конвертируется в mp4)", 400);
        }
        if ($_FILES['file']['size'] > cfg()['max_upload_size']) {
            admin_log('upload_failed', ['name' => $orig, 'reason' => 'too large', 'size' => $_FILES['file']['size']]);
            json_error('Файл слишком большой', 400);
        }
        $duration = null;
        if ($isImage) {
            $duration = isset($_POST['duration']) && $_POST['duration'] !== '' ? max(1, (int)$_POST['duration']) : null;
        }

        $storedSource = "src_{$id}_{$orig}";
        @mkdir(root_path('uploads'), 0775, true);
        if (!move_uploaded_file($_FILES['file']['tmp_name'], root_path('uploads/' . $storedSource))) {
            admin_log('upload_failed', ['name' => $orig, 'reason' => 'move_uploaded_file failed', 'context' => 'video_replace']);
            json_error('Не удалось сохранить загруженный файл в uploads/ (проверьте права на каталог)', 500);
        }

        $videos[$i]['status'] = 'queued';
        $videos[$i]['error']  = null;
        $videos[$i]['kind']   = $isImage ? 'image' : 'video';
        $videos[$i]['req_duration'] = $duration;
        store_write('videos.json', $videos);

        $queue = store_read('queue.json');
        $queue[] = [
            'video_id' => $id,
            'source'   => $storedSource,
            'filename' => $v['file'],   // то же итоговое имя — ссылка в расписании не меняется
            'kind'     => $isImage ? 'image' : 'video',
            'duration' => $duration,
            'added'    => date('c'),
        ];
        store_write('queue.json', $queue);
        admin_log('video_replace', ['id' => $id, 'title' => $v['title'], 'file' => $v['file'], 'orig' => $orig,
            'kind' => $videos[$i]['kind'], 'size' => $_FILES['file']['size']]);
        json_out(['ok' => true, 'video' => $videos[$i]], 201);
        break;

    case 'delete':
        require_auth();
        require_post();
        require_csrf();
        $d  = request_json();
        $id = (string)($d['id'] ?? '');
        $videos = store_read('videos.json');
        $i = find_video_index($videos, $id);
        $v = $videos[$i];
        // защита: уникальные заставки нельзя удалять — они нужны движку
        if (($v['splash_role'] ?? '') === 'splash_weekday') {
            json_error('Нельзя удалить заставку санитарных дней недели. Замените её файл кнопкой «⬆ Файл».', 409);
        }
        // проверка: ролик активен в текущем расписании?
        $now = new DateTimeImmutable('now', new DateTimeZone(cfg()['timezone']));
        foreach ((array)($v['schedule'] ?? []) as $per) {
            $s = new DateTimeImmutable($per['start'], new DateTimeZone(cfg()['timezone']));
            $e = new DateTimeImmutable($per['end'], new DateTimeZone(cfg()['timezone']));
            if ($now >= $s && $now < $e) {
                json_error('Ролик активен в текущем расписании (' . $per['start'] . ' — ' . $per['end'] . '). Сначала удалите действующий период.', 409);
            }
        }
        @unlink(root_path('media/' . $v['file']));
        @unlink(root_path('uploads/' . ("src_{$id}_" . ($v['orig_name'] ?? ''))));
        unset($videos[$i]);
        store_write('videos.json', array_values($videos));
        admin_log('video_delete', ['id' => $id, 'file' => $v['file'], 'title' => $v['title']]);
        json_out(['ok' => true]);
        break;

    default:
        json_error('Unknown action', 404);
}
