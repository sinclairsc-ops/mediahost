# mediahost

Система показа видеороликов на смарт-телевизорах (ТВ-плеер + административный раздел).

## Быстрый старт (Ubuntu 24.04, PHP 8+)

```bash
apt install php-cli ffmpeg            # ffmpeg нужен для конвертации
cp src/config.php.example src/config.php   # при необходимости поправить пути/таймзону
php src/auth.php add <логин> "<пароль>"    # создать пользователя админки (мин. 8 символов)
```

Вход в админку: `/admin/` — логин `admin`, пароль задаётся командой выше
(в репозитории паролей нет, хранится только argon2id-хэш в `data/users.json`).

Cron для очереди конвертации:
```
* * * * * www-data /usr/bin/php /var/www/mediahost/bin/worker.php >> /var/log/mediahost-worker.log 2>&1
```

## Структура
- `index.html`, `playlist.php` — ТВ-плеер (формат ответа совместим со старым, не менять без тестов на Chrome 22)
- `admin/` — административный раздел (SPA + JSON API в `admin/api/`)
- `src/` — ядро: `engine.php` (плейлист), `ffmpeg.php` (конвертация), `auth.php`, `util.php` (JSON-хранилище, логи), `config.php` (не в git)
- `bin/worker.php` — воркер очереди перекодирования
- `data/` — «база данных» в JSON: `videos.json`, `schedule.json`, `specials.json`, `users.json`, журнал `logs/admin-*.log`, состояние ротации `state/`
- `media/` — готовые ролики; `uploads/` — исходники до конвертации; `cache/` — кэш

## Управление пользователями
```bash
php src/auth.php add admin 'StrongPass123'      # добавить
php -r '$u=json_decode(file_get_contents("data/users.json"),true); foreach($u as &$x){if($x["login"]==="admin")$x["passhash"]=password_hash("NewPass123",PASSWORD_ARGON2ID);} file_put_contents("data/users.json",json_encode($u,JSON_PRETTY_PRINT));'  # сбросить пароль
```

## Конвертация (серверные аналоги windows-скриптов)
- Видео: `ffmpeg -i in -s 1280x720 -c:v libx264 -pix_fmt yuv420p -profile:v baseline -level 1.3 ... out.mp4`
- Изображение → видео: то же + `-loop 1 -t <сек>` (по умолчанию 60)
Пресеты настраиваются в `src/config.php`. 
