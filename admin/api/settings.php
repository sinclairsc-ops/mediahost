<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/ffmpeg.php';

$action = $_GET['action'] ?? '';

/** Валидация и нормализация настроек конвертера */
function validate_convert(array $in): array
{
    $cur = convert_settings();
    $out = $cur;

    $res = trim((string)($in['resolution'] ?? ''));
    if ($res !== '' && !preg_match('/^\d{2,5}x\d{2,5}$/', $res)) {
        json_error('Разрешение должно быть вида 1280x720');
    }
    $out['resolution'] = $res;

    foreach (['vcodec', 'pix_fmt', 'acodec'] as $k) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v !== '' && !preg_match('/^[a-z0-9]+$/i', $v)) {
            json_error("Недопустимое значение кодека/формата: $k");
        }
        $out[$k] = $v;
    }
    foreach (['profile'] as $k) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v !== '' && !preg_match('/^[a-z0-9_]+$/i', $v)) {
            json_error("Недопустимое значение: $k");
        }
        $out[$k] = $v;
    }
    // level — число вида 1.3 / 31 / 4.2
    $lv = trim((string)($in['level'] ?? ''));
    if ($lv !== '' && !preg_match('/^\d+(\.\d+)?$/', $lv)) {
        json_error('Level должен быть числом, например 1.3 или 31');
    }
    $out['level'] = $lv;

    $ps = trim((string)($in['preset_speed'] ?? ''));
    $allowedSpeeds = ['ultrafast','superfast','veryfast','faster','fast','medium','slow','slower','veryslow'];
    if ($ps !== '' && !in_array($ps, $allowedSpeeds, true)) {
        json_error('Недопустимый пресет скорости x264');
    }
    $out['preset_speed'] = $ps;

    $crf = trim((string)($in['crf'] ?? ''));
    if ($crf === '') {
        $out['crf'] = null;
    } else {
        if (!ctype_digit($crf) || (int)$crf < 0 || (int)$crf > 51) {
            json_error('CRF — целое число 0..51 (пусто = использовать битрейт)');
        }
        $out['crf'] = (int)$crf;
    }

    foreach (['bitrate', 'maxrate', 'bufsize', 'abitrate'] as $k) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v !== '' && !preg_match('/^\d+(\.\d+)?[kKmMgG]?$/', $v)) {
            json_error("Недопустимый битрейт в поле $k (пример: 2500k)");
        }
        $out[$k] = $v;
    }

    $di = (int)($in['duration_image'] ?? 60);
    $out['duration_image'] = max(1, min(3600, $di));

    return $out;
}

switch ($action) {
    case 'get':
        require_auth();
        json_out([
            'settings' => convert_settings(),
            'defaults' => cfg()['convert_defaults'] ?? [],
            'preview_video'   => FfmpegService::previewCommand(false),
            'preview_image'   => FfmpegService::previewCommand(true),
            'php' => [
                'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
                'post_max_size'       => (string)ini_get('post_max_size'),
                'max_file_uploads'    => (string)ini_get('max_file_uploads'),
            ],
            'max_upload_size' => cfg()['max_upload_size'],
        ]);
        break;

    case 'save':
        require_auth();
        require_post();
        require_csrf();
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            admin_log('settings_denied', ['reason' => 'not admin']);
            json_error('Только администратор может менять настройки', 403);
        }
        $in = request_json();
        $new = validate_convert($in);
        $old = convert_settings();
        save_convert_settings($new);
        admin_log('settings_save', ['from' => $old, 'to' => $new]);
        json_out(['ok' => true, 'settings' => $new,
            'preview_video' => FfmpegService::previewCommand(false),
            'preview_image' => FfmpegService::previewCommand(true)]);
        break;

    default:
        json_error('Unknown action', 404);
}
