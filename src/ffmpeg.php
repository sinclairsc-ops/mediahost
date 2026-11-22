<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/**
 * Сервис конвертации медиа при помощи ffmpeg (Ubuntu).
 * Команды соответствуют текущим windows-скриптам:
 *   видео:       ffmpeg -i IN -s 1280x720 -c:v libx264 -pix_fmt yuv420p -profile:v baseline -level 1.3 -y OUT.mp4
 *   изображение: ffmpeg -loop 1 -i IN -t 60 -s 1280x720 ... -y OUT.mp4
 * Дополнительно: -movflags +faststart (быстрый старт на ТВ), AAC-аудио.
 * Параметры берутся из convert_settings() (страница «Настройки» в админке).
 */
class FfmpegService
{
    public static function ffprobeDuration(string $file): ?float
    {
        $cmd = escapeshellarg(cfg()['ffprobe_bin'])
            . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($file);
        $out = shell_exec($cmd . ' 2>/dev/null');
        $v = trim((string)$out);
        return is_numeric($v) ? (float)$v : null;
    }

    /** Собрать команду конвертации по настройкам (массив аргументов — безопасно) */
    public static function buildCommand(string $input, string $output, array $opts, bool $isImage, ?int $duration = null): array
    {
        $cmd = [cfg()['ffmpeg_bin'], '-hide_banner', '-loglevel', 'error', '-y'];
        if ($isImage) {
            $cmd[] = '-loop';
            $cmd[] = '1';
        }
        $cmd[] = '-i';
        $cmd[] = $input;
        if ($isImage) {
            $cmd[] = '-t';
            $cmd[] = (string)max(1, (int)($duration ?? $opts['duration_image'] ?? 60));
        }
        if (!empty($opts['resolution'])) {
            $cmd[] = '-s';
            $cmd[] = $opts['resolution'];
        }
        $cmd[] = '-c:v';
        $cmd[] = $opts['vcodec'] ?? 'libx264';
        if (!empty($opts['pix_fmt'])) {
            $cmd[] = '-pix_fmt';
            $cmd[] = $opts['pix_fmt'];
        }
        if (!empty($opts['profile'])) {
            $cmd[] = '-profile:v';
            $cmd[] = $opts['profile'];
        }
        if (!empty($opts['level'])) {
            $cmd[] = '-level:v';
            $cmd[] = $opts['level'];
        }
        if (!empty($opts['preset_speed'])) {
            $cmd[] = '-preset';
            $cmd[] = $opts['preset_speed'];
        }
        if (($opts['crf'] ?? null) !== null && $opts['crf'] !== '') {
            $cmd[] = '-crf';
            $cmd[] = (string)(int)$opts['crf'];
        } elseif (!empty($opts['bitrate'])) {
            $cmd[] = '-b:v';
            $cmd[] = $opts['bitrate'];
        }
        if (!empty($opts['maxrate'])) {
            $cmd[] = '-maxrate';
            $cmd[] = $opts['maxrate'];
        }
        if (!empty($opts['bufsize'])) {
            $cmd[] = '-bufsize';
            $cmd[] = $opts['bufsize'];
        }
        // аудио: если в источнике нет дорожки, ffmpeg тихо пропустит -c:a
        $cmd[] = '-c:a';
        $cmd[] = $opts['acodec'] ?? 'aac';
        if (!empty($opts['abitrate'])) {
            $cmd[] = '-b:a';
            $cmd[] = $opts['abitrate'];
        }
        $cmd[] = '-movflags';
        $cmd[] = '+faststart';
        $cmd[] = $output;
        return $cmd;
    }

    /** Предпросмотр команды для страницы настроек */
    public static function previewCommand(bool $isImage): string
    {
        $sample = self::buildCommand(
            $isImage ? '/path/in/kartinka.jpg' : '/path/in/rolik.avi',
            $isImage ? '/var/www/html/media/out.mp4' : '/var/www/html/media/out.mp4',
            convert_settings(), $isImage, (int)(convert_settings()['duration_image'] ?? 60)
        );
        return implode(' ', array_map('escapeshellarg', $sample));
    }

    private static function escapeAll(array $cmd): string
    {
        return implode(' ', array_map('escapeshellarg', $cmd));
    }

    /**
     * Синхронная конвертация ЛЮБОГО входного файла (видео или изображение) в MP4.
     * Настройки конвертера читаются из convert_settings(). Возвращает true/false.
     */
    public static function convert(string $input, string $output, bool $isImage, ?int $duration = null): bool
    {
        $cmd = self::escapeAll(self::buildCommand($input, $output, convert_settings(), $isImage, $duration));
        $rc = 0;
        $log = [];
        @mkdir(root_path('tmp'), 0775, true);
        exec($cmd . ' 2>&1', $log, $rc);
        if ($rc !== 0) {
            file_put_contents(root_path('tmp/ffmpeg-error.log'),
                date('c') . ' rc=' . $rc . ' ' . $cmd . "\n" . implode("\n", $log) . "\n\n", FILE_APPEND);
            admin_log('ffmpeg_error', ['input' => basename($input), 'output' => basename($output), 'rc' => $rc,
                'last' => implode(' | ', array_slice($log, -3))]);
            return false;
        }
        return true;
    }

    /** Кадр превью для админки */
    public static function thumbnail(string $videoFile, string $jpgFile, int $atSecond = 2): bool
    {
        $cmd = self::escapeAll([
            cfg()['ffmpeg_bin'], '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', (string)$atSecond, '-i', $videoFile,
            '-frames:v', '1', '-vf', 'scale=320:-2', $jpgFile,
        ]);
        $rc = 0;
        exec($cmd . ' 2>&1', $out, $rc);
        return $rc === 0 && file_exists($jpgFile);
    }
}
