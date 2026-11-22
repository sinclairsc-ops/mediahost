<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

function specials(): array
{
    $s = store_read('specials.json');
    return $s + [
        'tech_periods'     => [],
        'weekday_sanitary' => ['enabled' => true, 'weekday' => 1],
    ];
}

/** Валидация окна периода {start,end}; одиночная дата в конце = конец суток */
function parse_period(array $d): array
{
    $start = valid_dt((string)($d['start'] ?? ''));
    $end   = valid_dt((string)($d['end'] ?? ''));
    if (!$start || !$end) {
        json_error('Неверный формат дат. Ожидается "ГГГГ-ММ-ДД ЧЧ:ММ" или "ГГГГ-ММ-ДД"');
    }
    if (strlen((string)$d['end']) === 10) {
        $end = date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00';
    }
    if ($start >= $end) {
        json_error('Дата окончания должна быть позже даты начала');
    }
    return ['start' => $start, 'end' => $end];
}

/** Найти индекс ролика по id в массиве videos */
function find_video_index2(array $videos, string $id): int
{
    foreach ($videos as $i => $v) {
        if ($v['id'] === $id) {
            return $i;
        }
    }
    json_error('Ролик-заставка не найден', 404);
}

/** Индекс единственного ролика-заставки санитарного дня недели (или -1) */
function weekday_splash_index(array $videos): int
{
    foreach ($videos as $i => $v) {
        if (($v['splash_role'] ?? '') === 'splash_weekday') {
            return $i;
        }
    }
    return -1;
}

switch ($action) {
    case 'get':
        require_auth();
        $s = specials();
        // список заставок tech — для выпадающего выбора в UI периодов техзакрытия
        $techSplashes = [];
        foreach (store_read('videos.json') as $v) {
            if (($v['type'] ?? '') === 'splash' && ($v['splash_role'] ?? '') === 'splash_tech') {
                $techSplashes[] = ['id' => $v['id'], 'title' => $v['title'], 'file' => $v['file'], 'status' => $v['status'] ?? 'ready'];
            }
        }
        $s['tech_splashes'] = $techSplashes;
        // периоды действия санитарных дней = расписание ролика splash_weekday (модель v3)
        $wdPeriods = [];
        foreach (store_read('videos.json') as $v) {
            if (($v['splash_role'] ?? '') === 'splash_weekday') {
                $wdPeriods = array_values(array_map(
                    fn($p) => ['id' => $p['id'] ?? '', 'start' => $p['start'], 'end' => $p['end']],
                    (array)($v['schedule'] ?? [])));
            }
        }
        $s['weekday_periods'] = $wdPeriods;
        json_out($s);
        break;

    // ---- периоды действия санитарных дней (расписание ролика splash_weekday) ----
    case 'weekday-period-save':
        require_auth();
        require_post();
        require_csrf();
        $d = $_POST ?: request_json();
        $p = parse_period($d);
        $videos = store_read('videos.json');
        $i = weekday_splash_index($videos);
        if ($i < 0) {
            json_error('Сначала загрузите ролик-заставку санитарных дней (блок «Ролик санитарного дня»)', 409);
        }
        $videos[$i]['schedule'] ??= [];
        $id = (string)($d['id'] ?? new_id());
        $item = ['id' => $id] + $p;
        $replaced = false;
        foreach ($videos[$i]['schedule'] as $k => $per) {
            if (($per['id'] ?? '') === $id) {
                admin_log('sanitary_weekday_period_update', ['from' => $per, 'to' => $item]);
                $videos[$i]['schedule'][$k] = $item;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $videos[$i]['schedule'][] = $item;
            admin_log('sanitary_weekday_period_create', $item);
        }
        usort($videos[$i]['schedule'], fn($a, $b) => strcmp($a['start'], $b['start']));
        store_write('videos.json', $videos);
        json_out(['ok' => true, 'item' => $item], $replaced ? 200 : 201);
        break;

    case 'weekday-period-delete':
        require_auth();
        require_post();
        require_csrf();
        $id = (string)(request_json()['id'] ?? '');
        $videos = store_read('videos.json');
        $i = weekday_splash_index($videos);
        if ($i < 0) {
            json_error('Заставка санитарных дней не найдена', 404);
        }
        $before = count((array)($videos[$i]['schedule'] ?? []));
        $videos[$i]['schedule'] = array_values(array_filter(
            (array)($videos[$i]['schedule'] ?? []), fn($per) => ($per['id'] ?? '') !== $id));
        store_write('videos.json', $videos);
        admin_log('sanitary_weekday_period_delete', ['id' => $id]);
        json_out(['ok' => true, 'deleted' => $before - count($videos[$i]['schedule'])]);
        break;

    // ---- периоды технического закрытия ----
    // Окно показа + назначенный mp4-ролик (splash_tech). Ролики создаются/редактируются
    // как обычные ролики во вкладке «Ролики» (фильтр «заставки»).
    case 'tech-save':
        require_auth();
        require_post();
        require_csrf();
        $d = $_POST ?: request_json();
        $p = parse_period($d);
        $videoId = trim((string)($d['video_id'] ?? ''));
        $splashVideo = null;
        if ($videoId !== '') {
            $vi = find_video_index2(store_read('videos.json'), $videoId);
            $sel = store_read('videos.json')[$vi];
            if (($sel['type'] ?? '') !== 'splash' || ($sel['splash_role'] ?? '') !== 'splash_tech') {
                json_error('Выбранный ролик не является заставкой технического закрытия', 400);
            }
            $splashVideo = $sel['file'];
        }
        $s = specials();
        $id = (string)($d['id'] ?? new_id());
        $item = ['id' => $id] + $p + ['splash_video' => $splashVideo];
        $replaced = false;
        foreach ($s['tech_periods'] as $i => $tp) {
            if ($tp['id'] === $id) {
                // при пустом video_id оставляем ранее назначенный ролик
                $item['splash_video'] = $splashVideo ?? ($tp['splash_video'] ?? null);
                admin_log('tech_period_update', ['from' => $tp, 'to' => $item]);
                $s['tech_periods'][$i] = $item;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $s['tech_periods'][] = $item;
            admin_log('tech_period_create', $item);
        }
        usort($s['tech_periods'], fn($a, $b) => strcmp($a['start'], $b['start']));
        store_write('specials.json', $s);
        json_out(['ok' => true, 'item' => $item], $replaced ? 200 : 201);
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
        $old = $s['weekday_sanitary'];
        $s['weekday_sanitary'] = ['enabled' => !empty($d['enabled']), 'weekday' => $wd];
        store_write('specials.json', $s);
        admin_log('weekday_sanitary_set', ['from' => $old, 'to' => $s['weekday_sanitary']]);
        json_out(['ok' => true, 'weekday_sanitary' => $s['weekday_sanitary']]);
        break;

    default:
        json_error('Unknown action', 404);
}
