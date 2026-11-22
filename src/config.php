<?php
// Скопируйте этот файл в config.php и укажите свои значения.
// config.php не должен попадать в git (см. .gitignore).

return [
    // Путь к ffmpeg/ffprobe на сервере Ubuntu
    'ffmpeg_bin'  => '/usr/bin/ffmpeg',
    'ffprobe_bin' => '/usr/bin/ffprobe',

    // Часовой пояс (важно для расписания!)
    'timezone' => 'Europe/Moscow',

    // Пресеты конвертации. Значения по умолчанию соответствуют текущему
    // windows-скрипту: 1280x720, H.264 baseline level 1.3, yuv420p.
    'presets' => [
        'tv720' => [
            'label'      => 'ТВ 720p (baseline, как сейчас)',
            'resolution' => '1280x720',
            'vcodec'     => 'libx264',
            'pix_fmt'    => 'yuv420p',
            'profile'    => 'baseline',
            'level'      => '1.3',
            'acodec'     => 'aac',
            'abitrate'   => '128k',
            'extra_v'    => ['-preset', 'medium'],
            'duration_image' => 60, // сек, длительность ролика из изображения
        ],
        'tv720_fast' => [
            'label'      => 'ТВ 720p (быстрое кодирование, crf 23)',
            'resolution' => '1280x720',
            'vcodec'     => 'libx264',
            'pix_fmt'    => 'yuv420p',
            'profile'    => 'baseline',
            'level'      => '1.3',
            'acodec'     => 'aac',
            'abitrate'   => '128k',
            'extra_v'    => ['-preset', 'veryfast', '-crf', '23'],
            'duration_image' => 60,
        ],
    ],

    // Разрешённые расширения загружаемых файлов
    'allowed_video_extensions' => ['mp4','mov','avi','mkv','webm','mts','wmv','flv','mpg','mpeg','m4v','3gp'],
    'allowed_image_extensions' => ['jpg','jpeg','png','webp','bmp','gif'],

    // Максимальный размер загрузки, байт
    'max_upload_size' => 2 * 1024 * 1024 * 1024,

    // Заставки (splash), показываются вместо обычного плейлиста
    'splashes' => [
        'sanitary' => ['file' => 's_day.mp4',  'picture' => 's_day.jpg'],
        'tech'     => ['file' => 'tech.mp4',   'picture' => 'tech03042023.jpg'],
    ],

    // Watchdog: round(duration_min + 1.5), минимум 3 — как в старом playlist.php
    'watchdog_default' => 3,
];
