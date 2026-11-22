<?php
declare(strict_types=1);

/**
 * Миграция модели данных v1 -> v2.
 *
 * v1: schedule.json — периоды ротации, внутри каждого периода списки main/additional video ids.
 * v2: расписание хранится НА РОЛИКЕ: videos.json[].schedule = [{start,end}] (тип ролика один: main|additional).
 *     Санитарные дни: specials.json.weekday_periods = [{id,start,end}] — периоды действия санитарных дней
 *     по выбранному дню недели; произвольные даты (sanitary_dates) остаются как есть.
 *
 * Запуск: php bin/migrate_v2.php [--dry-run]
 * Создатся бэкапы *.v1.json. Повторный запуск без --force пропускается.
 */

require_once dirname(__DIR__) . '/src/util.php';

$dryRun = in_array('--dry-run', $argv, true);
$force  = in_array('--force', $argv, true);

$videos   = store_read('videos.json');
$schedule = store_read('schedule.json');
$specials = store_read('specials.json') + [
    'tech_periods'     => [],
    'sanitary_dates'   => [],
    'weekday_sanitary' => ['enabled' => true, 'weekday' => 1],
];

if (!empty($videos[0]['schedule']) && !$force) {
    echo "Похоже, миграция уже выполнена (у роликов есть поле schedule). Используйте --force для повторного запуска.\n";
    exit(0);
}

// --- 1. Периоды -> списки периодов на роликах -------------------------------
// Собираем для каждого id ролика его периоды с учётом порядка в исходном списке периода.
$byId = [];
foreach ($schedule as $p) {
    foreach (['main', 'additional'] as $listKey) {
        foreach (array_values((array)($p[$listKey] ?? [])) as $pos => $vid) {
            $byId[$vid][] = [
                'start' => $p['start'],
                'end'   => $p['end'],
                'type'  => $listKey,
                'order' => $pos,          // позиция в списке периода — для определения порядка ротации
                'periodId' => $p['id'],
            ];
        }
    }
}

// Тип ролика: majority-vote (в старой модели тип был у ролика, но задавался через список периода)
$migratedPeriods = 0;
foreach ($videos as $i => $v) {
    $entries = $byId[$v['id']] ?? [];
    $types = array_count_values(array_column($entries, 'type'));
    $type = $v['type'] ?? 'main';
    if ($types) {
        arsort($types);
        $type = (string)array_key_first($types);
    }
    // сортируем периоды по началу, внутри одного начала — по позиции в списке
    usort($entries, fn($a, $b) => [$a['start'], $a['order']] <=> [$b['start'], $b['order']]);

    // склейка соседних периодов с одинаковыми границами (на случай дублей)
    $periods = [];
    foreach ($entries as $e) {
        $last = end($periods);
        if ($last && $last['start'] === $e['start'] && $last['end'] === $e['end']) {
            continue;
        }
        $periods[] = ['id' => new_id(), 'start' => $e['start'], 'end' => $e['end']];
    }

    $videos[$i]['type']     = $type;
    $videos[$i]['schedule'] = $periods;
    $migratedPeriods += count($periods);
}

// Ролики, которых нет в библиотеке, но которые упоминались в периодах — предупредить
$known = array_column($videos, 'id');
$orphans = array_diff(array_keys($byId), $known);

// --- 2. Флаги sanitaryday периодов -> периоды действия санитарных дней ------
// В v1 флаг был у каждого периода ротации. Объединяем пересекающиеся/смежные интервалы.
$spans = [];
foreach ($schedule as $p) {
    if (!empty($p['sanitaryday'])) {
        $spans[] = [strtotime($p['start']), strtotime($p['end'])];
    }
}
usort($spans, fn($a, $b) => $a[0] <=> $b[0]);
$merged = [];
foreach ($spans as $s) {
    $last = end($merged);
    if ($last && $s[0] <= $last[1]) {
        $last[1] = max($last[1], $s[1]);
        $merged[count($merged) - 1] = $last;
    } else {
        $merged[] = $s;
    }
}
$weekdayPeriods = array_map(fn($m) => [
    'id'    => new_id(),
    'start' => date('Y-m-d H:i', $m[0]),
    'end'   => date('Y-m-d H:i', $m[1]),
], $merged);

$newSpecials = $specials;
$newSpecials['weekday_periods'] = $weekdayPeriods;

// --- запись -----------------------------------------------------------------
printf(
    "Роликов: %d, перенесено периодов на ролики: %d, периоды сан. дней после слияния: %d%s\n",
    count($videos), $migratedPeriods, count($weekdayPeriods),
    $orphans ? ('; ВНИМАНИЕ, ссылки на несуществующие ролики: ' . implode(', ', $orphans)) : ''
);

if ($dryRun) {
    echo "--dry-run: изменения не записаны.\n";
    exit(0);
}

@copy(root_path('data/videos.json'), root_path('data/videos.v1.json'));
@copy(root_path('data/specials.json'), root_path('data/specials.v1.json'));
@copy(root_path('data/schedule.json'), root_path('data/schedule.v1.json'));

store_write('videos.json', $videos);
store_write('specials.json', $newSpecials);

echo "Миграция выполнена. Старые версии: data/*.v1.json; schedule.json оставлен для совместимости (не используется движком v2).\n";
