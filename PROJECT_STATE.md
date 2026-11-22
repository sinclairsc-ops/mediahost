# PROJECT STATE — детальное состояние проекта (для LLM и разработчиков)

> Этот файл — актуальный «снимок» всего, что сделано в проекте. Обновляйте его при каждом крупном изменении.
> Дата последнего обновления: 2026-11-23.

## 1. Что это за система

Показ видеороликов на смарт-телевизорах через встроенный браузер ТВ (старые ядра, вплоть до Chrome 22).
Бэкенд: Ubuntu 24.04, PHP 8.x, **без СУБД** (хранилище — JSON-файлы с блокировками).

- Плеер для ТВ: `index.html` + `playlist.php` (+ `image.php`). **Формат ответа и клиентский код менять нельзя без тестов на реальных ТВ** — они специально адаптированы под старые браузеры.
- Админка: `admin/index.html` (одностраничник на ванильном JS) + JSON API в `admin/api/*.php`, защищённый логином/паролем.
- Конвертация медиа — серверный ffmpeg (аналог прежних Windows .bat-скриптов), асинхронно через cron-воркер.

Репозиторий: https://github.com/sinclairsc-ops/mediahost.git, ветка `main`.

## 2. Структура репозитория

```
index.html          — страница плеера для ТВ (НЕ менять без необходимости)
playlist.php        — API плейлиста для ТВ (JSON: Playlist[], SanitaryDay, GeneralWatchdog, maintenance, maintenancepicture)
image.php           — заглушка (бывший Oracle-код удалён; формат ответа сохранён)
css/, favicon.ico   — ассеты плеера
s_day.jpg, tech03042023.jpg — легаси-картинки заставок (заменены видео-заставками, оставлены для старых ссылок)

admin/index.html    — SPA админки (вкладки: Ролики и расписание, Особые дни, Плейлист сейчас, Настройки, Пользователи, Журнал)
admin/api/
  _bootstrap.php    — сессия, авторизация, CSRF, разбор JSON-тела, json_ok/json_error
  auth.php          — login/logout/check
  videos.php        — CRUD роликов, загрузка, периоды, замена файла, переименование
  specials.php      — особые дни: санитарный день недели, периоды техзакрытия
  playlist.php      — now / sequence (симуляция плейлиста на дату)
  settings.php      — get/save настроек конвертера
  users.php         — list/add/set-password/set-role/delete (только роль admin)
  logs.php          — list (просмотр журнала действий)

src/
  config.php.example — шаблон конфига (копировать в config.php; config.php НЕ в git!)
  config.php         — локальная конфигурация (в .gitignore)
  engine.php         — PlaylistEngine: модель v3, формирование плейлиста, context() для админки
  ffmpeg.php         — сборка команд ffmpeg/ffprobe (escapeshellarg, без инъекций)
  util.php           — store_read/store_write (flock + .bak), slugify (транслит RU→ASCII), admin_log(), часы
  auth.php           — Auth: argon2id, сессии, CLI `php src/auth.php add <login> <pass>`

bin/
  worker.php         — воркер очереди конвертации (запуск из cron, файловая блокировка от параллельного запуска)
  migrate_v3.php     — миграция модели данных v2 → v3 (одноразовая, выполнена)

data/                — «база данных» (JSON, всё в git)
  videos.json        — список роликов (см. модель ниже)
  specials.json      — {tech_periods:[...], weekday_sanitary:{enabled,weekday}}
  users.json         — [{login, passhash(argon2id), role, created}]
  queue.json(+.bak)  — служебные файлы очереди/состояния
  logs/admin-YYYY-MM-DD.log — журнал действий админов (JSON Lines)
  state/             — state_<IP>.serialize — состояние чередования по IP (формат serialize([mainIdx, wasAdditional]), совместим со старым)
  *.v2.json, *.bak   — остатки миграций (можно удалить после стабилизации)

media/               — готовые mp4 (отдаются ТВ напрямую)
uploads/             — исходники загруженных файлов до конвертации (удаляются воркером)
cache/               — кэш метаданных/превью
tmp/                 — временные файлы
```

## 3. Модель данных v3 (текущая)

Расписание хранится **на ролике**. Заставки (санитарный день, техзакрытие) — тоже ролики типа `splash`.

### data/videos.json — массив объектов:
```jsonc
{
  "id": "migrated-1",              // уникальный id
  "title": "1_Про аквапарк",       // человекочитаемое название (RU допускается)
  "slug": "1",                     // транслит имени файла (см. util.php::slugify)
  "file": "1.mp4",                 // имя файла в media/ (ВСЕГДА .mp4)
  "type": "main",                  // main | additional | splash
  "kind": "video",                 // video | image (чем был исходник)
  "status": "ready",               // queued -> converting -> ready | error
  "duration": null|float,          // сек, заполняет воркер через ffprobe
  "splash_role": "splash_weekday", // только для type=splash: splash_weekday | splash_tech
  "schedule": [                    // СПИСОК периодов активности ролика (несколько периодов на ролик)
    {"id": "df80e8de...", "start": "2000-01-01 00:00", "end": "2099-12-31 23:30"}
  ]
}
```
- `main` — показывается строго по списку (порядок: по началу активного периода, затем по позиции в библиотеке).
- `additional` — выбирается случайно из активных между основными.
- `splash` — заставка; не участвует в ротации, играется только в соответствующем режиме.

