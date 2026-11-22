<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/**
 * Движок формирования плейлиста (модель v2).
 *
 * Совместимость со старым playlist.php:
 *  - формат ответа: {"Playlist":[...], "SanitaryDay":bool, "GeneralWatchdog":N, ["maintenance":true], ["maintenancepicture":"..."]}
 *  - состояние чередования основных/дополнительных по IP: serialize([mainIdx, wasAdditional])
 *  - watchdog = round(duration_min + 1.5), минимум 3 (как GenerateWatchDog в старом коде)
 *
 * Модель данных v2 (расписание хранится НА РОЛИКЕ):
 *  videos.json   — [{id,title,slug,file,type:main|additional,duration,status,
 *                    schedule:[{id,start,end}, ...]}]  — список периодов активности ролика
 *  specials.json — {
 *      tech_periods:    [{id,start,end,picture,video}],          // техзакрытие (наивысший приоритет)
 *      sanitary_dates:  [{id,date}],                             // произвольные санитарные дни
 *      weekday_periods: [{id,start,end}],                        // периоды действия санитарных дней по дню недели
 *      weekday_sanitary:{enabled,weekday}                        // какой день недели считать санитарным
 *  }
 *  Приоритеты: техзакрытие > санитарный день > ротация активных роликов.
 *  Основные ролики идут по порядку (сортировка по началу периода, затем по позиции в библиотеке),
 *  дополнительные выбираются случайным образом из активных на текущий момент.
 */
class PlaylistEngine
{
    private array $videos;     // id => video
    private array $specials;

    public function __construct()
    {
        $this->videos = [];
        foreach (store_read('videos.json') as $v) {
            $this->videos[$v['id']] = $v;
        }
        $this->specials = store_read('specials.json') + [
            'tech_periods'     => [],
            'sanitary_dates'   => [],
            'weekday_periods'  => [],
            'weekday_sanitary' => ['enabled' => true, 'weekday' => 1],
        ];
    }

    /** Файл ролика по id (для отдачи плееру) */
    public function videoFile(string $id): ?string
    {
        return $this->videos[$id]['file'] ?? null;
    }

    /** Активные на момент $now ролики заданного типа, отсортированные по порядку показа */
    public function activeVideos(DateTimeInterface $now, string $type): array
    {
        $out = [];
        foreach ($this->videos as $pos => $v) {
            if (($v['type'] ?? 'main') !== $type) {
                continue;
            }
            if (($v['status'] ?? 'ready') !== 'ready') {
                continue; // ещё не сконвертирован / ошибка
            }
            foreach ((array)($v['schedule'] ?? []) as $p) {
                if ($this->inRange($now, (string)$p['start'], (string)$p['end'])) {
                    $out[] = ['video' => $v, 'start' => $p['start'], 'pos' => $pos];
                    break;
                }
            }
        }
        usort($out, fn($a, $b) => [$a['start'], $a['pos']] <=> [$b['start'], $b['pos']]);
        return $out;
    }

    /** Период(ы) активности конкретного ролика, накрывающие $now */
    public function activePeriodsOf(array $video, DateTimeInterface $now): array
    {
        $res = [];
        foreach ((array)($video['schedule'] ?? []) as $p) {
            if ($this->inRange($now, (string)$p['start'], (string)$p['end'])) {
                $res[] = $p;
            }
        }
        return $res;
    }

