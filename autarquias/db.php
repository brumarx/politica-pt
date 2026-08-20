<?php
/**
 * autarquias/db.php — BD + cache do módulo Autarquias (separado da AR)
 */

define('AUT_DB_PATH', __DIR__ . '/autarquias_pt.db');
define('AUT_CACHE_TTL', 3600);

function aut_get_db(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . AUT_DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=3000');
    }
    return $db;
}

function aut_query(string $sql, array $params = []): array {
    $st = aut_get_db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetchAll();
}

function aut_one(string $sql, array $params = []): ?array {
    $st = aut_get_db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetch() ?: null;
}

function aut_cache_get(string $key): mixed {
    try {
        $row = aut_one("SELECT valor FROM cache WHERE cache_key=? AND expires_at > datetime('now')", [$key]);
        return $row ? json_decode($row['valor'], true) : null;
    } catch (Exception) { return null; }
}
function aut_cache_set(string $key, mixed $value, int $ttl = AUT_CACHE_TTL): void {
    try {
        $exp = date('Y-m-d H:i:s', time() + $ttl);
        aut_get_db()->prepare("INSERT OR REPLACE INTO cache(cache_key,valor,expires_at) VALUES(?,?,?)")
           ->execute(array_values([$key, json_encode($value), $exp]));
    } catch (Exception) {}
}
