#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Миграция на модель v3: заставки (санитарный день недели, техзакрытия) становятся
 * обычными mp4-роликами в videos.json с type=splash и собственным расписанием.
 *  - weekday_periods -> расписание единственного ролика splash_role=splash_weekday;
 *    файл берётся из config['splashes']['sanitary']['file'] (обычно s_day.mp4)
 *  - tech_periods[].video/picture -> окно показа + ссылка на ролик-заставку splash_tech
 *    (расписание ролика покрывает все окна техзакрытия с этим файлом)
 *  - sanitary_dates удаляется: произвольная санитарная дата = период техзакрытия на один день
 *  Запуск: php bin/migrate_v3.php [--dry-run]
 */

require_once dirname(__DIR__) . '/src/util.php';

$dry = in_array('--dry-run', $argv, true);
date_default_timezone_set(cfg()['timezone']);

$videos   = store_read('videos.json');
$specials = store_read('specials.json') + [
    'tech_periods'     => [],
    'sanitary_dates'   => [],
    'weekday_periods'  => [],
    'weekday_sanitary' => ['enabled' => true, 'weekday' => 1],
];

function find_index_by_file(array $videos, string $file): int
{
    foreach ($videos as $i => $v) {
        if ($v['file'] === $file) {
            return $i;
        }
    }
    return -1;
}

// --- 1. ролик санитарного дня недели ---
$sanFile = cfg()['splashes']['sanitary']['file'] ?? 's_day.mp4';
$i = find_index_by_file($videos, $sanFile);
if ($i >= 0) {
    $videos[$i]['type'] = 'splash';
    $videos[$i]['splash_role'] = 'splash_weekday';
    $videos[$i]['schedule'] = array_map(fn($p) => ['id' => $p['id'] ?? new_id(), 'start' => $p['start'], 'end' => $p['end']],
        (array)$specials['weekday_periods']);
    echo "Заставка сан. дней: существующий ролик {$videos[$i]['title']} ({$sanFile}), периодов: " . count($videos[$i]['schedule']) . "\n";
} else {
    $videos[] = [
        'id' => new_id(), 'title' => 'Санитарный день', 'slug' => slugify('Санитарный день'),
        'file' => $sanFile, 'type' => 'splash', 'kind' => 'video', 'splash_role' => 'splash_weekday',
        'status' => is_file(root_path('media/' . $sanFile)) ? 'ready' : 'error',
        'duration' => null, 'filesize' => null,
        'schedule' => array_map(fn($p) => ['id' => $p['id'] ?? new_id(), 'start' => $p['start'], 'end' => $p['end']],
            (array)$specials['weekday_periods']),
        'created' => date('c'), 'error' => null,
    ];
    echo "Заставка сан. дней: создан новый ролик ({$sanFile})\n";
}

// --- 2. ролики технических заставок: группируем окна по файлу-заставке ---
$resolvedTech = [];   // file => [[start,end],...]
foreach ((array)$specials['tech_periods'] as $tp) {
    $file = $tp['video'] ?? null;
    if ($file === null || $file === '') {
        $file = cfg()['splashes']['tech']['file'] ?? 'tech.mp4';
    }
    $resolvedTech[$file][] = ['start' => $tp['start'], 'end' => $tp['end']];
}
unset($resolvedTech[$sanFile]); // файл санитарной заставки остаётся role=splash_weekday

$newTech = [];
foreach ((array)$specials['tech_periods'] as $tp) {
    $file = $tp['video'] ?? null;
    if ($file === null || $file === '') {
        $file = cfg()['splashes']['tech']['file'] ?? 'tech.mp4';
    }
    $newTech[] = [
        'id' => $tp['id'], 'start' => $tp['start'], 'end' => $tp['end'],
        'splash_video' => $file,
    ];
}
foreach ($resolvedTech as $file => $windows) {
    $sched = [];
    foreach ($windows as $w) {
        $sched[] = ['id' => new_id(), 'start' => $w['start'], 'end' => $w['end']];
    }
    usort($sched, fn($a, $b) => strcmp($a['start'], $b['start']));
    $i = find_index_by_file($videos, $file);
    if ($i >= 0) {
        $videos[$i]['type'] = 'splash';
        $videos[$i]['splash_role'] = 'splash_tech';
        $videos[$i]['schedule'] = $sched;
        echo "Техзаставка {$file}: дополнен существующий ролик, окон: " . count($sched) . "\n";
    } else {
        $title = pathinfo($file, PATHINFO_FILENAME);
        $videos[] = [
            'id' => new_id(), 'title' => $title, 'slug' => slugify($title),
            'file' => $file, 'type' => 'splash', 'kind' => 'video', 'splash_role' => 'splash_tech',
            'status' => is_file(root_path('media/' . $file)) ? 'ready' : 'error',
            'duration' => null, 'filesize' => null, 'schedule' => $sched,
            'created' => date('c'), 'error' => null,
        ];
        echo "Техзаставка {$file}: создан ролик, окон: " . count($sched) . "\n";
    }
}

$newSpecials = [
    'tech_periods'     => $newTech,
    'weekday_sanitary' => $specials['weekday_sanitary'],
];

echo "\nИтого роликов: " . count($videos) . ", окон техзакрытия: " . count($newTech)
    . ", удалено произвольных сан. дат: " . count((array)$specials['sanitary_dates']) . "\n";
foreach ($videos as $v) {
    if (($v['type'] ?? '') === 'splash') {
        echo sprintf("  SPLASH %-15s %-28s [%s] per=%d\n", $v['splash_role'], $v['file'], $v['status'], count($v['schedule'] ?? []));
    }
}

if (!$dry) {
    copy(root_path('data/videos.json'), root_path('data/videos.v2.json'));
    copy(root_path('data/specials.json'), root_path('data/specials.v2.json'));
    store_write('videos.json', $videos);
    store_write('specials.json', $newSpecials);
    admin_log('migrate_v3', ['splash_videos' => count(array_filter($videos, fn($v) => ($v['type'] ?? '') === 'splash')),
        'tech_windows' => count($newTech)]);
    echo "\nМиграция выполнена. Бэкапы: data/videos.v2.json, data/specials.v2.json\n";
} else {
    echo "\nDRY-RUN: ничего не записано\n";
}
