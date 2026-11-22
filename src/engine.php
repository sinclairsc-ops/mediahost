<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/**
 * Движок формирования плейлиста (модель v3).
 *
 * Совместимость со старым playlist.php:
 *  - формат ответа: {"Playlist":[...], "SanitaryDay":bool, "GeneralWatchdog":N, ["maintenance":true]}
 *  - состояние чередования основных/дополнительных по IP: serialize([mainIdx, wasAdditional])
 *  - watchdog = round(duration_min + 1.5), минимум 3 (как GenerateWatchDog в старом коде)
 *
 * Модель данных v3 (расписание хранится НА РОЛИКЕ; заставки — тоже ролики):
 *  videos.json   — [{id,title,slug,file,type:main|additional|splash,duration,status,
 *                    splash_role:splash_weekday|splash_tech (для type=splash),
 *                    schedule:[{id,start,end}, ...]}]  — периоды активности ролика
 *  specials.json — {
 *      tech_periods:    [{id,start,end,splash_video}],  // окна техзакрытия (наивысший приоритет)
 *      weekday_sanitary:{enabled,weekday}               // какой день недели считать санитарным
 *  }
 *  Санитарный день недели показывает ролик с splash_role=splash_weekday и активным периодом на сегодня.
 *  Произвольная санитарная дата = период техзакрытия на один день с нужным роликом.
 *  Приоритеты: техзакрытие > санитарный день > ротация активных роликов.
 *  Основные ролики идут по порядку (сортировка по началу периода, затем по позиции в библиотеке),
 *  дополнительные выбираются случайным образом из активных на текущий момент.
 */
class PlaylistEngine
{
    private array $videos;     // id => video (порядок = позиция в библиотеке)
    private array $specials;

    public function __construct()
    {
        $this->videos = [];
        foreach (store_read('videos.json') as $v) {
            $this->videos[$v['id']] = $v;
        }
        $this->specials = store_read('specials.json') + [
            'tech_periods'     => [],
            'weekday_sanitary' => ['enabled' => true, 'weekday' => 1],
        ];
    }

    /** Файл ролика по id (для отдачи плееру) */
    public function videoFile(string $id): ?string
    {
        return $this->videos[$id]['file'] ?? null;
    }

    /** Активные на момент $now ролики с указанными типами, отсортированные по порядку показа */
    public function activeVideos(DateTimeInterface $now, string|array $types): array
    {
        $types = (array)$types;
        $out = [];
        foreach ($this->videos as $pos => $v) {
            if (!in_array($v['type'] ?? 'main', $types, true)) {
                continue;
            }
            if (($v['status'] ?? 'ready') !== 'ready') {
                continue; // ещё не сконвертирован / ошибка
            }
            foreach ((array)($v['schedule'] ?? []) as $p) {
                if ($this->inRange($now, (string)$p['start'], (string)$p['end'])) {
                    $out[] = ['video' => $v, 'period' => $p, 'pos' => $pos];
                    break;
                }
            }
        }
        usort($out, function ($a, $b) {
            $c = strcmp($a['period']['start'], $b['period']['start']);
            return $c !== 0 ? $c : ($a['pos'] <=> $b['pos']);
        });
        return $out;
    }

    /**
     * Сформировать ответ для клиента (ТВ) на текущий момент, обновить состояние по IP.
     */
    public function buildForClient(string $clientIp, DateTimeImmutable $now): array
    {
        $state = $this->loadState($clientIp);
        $res = $this->build($now, $state, true);
        $this->saveState($clientIp, $res['state']);
        return $res['payload'];
    }

    /** Только предпросмотр без изменения состояния (для админки) */
    public function preview(DateTimeImmutable $now, string $clientIp = '__preview__', int $extra = 0): array
    {
        $items = [];
        $state = $this->loadState($clientIp);
        $t = $now;
        for ($i = 0; $i <= $extra; $i++) {
            $res = $this->build($t, $state, false);
            $items[] = $res['payload'];
            $state = $res['state'];
            $t = $t->modify('+' . max(1, $res['payload']['GeneralWatchdog']) . ' minutes');
        }
        return $items;
    }

