<?php
/**
 * db.php — BD + cache partilhados por todas as páginas
 * (dashboard.php, deputado.php, ajuda.php, ...)
 */

define('DB_PATH',   __DIR__ . '/transparencia_pt.db');
define('CACHE_TTL', 3600);
define('LEG_ID',    17);
define('LEG_NUM',   'XVII');

function get_db(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=3000'); // falha em 3s em vez do default de 60s se a BD estiver bloqueada por um writer (ex: ETL)
    }
    return $db;
}

function db_query(string $sql, array $params = []): array {
    $st = get_db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetchAll();
}

function db_one(string $sql, array $params = []): ?array {
    $st = get_db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetch() ?: null;
}

// ─── Snapshots (pré-computados pelo ETL — etl/gerar_snapshots.py) ────────────
define('SNAP_DIR', __DIR__ . '/data/snapshots');
function snapshot_get(string $name): ?array {
    $path = SNAP_DIR . "/{$name}.json";
    if (!is_file($path)) return null;
    $json = @file_get_contents($path);
    if ($json === false) return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

// ─── Cache ───────────────────────────────────────────────────────────────────
function cache_get(string $key): mixed {
    try {
        $row = db_one("SELECT valor FROM cache WHERE cache_key=? AND expires_at > datetime('now')", [$key]);
        return $row ? json_decode($row['valor'], true) : null;
    } catch (Exception) { return null; }
}
function cache_set(string $key, mixed $value, int $ttl = CACHE_TTL): void {
    try {
        $exp = date('Y-m-d H:i:s', time() + $ttl);
        $db = get_db();
        $db->prepare("INSERT OR REPLACE INTO cache(cache_key,valor,expires_at) VALUES(?,?,?)")
           ->execute(array_values([$key, json_encode($value), $exp]));
    } catch (Exception) {}
}

// ─── Helpers de apresentação ────────────────────────────────────────────────
function get_gps(): array {
    $ck = 'gps_' . LEG_ID;
    if ($c = cache_get($ck)) return $c;
    $rows = db_query("SELECT * FROM grupos_parlamentares WHERE legislatura_id=? ORDER BY sigla", [LEG_ID]);
    cache_set($ck, $rows, 3600);
    return $rows;
}

$GLOBALS['gps_lista'] = get_gps();
$GLOBALS['gps_map']   = array_column($GLOBALS['gps_lista'], null, 'sigla');

function gp_cor(string $gp): string {
    return $GLOBALS['gps_map'][$gp]['cor_hex'] ?? '#94a3b8';
}
function pct(float $v): string { return number_format($v * 100, 1) . '%'; }
function score_cls(float $s): string { return $s >= 70 ? 'g' : ($s >= 40 ? 'y' : 'r'); }
function bar(float $v, string $c = '#2563eb'): string {
    $w = min(100, max(0, round($v)));
    return "<div class='bar'><div class='bfill' style='width:{$w}%;background:{$c}'></div></div>";
}
