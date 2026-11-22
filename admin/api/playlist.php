<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/engine.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'now':
        // Что покажет плеер прямо сейчас + режим и активные ролики
        require_auth();
        $engine = new PlaylistEngine();
        $now = new DateTimeImmutable('now', new DateTimeZone(cfg()['timezone']));
        $payload = $engine->buildForClient('__preview__', $now); // состояние preview-клиента, не ТВ
        $ctx = $engine->context($now);
        $brief = function (array $entries): array {
            return array_map(fn($e) => [
                'title'  => $e['video']['title'],
                'file'   => $e['video']['file'],
                'periods'=> array_values(array_map(fn($p) => ['start' => $p['start'], 'end' => $p['end']],
                    (array)($e['video']['schedule'] ?? []))),
            ], $entries);
        };
        json_out([
            'now'     => $now->format('Y-m-d H:i:s'),
            'current' => $payload,
            'mode'    => $ctx['mode'],
            'main'       => $brief($ctx['main'] ?? []),
            'additional' => $brief($ctx['additional'] ?? []),
            'tech'       => $ctx['tech'] ?? null,
            'sanitary_video' => isset($ctx['video']) ? ['title' => $ctx['video']['title'], 'file' => $ctx['video']['file']] : null,
        ]);
        break;

    case 'sequence':
        // Смоделировать N следующих показов на заданный момент времени
        require_auth();
        $when = valid_dt((string)($_GET['at'] ?? '')) ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i');
        $n = max(1, min(50, (int)($_GET['count'] ?? 12)));
        $engine = new PlaylistEngine();
        $videosByFile = [];
        foreach (store_read('videos.json') as $v) {
            $videosByFile[$v['file']] = $v;
        }
        $items = $engine->preview(new DateTimeImmutable($when), '__seq__', $n - 1);
        $out = [];
        foreach ($items as $p) {
            $file = $p['Playlist'][0] ?? null;
            $kind = empty($p['Playlist']) ? 'empty'
                  : (!empty($p['maintenance']) ? 'tech'
                  : ($p['SanitaryDay'] ? 'sanitary' : ($videosByFile[$file]['type'] ?? 'main')));
            $title = $file !== null ? ($videosByFile[$file]['title'] ?? $file) : null;
            $out[] = [
                'time'      => null, // время считается на клиенте по watchdog
                'file'      => $file,
                'title'     => $title,
                'kind'      => $kind,
                'watchdog'  => $p['GeneralWatchdog'],
            ];
        }
        json_out(['at' => $when, 'items' => $out]);
        break;

    default:
        json_error('Unknown action', 404);
}