### data/specials.json:
```jsonc
{
  "tech_periods": [
    {"id":"migrated-tech-1","start":"2023-04-03 00:00","end":"2023-04-30 23:59","splash_video":"tech03042023.mp4"}
    // splash_video — файл ролика-заставки из media/. Произвольная санитарная дата = период техзакрытия на 1 день с роликом s_day.mp4
  ],
  "weekday_sanitary": {"enabled": true, "weekday": 1}   // 1 = понедельник
}
```
Периоды действия санитарных дней задаются как schedule у ролика-заставки `splash_role=splash_weekday`.

### Приоритеты движка (src/engine.php):
1. **Техническое закрытие** (совпадение даты с tech_periods) → показывается только ролик-заставка техзакрытия (`maintenance:true` + `maintenancepicture`);
2. **Санитарный день** (день недели совпадает с weekday_sanitary И сегодня внутри периода заставки splash_weekday) → только она, `SanitaryDay:true`;
3. **Ротация**: основные по порядку, чередуясь со случайными дополнительными.
Если активных роликов нет — пустой плейлист, админка показывает предупреждение «ПУСТО».

### Формат ответа playlist.php (обратная совместимость!):
`{"Playlist":[{"Video":"1.mp4",...}], "SanitaryDay":bool, "GeneralWatchdog":int}` (+ `maintenance`, `maintenancepicture` при техзакрытии).
Watchdog = round(duration_min + 1.5), минимум 3 — берётся из кэшированной длительности, **ffmpeg в HTTP-запросе не вызывается**.

## 4. Конвертация медиа (ffmpeg)

Windows-аналоги (исторические требования заказчика):
- видео: `ffmpeg -i IN -s 1280x720 -c:v libx264 -pix_fmt yuv420p -profile:v baseline -level 1.3 -y OUT.mp4`
- изображение: то же + `-loop 1 -t 60` (длительность спрашивается при загрузке, по умолчанию 60 с)

Реализация:
- Все настройки пресета лежат в `data/settings.json` (редактируются во вкладке «Настройки»; значения по умолчанию — `convert_defaults` в config.php). Поля: resolution, vcodec, pix_fmt, profile, level, preset_speed, crf/bitrate, maxrate, bufsize, acodec, abitrate, duration_image.
- Команды собираются массивом аргументов с escapeshellarg (src/ffmpeg.php). Добавлено `-movflags +faststart` (быстрый старт на ТВ).
- **Все файлы после загрузки становятся .mp4** — включая изображения и любые контейнеры (mov/avi/mkv/webm...).
- Поток: upload → запись в uploads/ + запись в videos.json со статусом `queued` → ответ админу → cron-воркер `bin/worker.php` (каждую минуту, flock от параллельного запуска) берёт queued → `converting` → ffmpeg → ffprobe пишет duration → публикует в media/ → `ready`; при ошибке `error` (текст в записи ролика). Исходник из uploads/ удаляется.
- Статусы опрашиваются админкой каждые ~3 с (polling), UI обновляет бейджи live.
- Cron: `* * * * * www-data /usr/bin/php /var/www/mediahost/bin/worker.php >> /var/log/mediahost-worker.log 2>&1`
  Нюансы: пользователь www-data (права на data/uploads/media/cache/logs), абсолютные пути, ffmpeg/ffprobe в конфиге, timeout на длинных конвертациях желателен.

## 5. Загрузка файлов — лимиты и диагностика

Известная проблема (решена): ошибка `{"error":"Файл не загружен..."}` при code=UPLOAD_ERR_INI_SIZE(4)/POST_MAX_SIZE(7), когда тело запроса больше `post_max_size` — PHP полностью отбрасывает $_POST/$_FILES.
Для Apache (боевая среда) лимиты поднимаются в `.htaccess`/конфе сайта:
`php_admin_value[upload_max_filesize]=2G`, `php_admin_value[post_max_size]=2100M` (или через .htaccess php_value).
API при провале пишет в журнал деталей: content_type, content_length, is_multipart, post_keys, files_keys, php_upload_max, php_post_max, error_code. В админке есть подсказка про лимиты.
Логирование ошибок загрузки — экшен `upload_failed` в журнале админ-действий.

## 6. Авторизация и пользователи

