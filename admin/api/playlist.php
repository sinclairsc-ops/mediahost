<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/engine.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'now':
        // Что покажет плеер прямо сейчас + активный период + предупреждения
        require_auth();
        $engine = new PlaylistEngine();
        $now = new DateTimeImmutable('now', new DateTimeZone(cfg()['timezone']));
        $payload = $engine->buildForClient('__preview__', $now); // состояние preview-клиента, не ТВ
        $period = $engine->activePeriod($now);
        json_out([
            'now'     => $now->format('Y-m-d H:i:s'),
            'current' => $payload,
            'period'  => $period,
        ]);
        break;

    case 'sequence':
        // Смоделировать N следующих показов на заданный момент времени
        require_auth();
        $when = valid_dt((string)($_GET['at'] ?? '')) ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i');
        $n = max(1, min(50, (int)($_GET['count'] ?? 12)));
        $engine = new PlaylistEngine();
        $videosById = [];
        foreach (store_read('videos.json') as $v) {
            $videosById[$v['file']] = $v;
        }
        $items = $engine->preview(new DateTimeImmutable($when), '__seq__', $n - 1);
        $out = [];
        foreach ($items as $p) {
            $file = $p['Playlist'][0] ?? null;
            $out[] = [
                'time'      => null, // время считается на клиенте по watchdog
                'file'      => $file,
                'title'     => $file && isset($videosById[$file]) ? $videosById[$file]['title'] : $file,
                'kind'      => empty($p['Playlist']) ? 'empty'
                              : (!empty($p['maintenance']) ? 'tech'
                              : ($p['SanitaryDay'] ? 'sanitary' : ($videosById[$file]['type'] ?? 'main'))),
                'watchdog'  => $p['GeneralWatchdog'],
            ];
        }
        json_out(['at' => $when, 'items' => $out]);
        break;

    default:
        json_error('Unknown action', 404);
}
