#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Воркер очереди конвертации. Запускать из cron каждые N минут:
 *   * * * * * www-data /usr/bin/php /var/www/html/bin/worker.php >> /var/log/media-worker.log 2>&1
 * Либо как systemd-timer. Обрабатывает задачи по одной за проход.
 */

require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/ffmpeg.php';

date_default_timezone_set(cfg()['timezone']);

$queueFile = root_path('data/queue.json');
$fp = fopen($queueFile, 'c+b');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit(0); // другая копия воркера уже работает
}

$queue = json_decode((string)stream_get_contents($fp), true);
if (!is_array($queue) || !$queue) {
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);
}

$task = array_shift($queue);

// запишем очередь обратно до начала долгой работы, чтобы не схватить задачу дважды
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

markStatus($task['video_id'], 'converting');

$input  = root_path('uploads/' . $task['source']);
$output = root_path('media/' . $task['filename']);
@mkdir(dirname($output), 0775, true);

$isImage = $task['kind'] === 'image';
$ok = is_file($input) && FfmpegService::convert($input, $output, $isImage, $task['duration'] ?? null);

if ($ok) {
    $dur = FfmpegService::ffprobeDuration($output);
    if (isTechTask($task)) {
        setTechVideo((string)$task['target'], $task['filename']);
    } else {
        updateVideo($task['video_id'], [
            'status'   => 'ready',
            'duration' => $dur,
            'filesize' => filesize($output),
            'error'    => null,
        ]);
    }
    @unlink($input); // исходник больше не нужен
    echo "OK: {$task['filename']} (" . round($dur ?? 0) . "s)\n";
} else {
    if (!isTechTask($task)) {
        updateVideo($task['video_id'], ['status' => 'error', 'error' => 'ffmpeg failed, see tmp/ffmpeg-error.log']);
    }
    admin_log('convert_failed', ['file' => $task['filename'], 'source' => $task['source'], 'kind' => $task['kind']]);
    echo "FAIL: {$task['filename']}\n";
}

function isTechTask(array $task): bool
{
    return isset($task['target']) && str_starts_with((string)$task['target'], 'tech:');
}

/** После конвертации заставки техзакрытия — прописать mp4 в соответствующий период */
function setTechVideo(string $target, string $filename): void
{
    $id = substr($target, strlen('tech:'));
    $s = store_read('specials.json') + ['tech_periods' => []];
    foreach ($s['tech_periods'] as $i => $tp) {
        if (($tp['id'] ?? '') === $id) {
            $s['tech_periods'][$i]['video'] = $filename;
            break;
        }
    }
    store_write('specials.json', $s);
    admin_log('tech_splash_ready', ['period_id' => $id, 'file' => $filename]);
}

function markStatus(string $videoId, string $status): void
{
    if (str_starts_with($videoId, 'tech:')) {
        return; // у задач техзакрытия статуса в videos.json нет
    }
    updateVideo($videoId, ['status' => $status]);
}

function updateVideo(string $videoId, array $fields): void
{
    $videos = store_read('videos.json');
    foreach ($videos as $i => $v) {
        if ($v['id'] === $videoId) {
            $videos[$i] = array_merge($v, $fields);
            break;
        }
    }
    store_write('videos.json', $videos);
}