    /**
     * Основной вход для playlist.php. Возвращает массив для json_encode.
     * $ip — адрес телевизора, $now — текущее время (можно подставить для теста).
     */
    public function buildForClient(string $ip, ?DateTimeInterface $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(cfg()['timezone']));
        $state = $this->loadState($ip);
        $res = $this->build($now, $state, true);
        $this->saveState($ip, $res['state']);
        unset($res['state']);
        return $res['payload'];
    }

    /**
     * Предпросмотр без изменения состояния (для админки).
     * $advance — сколько шагов проигрывания смоделировать вперёд.
     */
    public function preview(DateTimeInterface $now, string $ip = 'preview', int $advance = 0): array
    {
        $now = DateTimeImmutable::createFromInterface($now);
        $state = [0, 0];
        $items = [];
        for ($i = 0; $i <= $advance; $i++) {
            $r = $this->build($now, $state, true);
            $state = $r['state'];
            $p = $r['payload'];
            $items[] = $p;
            if ($i < $advance) {
                // сдвинем время на длительность показанного ролика
                $dur = max(3, (int)round(($p['GeneralWatchdog'] ?? 3)));
                $now = $now->modify("+{$dur} minutes");
            }
        }
        return $items;
    }

    /** Внутренний расчёт одного "слота" показа */
    private function build(DateTimeImmutable $now, array $state, bool $useRandom): array
    {
        $splash = cfg()['splashes'];

        // 1. Период технического закрытия — имеет наивысший приоритет
        foreach ($this->specials['tech_periods'] ?? [] as $tp) {
            if ($this->inRange($now, $tp['start'], $tp['end'])) {
                // заставка: видео периода (если задано), иначе картинка + дефолтное видео-заставка
                $video = !empty($tp['video']) ? $tp['video'] : $splash['tech']['file'];
                $payload = [
                    'Playlist' => [$video],
                    'SanitaryDay' => false,
                    'GeneralWatchdog' => $this->watchdogForFile($video),
                    'maintenance' => true,
                ];
                if (!empty($tp['picture'])) {
                    $payload['maintenancepicture'] = $tp['picture'];
                }
                return ['state' => $state, 'payload' => $payload];
            }
        }

        // 2. Санитарный день: произвольные даты ИЛИ день недели внутри периода действия сан. дней
        $isSanitary = false;
        foreach ($this->specials['sanitary_dates'] ?? [] as $sd) {
            if ($sd['date'] === $now->format('Y-m-d')) {
                $isSanitary = true;
                break;
            }
        }
        if (!$isSanitary && !empty($this->specials['weekday_sanitary']['enabled'])) {
            $wd = (int)($this->specials['weekday_sanitary']['weekday'] ?? 1);
            if ((int)$now->format('N') === $wd) {
                foreach ($this->specials['weekday_periods'] ?? [] as $wp) {
                    if ($this->inRange($now, (string)$wp['start'], (string)$wp['end'])) {
                        $isSanitary = true;
                        break;
                    }
                }
            }
        }
        if ($isSanitary) {
            // состояние не крутим — показываем заставку
            return [
                'state' => $state,
                'payload' => [
                    'Playlist' => [$splash['sanitary']['file']],
                    'SanitaryDay' => true,
                    'GeneralWatchdog' => 4, // как в старом коде для санитарного дня
                ],
            ];
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
            if ($this->inRange($now, $tp['start'], $tp['end'])) {
                return ['mode' => 'tech', 'tech' => $tp];
            }
        }
        $sanitaryReason = null;
        foreach ($this->specials['sanitary_dates'] ?? [] as $sd) {
            if ($sd['date'] === $now->format('Y-m-d')) {
                $sanitaryReason = 'date';
                break;
            }
        }
        if ($sanitaryReason === null && !empty($this->specials['weekday_sanitary']['enabled'])) {
            $wd = (int)($this->specials['weekday_sanitary']['weekday'] ?? 1);
            if ((int)$now->format('N') === $wd) {
                foreach ($this->specials['weekday_periods'] ?? [] as $wp) {
                    if ($this->inRange($now, (string)$wp['start'], (string)$wp['end'])) {
                        $sanitaryReason = 'weekday';
                        break;
                    }
                }
            }
        }
        if ($sanitaryReason !== null) {
            return ['mode' => 'sanitary', 'reason' => $sanitaryReason];
        }
        return [
            'mode'   => 'rotation',
            'main'       => $this->activeVideos($now, 'main'),
            'additional' => $this->activeVideos($now, 'additional'),
        ];
    }

    private function inRange(DateTimeInterface $now, string $start, string $end): bool
    {
        $tz = new DateTimeZone(cfg()['timezone']);
        $s = new DateTimeImmutable($start, $tz);
        $e = new DateTimeImmutable($end, $tz);
        return $now >= $s && $now < $e;
    }

    /** Watchdog по метаданным ролика (без вызова ffmpeg в HTTP-пути) */
    private function watchdogFor(?string $file): int
    {
        return $this->watchdogForFile($file);
    }

    /** Watchdog по имени файла: из videos.json, иначе — прямой ffprobe с кэшем в cache/ */
    private function watchdogForFile(?string $file): int
    {
        if ($file === null) {
            return cfg()['watchdog_default'];
        }
        foreach ($this->videos as $v) {
            if ($v['file'] === $file && !empty($v['duration'])) {
                return max(3, (int)round($v['duration'] / 60 + 1.5));
            }
        }
        // заставочные файлы (s_day.mp4, tech*.mp4) не в библиотеке — измерим один раз и закэшируем
        $cacheFile = root_path('cache/wd_' . md5($file) . '.txt');
        if (is_file($cacheFile)) {
            $dur = (float)file_get_contents($cacheFile);
        } else {
            require_once __DIR__ . '/ffmpeg.php';
            $path = root_path('media/' . $file);
            $dur = is_file($path) ? (FfmpegService::ffprobeDuration($path) ?? 0.0) : 0.0;
            @mkdir(root_path('cache'), 0775, true);
            file_put_contents($cacheFile, (string)$dur);
        }
        if ($dur <= 0) {
            return cfg()['watchdog_default'];
        }
        return max(3, (int)round($dur / 60 + 1.5));
    }

    // ---------- Состояние по IP (совместимо со старым format serialize([int,int])) ----------

    private function stateFile(string $ip): string
    {
        // защитимся от слэшей/точек в имени
        $safe = preg_replace('/[^0-9a-fA-F.:_]/', '_', $ip);
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
