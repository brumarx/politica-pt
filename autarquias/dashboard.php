<?php
/**
 * autarquias/dashboard.php — listagem de municípios com indicadores financeiros
 */
require __DIR__ . '/db.php';

$search = trim($_GET['q'] ?? '');
$ordem  = in_array($_GET['ord'] ?? 'despesa', ['despesa','receita','nome']) ? ($_GET['ord'] ?? 'despesa') : 'despesa';

function get_municipios_lista(string $search, string $ordem): array {
    $ck = 'municipios_' . md5($search . $ordem);
    if ($c = aut_cache_get($ck)) return $c;

    $where = '1=1';
    $params = [];
    if ($search) { $where .= ' AND m.nome LIKE ?'; $params[] = "%$search%"; }
    $order_map = [
        'despesa' => 'despesa_total DESC',
        'receita' => 'receita_total DESC',
        'nome'    => 'm.nome ASC',
    ];
    $ord = $order_map[$ordem] ?? 'despesa_total DESC';

    $rows = aut_query("
        SELECT m.id, m.nome,
               (SELECT valor_milhares FROM municipio_financas WHERE municipio_id=m.id AND tipo='despesa' AND categoria_cod='D') as despesa_total,
               (SELECT valor_milhares FROM municipio_financas WHERE municipio_id=m.id AND tipo='receita' AND categoria_cod='R') as receita_total,
               (SELECT ano FROM municipio_financas WHERE municipio_id=m.id AND tipo='despesa' AND categoria_cod='D') as ano_despesa,
               (SELECT ano FROM municipio_financas WHERE municipio_id=m.id AND tipo='receita' AND categoria_cod='R') as ano_receita
        FROM municipios m
        WHERE $where
        ORDER BY $ord
    ", $params);
    aut_cache_set($ck, $rows, 3600);
    return $rows;
}

function get_stats_globais(): array {
    if ($c = aut_cache_get('stats_globais')) return $c;
    $stats = [
        'total_municipios' => aut_one("SELECT COUNT(*) as n FROM municipios")['n'] ?? 0,
        'despesa_total'    => aut_one("SELECT SUM(valor_milhares) as n FROM municipio_financas WHERE tipo='despesa' AND categoria_cod='D'")['n'] ?? 0,
        'receita_total'    => aut_one("SELECT SUM(valor_milhares) as n FROM municipio_financas WHERE tipo='receita' AND categoria_cod='R'")['n'] ?? 0,
    ];
    aut_cache_set('stats_globais', $stats, 3600);
    return $stats;
}

$municipios = get_municipios_lista($search, $ordem);
$stats = get_stats_globais();

$tab = 'municipios';
$ambito = 'autarquias';
$page_title = 'Municípios';
require __DIR__ . '/../_header.php';
?>
<main class="wrap">
<div style="padding:28px 0 80px">

<div class="stats">
  <div class="scard"><div class="sval acc"><?=number_format($stats['total_municipios'])?></div><div class="slbl">Municípios</div></div>
  <div class="scard"><div class="sval">€<?=number_format($stats['despesa_total']/1000,1)?>M</div><div class="slbl">Despesa total (mais recente por município)</div></div>
  <div class="scard"><div class="sval">€<?=number_format($stats['receita_total']/1000,1)?>M</div><div class="slbl">Receita total (mais recente por município)</div></div>
</div>

<div class="method" style="margin-bottom:20px">
  <h3>De onde vêm estes dados</h3>
  <p style="font-size:.82rem;color:var(--mut);line-height:1.6">
    Indicadores financeiros do <strong>INE / Direção-Geral das Autarquias Locais (DGAL)</strong>
    — despesas e receitas por categoria económica, o ano mais recente disponível por
    município (normalmente com 2-3 anos de atraso face ao ano corrente; despesas e receitas
    podem ter anos de referência diferentes, a fonte não os publica em sincronia).
    Ao contrário da Assembleia da República, não existe uma fonte nacional com o mesmo nível
    de detalhe ao nível de <strong>freguesia</strong> — ver <a href="ajuda.php">Ajuda</a>.
  </p>
</div>

<form method="get" action="">
  <div class="search-box">
    <input type="text" name="q" placeholder="Pesquisar município..." value="<?=htmlspecialchars($search)?>">
    <button type="submit">Pesquisar</button>
  </div>
</form>

<div class="sorts">
  <span>Ordenar:</span>
  <a href="?ord=despesa&q=<?=urlencode($search)?>" class="sbtn <?=$ordem==='despesa'?'on':''?>">Despesa</a>
  <a href="?ord=receita&q=<?=urlencode($search)?>" class="sbtn <?=$ordem==='receita'?'on':''?>">Receita</a>
  <a href="?ord=nome&q=<?=urlencode($search)?>" class="sbtn <?=$ordem==='nome'?'on':''?>">Nome</a>
</div>

<?php if ($municipios): ?>
<div class="card">
<div style="overflow-x:auto">
<table class="tbl">
<thead><tr>
  <th>Município</th>
  <th style="width:140px">Despesa total</th>
  <th style="width:140px">Receita total</th>
  <th style="width:70px">Ano</th>
</tr></thead>
<tbody>
<?php foreach ($municipios as $m): ?>
<tr>
  <td><a href="municipio.php?id=<?=$m['id']?>"><?=htmlspecialchars($m['nome'])?></a></td>
  <td style="font-family:var(--serif)"><?=$m['despesa_total'] !== null ? '€'.number_format($m['despesa_total']) . 'k' : '—'?></td>
  <td style="font-family:var(--serif)"><?=$m['receita_total'] !== null ? '€'.number_format($m['receita_total']) . 'k' : '—'?></td>
  <td style="font-size:.75rem;color:var(--mut)"><?=$m['ano_despesa'] ?? $m['ano_receita'] ?? '—'?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="empty"><div class="ei">🏘️</div><h3>Sem dados</h3><p>Correr o ETL primeiro.</p><code>python3 etl/importar_autarquias.py --all</code></div>
<?php endif; ?>

</div>
</main>
<?php require __DIR__ . '/../_footer.php'; ?>
