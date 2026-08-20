<?php
/**
 * autarquias/municipio.php — perfil individual de um município
 */
require __DIR__ . '/db.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: dashboard.php'); exit; }

$municipio = aut_one("SELECT * FROM municipios WHERE id = ?", [$id]);
if (!$municipio) {
    $ambito = 'autarquias';
    $page_title = 'Município não encontrado';
    require __DIR__ . '/../_header.php';
    echo '<main class="wrap"><div class="empty"><div class="ei">🔍</div><h3>Município não encontrado</h3><p><a href="dashboard.php">← voltar à lista</a></p></div></main>';
    require __DIR__ . '/../_footer.php';
    exit;
}

$despesas = aut_query(
    "SELECT categoria_cod, categoria_nome, valor_milhares, ano FROM municipio_financas
     WHERE municipio_id=? AND tipo='despesa' AND categoria_cod != 'D'
     ORDER BY valor_milhares DESC", [$id]
);
$receitas = aut_query(
    "SELECT categoria_cod, categoria_nome, valor_milhares, ano FROM municipio_financas
     WHERE municipio_id=? AND tipo='receita' AND categoria_cod != 'R'
     ORDER BY valor_milhares DESC", [$id]
);
$totais = aut_one(
    "SELECT
        (SELECT valor_milhares FROM municipio_financas WHERE municipio_id=? AND tipo='despesa' AND categoria_cod='D') as despesa_total,
        (SELECT ano FROM municipio_financas WHERE municipio_id=? AND tipo='despesa' AND categoria_cod='D') as ano_despesa,
        (SELECT valor_milhares FROM municipio_financas WHERE municipio_id=? AND tipo='receita' AND categoria_cod='R') as receita_total,
        (SELECT ano FROM municipio_financas WHERE municipio_id=? AND tipo='receita' AND categoria_cod='R') as ano_receita
    ", [$id, $id, $id, $id]
);
$eleicoes = aut_query(
    "SELECT partido_sigla, votos, percentagem, mandatos, presidente_eleito, ano
     FROM municipio_eleicoes WHERE municipio_id=? ORDER BY mandatos DESC, votos DESC", [$id]
);

$ambito = 'autarquias';
$page_title = $municipio['nome'];
require __DIR__ . '/../_header.php';
?>
<main class="wrap">
<div style="padding:28px 0 80px">

<div class="breadcrumb"><a href="dashboard.php">← Municípios</a></div>

<div class="profile-hdr">
  <div class="profile-foto" style="display:flex;align-items:center;justify-content:center;font-size:2rem">🏘️</div>
  <div>
    <div class="profile-nome"><?=htmlspecialchars($municipio['nome'])?></div>
    <div class="profile-meta">
      <?php if ($municipio['presidente_partido']): ?><span class="gptag" style="background:var(--acc)">Câmara: <?=htmlspecialchars($municipio['presidente_partido'])?></span><?php endif; ?>
      <?php if ($municipio['presidente_camara']): ?><span>Presidente: <?=htmlspecialchars($municipio['presidente_camara'])?></span><?php endif; ?>
      <?php if ($municipio['populacao']): ?><span>· <?=number_format($municipio['populacao'])?> habitantes</span><?php endif; ?>
    </div>
  </div>
  <div class="profile-score" style="margin-left:auto;display:flex;gap:24px">
    <div>
      <div class="sval acc"><?=$totais['despesa_total'] !== null ? '€'.number_format($totais['despesa_total']).'k' : '—'?></div>
      <div class="slbl">Despesa total <?=$totais['ano_despesa'] ? '('.$totais['ano_despesa'].')' : ''?></div>
    </div>
    <div>
      <div class="sval"><?=$totais['receita_total'] !== null ? '€'.number_format($totais['receita_total']).'k' : '—'?></div>
      <div class="slbl">Receita total <?=$totais['ano_receita'] ? '('.$totais['ano_receita'].')' : ''?></div>
    </div>
  </div>
</div>

<?php if ($eleicoes): ?>
<div class="sec-hdr"><h2 class="sec-ttl">🗳️ Eleições Autárquicas <?=$eleicoes[0]['ano']?></h2></div>
<div class="card">
<div style="overflow-x:auto">
<table class="tbl">
<thead><tr>
  <th>Partido/Coligação</th>
  <th style="width:100px">Votos</th>
  <th style="width:80px">%</th>
  <th style="width:90px">Mandatos</th>
</tr></thead>
<tbody>
<?php foreach ($eleicoes as $e): ?>
<tr<?=$e['presidente_eleito']?' style="background:var(--surf2)"':''?>>
  <td style="font-size:.85rem"><?=$e['presidente_eleito']?'👑 ':''?><?=htmlspecialchars($e['partido_sigla'])?></td>
  <td><?=number_format($e['votos'])?></td>
  <td><?=number_format($e['percentagem'],1)?>%</td>
  <td><?=(int)$e['mandatos']?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<div class="two">
  <div>
    <div class="sec-hdr"><h2 class="sec-ttl">📉 Despesas por categoria</h2></div>
    <?php if ($despesas): ?>
    <div class="card"><ul class="mlist">
    <?php foreach (array_slice($despesas,0,12) as $d): ?>
      <li>
        <div class="mn"><?=htmlspecialchars($d['categoria_nome'] ?? $d['categoria_cod'])?></div>
        <div class="mv">€<?=number_format($d['valor_milhares'])?>k</div>
      </li>
    <?php endforeach; ?>
    </ul></div>
    <?php else: ?>
    <div class="empty"><div class="ei">📉</div><h3>Sem dados de despesas</h3></div>
    <?php endif; ?>
  </div>
  <div>
    <div class="sec-hdr"><h2 class="sec-ttl">📈 Receitas por categoria</h2></div>
    <?php if ($receitas): ?>
    <div class="card"><ul class="mlist">
    <?php foreach (array_slice($receitas,0,12) as $r): ?>
      <li>
        <div class="mn"><?=htmlspecialchars($r['categoria_nome'] ?? $r['categoria_cod'])?></div>
        <div class="mv">€<?=number_format($r['valor_milhares'])?>k</div>
      </li>
    <?php endforeach; ?>
    </ul></div>
    <?php else: ?>
    <div class="empty"><div class="ei">📈</div><h3>Sem dados de receitas</h3></div>
    <?php endif; ?>
  </div>
</div>

<p style="font-size:.76rem;color:var(--mut);margin-top:20px">
  Fonte: INE / DGAL. Despesas e receitas podem ter anos de referência diferentes — a fonte
  oficial não os publica em sincronia. Ver <a href="ajuda.php">Ajuda</a> para mais detalhes
  sobre limitações destes dados.
</p>

</div>
</main>
<?php require __DIR__ . '/../_footer.php'; ?>
