<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/ffmpeg.php';

$action = $_GET['action'] ?? '';

switch ($action) {
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
        // multipart/form-data: file, title, type(main|additional|splash), preset, duration(для изображений)
        require_auth();
        require_post();
        require_csrf();

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            json_error('Файл не загружен: ' . ($_FILES['file']['error'] ?? 'no file'), 400);
        }
        $tmp   = $_FILES['file']['tmp_name'];
        $orig  = basename($_FILES['file']['name']);
        $ext   = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $title = trim((string)($_POST['title'] ?? pathinfo($orig, PATHINFO_FILENAME)));
        $type  = in_array($_POST['type'] ?? '', ['main','additional'], true) ? $_POST['type'] : 'main';
        $preset = array_key_exists($_POST['preset'] ?? '', cfg()['presets']) ? $_POST['preset'] : array_key_first(cfg()['presets']);

        $isVideo = in_array($ext, cfg()['allowed_video_extensions'], true);
        $isImage = in_array($ext, cfg()['allowed_image_extensions'], true);
        if (!$isVideo && !$isImage) {
            admin_log('upload_rejected', ['name' => $orig, 'ext' => $ext]);
            json_error("Недопустимый тип файла: .$ext", 400);
        }
        if ($_FILES['file']['size'] > cfg()['max_upload_size']) {
            json_error('Файл слишком большой', 400);
        }

        $id  = new_id();
        $slug = slugify($title);
        $storedSource = "src_{$id}_{$orig}"; // как лежит в uploads (безопасное имя)
        $destDir = root_path('uploads');
        @mkdir($destDir, 0775, true);
        if (!move_uploaded_file($tmp, $destDir . '/' . $storedSource)) {
            json_error('Не удалось сохранить загруженный файл', 500);
        }

        // итоговое имя готового ролика: slug.mp4 (уникальное)
        $mediaDir = root_path('media');
        @mkdir($mediaDir, 0775, true);
        $finalName = unique_filename($mediaDir, $slug, 'mp4');

        $duration = null;
        if ($isImage) {
            $duration = (int)($_POST['duration'] ?? cfg()['presets'][$preset]['duration_image']);
            $duration = max(3, min(600, $duration));
        }

        $video = [
            'id'         => $id,
            'title'      => $title,
            'slug'       => $slug,
            'file'       => $finalName,          // media/<file>
            'type'       => $type,               // основной / дополнительный (по умолчанию)
            'kind'       => $isImage ? 'image' : 'video',
            'status'     => 'queued',            // queued -> converting -> ready | error
            'duration'   => null,
            'filesize'   => null,
            'preset'     => $preset,
            'req_duration' => $duration,
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
            'preset'   => $preset,
            'duration' => $duration,
            'added'    => date('c'),
        ];
        store_write('queue.json', $queue);

        admin_log('video_upload', ['id' => $id, 'title' => $title, 'file' => $finalName, 'kind' => $video['kind'], 'preset' => $preset]);
        json_out(['ok' => true, 'video' => $video], 201);
        break;

    case 'update':
        require_auth();
        require_post();
        require_csrf();
        $d  = request_json();
        $id = (string)($d['id'] ?? '');
        $videos = store_read('videos.json');
        foreach ($videos as $i => $v) {
            if ($v['id'] === $id) {
                $changed = [];
                foreach (['title', 'type'] as $f) {
                    if (isset($d[$f]) && $d[$f] !== $v[$f]) {
                        $videos[$i][$f] = $d[$f];
                        $changed[$f] = $d[$f];
                    }
                }
                // переименование файла при смене названия — только если ролик ready и на него нет ссылок в расписании...
                // имя файла остаётся стабильным; меняем только title. Slug-файл переименовывается отдельной операцией rename-file.
                store_write('videos.json', $videos);
                admin_log('video_update', ['id' => $id, 'changes' => $changed]);
                json_out(['ok' => true, 'video' => $videos[$i]]);
            }
        }
        json_error('Not found', 404);
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
        foreach ($videos as $i => $v) {
            if ($v['id'] !== $id) continue;
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
        }
        json_error('Not found', 404);
        break;

    case 'delete':
        require_auth();
        require_post();
        require_csrf();
        $d  = request_json();
        $id = (string)($d['id'] ?? '');
        $videos = store_read('videos.json');
        // проверка использования в расписании
        foreach (store_read('schedule.json') as $p) {
            if (in_array($id, $p['main'] ?? [], true) || in_array($id, $p['additional'] ?? [], true)) {
                json_error("Ролик используется в периоде «{$p['start']} — {$p['end']}». Сначала удалите его из расписания.", 409);
            }
        }
        foreach ($videos as $i => $v) {
            if ($v['id'] === $id) {
                @unlink(root_path('media/' . $v['file']));
                @unlink(root_path('uploads/' . ("src_{$id}_" . ($v['orig_name'] ?? ''))));
                unset($videos[$i]);
                store_write('videos.json', array_values($videos));
                admin_log('video_delete', ['id' => $id, 'file' => $v['file'], 'title' => $v['title']]);
                json_out(['ok' => true]);
            }
        }
        json_error('Not found', 404);
        break;

    default:
        json_error('Unknown action', 404);
}