    /** Внутренний расчёт одного "слота" показа */
    private function build(DateTimeImmutable $now, array $state, bool $useRandom): array
    {
        // 1. Период технического закрытия — наивысший приоритет: играется только ролик техзакрытия.
        foreach ($this->specials['tech_periods'] ?? [] as $tp) {
            if ($this->inRange($now, (string)$tp['start'], (string)$tp['end'])) {
                $video = $this->splashFileForPeriod($tp);
                return [
                    'state' => $state,
                    'payload' => [
                        'Playlist' => [$video],
                        'SanitaryDay' => false,
                        'GeneralWatchdog' => $this->watchdogForFile((string)$video),
                        'maintenance' => true,
                    ],
                ];
            }
        }

        // 2. Санитарный день недели: показываем единственный ролик-заставку splash_weekday,
        //    у которого есть активный период на сейчас (расписание как у обычных роликов).
        if (!empty($this->specials['weekday_sanitary']['enabled'])) {
            $wd = (int)($this->specials['weekday_sanitary']['weekday'] ?? 1);
            if ((int)$now->format('N') === $wd) {
                $san = $this->weekdaySplashVideo($now);
                if ($san !== null) {
                    // состояние ротации не крутим — показываем заставку
                    return [
                        'state' => $state,
                        'payload' => [
                            'Playlist' => [$san['file']],
                            'SanitaryDay' => true,
                            'GeneralWatchdog' => max(3, $this->watchdogForFile($san['file'])),
                        ],
                    ];
                }
            }
        }

        // 3. Обычная ротация: основные ролики по порядку + случайные дополнительные (по своим периодам)
        $mainList = array_map(fn($e) => $e['video']['file'], $this->activeVideos($now, 'main'));
        $addList  = array_map(fn($e) => $e['video']['file'], $this->activeVideos($now, 'additional'));
        $main = array_values(array_filter($mainList, fn($f) => $f !== null));
        $additional = array_values(array_filter($addList, fn($f) => $f !== null));

        $wasAdditional = (int)($state[1] ?? 0);
        $mainIdx       = (int)($state[0] ?? 0);
        $playlist = [];

        if (!$main && !$additional) {
            // нет активного периода/роликов — пустой список, плеер покажет дефолт
            return [
                'state' => $state,
                'payload' => [
                    'Playlist' => [],
                    'SanitaryDay' => false,
                    'GeneralWatchdog' => cfg()['watchdog_default'],
                ],
            ];
        }

        // чередование: после основного идёт дополнительный (если есть), иначе основной
        if ($wasAdditional === 1 && count($additional)) {
            $idx = $useRandom
                ? random_int(0, count($additional) - 1)
                : ($mainIdx % count($additional));
            $playlist[0] = $additional[$idx];
            $newWasAdd = 0;
        } else {
            if ($mainIdx >= count($main)) {
                $mainIdx = 0;
            }
            $playlist[0] = $main[$mainIdx];
            $mainIdx++;
            $newWasAdd = count($additional) ? 1 : 0;
        }

        $state = [$mainIdx % max(1, count($main)), $newWasAdd];

        return [
            'state' => $state,
            'payload' => [
                'Playlist' => $playlist,
                'SanitaryDay' => false,
                'GeneralWatchdog' => $this->watchdogFor($playlist[0]),
            ],
        ];
    }

    /**
     * Контекст для админки: активные сейчас ролики, статус санитарного дня/техзакрытия.
     */
    public function context(DateTimeInterface $now): array
    {
        foreach ($this->specials['tech_periods'] ?? [] as $tp) {
            if ($this->inRange($now, (string)$tp['start'], (string)$tp['end'])) {
                return ['mode' => 'tech', 'tech' => $tp + ['splash_video' => $this->splashFileForPeriod($tp)]];
            }
        }
        if (!empty($this->specials['weekday_sanitary']['enabled'])) {
            $wd = (int)($this->specials['weekday_sanitary']['weekday'] ?? 1);
            if ((int)$now->format('N') === $wd) {
                $san = $this->weekdaySplashVideo($now);
                if ($san !== null) {
                    return ['mode' => 'sanitary', 'reason' => 'weekday', 'video' => $san];
                }
            }
        }
        return [
            'mode'   => 'rotation',
            'main'       => $this->activeVideos($now, 'main'),
            'additional' => $this->activeVideos($now, 'additional'),
        ];
    }

