<?php
/**
 * Transparência Política PT — dashboard.php
 * PHP 8 + SQLite WAL
 * Tabs: Visão Geral · Score · Iniciativas · Partidos · Declarações
 */

define('DB_PATH',   __DIR__ . '/transparencia_pt.db');
define('CACHE_TTL', 3600);
define('LEG_ID',    17);
define('LEG_NUM',   'XVII');

// ─── BD ──────────────────────────────────────────────────────────────────────
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

// ─── Params ──────────────────────────────────────────────────────────────────
$tab   = in_array($_GET['tab'] ?? 'visao', ['visao','score','iniciativas','partidos','grupos','declaracoes']) ? ($_GET['tab'] ?? 'visao') : 'visao';
$gp    = preg_replace('/[^A-Z]/', '', strtoupper($_GET['gp'] ?? ''));
$ordem = in_array($_GET['ord'] ?? 'score', ['score','presenca','iniciativas','nome']) ? ($_GET['ord'] ?? 'score') : 'score';
$page  = max(1, (int)($_GET['p'] ?? 1));
$search = trim($_GET['q'] ?? '');
$ano   = preg_replace('/[^0-9]/', '', $_GET['ano'] ?? '') ? (int)($_GET['ano'] ?? '') : null;
$dep_id = preg_replace('/[^0-9]/', '', $_GET['dep'] ?? '') ? (int)($_GET['dep'] ?? '') : null;

// ─── Dados ───────────────────────────────────────────────────────────────────
function get_gps(): array {
    $ck = 'gps_' . LEG_ID;
    if ($c = cache_get($ck)) return $c;
    $r = db_query("SELECT sigla, nome, cor_hex FROM grupos_parlamentares WHERE legislatura_id=? ORDER BY sigla", [LEG_ID]);
    cache_set($ck, $r, 86400);
    return $r;
}

