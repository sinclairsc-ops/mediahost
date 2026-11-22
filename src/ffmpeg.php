<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/**
 * Сервис конвертации медиа при помощи ffmpeg (Ubuntu).
 * Команды соответствуют текущим windows-скриптам:
 *   видео:       ffmpeg -i IN -s 1280x720 -c:v libx264 -pix_fmt yuv420p -profile:v baseline -level 1.3 -y OUT.mp4
 *   изображение: ffmpeg -loop 1 -i IN -t 60 -s 1280x720 ... -y OUT.mp4
 * Дополнительно добавлены -movflags +faststart (быстрый старт на ТВ) и AAC-аудио.
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

    /** Разрешить произвольные параметры конвертации, но собрать команду безопасно (массив аргументов) */
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
            $cmd[] = (string)($duration ?? $opts['duration_image'] ?? 60);
        }
        $cmd[] = '-s';
        $cmd[] = $opts['resolution'] ?? '1280x720';
        $cmd[] = '-c:v';
        $cmd[] = $opts['vcodec'] ?? 'libx264';
        $cmd[] = '-pix_fmt';
        $cmd[] = $opts['pix_fmt'] ?? 'yuv420p';
        if (!empty($opts['profile'])) {
            $cmd[] = '-profile:v';
            $cmd[] = $opts['profile'];
        }
        if (!empty($opts['level'])) {
            $cmd[] = '-level:v';
            $cmd[] = $opts['level'];
        }
        foreach ($opts['extra_v'] ?? [] as $a) {
            $cmd[] = $a;
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

    private static function escapeAll(array $cmd): string
    {
        return implode(' ', array_map('escapeshellarg', $cmd));
    }

    /**
     * Синхронная конвертация (используется воркером). Возвращает true/false.
     */
    public static function convert(string $input, string $output, string $presetKey, bool $isImage, ?int $duration = null): bool
    {
        $presets = cfg()['presets'];
        $opts = $presets[$presetKey] ?? reset($presets);
        $cmd = self::escapeAll(self::buildCommand($input, $output, $opts, $isImage, $duration));
        $rc = 0;
        $log = [];
        exec($cmd . ' 2>&1', $log, $rc);
        if ($rc !== 0) {
            file_put_contents(root_path('tmp/ffmpeg-error.log'),
                date('c') . ' ' . $cmd . "\n" . implode("\n", $log) . "\n\n", FILE_APPEND);
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