    /** Единственный ролик-заставка санитарных дней недели, активный на момент $now */
    private function weekdaySplashVideo(DateTimeInterface $now): ?array
    {
        foreach ($this->videos as $v) {
            if (($v['type'] ?? '') === 'splash' && ($v['splash_role'] ?? '') === 'splash_weekday'
                && ($v['status'] ?? '') === 'ready' && !empty($v['file'])) {
                foreach ((array)($v['schedule'] ?? []) as $p) {
                    if ($this->inRange($now, (string)$p['start'], (string)$p['end'])) {
                        return $v;
                    }
                }
                return null; // заставка есть, но сегодня вне периодов действия — обычная ротация
            }
        }
        return null;
    }

    /**
     * Файл mp4-заставки для периода техзакрытия:
     *  1) явно назначенный периоду ролик (splash_video, легаси video);
     *  2) любой готовый splash_tech, у которого есть период, покрытый окном техзакрытия;
     *  3) резервный файл из config ('splashes').
     */
    private function splashFileForPeriod(array $tp): ?string
    {
        foreach ([$tp['splash_video'] ?? null, $tp['video'] ?? null] as $f) {
            if (is_string($f) && $f !== '' && $this->videoReadyByFile($f)) {
                return $f;
            }
        }
        $tz = new DateTimeZone(cfg()['timezone']);
        $winStart = new DateTimeImmutable($tp['start'], $tz);
        $winEnd   = new DateTimeImmutable($tp['end'], $tz);
        foreach ($this->videos as $v) {
            if (($v['type'] ?? '') !== 'splash' || ($v['splash_role'] ?? '') !== 'splash_tech') {
                continue;
            }
            if (($v['status'] ?? '') !== 'ready' || empty($v['file'])) {
                continue;
            }
            foreach ((array)($v['schedule'] ?? []) as $p) {
                $s = new DateTimeImmutable($p['start'], $tz);
                $e = new DateTimeImmutable($p['end'], $tz);
                if ($s < $winEnd && $e > $winStart) {
                    return $v['file'];
                }
            }
        }
        return cfg()['splashes']['tech']['file'] ?? null;
    }

    /** Готов ли файл к показу: есть в библиотеке со статусом ready либо лежит в media/ */
    private function videoReadyByFile(string $file): bool
    {
        foreach ($this->videos as $v) {
            if ($v['file'] === $file) {
                return ($v['status'] ?? 'ready') === 'ready';
            }
        }
        return is_file(root_path('media/' . $file));
    }

    private function inRange(DateTimeInterface $now, string $start, string $end): bool
    {
        $tz = new DateTimeZone(cfg()['timezone']);
        $s = new DateTimeImmutable($start, $tz);
        $e = new DateTimeImmutable($end, $tz);
        return $now >= $s && $now < $e;
    }

    /** Watchdog в минутах по имени файла (duration из videos.json), как в старом GenerateWatchDog */
    private function watchdogForFile(string $file): int
    {
        foreach ($this->videos as $v) {
            if ($v['file'] === $file) {
                return $this->watchdogValue($v['duration'] ?? null);
            }
        }
        return cfg()['watchdog_default'];
    }

    private function watchdogFor(?string $file): int
    {
        return $file === null ? cfg()['watchdog_default'] : $this->watchdogForFile($file);
    }

    private function watchdogValue($durationSec): int
    {
        if (!$durationSec) {
            return cfg()['watchdog_default'];
        }
        return max(3, (int)round(((float)$durationSec) / 60 + 1.5));
    }

    private function stateFile(string $ip): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $ip);
        return root_path("data/state/state_{$safe}.dat");
    }

    private function loadState(string $ip): array
    {
        $f = $this->stateFile($ip);
        if (is_file($f)) {
            $st = @unserialize(file_get_contents($f));
            if (is_array($st) && count($st) >= 2) {
                return [(int)$st[0], (int)$st[1]];
            }
        }
        return [0, 0];
    }

    private function saveState(string $ip, array $state): void
    {
        $f = $this->stateFile($ip);
        @mkdir(dirname($f), 0775, true);
        file_put_contents($f, serialize($state), LOCK_EX);
    }
}