- Хранение: `data/users.json`, пароль — argon2id-хэш. **Паролей по умолчанию в коде нет.**
- CLI создания: `php src/auth.php add <login> "<password>"` (мин. 8 символов, роль admin по умолчанию; второй аргумент роли — см. users.php).
  Исторически на тестовом стенде паролю админа соответствует значение `TvAdmin#2026` (было выставлено вручную при развёртывании; после доступа в админку — сменить!).
- Смена пароля: любой пользователь — из шапки админки (с подтверждением текущим паролем); роль `admin` может сбросить пароль любому (users.php action=set-password без current).
- Создание/удаление пользователей и смена ролей — только роль `admin` (вкладка «Пользователи»). Роли: `admin`, `editor`.
- Механизм: httpOnly-сессии PHP, CSRF-токены во всех POST, rate-limiting попыток входа, логирование login/logout/failed_login.

## 7. Логирование действий администраторов

`src/util.php::admin_log($action, $details)` → `data/logs/admin-YYYY-MM-DD.log` (JSON Lines: ts, user, ip, action, details, при изменениях — до/после).
Экшены: login, logout, failed_login, video_add/upload, upload_failed, video_update, video_delete, period-add/update/delete, rename-file, replace, tech-save/tech-delete, weekday-period-save/delete, weekday-set, settings_save, user_add/user_delete/set-password/set-role.
Просмотр: вкладка «Журнал» (admin/api/logs.php?action=list). Ротация логов не настроена (можно logrotate).

## 8. Транслитерация имён файлов

`util.php::slugify()` — названия роликов (RU) преобразуются в читаемые ASCII-имена файлов:
«Акция День рождения» → `aktsiya-den-rozhdeniya.mp4`. Оригинальное название сохраняется в `title`.
При конфликтах имён добавляется суффикс. Файлы в media/ всегда .mp4.

## 9. История версий модели данных

- **v1** (исходная): единый файл playlist_new.json с глобальным расписанием; мигрировано при старте разработки (39 роликов, 45 периодов, 7 техзакрытий). Файлы v1 удалены из git.
- **v2**: расписание перенесено НА РОЛИК (videos.json[].schedule), тип один у ролика; specials.json хранил tech_periods, sanitary_dates, weekday_periods. Миграция bin/migrate_v2.php (выполнена, скрипт удалён). Бэкапы videos.v2.json/specials.v2.json пока в data/ (можно удалить).
- **v3 (текущая)**: заставки стали роликами type=splash (splash_role=splash_weekday|splash_tech); блок произвольных санитарных дат удалён (заменяется периодом техзакрытия на 1 день с нужным роликом); картинка у техзакрытия убрана — только видео; bin/migrate_v3.php (выполнен).
- Даты периодов нормализуются с точностью до дня (UI: date-input без времени; 00:00–23:59).

## 10. Git / GitHub — рабочее состояние

- Remote: origin = https://github.com/sinclairsc-ops/mediahost.git, пуш через HTTPS c Personal Access Token (fine-grained, Contents: Read+write). Токен хранится в credentials helper среды; пользователь обещает отозвать по завершении.
- Ветка одна: `main`. Пуш-цикл: правки → `php -l` затронутых файлов → смоук-тест движка (`php -r` с PlaylistEngine на контрольных датах) → `git add -A && git commit && git push`.
- **ВАЖНОЕ правило процесса:** никогда не сообщать о коммите/пуше без проверки результата (`git status`, `git log`, `git ls-remote origin main`). В истории были фиктивные заявления о несуществующих коммитах (5c78936, 256b3d8, 3768c91 — их НЕТ) — реальная цепочка: a104f2d → 587ba26 → 89bd268 (HEAD).
- `src/config.php` в .gitignore (секреты не в репозитории). ВНИМАНИЕ: старый config.php исторически попадал в историю git — при открытии репозитория публичным рекомендуется очистка истории или приватность.
- Не коммитить: test-пользователей (testadmin/testeditor удалены), tmp/, содержимое cache/.

## 11. Известные проблемы и TODO

- Проверить на живом Apache: загрузка >128 МБ после поднятия лимитов PHP (ждём подтверждения пользователя).
- Удалить остатки миграций: data/*.v2.json, *.bak — после стабилизации.
- Превью-кадры роликов в библиотеке — не реализовано.
- Ролевой доступ тоньше (editor без «Настроек») — частично.
- logrotate для data/logs — не настроен.
- Смоук-тест формата playlist.php против старого ответа на реальном ТВ — рекомендован перед продакшеном.
- После завершения работ — отозвать GitHub-токен.

## 12. Быстрая проверка работоспособности (dev)

```bash
php -l src/engine.php admin/api/*.php playlist.php bin/worker.php
php -S 127.0.0.1:8080                      # корень = каталог проекта
curl 'http://127.0.0.1:8080/playlist.php'  # expect JSON Playlist/SanitaryDay/GeneralWatchdog
php -r 'require "src/engine.php"; /* контекст на дату */'  # тест движка: пн->sanitary, техзакрытие->maintenance
```
