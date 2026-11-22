<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

function specials(): array
{
    $s = store_read('specials.json');
    return $s + [
        'tech_periods'    => [],
        'sanitary_dates'  => [],
        'weekday_sanitary'=> ['enabled' => true, 'weekday' => 1],
    ];
}

switch ($action) {
    case 'get':
        require_auth();
        json_out(specials());
        break;

    // ---- периоды технического закрытия ----
    case 'tech-save':
        require_auth();
        require_post();
        require_csrf();
        $d = request_json();
        $start = valid_dt((string)($d['start'] ?? ''));
        $end   = valid_dt((string)($d['end'] ?? ''));
        if (!$start || !$end || $start >= $end) {
            json_error('Неверный период техзакрытия');
        }
        $s = specials();
        $pic = trim((string)($d['picture'] ?? ''));
        if ($pic !== '' && !preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $pic)) {
            json_error('Недопустимое имя картинки');
        }
        $item = ['id' => (string)($d['id'] ?? new_id()), 'start' => $start, 'end' => $end, 'picture' => $pic ?: null];
        $replaced = false;
        foreach ($s['tech_periods'] as $i => $tp) {
            if ($tp['id'] === $item['id']) {
                $s['tech_periods'][$i] = $item;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $s['tech_periods'][] = $item;
        }
        store_write('specials.json', $s);
        admin_log($replaced ? 'tech_period_update' : 'tech_period_create', $item);
        json_out(['ok' => true, 'item' => $item]);
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

    // ---- произвольные санитарные даты ----
    case 'sanitary-add':
        require_auth();
        require_post();
        require_csrf();
        $date = (string)(request_json()['date'] ?? '');
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            json_error('Неверная дата (ожидается ГГГГ-ММ-ДД)');
        }
        $s = specials();
        foreach ($s['sanitary_dates'] as $sd) {
            if ($sd['date'] === $date) {
                json_error('Эта дата уже отмечена санитарной', 409);
            }
        }
        $item = ['id' => new_id(), 'date' => $date];
        $s['sanitary_dates'][] = $item;
        usort($s['sanitary_dates'], fn($a, $b) => strcmp($a['date'], $b['date']));
        store_write('specials.json', $s);
        admin_log('sanitary_date_add', $item);
        json_out(['ok' => true, 'item' => $item]);
        break;

    case 'sanitary-remove':
        require_auth();
        require_post();
        require_csrf();
        $id = (string)(request_json()['id'] ?? '');
        $s = specials();
        $s['sanitary_dates'] = array_values(array_filter($s['sanitary_dates'], fn($sd) => $sd['id'] !== $id));
        store_write('specials.json', $s);
        admin_log('sanitary_date_remove', ['id' => $id]);
        json_out(['ok' => true]);
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
        $s['weekday_sanitary'] = ['enabled' => !empty($d['enabled']), 'weekday' => $wd];
        store_write('specials.json', $s);
        admin_log('weekday_sanitary_set', $s['weekday_sanitary']);
        json_out(['ok' => true, 'weekday_sanitary' => $s['weekday_sanitary']]);
        break;

    default:
        json_error('Unknown action', 404);
}
