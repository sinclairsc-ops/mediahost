<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

/** Валидация и нормализация периода из JSON запроса */
function parse_period(array $d): array
{
    $start = valid_dt((string)($d['start'] ?? ''));
    $end   = valid_dt((string)($d['end'] ?? ''));
    if (!$start || !$end) {
        json_error('Неверный формат дат. Ожидается "ГГГГ-ММ-ДД ЧЧ:ММ"');
    }
    if ($start >= $end) {
        json_error('Дата окончания должна быть позже даты начала');
    }
    $videosById = [];
    foreach (store_read('videos.json') as $v) {
        $videosById[$v['id']] = $v;
    }
    $lists = [];
    foreach (['main', 'additional'] as $key) {
        $ids = array_values(array_unique(array_map('strval', (array)($d[$key] ?? []))));
        foreach ($ids as $id) {
            if (!isset($videosById[$id])) {
                json_error("Ролик $id не найден в библиотеке");
            }
        }
        $lists[$key] = $ids;
    }
    return [
        'start'       => $start,
        'end'         => $end,
        'sanitaryday' => !empty($d['sanitaryday']),
        'main'        => $lists['main'],
        'additional'  => $lists['additional'],
    ];
}

switch ($action) {
    case 'list':
        require_auth();
        $periods = store_read('schedule.json');
        usort($periods, fn($a, $b) => strcmp($a['start'], $b['start']));
        json_out($periods);
        break;

    case 'save': // создать или обновить период (id опционально)
        require_auth();
        require_post();
        require_csrf();
        $d = request_json();
        $p = parse_period($d);
        $periods = store_read('schedule.json');

        if (!empty($d['id'])) {
            $found = false;
            foreach ($periods as $i => $old) {
                if ($old['id'] === $d['id']) {
                    $p['id'] = $old['id'];
                    $periods[$i] = $p;
                    $found = true;
                    admin_log('period_update', ['id' => $p['id'], 'from' => $old, 'to' => $p]);
                    break;
                }
            }
            if (!$found) {
                json_error('Период не найден', 404);
            }
        } else {
            $p['id'] = new_id();
            $p['created'] = date('c');
            $periods[] = $p;
            admin_log('period_create', ['id' => $p['id'], 'period' => $p]);
        }
        store_write('schedule.json', $periods);
        json_out(['ok' => true, 'period' => $p], empty($d['id']) ? 201 : 200);
        break;

    case 'delete':
        require_auth();
        require_post();
        require_csrf();
        $d  = request_json();
        $id = (string)($d['id'] ?? '');
        $periods = store_read('schedule.json');
        foreach ($periods as $i => $p) {
            if ($p['id'] === $id) {
                unset($periods[$i]);
                store_write('schedule.json', array_values($periods));
                admin_log('period_delete', ['id' => $id, 'period' => $p]);
                json_out(['ok' => true]);
            }
        }
        json_error('Not found', 404);
        break;

    default:
        json_error('Unknown action', 404);
}