function get_stats(): array {
    $ck = 'stats_' . LEG_ID;
    if ($c = cache_get($ck)) return $c;
    $leg = LEG_ID;
    $stats = [
        'deputados'   => db_one("SELECT COUNT(*) as n FROM deputados WHERE activo=1 AND legislatura_id=?", [$leg])['n'] ?? 0,
        'sessoes'     => db_one("SELECT COUNT(*) as n FROM sessoes_plenarias WHERE legislatura_id=?", [$leg])['n'] ?? 0,
        'iniciativas' => db_one("SELECT COUNT(*) as n FROM iniciativas WHERE legislatura_id=?", [$leg])['n'] ?? 0,
        'presenca_media' => db_one("SELECT ROUND(AVG(taxa_presenca)*100,1) as n FROM scores WHERE legislatura_id=?", [$leg])['n'] ?? 0,
        'por_gp' => db_query(
            "SELECT d.gp_sigla as gp, COUNT(d.id) as total,
                    ROUND(AVG(s.taxa_presenca)*100,1) as presenca_media,
                    SUM(s.n_iniciativas) as iniciativas,
                    ROUND(AVG(s.score_total),1) as score_medio
             FROM deputados d
             LEFT JOIN scores s ON s.dep_id=d.id AND s.legislatura_id=d.legislatura_id
             WHERE d.activo=1 AND d.legislatura_id=?
             GROUP BY d.gp_sigla ORDER BY total DESC", [$leg]),
        'top_presenca' => db_query(
            "SELECT d.nome_curto, d.gp_sigla, s.taxa_presenca, s.score_total
             FROM deputados d JOIN scores s ON s.dep_id=d.id
             WHERE d.activo=1 AND d.legislatura_id=?
             ORDER BY s.taxa_presenca DESC LIMIT 10", [$leg]),
        'menos_presenca' => db_query(
            "SELECT d.nome_curto, d.gp_sigla, s.taxa_presenca, s.score_total
             FROM deputados d JOIN scores s ON s.dep_id=d.id
             WHERE d.activo=1 AND d.legislatura_id=?
             ORDER BY s.taxa_presenca ASC LIMIT 10", [$leg]),
        'mais_iniciativas' => db_query(
            "SELECT d.nome_curto, d.gp_sigla, s.n_iniciativas, s.score_total
             FROM deputados d JOIN scores s ON s.dep_id=d.id
             WHERE d.activo=1 AND d.legislatura_id=?
             ORDER BY s.n_iniciativas DESC LIMIT 10", [$leg]),
    ];
    cache_set($ck, $stats, 3600);
    return $stats;
}

function get_ranking(int $page, string $gp, string $ordem, string $search): array {
    $per = 25;
    $ck = "rank_{$page}_{$gp}_{$ordem}_" . md5($search) . '_' . LEG_ID;
    if ($c = cache_get($ck)) return $c;
    $leg = LEG_ID;
    $where = "d.legislatura_id=?";
    $params = [$leg];
    if ($gp)     { $where .= " AND d.gp_sigla=?"; $params[] = $gp; }
    if ($search) { $where .= " AND (d.nome_completo LIKE ? OR d.nome_curto LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    $order_map = ['score'=>'s.score_total DESC','presenca'=>'s.taxa_presenca DESC','iniciativas'=>'COALESCE(s.n_iniciativas,0) DESC','nome'=>'d.nome_curto ASC'];
    $ord = $order_map[$ordem] ?? 's.score_total DESC';
    
    // Mostra TODOS os deputados (mesmo sem score), mas ordena pelos que têm
    $total = db_one("SELECT COUNT(*) as n FROM deputados d WHERE $where", $params)['n'] ?? 0;
    $offset = ($page - 1) * $per;
    $rows = db_query(
        "SELECT d.id, d.nome_curto, d.nome_completo, d.gp_sigla, d.url_foto,
                COALESCE(s.score_total,0) as score_total,
                COALESCE(s.taxa_presenca,0) as taxa_presenca,
                COALESCE(s.n_iniciativas,0) as n_iniciativas,
                COALESCE(s.rank_geral,9999) as rank_geral
         FROM deputados d 
         LEFT JOIN scores s ON s.dep_id=d.id AND s.legislatura_id=d.legislatura_id
         WHERE $where 
         ORDER BY $ord, d.nome_curto ASC
         LIMIT ? OFFSET ?",
        array_values(array_merge($params, [$per, $offset]))
    );
    $r = compact('rows','total','page','per','gp','ordem','search');
    cache_set($ck, $r, 1800);
    return $r;
}

function get_iniciativas(int $page, string $gp, string $search): array {
    $per = 25;
    $leg = LEG_ID;
    $where = "i.legislatura_id=?";
    $params = [$leg];
    if ($search) { $where .= " AND i.titulo LIKE ?"; $params[] = "%$search%"; }
    $total = db_one("SELECT COUNT(*) as n FROM iniciativas i WHERE $where", $params)['n'] ?? 0;
    $offset = ($page - 1) * $per;
    $rows = db_query(
        "SELECT i.id, i.tipo, i.titulo, i.autoria_gp, i.data_entrada, i.estado, i.url_ar,
                COUNT(ia.dep_id) as n_autores
         FROM iniciativas i
         LEFT JOIN iniciativas_autores ia ON ia.iniciativa_id=i.id
         WHERE $where GROUP BY i.id ORDER BY i.data_entrada DESC LIMIT ? OFFSET ?",
        array_values(array_merge($params, [$per, $offset]))
    );
    return compact('rows','total','page','per');
}

function get_partidos(): array {
    $ck = 'partidos_' . LEG_ID;
    if ($c = cache_get($ck)) return $c;
    $leg = LEG_ID;
    $r = db_query(
        "SELECT g.sigla, g.nome, g.cor_hex,
                COUNT(d.id) as n_deputados,
                ROUND(AVG(s.taxa_presenca)*100,1) as presenca_media,
                ROUND(AVG(s.score_total),1) as score_medio,
                SUM(s.n_iniciativas) as total_iniciativas,
                MAX(s.taxa_presenca) as melhor_presenca,
                MIN(s.taxa_presenca) as pior_presenca
         FROM grupos_parlamentares g
         LEFT JOIN deputados d ON d.gp_sigla=g.sigla AND d.activo=1 AND d.legislatura_id=?
         LEFT JOIN scores s ON s.dep_id=d.id AND s.legislatura_id=?
         WHERE g.legislatura_id=?
         GROUP BY g.sigla ORDER BY n_deputados DESC", [$leg, $leg, $leg]);
    cache_set($ck, $r, 3600);
    return $r;
}

function get_declaracoes(int $page, string $gp, string $search, int $ano = null): array {
    $per = 25;
    $ck = "decl_{$page}_{$gp}_" . md5($search) . "_{$ano}_" . LEG_ID;
    if ($c = cache_get($ck)) return $c;
    
    $where = "d.legislatura_id=?";
    $params = [LEG_ID];
    
    if ($gp) { 
        $where .= " AND d.gp_sigla=?"; 
        $params[] = $gp; 
    }
    if ($search) { 
        $where .= " AND (d.nome_completo LIKE ? OR d.nome_curto LIKE ?)"; 
        $params[] = "%$search%"; 
        $params[] = "%$search%"; 
    }
    if ($ano) { 
        $where .= " AND dr.ano=?"; 
        $params[] = $ano; 
    }
    
    $total = db_one(
        "SELECT COUNT(DISTINCT d.id) as n 
         FROM deputados d 
         LEFT JOIN declaracoes_rendimentos dr ON dr.dep_id=d.id 
         WHERE $where", 
        $params
    )['n'] ?? 0;
    
    $offset = ($page - 1) * $per;
    $rows = db_query(
        "SELECT DISTINCT d.id, d.nome_curto, d.nome_completo, d.gp_sigla, d.url_foto,
                COUNT(dr.id) as n_declaracoes,
                MAX(dr.ano) as ultimo_ano,
                MAX(dr.rendimento_total) as max_rendimento,
                GROUP_CONCAT(DISTINCT dr.tipo) as tipos,
                MAX(dr.extraido_em) as ultima_atualizacao
         FROM deputados d 
         LEFT JOIN declaracoes_rendimentos dr ON dr.dep_id=d.id
         WHERE $where 
         GROUP BY d.id 
         ORDER BY n_declaracoes DESC, d.nome_curto ASC 
         LIMIT ? OFFSET ?",
        array_values(array_merge($params, [$per, $offset]))
    );
    
    $r = compact('rows','total','page','per','gp','search','ano');
    cache_set($ck, $r, 1800);
    return $r;
}

function get_grupos(): array {
    $ck = 'grupos_' . LEG_ID;
    if ($c = cache_get($ck)) return $c;
    $leg = LEG_ID;
    $r = db_query(
        "SELECT g.sigla, g.nome, g.cor_hex,
                COUNT(d.id) as n_deputados,
                COUNT(CASE WHEN d.activo=1 THEN 1 END) as n_activos,
                ROUND(AVG(CASE WHEN d.activo=1 THEN s.taxa_presenca END)*100,1) as presenca_media,
                ROUND(AVG(CASE WHEN d.activo=1 THEN s.score_total END),1) as score_medio,
                SUM(CASE WHEN d.activo=1 THEN s.n_iniciativas END) as total_iniciativas,
                MAX(CASE WHEN d.activo=1 THEN s.taxa_presenca END) as melhor_presenca,
                MIN(CASE WHEN d.activo=1 THEN s.taxa_presenca END) as pior_presenca,
                -- Líder (primeiro da lista por convenção)
                COALESCE(
                    (SELECT d2.nome_curto FROM deputados d2 WHERE d2.id=g.lider_bid LIMIT 1),
                    (SELECT d2.nome_curto FROM deputados d2 LEFT JOIN scores s2 ON s2.dep_id=d2.id WHERE d2.gp_sigla=g.sigla AND d2.activo=1 AND d2.legislatura_id=? ORDER BY COALESCE(s2.score_total,0) DESC LIMIT 1)
                ) as lider_nome,
                COALESCE(
                    (SELECT d2.url_foto FROM deputados d2 WHERE d2.id=g.lider_bid LIMIT 1),
                    (SELECT d2.url_foto FROM deputados d2 LEFT JOIN scores s2 ON s2.dep_id=d2.id WHERE d2.gp_sigla=g.sigla AND d2.activo=1 AND d2.legislatura_id=? ORDER BY COALESCE(s2.score_total,0) DESC LIMIT 1)
                ) as lider_foto
         FROM grupos_parlamentares g
         LEFT JOIN deputados d ON d.gp_sigla=g.sigla AND d.legislatura_id=?
         LEFT JOIN scores s ON s.dep_id=d.id AND s.legislatura_id=?
         WHERE g.legislatura_id=?
         GROUP BY g.sigla 
         HAVING n_activos > 0
         ORDER BY n_activos DESC", [$leg, $leg, $leg, $leg, $leg]);
    cache_set($ck, $r, 3600);
    return $r;
}

function get_declaracoes_det(int $dep_id): array {
    $ck = "decl_det_{$dep_id}";
    if ($c = cache_get($ck)) return $c;
    
    $rows = db_query(
        "SELECT dr.ano, dr.tipo, dr.rendimento_total, dr.patrimonio_json, dr.url_pdf, dr.extraido_em,
                d.nome_curto, d.nome_completo, d.gp_sigla
         FROM declaracoes_rendimentos dr
         JOIN deputados d ON d.id = dr.dep_id
         WHERE dr.dep_id = ?
         ORDER BY dr.ano DESC, dr.tipo ASC",
        [$dep_id]
    );
    
    cache_set($ck, $rows, 3600);
    return $rows;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
$gps_lista = get_gps();
$gps_map   = array_column($gps_lista, null, 'sigla');

function gp_cor(string $gp): string {
    global $gps_map;
    return $gps_map[$gp]['cor_hex'] ?? '#94a3b8';
}
function pct(float $v): string { return number_format($v * 100, 1) . '%'; }
function score_cls(float $s): string { return $s >= 70 ? 'g' : ($s >= 40 ? 'y' : 'r'); }
function bar(float $v, string $c = '#2563eb'): string {
    $w = min(100, max(0, round($v)));
    return "<div class='bar'><div class='bfill' style='width:{$w}%;background:{$c}'></div></div>";
}
function url(array $extra = []): string {
    global $tab, $gp, $ordem, $page, $search, $ano, $dep_id;
    $base = ['tab'=>$tab,'gp'=>$gp,'ord'=>$ordem,'p'=>$page,'q'=>$search,'ano'=>$ano,'dep'=>$dep_id];
    $p = array_merge($base, $extra);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 1 || $v === 0);
    return '?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transparência PT — Assembleia da República</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f8f7f4;--surf:#fff;--surf2:#f1f0ed;--bord:#e5e3dc;
  --txt:#1a1917;--mut:#6b6860;--acc:#1a4f8a;--acc2:#e8375c;
  --grn:#16a34a;--ylw:#ca8a04;--red:#dc2626;
  --r:10px;--sh:0 1px 3px rgba(0,0,0,.07),0 4px 16px rgba(0,0,0,.05);
  --sans:'DM Sans',sans-serif;--serif:'Instrument Serif',serif;
}
html{font-size:16px;scroll-behavior:smooth}
body{font-family:var(--sans);background:var(--bg);color:var(--txt);min-height:100vh;-webkit-font-smoothing:antialiased}
a{color:var(--acc);text-decoration:none}
a:hover{text-decoration:underline}

/* Layout */
.wrap{max-width:1280px;margin:0 auto;padding:0 20px}
.grid{display:grid;grid-template-columns:200px 1fr;gap:28px;padding:28px 0 80px}
@media(max-width:768px){.grid{grid-template-columns:1fr}.sidebar{display:none}}

/* Header */
.hdr{background:var(--surf);border-bottom:1px solid var(--bord);position:sticky;top:0;z-index:100}
.hdr-inner{display:flex;align-items:center;justify-content:space-between;height:58px}
.logo{display:flex;align-items:center;gap:10px;color:var(--txt);font-weight:600;font-size:.9rem}
.logo-icon{width:30px;height:30px;background:var(--acc);border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px}
.logo-sub{font-size:.7rem;color:var(--mut);font-weight:400;display:block}
.hdr-badge{font-size:.7rem;background:var(--surf2);border:1px solid var(--bord);border-radius:20px;padding:3px 10px;color:var(--mut)}

/* Tabs */
.tabs{display:flex;border-bottom:1px solid var(--bord);background:var(--surf);overflow-x:auto;gap:0}
.tab{padding:12px 18px;font-size:.82rem;font-weight:500;color:var(--mut);border-bottom:2px solid transparent;white-space:nowrap;transition:color .15s,border-color .15s;display:flex;align-items:center;gap:5px}
.tab:hover{color:var(--txt);text-decoration:none}
.tab.on{color:var(--acc);border-bottom-color:var(--acc)}

/* Sidebar */
.stitle{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--mut);margin-bottom:8px;padding-left:4px}
.gp-list{list-style:none;margin-bottom:24px}
.gp-list li a{display:flex;align-items:center;gap:7px;padding:5px 7px;border-radius:6px;font-size:.82rem;color:var(--txt);transition:background .12s}
.gp-list li a:hover{background:var(--surf2);text-decoration:none}
.gp-list li a.on{background:var(--acc);color:#fff}
.dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}

/* Search */
.search-box{display:flex;gap:8px;margin-bottom:16px}
.search-box input{flex:1;padding:7px 12px;border:1px solid var(--bord);border-radius:7px;font-family:var(--sans);font-size:.85rem;background:var(--surf);outline:none;transition:border .15s}
.search-box input:focus{border-color:var(--acc)}
.search-box button{padding:7px 14px;background:var(--acc);color:#fff;border:none;border-radius:7px;font-family:var(--sans);font-size:.82rem;cursor:pointer}

/* Stats cards */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:28px}
.scard{background:var(--surf);border:1px solid var(--bord);border-radius:var(--r);padding:18px;box-shadow:var(--sh)}
.sval{font-family:var(--serif);font-size:2.2rem;line-height:1;color:var(--txt);margin-bottom:3px}
.sval.acc{color:var(--acc)}
.slbl{font-size:.75rem;color:var(--mut);font-weight:500}
.sdelta{font-size:.72rem;color:var(--grn);margin-top:3px;font-weight:500}

/* Section */
.sec-hdr{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:14px;margin-top:28px}
.sec-ttl{font-size:.95rem;font-weight:600}
.sec-act{font-size:.78rem;color:var(--acc)}

/* GP cards */
.gp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:4px}
.gpc{background:var(--surf);border:1px solid var(--bord);border-radius:var(--r);padding:14px;box-shadow:var(--sh)}
.gpc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.gpc-sig{font-size:.75rem;font-weight:700;color:#fff;padding:2px 8px;border-radius:4px}
.gpc-score{font-family:var(--serif);font-size:1.4rem}
.gpc-meta{font-size:.78rem;color:var(--mut)}
.gpc-meta b{color:var(--txt)}

/* Table */
.card{background:var(--surf);border:1px solid var(--bord);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden}
.tbl{width:100%;border-collapse:collapse;font-size:.82rem}
.tbl th{text-align:left;padding:9px 14px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut);background:var(--surf2);border-bottom:1px solid var(--bord)}
.tbl td{padding:11px 14px;border-bottom:1px solid var(--bord);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#fafaf8}

/* Dep info */
.dep{display:flex;align-items:center;gap:9px}
.dep-foto{width:30px;height:30px;border-radius:50%;object-fit:cover;background:var(--surf2);border:1px solid var(--bord);flex-shrink:0}
.dep-n{font-weight:500}
.dep-n small{display:block;font-size:.73rem;font-weight:400;color:var(--mut);margin-top:1px}
.gptag{display:inline-block;padding:1px 7px;border-radius:4px;font-size:.68rem;font-weight:700;color:#fff;white-space:nowrap}

/* Bars */
.bar{height:5px;background:var(--surf2);border-radius:3px;overflow:hidden;min-width:70px;margin-top:3px}
.bfill{height:100%;border-radius:3px}

/* Badges */
.badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:.76rem;font-weight:700;min-width:44px;text-align:center}
.bg{background:#dcfce7;color:#166534}
.by{background:#fef9c3;color:#854d0e}
.br{background:#fee2e2;color:#991b1b}

/* Rank */
.rank{font-family:var(--serif);font-size:1rem;color:var(--mut);width:28px;text-align:right}
.rank.t{color:var(--acc);font-weight:600}

/* Sort buttons */
.sorts{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.sorts span{font-size:.75rem;color:var(--mut);font-weight:600}
.sbtn{padding:4px 11px;border:1px solid var(--bord);border-radius:6px;font-size:.76rem;background:var(--surf);color:var(--mut);cursor:pointer;text-decoration:none;font-family:var(--sans);font-weight:500;transition:all .12s;white-space:nowrap}
.sbtn:hover{border-color:var(--acc);color:var(--acc);text-decoration:none}
.sbtn.on{background:var(--acc);border-color:var(--acc);color:#fff}

/* Pagination */
.pag{display:flex;align-items:center;justify-content:center;gap:5px;padding:18px 14px;border-top:1px solid var(--bord)}
.pbtn{padding:5px 11px;border:1px solid var(--bord);border-radius:6px;font-size:.76rem;color:var(--mut);text-decoration:none;background:var(--surf);font-weight:500;transition:all .12s}
.pbtn:hover{border-color:var(--acc);color:var(--acc);text-decoration:none}
.pbtn.on{background:var(--acc);border-color:var(--acc);color:#fff}
.pinfo{font-size:.76rem;color:var(--mut);padding:0 8px}

/* Mini list */
.mlist{list-style:none}
.mlist li{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--bord);font-size:.82rem}
.mlist li:last-child{border-bottom:none}
.mlist .mn{font-weight:500}
.mlist .mn small{display:block;font-size:.72rem;color:var(--mut)}
.mlist .mv{font-family:var(--serif);font-size:1rem}

/* Two cols */
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px}
@media(max-width:640px){.two{grid-template-columns:1fr}}

/* Partido card grande */
.pcard{background:var(--surf);border:1px solid var(--bord);border-radius:var(--r);padding:20px;box-shadow:var(--sh)}
.pcard-hdr{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.pcard-sig{font-size:1rem;font-weight:800;color:#fff;padding:4px 12px;border-radius:6px}
.pcard-nome{font-size:.85rem;color:var(--mut)}
.pcard-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.pstat-v{font-family:var(--serif);font-size:1.5rem;color:var(--txt)}
.pstat-l{font-size:.72rem;color:var(--mut);margin-top:1px}

/* Tipo badge iniciativa */
.tipo{display:inline-block;padding:1px 7px;border-radius:4px;font-size:.68rem;font-weight:700;background:var(--surf2);color:var(--mut);white-space:nowrap}

/* Empty state */
.empty{text-align:center;padding:50px 20px;color:var(--mut)}
.empty .ei{font-size:2rem;margin-bottom:10px}
.empty h3{font-size:.95rem;font-weight:600;color:var(--txt);margin-bottom:5px}
.empty code{display:inline-block;margin-top:10px;background:var(--surf2);border:1px solid var(--bord);border-radius:6px;padding:5px 12px;font-size:.78rem}

/* Footer */
.ftr{border-top:1px solid var(--bord);background:var(--surf);padding:18px 0;font-size:.75rem;color:var(--mut)}
.ftr-inner{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.ftr a{color:var(--mut)}
.ftr a:hover{color:var(--acc)}

/* Metodologia */
.method{background:var(--surf);border:1px solid var(--bord);border-radius:var(--r);padding:20px;margin-bottom:20px;box-shadow:var(--sh)}
.method h3{font-size:.88rem;font-weight:600;margin-bottom:12px}
.mrow{display:flex;align-items:center;gap:12px;margin-bottom:9px}
.mw{font-family:var(--serif);font-size:1.3rem;color:var(--acc);width:48px;flex-shrink:0}
.md{font-size:.82rem;color:var(--mut)}
.md strong{color:var(--txt)}

/* Declarações */
.decl-info{background:linear-gradient(135deg,#f8f7f4,#f0ede8);border:1px solid var(--bord);border-radius:var(--r);padding:32px;text-align:center;box-shadow:var(--sh)}
.decl-info .icon{font-size:2.5rem;margin-bottom:12px}
.decl-info h3{font-size:1rem;font-weight:600;margin-bottom:8px}
.decl-info p{font-size:.85rem;color:var(--mut);max-width:400px;margin:0 auto 16px}
.decl-info .sources{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:12px}
.decl-info .src{background:var(--surf);border:1px solid var(--bord);border-radius:6px;padding:6px 14px;font-size:.78rem;color:var(--mut)}

/* Ano selector */
.ano-selector{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.ano-selector span{font-size:.75rem;color:var(--mut);font-weight:600}
.abtn{padding:4px 11px;border:1px solid var(--bord);border-radius:6px;font-size:.76rem;background:var(--surf);color:var(--mut);cursor:pointer;text-decoration:none;font-family:var(--sans);font-weight:500;transition:all .12s;white-space:nowrap}
.abtn:hover{border-color:var(--acc);color:var(--acc);text-decoration:none}
.abtn.on{background:var(--acc);border-color:var(--acc);color:#fff}

/* Declaração card */
.decl-card{background:var(--surf);border:1px solid var(--bord);border-radius:var(--r);padding:16px;margin-bottom:12px;box-shadow:var(--sh)}
.decl-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.decl-titulo{font-weight:600;font-size:.88rem}
.decl-meta{font-size:.76rem;color:var(--mut)}
.decl-detalhe{font-size:.82rem;color:var(--txt);line-height:1.4}
.decl-valor{font-family:var(--serif);font-size:1.1rem;color:var(--acc);margin:4px 0}
</style>
</head>
<body>

<header class="hdr">
  <div class="wrap hdr-inner">
    <a href="?" class="logo">
      <div class="logo-icon">🏛</div>
      <div>Transparência PT<span class="logo-sub">Assembleia da República · <?= LEG_NUM ?> Legislatura</span></div>
    </a>
    <span class="hdr-badge">Beta · dados abertos AR</span>
  </div>
  <div class="wrap">
    <nav class="tabs">
      <a href="?tab=visao" class="tab <?= $tab==='visao'?'on':'' ?>">📊 Visão Geral</a>
      <a href="?tab=score" class="tab <?= $tab==='score'?'on':'' ?>">🏆 Score</a>
      <a href="?tab=iniciativas" class="tab <?= $tab==='iniciativas'?'on':'' ?>">📋 Iniciativas</a>
      <a href="?tab=partidos" class="tab <?= $tab==='partidos'?'on':'' ?>">🎯 Partidos</a>
      <a href="?tab=grupos" class="tab <?= $tab==='grupos'?'on':'' ?>">🏛️ Grupos</a>
      <a href="?tab=declaracoes" class="tab <?= $tab==='declaracoes'?'on':'' ?>">💼 Declarações</a>
    </nav>
  </div>
</header>

<main class="wrap">
<div class="grid">

<!-- Sidebar -->
<aside class="sidebar">
  <div class="stitle">Grupos Parlamentares</div>
  <ul class="gp-list">
    <li><a href="?tab=<?=$tab?>&ord=<?=$ordem?>" class="<?=$gp===''?'on':''?>">
      <span class="dot" style="background:#94a3b8"></span>Todos
    </a></li>
    <?php foreach ($gps_lista as $g): ?>
    <li><a href="?tab=<?=$tab?>&gp=<?=$g['sigla']?>&ord=<?=$ordem?>" class="<?=$gp===$g['sigla']?'on':''?>">
      <span class="dot" style="background:<?=htmlspecialchars($g['cor_hex']??'#999')?>"></span>
      <?=htmlspecialchars($g['sigla'])?>
    </a></li>
    <?php endforeach; ?>
  </ul>
  <div class="stitle">Fontes</div>
  <ul class="gp-list" style="font-size:.75rem">
    <li><a href="https://www.parlamento.pt" target="_blank">↗ Assembleia da República</a></li>
    <li><a href="https://www.base.gov.pt" target="_blank">↗ Portal Base</a></li>
    <li><a href="https://www.entidadetransparencia.pt" target="_blank">↗ Entidade Transparência</a></li>
    <li><a href="https://www.cne.pt" target="_blank">↗ CNE</a></li>
  </ul>
</aside>

<!-- Content -->
<div>

<?php
// ══════════════════════════════════════════════
// TAB: VISÃO GERAL
// ══════════════════════════════════════════════
if ($tab === 'visao'):
try { $st = get_stats(); $ok = $st['deputados'] > 0; } catch(Exception $e) { $st=[]; $ok=false; }
?>
<?php if (!$ok): ?>
<div class="empty"><div class="ei">⚙️</div><h3>Base de dados ainda vazia</h3>
<p>Execute o ETL para importar os dados da Assembleia da República.</p>
<code>python importar_ar.py --all</code></div>
<?php else: ?>

<div class="stats">
  <div class="scard"><div class="sval acc"><?=number_format($st['deputados'])?></div><div class="slbl">Deputados em funções</div></div>
  <div class="scard"><div class="sval"><?=number_format($st['sessoes'])?></div><div class="slbl">Sessões plenárias</div></div>
  <div class="scard"><div class="sval"><?=number_format($st['iniciativas'])?></div><div class="slbl">Iniciativas legislativas</div></div>
  <div class="scard"><div class="sval"><?=$st['presenca_media']?>%</div><div class="slbl">Presença média</div></div>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">Por Grupo Parlamentar</h2></div>
<div class="gp-grid" style="margin-bottom:28px">
<?php foreach ($st['por_gp'] as $g):
  $cor = gp_cor($g['gp'] ?? ''); ?>
<div class="gpc">
  <div class="gpc-top">
    <span class="gpc-sig" style="background:<?=htmlspecialchars($cor)?>"><?=htmlspecialchars($g['gp']??'?')?></span>
    <span class="gpc-score"><?=$g['score_medio']??'—'?></span>
  </div>
  <div class="gpc-meta" style="margin-bottom:6px"><b><?=$g['total']?></b> deputados</div>
  <?= bar($g['presenca_media']??0, $cor) ?>
  <div style="font-size:.72rem;color:var(--mut);margin-top:3px"><?=$g['presenca_media']??0?>% presença · <?=$g['iniciativas']??0?> iniciativas</div>
</div>
<?php endforeach; ?>
</div>

<div class="two">
  <div>
    <div class="sec-hdr"><h2 class="sec-ttl">✅ Mais presentes</h2>
    <a href="?tab=score&ord=presenca" class="sec-act">ver ranking</a></div>
    <div class="card"><ul class="mlist">
    <?php foreach (array_slice($st['top_presenca'],0,8) as $r):
      $cor = gp_cor($r['gp_sigla']??''); ?>
    <li>
      <div class="mn"><?=htmlspecialchars($r['nome_curto']??'')?><small><span class="gptag" style="background:<?=$cor?>"><?=htmlspecialchars($r['gp_sigla']??'')?></span></small></div>
      <div class="mv" style="color:var(--grn)"><?=pct($r['taxa_presenca']??0)?></div>
    </li>
    <?php endforeach; ?></ul></div>
  </div>
  <div>
    <div class="sec-hdr"><h2 class="sec-ttl">❌ Menos presentes</h2></div>
    <div class="card"><ul class="mlist">
    <?php foreach (array_slice($st['menos_presenca'],0,8) as $r):
      $cor = gp_cor($r['gp_sigla']??''); ?>
    <li>
      <div class="mn"><?=htmlspecialchars($r['nome_curto']??'')?><small><span class="gptag" style="background:<?=$cor?>"><?=htmlspecialchars($r['gp_sigla']??'')?></span></small></div>
      <div class="mv" style="color:var(--red)"><?=pct($r['taxa_presenca']??0)?></div>
    </li>
    <?php endforeach; ?></ul></div>
  </div>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">📋 Mais activos em iniciativas</h2>
<a href="?tab=score&ord=iniciativas" class="sec-act">ver ranking</a></div>
<p style="font-size:.76rem;color:var(--mut);margin:-4px 0 8px">Conta subscrição, não autoria exclusiva — iniciativas assinadas por todo o Grupo Parlamentar contam para cada um dos seus deputados.</p>
<div class="card"><ul class="mlist">
<?php foreach (array_slice($st['mais_iniciativas'],0,8) as $i => $r):
  $cor = gp_cor($r['gp_sigla']??''); ?>
<li>
  <div class="mn"><?=($i+1)?>.&nbsp;<?=htmlspecialchars($r['nome_curto']??'')?><small><span class="gptag" style="background:<?=$cor?>"><?=htmlspecialchars($r['gp_sigla']??'')?></span></small></div>
  <div class="mv" style="color:var(--acc)"><?=(int)$r['n_iniciativas']?> ini.</div>
</li>
<?php endforeach; ?></ul></div>

<?php endif; ?>

<?php
// ══════════════════════════════════════════════
// TAB: SCORE
// ══════════════════════════════════════════════
elseif ($tab === 'score'):
try { $rk = get_ranking($page, $gp, $ordem, $search); $ok = count($rk['rows']) > 0; }
catch(Exception $e) { $rk=['rows'=>[],'total'=>0,'page'=>1,'per'=>25,'gp'=>'','ordem'=>'score','search'=>'']; $ok=false; }
$total_pags = $ok ? (int)ceil($rk['total'] / $rk['per']) : 0;
?>

<?php
$tem_autores_ui   = (bool) db_one("SELECT 1 as x FROM iniciativas_autores LIMIT 1", []);
$tem_contratos_ui = (bool) db_one("SELECT 1 as x FROM contratos_base LIMIT 1", []);
$soma_pesos_ui    = 0.40 + ($tem_autores_ui ? 0.30 : 0) + ($tem_contratos_ui ? 0.30 : 0);
$peso_presenca_ui = 0.40 / $soma_pesos_ui;
$peso_ini_ui      = $tem_autores_ui ? 0.30 / $soma_pesos_ui : 0;
?>
<div class="method">
  <h3>Como é calculado o Score de Transparência</h3>
  <div class="mrow"><div class="mw"><?=round($peso_presenca_ui*100)?>%</div><div class="md"><strong>Presença em Plenário</strong> — % de sessões com presença registada</div></div>
  <?php if ($tem_autores_ui): ?>
  <div class="mrow"><div class="mw"><?=round($peso_ini_ui*100)?>%</div><div class="md"><strong>Iniciativas Legislativas</strong> — iniciativas subscritas (tecto: 5 = 100%). Conta subscrição, não autoria exclusiva: quando um Grupo Parlamentar assina uma iniciativa colectivamente, todos os seus deputados recebem crédito por igual — o tecto de 5 existe precisamente para atenuar essa distorção.</div></div>
  <?php else: ?>
  <div class="mrow"><div class="mw" style="color:var(--mut)">—</div><div class="md" style="color:var(--mut)"><strong>Iniciativas Legislativas</strong> — desactivado: ainda sem dados de autoria por deputado (peso redistribuído para Presença)</div></div>
  <?php endif; ?>
  <div class="mrow"><div class="mw" style="color:var(--mut)">—</div><div class="md" style="color:var(--mut)"><strong>Contratos Públicos</strong> — não incluído. Ao contrário do Brasil (CPF público e cruzável), em Portugal o NIF de pessoa singular não é público e o Portal Base só pesquisa por NIPC (empresa); ligar um deputado a uma empresa contratada exigiria o Registo Central do Beneficiário Efectivo, que não é de acesso livre. Peso redistribuído para Presença e Iniciativas.</div></div>
</div>

<form method="get" action="">
  <input type="hidden" name="tab" value="score">
  <input type="hidden" name="gp" value="<?=htmlspecialchars($gp)?>">
  <input type="hidden" name="ord" value="<?=htmlspecialchars($ordem)?>">
  <div class="search-box">
    <input type="text" name="q" placeholder="Pesquisar deputado..." value="<?=htmlspecialchars($search)?>">
    <button type="submit">Pesquisar</button>
  </div>
</form>

<div class="sorts">
  <span>Ordenar:</span>
  <?php foreach(['score'=>'Score Total','presenca'=>'Presença','iniciativas'=>'Iniciativas','nome'=>'Nome'] as $k=>$v): ?>
  <a href="?tab=score&gp=<?=$gp?>&ord=<?=$k?>&p=1&q=<?=urlencode($search)?>" class="sbtn <?=$ordem===$k?'on':''?>"><?=$v?></a>
  <?php endforeach; ?>
  <?php if ($ok): ?><span class="pinfo" style="margin-left:auto"><?=number_format($rk['total'])?> deputados</span><?php endif; ?>
</div>

<?php if (!$ok): ?>
<div class="empty"><div class="ei">📋</div><h3>Sem dados</h3><p>Correr o ETL primeiro.</p><code>python importar_ar.py --all</code></div>
<?php else: ?>
<div class="card">
<table class="tbl">
<thead><tr>
  <th style="width:36px">#</th>
  <th>Deputado</th>
  <th style="width:56px">GP</th>
  <th style="min-width:120px">Score</th>
  <th style="min-width:110px">Presença</th>
  <th style="width:80px;text-align:center">Iniciativas</th>
</tr></thead>
<tbody>
<?php foreach ($rk['rows'] as $dep):
  $rank = (int)$dep['rank_geral'];
  $cor  = gp_cor($dep['gp_sigla']??'');
?>
<tr>
  <td><span class="rank <?=$rank<=3?'t':''?>"><?=$rank?></span></td>
  <td><div class="dep">
    <img src="<?=htmlspecialchars($dep['url_foto']??'')?>" alt="" class="dep-foto" onerror="this.style.display='none'">
    <div class="dep-n"><?=htmlspecialchars($dep['nome_curto']??$dep['nome_completo']??'')?>
  </div></td>
  <td><span class="gptag" style="background:<?=$cor?>"><?=htmlspecialchars($dep['gp_sigla']??'?')?></span></td>
  <td>
    <span class="badge b<?=score_cls($dep['score_total'])?>"><?=number_format($dep['score_total'],1)?></span>
    <?=bar($dep['score_total'], $cor)?>
  </td>
  <td>
    <div style="font-size:.76rem;color:var(--mut)"><?=pct($dep['taxa_presenca']??0)?></div>
    <?=bar(($dep['taxa_presenca']??0)*100,'#2563eb')?>
  </td>
  <td>
    <div style="font-size:.76rem;color:var(--mut)">
      <?php if ($dep['n_iniciativas'] > 0): ?>
        <?=number_format($dep['n_iniciativas'])?> iniciativas
      <?php else: ?>
        <span style="color:var(--mut);font-size:.73rem">0</span>
      <?php endif; ?>
    </div>
    <?php if ($dep['n_iniciativas'] > 0): ?>
      <?=bar(min($dep['n_iniciativas']/5, 1), '#16a34a')?>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ($total_pags > 1): ?>
<div class="pag">
  <?php if ($page > 1): ?><a href="?tab=score&gp=<?=$gp?>&ord=<?=$ordem?>&p=<?=$page-1?>&q=<?=urlencode($search)?>" class="pbtn">← Anterior</a><?php endif; ?>
  <?php for($pg=max(1,$page-2);$pg<=min($total_pags,$page+2);$pg++): ?>
  <a href="?tab=score&gp=<?=$gp?>&ord=<?=$ordem?>&p=<?=$pg?>&q=<?=urlencode($search)?>" class="pbtn <?=$pg===$page?'on':''?>"><?=$pg?></a>
  <?php endfor; ?>
  <?php if ($page < $total_pags): ?><a href="?tab=score&gp=<?=$gp?>&ord=<?=$ordem?>&p=<?=$page+1?>&q=<?=urlencode($search)?>" class="pbtn">Seguinte →</a><?php endif; ?>
  <span class="pinfo">Página <?=$page?> de <?=$total_pags?></span>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════
// TAB: INICIATIVAS
// ══════════════════════════════════════════════
elseif ($tab === 'iniciativas'):
try { $ini = get_iniciativas($page, $gp, $search); $ok = count($ini['rows']) > 0; }
catch(Exception $e) { $ini=['rows'=>[],'total'=>0,'page'=>1,'per'=>25]; $ok=false; }
$total_pags = $ok ? (int)ceil($ini['total'] / $ini['per']) : 0;
?>

<div class="stats">
  <div class="scard"><div class="sval acc"><?=number_format($ini['total'])?></div><div class="slbl">Iniciativas na XVII Leg.</div></div>
  <?php
  $tipos = db_query("SELECT tipo, COUNT(*) as n FROM iniciativas WHERE legislatura_id=? GROUP BY tipo ORDER BY n DESC LIMIT 3", [LEG_ID]);
  foreach ($tipos as $t): ?>
  <div class="scard"><div class="sval"><?=$t['n']?></div><div class="slbl"><?=htmlspecialchars($t['tipo']??'?')?></div></div>
  <?php endforeach; ?>
</div>

<form method="get" action="">
  <input type="hidden" name="tab" value="iniciativas">
  <div class="search-box">
    <input type="text" name="q" placeholder="Pesquisar por título..." value="<?=htmlspecialchars($search)?>">
    <button type="submit">Pesquisar</button>
  </div>
</form>

<?php if (!$ok): ?>
<div class="empty"><div class="ei">📋</div><h3>Sem iniciativas</h3><p>Correr o ETL de iniciativas.</p><code>python importar_ar.py --iniciativas</code></div>
<?php else: ?>
<div class="card">
<table class="tbl">
<thead><tr>
  <th style="width:60px">Tipo</th>
  <th>Título</th>
  <th style="width:110px">Autoria</th>
  <th style="width:90px">Data</th>
  <th style="width:80px">Estado</th>
  <th style="width:60px">Link</th>
</tr></thead>
<tbody>
<?php foreach ($ini['rows'] as $i): ?>
<tr>
  <td><span class="tipo"><?=htmlspecialchars($i['tipo']??'?')?></span></td>
  <td style="font-size:.82rem"><?=htmlspecialchars(substr($i['titulo']??'',0,100))?><?=strlen($i['titulo']??'')>100?'…':''?></td>
  <td style="font-size:.75rem;color:var(--mut)" title="<?=(int)$i['n_autores']?> deputado(s) subscritor(es)"><?=htmlspecialchars($i['autoria_gp']??'—')?></td>
  <td style="font-size:.76rem;color:var(--mut)"><?=htmlspecialchars(substr($i['data_entrada']??'',0,10))?></td>
  <td style="font-size:.75rem;color:var(--mut)"><?=htmlspecialchars($i['estado']??'')?></td>
  <td><?php if ($i['url_ar']): ?><a href="<?=htmlspecialchars($i['url_ar'])?>" target="_blank" style="font-size:.75rem">↗ AR</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ($total_pags > 1): ?>
<div class="pag">
  <?php if ($page > 1): ?><a href="?tab=iniciativas&p=<?=$page-1?>&q=<?=urlencode($search)?>" class="pbtn">← Anterior</a><?php endif; ?>
  <?php for($pg=max(1,$page-2);$pg<=min($total_pags,$page+2);$pg++): ?>
  <a href="?tab=iniciativas&p=<?=$pg?>&q=<?=urlencode($search)?>" class="pbtn <?=$pg===$page?'on':''?>"><?=$pg?></a>
  <?php endfor; ?>
  <?php if ($page < $total_pags): ?><a href="?tab=iniciativas&p=<?=$page+1?>&q=<?=urlencode($search)?>" class="pbtn">Seguinte →</a><?php endif; ?>
  <span class="pinfo">Página <?=$page?> de <?=$total_pags?></span>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════
// TAB: GRUPOS - Grupos Parlamentares Activos
// ══════════════════════════════════════════════
elseif ($tab === 'grupos'):
try { 
    $grupos = get_grupos(); 
    $ok = count($grupos) > 0; 
} catch(Exception $e) { 
    $grupos = []; 
    $ok = false; 
}
?>

<?php if (!$ok): ?>
<div class="empty"><div class="ei">🏛️</div><h3>Sem dados de grupos</h3></div>
<?php else: ?>

<div class="stats" style="margin-bottom:28px">
  <div class="scard"><div class="sval acc"><?=count($grupos)?></div><div class="slbl">Grupos parlamentares activos</div></div>
  <?php
  $total_deputados = array_sum(array_column($grupos, 'n_activos'));
  $total_iniciativas = array_sum(array_column($grupos, 'total_iniciativas'));
  ?>
  <div class="scard"><div class="sval"><?=number_format($total_deputados)?></div><div class="slbl">Deputados em funções</div></div>
  <div class="scard"><div class="sval"><?=number_format($total_iniciativas)?></div><div class="slbl">Total de iniciativas</div></div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
<?php foreach ($grupos as $g):
  if (!($g['n_activos']??0)) continue;
  $cor = gp_cor($g['sigla']??'');
?>
<div class="pcard">
  <div class="pcard-hdr">
    <span class="pcard-sig" style="background:<?=$cor?>"><?=htmlspecialchars($g['sigla']??'')?></span>
    <div style="flex:1">
      <div style="font-weight:600;font-size:.9rem"><?=htmlspecialchars($g['nome']??'')?></div>
      <div class="pcard-nome"><?=$g['n_activos']?> deputados activos · <?=$g['n_deputados']?> total</div>
    </div>
    <?php if ($g['lider_foto']): ?>
      <img src="<?=htmlspecialchars($g['lider_foto'])?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid <?=$cor?>">
    <?php endif; ?>
  </div>
  
  <div class="pcard-stats">
    <div><div class="pstat-v"><?=$g['presenca_media']??0?>%</div><div class="pstat-l">Presença média</div></div>
    <div><div class="pstat-v"><?=(int)($g['total_iniciativas']??0)?></div><div class="pstat-l">Iniciativas</div></div>
    <div><div class="pstat-v"><?=$g['score_medio']??0?></div><div class="pstat-l">Score médio</div></div>
  </div>
  
  <div style="margin-top:12px">
    <div style="font-size:.82rem;color:var(--mut);margin-bottom:6px">
      <strong>Líder:</strong> <?=htmlspecialchars($g['lider_nome']??'N/A')?>
    </div>
    <?= bar(($g['presenca_media']??0)/100, $cor) ?>
    <div style="font-size:.72rem;color:var(--mut);margin-top:3px">
      Presença: <?=round(($g['pior_presenca']??0)*100,1)?>%–<?=round(($g['melhor_presenca']??0)*100,1)?>% (min–max)
    </div>
  </div>
  
  <div style="margin-top:12px;display:flex;gap:8px;justify-content:space-between">
    <a href="?tab=score&gp=<?=urlencode($g['sigla']??'')?>" class="sbtn" style="font-size:.75rem">Ver ranking →</a>
    <a href="?tab=visao&gp=<?=urlencode($g['sigla']??'')?>" class="sbtn" style="font-size:.75rem">Visão geral</a>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php
// ══════════════════════════════════════════════
// TAB: PARTIDOS
// ══════════════════════════════════════════════
elseif ($tab === 'partidos'):
try { $partidos = get_partidos(); $ok = count($partidos) > 0; }
catch(Exception $e) { $partidos=[]; $ok=false; }
?>

<?php if (!$ok): ?>
<div class="empty"><div class="ei">🎯</div><h3>Sem dados</h3></div>
<?php else: ?>

<div class="stats" style="margin-bottom:28px">
  <div class="scard"><div class="sval acc"><?=count(array_filter($partidos,fn($p)=>($p['n_deputados']??0)>0))?></div><div class="slbl">Grupos parlamentares activos</div></div>
  <?php
  $melhor = array_reduce($partidos, fn($c,$p) => (!$c || ($p['presenca_media']??0) > ($c['presenca_media']??0)) ? $p : $c, null);
  $mais_ini = array_reduce($partidos, fn($c,$p) => (!$c || ($p['total_iniciativas']??0) > ($c['total_iniciativas']??0)) ? $p : $c, null);
  ?>
  <?php if ($melhor): ?>
  <div class="scard">
    <div class="sval" style="color:<?=gp_cor($melhor['sigla']??'')?>"><?=$melhor['presenca_media']?>%</div>
    <div class="slbl">Melhor presença — <?=htmlspecialchars($melhor['sigla']??'')?></div>
  </div>
  <?php endif; ?>
  <?php if ($mais_ini): ?>
  <div class="scard">
    <div class="sval" style="color:<?=gp_cor($mais_ini['sigla']??'')?>"><?=(int)$mais_ini['total_iniciativas']?></div>
    <div class="slbl">Mais iniciativas — <?=htmlspecialchars($mais_ini['sigla']??'')?></div>
  </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
<?php foreach ($partidos as $p):
  if (!($p['n_deputados']??0)) continue;
  $cor = gp_cor($p['sigla']??'');
?>
<div class="pcard">
  <div class="pcard-hdr">
    <span class="pcard-sig" style="background:<?=$cor?>"><?=htmlspecialchars($p['sigla']??'')?></span>
    <div>
      <div style="font-weight:600;font-size:.88rem"><?=htmlspecialchars($p['nome']??'')?></div>
      <div class="pcard-nome"><?=$p['n_deputados']?> deputados</div>
    </div>
  </div>
  <div class="pcard-stats">
    <div><div class="pstat-v"><?=$p['presenca_media']??0?>%</div><div class="pstat-l">Presença média</div></div>
    <div><div class="pstat-v"><?=(int)($p['total_iniciativas']??0)?></div><div class="pstat-l">Iniciativas</div></div>
    <div><div class="pstat-v"><?=$p['score_medio']??0?></div><div class="pstat-l">Score médio</div></div>
  </div>
  <div style="margin-top:10px">
    <?= bar($p['presenca_media']??0, $cor) ?>
    <div style="font-size:.72rem;color:var(--mut);margin-top:3px">
      Presença: <?=$p['pior_presenca']?100:0?>%–<?=round(($p['melhor_presenca']??0)*100,1)?>% (min–max)
    </div>
  </div>
  <div style="margin-top:10px;text-align:right">
    <a href="?tab=score&gp=<?=urlencode($p['sigla']??'')?>" class="sbtn" style="font-size:.75rem">Ver deputados →</a>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════
// TAB: DECLARAÇÕES
// ══════════════════════════════════════════════
elseif ($tab === 'declaracoes'):

// Se temos dep_id, mostrar detalhes de um deputado específico
if ($dep_id):
    $decl_det = get_declaracoes_det($dep_id);
    $dep_info = $decl_det[0] ?? null;
    $n_decl = count($decl_det);
?>
    
<?php if ($dep_info): ?>
<div style="margin-bottom:20px">
  <a href="?tab=declaracoes&gp=<?=$gp?>&ano=<?=$ano?>&q=<?=urlencode($search)?>" class="sbtn">← Voltar à lista</a>
</div>

<div class="decl-card">
  <div class="decl-header">
    <div class="decl-titulo"><?=htmlspecialchars($dep_info['nome_curto'] ?? $dep_info['nome_completo'])?></div>
    <div class="decl-meta">
      <span class="gptag" style="background:<?=gp_cor($dep_info['gp_sigla']??'')?>"><?=htmlspecialchars($dep_info['gp_sigla']??'')?></span>
      · <?=$n_decl?> declarações
    </div>
  </div>
  <div style="margin-top:12px;padding:12px;background:var(--surf2);border-radius:7px;font-size:.82rem;color:var(--mut)">
    ℹ️ Os dados de rendimentos e património não são públicos por lei em Portugal. 
    O que é público é o <strong>Registo de Interesses</strong> (cargos e actividades declaradas).
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
      <a href="https://www.parlamento.pt/RegistoInteresses/Paginas/RegistoInteressesDeputados.aspx" 
         target="_blank" class="sbtn">↗ Registo de Interesses AR</a>
      <a href="https://entidadetransparencia.pt" 
         target="_blank" class="sbtn">↗ Entidade da Transparência</a>
    </div>
  </div>
</div>

<div class="card" style="margin-top:16px">
<table class="tbl">
<thead><tr>
  <th style="width:80px">Ano</th>
  <th style="width:100px">Tipo</th>
  <th style="width:120px">Rendimento Total</th>
  <th>Património</th>
  <th style="width:80px">PDF</th>
</tr></thead>
<tbody>
<?php foreach ($decl_det as $d): ?>
<tr>
  <td style="font-weight:600"><?=$d['ano']?></td>
  <td><span class="tipo"><?=htmlspecialchars($d['tipo']??'')?></span></td>
  <td>
    <?php if ($d['rendimento_total'] && $d['rendimento_total'] > 0): ?>
      <div class="decl-valor">€<?=number_format($d['rendimento_total'],0)?></div>
    <?php else: ?>
      <span style="color:var(--mut);font-size:.76rem">Não declarado</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($d['patrimonio_json']): ?>
      <?php 
      $pat = json_decode($d['patrimonio_json'], true);
      if ($pat && isset($pat['imoveis']) && count($pat['imoveis']) > 0): 
      ?>
        <div style="font-size:.82rem">
          <strong><?=count($pat['imoveis'])?> imóveis</strong>
          <?php if (isset($pat['veiculos']) && count($pat['veiculos']) > 0): ?>
            · <?=count($pat['veiculos'])?> veículos
          <?php endif; ?>
        </div>
      <?php else: ?>
        <span style="color:var(--mut);font-size:.76rem">Sem detalhes</span>
      <?php endif; ?>
    <?php else: ?>
      <span style="color:var(--mut);font-size:.76rem">Não processado</span>
    <?php endif; ?>
  </td>
  <td>
    <a href="https://www.parlamento.pt/RegistoInteresses/Paginas/RegInteresses_v5.aspx?BID=<?=$dep_id?>&leg=XVII" 
       target="_blank" style="font-size:.75rem" title="Registo de Interesses na AR">↗ AR</a>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php else: ?>
<div class="empty"><div class="ei">📋</div><h3>Deputado não encontrado</h3></div>
<?php endif; ?>

<?php else: ?>
<?php
// Vista principal da lista de deputados
try { 
    $decl = get_declaracoes($page, $gp, $search, $ano); 
    $ok = count($decl['rows']) > 0; 
} catch(Exception $e) { 
    $decl = ['rows'=>[],'total'=>0,'page'=>1,'per'=>25,'gp'=>'','search'=>'','ano'=>null]; 
    $ok = false; 
}
$total_pags = $ok ? (int)ceil($decl['total'] / $decl['per']) : 0;

// Anos disponíveis para filtros
$anos_disponiveis = db_query("SELECT DISTINCT ano FROM declaracoes_rendimentos ORDER BY ano DESC");
?>

<?php if ($ok): ?>
<div class="stats">
  <div class="scard"><div class="sval acc"><?=number_format($decl['total'])?></div><div class="slbl">Deputados com declarações</div></div>
  <div class="scard"><div class="sval"><?=count($anos_disponiveis)?></div><div class="slbl">Anos disponíveis</div></div>
  <?php
  $total_decl = db_one("SELECT COUNT(*) as n FROM declaracoes_rendimentos", [])['n'] ?? 0;
  ?>
  <div class="scard"><div class="sval"><?=number_format($total_decl)?></div><div class="slbl">Total de declarações</div></div>
</div>

<form method="get" action="">
  <input type="hidden" name="tab" value="declaracoes">
  <div class="search-box">
    <input type="text" name="q" placeholder="Pesquisar deputado..." value="<?=htmlspecialchars($search)?>">
    <button type="submit">Pesquisar</button>
  </div>
</form>

<div class="ano-selector">
  <span>Ano:</span>
  <a href="?tab=declaracoes&gp=<?=$gp?>&q=<?=urlencode($search)?>" class="abtn <?=$ano===null?'on':''?>">Todos</a>
  <?php foreach ($anos_disponiveis as $a): ?>
  <a href="?tab=declaracoes&gp=<?=$gp?>&ano=<?=$a['ano']?>&q=<?=urlencode($search)?>" class="abtn <?=$ano===$a['ano']?'on':''?>"><?=$a['ano']?></a>
  <?php endforeach; ?>
  <?php if ($ok): ?><span class="pinfo" style="margin-left:auto"><?=number_format($decl['total'])?> deputados</span><?php endif; ?>
</div>

<div class="card">
<table class="tbl">
<thead><tr>
  <th>Deputado</th>
  <th style="width:56px">GP</th>
  <th style="width:80px;text-align:center">Declarações</th>
  <th style="width:80px">Último ano</th>
  <th style="width:110px">Maior rendimento</th>
  <th style="width:90px">Ações</th>
</tr></thead>
<tbody>
<?php foreach ($decl['rows'] as $d):
  $cor = gp_cor($d['gp_sigla']??'');
?>
<tr>
  <td><div class="dep">
    <img src="<?=htmlspecialchars($d['url_foto']??'')?>" alt="" class="dep-foto" onerror="this.style.display='none'">
    <div class="dep-n"><?=htmlspecialchars($d['nome_curto'] ?? $d['nome_completo'] ?? '')?>
  </div></td>
  <td><span class="gptag" style="background:<?=$cor?>"><?=htmlspecialchars($d['gp_sigla']??'?')?></span></td>
  <td style="text-align:center;font-family:var(--serif);font-size:1rem"><?=number_format($d['n_declaracoes'])?></td>
  <td style="font-size:.76rem;color:var(--mut)"><?=$d['ultimo_ano']??'—'?></td>
  <td>
    <?php if ($d['max_rendimento'] && $d['max_rendimento'] > 0): ?>
      <div class="decl-valor">€<?=number_format($d['max_rendimento'],0)?></div>
    <?php else: ?>
      <span style="color:var(--mut);font-size:.76rem">Não declarado</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($d['n_declaracoes'] > 0): ?>
      <a href="?tab=declaracoes&dep=<?=$d['id']?>&gp=<?=$gp?>&ano=<?=$ano?>&q=<?=urlencode($search)?>" class="sbtn" style="font-size:.75rem">Ver detalhes →</a>
    <?php else: ?>
      <span style="color:var(--mut);font-size:.76rem">Sem declarações</span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ($total_pags > 1): ?>
<div class="pag">
  <?php if ($page > 1): ?><a href="?tab=declaracoes&gp=<?=$gp?>&ano=<?=$ano?>&p=<?=$page-1?>&q=<?=urlencode($search)?>" class="pbtn">← Anterior</a><?php endif; ?>
  <?php for($pg=max(1,$page-2);$pg<=min($total_pags,$page+2);$pg++): ?>
  <a href="?tab=declaracoes&gp=<?=$gp?>&ano=<?=$ano?>&p=<?=$pg?>&q=<?=urlencode($search)?>" class="pbtn <?=$pg===$page?'on':''?>"><?=$pg?></a>
  <?php endfor; ?>
  <?php if ($page < $total_pags): ?><a href="?tab=declaracoes&gp=<?=$gp?>&ano=<?=$ano?>&p=<?=$page+1?>&q=<?=urlencode($search)?>" class="pbtn">Seguinte →</a><?php endif; ?>
  <span class="pinfo">Página <?=$page?> de <?=$total_pags?></span>
</div>
<?php endif; ?>
</div>

<?php else: ?>
<div class="decl-info">
  <div class="icon">💼</div>
  <h3>Declarações de Rendimentos e Património</h3>
  <p>Os dados das declarações de rendimentos e interesses dos deputados são publicados pela Entidade da Transparência em formato PDF.</p>
  <p style="margin-top:8px">O scraper de PDFs está em desenvolvimento. Quando disponível, esta tab mostrará:</p>
  <div class="sources">
    <span class="src">💰 Rendimentos declarados</span>
    <span class="src">🏠 Património imobiliário</span>
    <span class="src">📈 Aplicações financeiras</span>
    <span class="src">🚗 Veículos</span>
    <span class="src">💼 Actividades remuneradas</span>
  </div>
  <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
    <a href="https://www.entidadetransparencia.pt/transparencia/titulares-de-cargos-politicos-e-altos-cargos-publicos/assembleia-da-republica/" target="_blank" class="sbtn">↗ Entidade da Transparência</a>
    <a href="https://www.parlamento.pt/RegistoInteresses/Paginas/deputados-e-membros-governo.aspx" target="_blank" class="sbtn">↗ Registo de Interesses AR</a>
  </div>
</div>
<?php endif; ?>

<?php endif; // dep_id check ?>

<?php endif; // tabs ?>

</div><!-- /content -->
</div><!-- /grid -->
</main>

<footer class="ftr">
  <div class="wrap ftr-inner">
    <span>Dados: <a href="https://www.parlamento.pt/Cidadania/Paginas/DadosAbertos.aspx" target="_blank">Assembleia da República</a> · <a href="https://www.base.gov.pt" target="_blank">Portal Base</a> · <a href="https://www.entidadetransparencia.pt" target="_blank">Entidade da Transparência</a></span>
    <span>Actualizado: <?= date('d/m/Y H:i') ?></span>
  </div>
</footer>

</body>
</html>
