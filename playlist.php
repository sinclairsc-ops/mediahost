<?php
declare(strict_types=1);

/**
 * playlist.php — интерфейс для плеера на телевизорах.
 * Формат ответа, состояние по IP и логика чередования сохранены как в исходной версии,
 * чтобы index.html не требовал изменений и тестирования.
 *
 * Данные: data/videos.json, data/specials.json (см. README).
 */

require_once __DIR__ . '/src/util.php';
require_once __DIR__ . '/src/engine.php';

date_default_timezone_set(cfg()['timezone']);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$engine = new PlaylistEngine();
$current = $engine->buildForClient($ip);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($current);
?>
