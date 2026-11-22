<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

function specials(): array
{
    $s = store_read('specials.json');
    return $s + [
        'tech_periods'     => [],
        'sanitary_dates'   => [],
        'weekday_periods'  => [],
        'weekday_sanitary' => ['enabled' => true, 'weekday' => 1],
    ];
}

switch ($action) {
    case 'get':
        require_auth();
        json_out(specials());
        break;

    // ---- периоды технического закрытия ----
    case 'tech-save':
        // JSON (существующие picture/video — по именам файлов в media/) или multipart с файлами-заставками
        require_auth();
        require_post();
        require_csrf();

        if (!empty($_FILES)) {
            $errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                admin_log('upload_failed', ['context' => 'tech_save', 'error_code' => (int)$errCode,
                    'files_keys' => array_keys($_FILES)]);
                json_error('Файл заставки не загружен: ' . upload_err_message((int)$errCode), 400);
            }
        }

        $d = $_POST ?: request_json();
        $start = valid_dt((string)($d['start'] ?? ''));
        $end   = valid_dt((string)($d['end'] ?? ''));
        if (!$start || !$end) {
            json_error('Неверный формат дат. Ожидается "ГГГГ-ММ-ДД ЧЧ:ММ"');
        }
        if (strlen((string)$d['end']) === 10) {
            $end = date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00';
        }
        if ($start >= $end) {
            json_error('Дата окончания должна быть позже даты начала');
        }
        $s = specials();
        $id = (string)($d['id'] ?? new_id());

        $pic  = trim((string)($d['picture'] ?? ''));
        $vid  = trim((string)($d['video'] ?? ''));
        if ($pic !== '' && !preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $pic)) {
            json_error('Недопустимое имя картинки');
        }
        if ($vid !== '' && !preg_match('/^[A-Za-z0-9._-]+\.mp4$/i', $vid)) {
            json_error('Недопустимое имя видео-заставки (только .mp4)');
        }

        // загрузка файла-заставки: изображения конвертируются в mp4 (как и ролики), картинка остаётся jpg
        $uploadedFile = null;
        $isImageUpload = false;
        if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $orig = basename($_FILES['file']['name']);
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $isVideoUp = in_array($ext, cfg()['allowed_video_extensions'], true);
            $isImageUp = in_array($ext, cfg()['allowed_image_extensions'], true);
            if (!$isVideoUp && !$isImageUp) {
                admin_log('upload_rejected', ['name' => $orig, 'ext' => $ext, 'context' => 'tech_save']);
                json_error("Недопустимый тип файла заставки: .$ext (видео или изображение)", 400);
            }
            $title = trim((string)($d['title'] ?? ('tech-' . $id)));
            $slug  = slugify($title);
            $srcName = "src_tech_{$id}_{$orig}";
            @mkdir(root_path('uploads'), 0775, true);
            if (!move_uploaded_file($_FILES['file']['tmp_name'], root_path('uploads/' . $srcName))) {
                admin_log('upload_failed', ['name' => $orig, 'reason' => 'move_uploaded_file failed', 'context' => 'tech_save']);
                json_error('Не удалось сохранить загруженный файл', 500);
            }
            $uploadedFile = ['src' => $srcName, 'slug' => $slug];
            $isImageUpload = $isImageUp;
        }

        $item = [
            'id'      => $id,
            'start'   => $start,
            'end'     => $end,
            'picture' => $pic ?: null,
            'video'   => $vid ?: null,
        ];
        $replaced = false;
        foreach ($s['tech_periods'] as $i => $tp) {
            if ($tp['id'] === $item['id']) {
                $item['video'] = $item['video'] ?: ($tp['video'] ?? null);
                $item['picture'] = $item['picture'] ?: ($tp['picture'] ?? null);
                $s['tech_periods'][$i] = $item;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $s['tech_periods'][] = $item;
        }
        usort($s['tech_periods'], fn($a, $b) => strcmp($a['start'], $b['start']));
        store_write('specials.json', $s);
        admin_log($replaced ? 'tech_period_update' : 'tech_period_create', $item);

        if ($uploadedFile) {
            // ставим задачу воркеру: любой загруженный файл -> mp4 в media/
            $finalName = unique_filename(root_path('media'), $uploadedFile['slug'], 'mp4');
            $queue = store_read('queue.json');
            $queue[] = [
                'video_id' => 'tech:' . $item['id'],   // special task id (не ролик из videos.json)
                'source'   => $uploadedFile['src'],
                'filename' => $finalName,
                'kind'     => $isImageUpload ? 'image' : 'video',
                'duration' => isset($d['duration']) && $isImageUpload ? (int)$d['duration'] : null,
                'target'   => 'tech:' . $item['id'],
                'added'    => date('c'),
            ];
            store_write('queue.json', $queue);
            admin_log('tech_splash_upload', ['period_id' => $item['id'], 'file' => $finalName, 'kind' => $isImageUpload ? 'image' : 'video']);
            json_out(['ok' => true, 'item' => $item, 'queued' => $finalName], 201);
        }
        json_out(['ok' => true, 'item' => $item]);
        break;

    case 'tech-delete':
        require_auth();
        require_post();
        require_csrf();
        $id = (string)(request_json()['id'] ?? '');
        $s = specials();
        $before = count($s['tech_periods']);
        $s['tech_periods'] = array_values(array_filter($s['tech_periods'], fn($tp) => $tp['id'] !== $id));
        store_write('specials.json', $s);
        admin_log('tech_period_delete', ['id' => $id]);
        json_out(['ok' => true, 'deleted' => $before - count($s['tech_periods'])]);
        break;

    // ---- произвольные санитарные даты ----
    case 'sanitary-add':
        require_auth();
        require_post();
        require_csrf();
        $date = (string)(request_json()['date'] ?? '');
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            json_error('Неверная дата (ожидается ГГГГ-ММ-ДД)');
        }
        $s = specials();
        foreach ($s['sanitary_dates'] as $sd) {
            if ($sd['date'] === $date) {
                json_error('Эта дата уже отмечена санитарной', 409);
            }
        }
        $item = ['id' => new_id(), 'date' => $date];
        $s['sanitary_dates'][] = $item;
        usort($s['sanitary_dates'], fn($a, $b) => strcmp($a['date'], $b['date']));
        store_write('specials.json', $s);
        admin_log('sanitary_date_add', $item);
        json_out(['ok' => true, 'item' => $item]);
        break;

    case 'sanitary-remove':
        require_auth();
        require_post();
        require_csrf();
        $id = (string)(request_json()['id'] ?? '');
        $s = specials();
        $s['sanitary_dates'] = array_values(array_filter($s['sanitary_dates'], fn($sd) => $sd['id'] !== $id));
        store_write('specials.json', $s);
        admin_log('sanitary_date_remove', ['id' => $id]);
        json_out(['ok' => true]);
        break;

    // ---- день недели для санитарных дней ----
    case 'weekday-set':
        require_auth();
        require_post();
        require_csrf();
        $d = request_json();
        $wd = (int)($d['weekday'] ?? 1);
        if ($wd < 1 || $wd > 7) {
            json_error('День недели должен быть 1..7 (1 — понедельник)');
        }
        $s = specials();
        $s['weekday_sanitary'] = ['enabled' => !empty($d['enabled']), 'weekday' => $wd];
        store_write('specials.json', $s);
        admin_log('weekday_sanitary_set', $s['weekday_sanitary']);
        json_out(['ok' => true, 'weekday_sanitary' => $s['weekday_sanitary']]);
        break;

    // ---- периоды действия санитарных дней по дню недели ----
    case 'weekday-period-save':   // создать или обновить период {id?,start,end}
        require_auth();
        require_post();
        require_csrf();
        $d = request_json();
        $start = valid_dt((string)($d['start'] ?? ''));
        $end   = valid_dt((string)($d['end'] ?? ''));
        if (!$start || !$end) {
            json_error('Неверный формат дат. Ожидается "ГГГГ-ММ-ДД ЧЧ:ММ"');
        }
        if (strlen((string)$d['end']) === 10) {
            $end = date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00';
        }
        if ($start >= $end) {
            json_error('Дата окончания должна быть позже даты начала');
        }
        $s = specials();
        $item = ['id' => (string)($d['id'] ?? new_id()), 'start' => $start, 'end' => $end];
        $replaced = false;
        foreach ($s['weekday_periods'] as $i => $wp) {
            if ($wp['id'] === $item['id']) {
                admin_log('sanitary_weekday_period_update', ['from' => $wp, 'to' => $item]);
                $s['weekday_periods'][$i] = $item;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $s['weekday_periods'][] = $item;
            admin_log('sanitary_weekday_period_create', $item);
        }
        usort($s['weekday_periods'], fn($a, $b) => strcmp($a['start'], $b['start']));
        store_write('specials.json', $s);
        json_out(['ok' => true, 'item' => $item]);
        break;

    case 'weekday-period-delete':
        require_auth();
        require_post();
        require_csrf();
        $id = (string)(request_json()['id'] ?? '');
        $s = specials();
        $removed = null;
        foreach ($s['weekday_periods'] as $wp) {
            if ($wp['id'] === $id) {
                $removed = $wp;
            }
        }
        $s['weekday_periods'] = array_values(array_filter($s['weekday_periods'], fn($wp) => $wp['id'] !== $id));
        store_write('specials.json', $s);
        admin_log('sanitary_weekday_period_delete', $removed ?? ['id' => $id]);
        json_out(['ok' => true]);
        break;

    default:
        json_error('Unknown action', 404);
}
