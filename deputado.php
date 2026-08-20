<?php
/**
 * deputado.php — perfil individual de um deputado
 */
require __DIR__ . '/db.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: dashboard.php?tab=score'); exit; }

$dep = db_one(
    "SELECT d.*, g.nome as gp_nome, g.cor_hex, c.nome as circulo_nome
     FROM deputados d
     LEFT JOIN grupos_parlamentares g ON g.sigla = d.gp_sigla AND g.legislatura_id = d.legislatura_id
     LEFT JOIN circulos_eleitorais c ON c.id = d.circulo_id
     WHERE d.id = ?", [$id]
);
if (!$dep) {
    $page_title = 'Deputado não encontrado';
    require __DIR__ . '/_header.php';
    echo '<main class="wrap"><div class="empty"><div class="ei">🔍</div><h3>Deputado não encontrado</h3><p><a href="dashboard.php?tab=score">← voltar ao ranking</a></p></div></main>';
    require __DIR__ . '/_footer.php';
    exit;
}

$score = db_one("SELECT * FROM scores WHERE dep_id = ?", [$id]);
$perfil = db_one("SELECT * FROM perfis_deputados WHERE dep_id = ?", [$id]);

$tem_autores_ui   = (bool) db_one("SELECT 1 as x FROM iniciativas_autores LIMIT 1", []);
$tem_contratos_ui = (bool) db_one("SELECT 1 as x FROM contratos_base LIMIT 1", []);

$iniciativas = db_query(
    "SELECT i.id, i.tipo, i.titulo, i.autoria_gp, i.data_entrada, i.resultado, i.url_ar
     FROM iniciativas_autores ia
     JOIN iniciativas i ON i.id = ia.iniciativa_id
     WHERE ia.dep_id = ?
     ORDER BY i.data_entrada DESC, i.id DESC
     LIMIT 30", [$id]
);
$ofertas = db_query(
    "SELECT categoria, descricao, valor, local, ofertante, representacao, data_registo, duracao, destino_final
     FROM ofertas_hospitalidades WHERE dep_id = ?
     ORDER BY categoria, data_registo DESC", [$id]
);
$total_iniciativas = db_one(
    "SELECT COUNT(*) as n FROM iniciativas_autores WHERE dep_id = ?", [$id]
)['n'] ?? 0;

$presencas = db_one(
    "SELECT COUNT(*) as total,
            SUM(CASE WHEN p.estado='P' THEN 1 ELSE 0 END) as presentes
     FROM presencas p
     JOIN sessoes_plenarias s ON s.id = p.sessao_id
     WHERE p.dep_id = ? AND s.legislatura_id = ?", [$id, LEG_ID]
);
$total_sessoes = db_one("SELECT COUNT(*) as n FROM sessoes_plenarias WHERE legislatura_id=?", [LEG_ID])['n'] ?? 1;

$cor = $dep['cor_hex'] ?: gp_cor($dep['gp_sigla'] ?? '');
$page_title = $dep['nome_curto'] ?: $dep['nome_completo'];
require __DIR__ . '/_header.php';
?>
<main class="wrap">
<div style="padding:28px 0 80px">

<div class="breadcrumb"><a href="dashboard.php?tab=score">← Score</a> / <?=htmlspecialchars($dep['gp_sigla'] ?? '')?></div>

<div class="profile-hdr">
  <?php if ($dep['url_foto']): ?>
    <img src="<?=htmlspecialchars($dep['url_foto'])?>" class="profile-foto" alt="">
  <?php else: ?>
    <div class="profile-foto" style="display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--mut)">👤</div>
  <?php endif; ?>
  <div>
    <div class="profile-nome"><?=htmlspecialchars($dep['nome_completo'])?></div>
    <div class="profile-meta">
      <span class="gptag" style="background:<?=htmlspecialchars($cor)?>"><?=htmlspecialchars($dep['gp_sigla'] ?? '?')?></span>
      <?php if ($dep['gp_nome']): ?><span><?=htmlspecialchars($dep['gp_nome'])?></span><?php endif; ?>
      <?php if ($dep['circulo_nome']): ?><span>· 📍 <?=htmlspecialchars($dep['circulo_nome'])?></span><?php endif; ?>
      <?php if ($perfil && $perfil['profissao']): ?><span>· <?=htmlspecialchars($perfil['profissao'])?></span><?php endif; ?>
      <?php if ($dep['url_parlamento']): ?><span>· <a href="<?=htmlspecialchars($dep['url_parlamento'])?>" target="_blank">↗ perfil na AR</a></span><?php endif; ?>
    </div>
  </div>
  <?php if ($score): ?>
  <div class="profile-score">
    <div class="sval acc"><?=number_format($score['score_total'],1)?></div>
    <div class="slbl">Score · rank #<?=$score['rank_geral'] ?? '—'?></div>
  </div>
  <?php endif; ?>
</div>

<?php if ($score): ?>
<div class="stats">
  <div class="scard">
    <div class="sval"><?=pct($score['taxa_presenca'])?></div>
    <div class="slbl">Presença em Plenário</div>
    <?=bar($score['taxa_presenca']*100, '#16a34a')?>
  </div>
  <div class="scard">
    <div class="sval"><?=(int)$score['n_iniciativas']?></div>
    <div class="slbl">Iniciativas subscritas <?php if(!$tem_autores_ui):?><span style="color:var(--mut)">(desactivado)</span><?php endif;?></div>
    <?=bar(min($score['n_iniciativas']/5,1)*100, '#1a4f8a')?>
  </div>
  <div class="scard">
    <div class="sval" style="color:var(--mut)">—</div>
    <div class="slbl">Contratos Públicos (não disponível em PT)</div>
  </div>
</div>
<p style="font-size:.76rem;color:var(--mut);margin-top:-16px;margin-bottom:24px">
  Iniciativas conta subscrição, não autoria exclusiva — quando um Grupo Parlamentar assina em bloco, todos os seus deputados recebem o mesmo crédito (por isso o tecto de 5 = 100% no score).
  <a href="dashboard.php?tab=score">ver metodologia completa</a>
</p>
<?php else: ?>
<div class="empty"><div class="ei">📊</div><h3>Sem score calculado</h3><p>Correr <code>python importar_ar.py --scores</code></p></div>
<?php endif; ?>

<?php if ($perfil && $perfil['comissoes_json']):
  $comissoes = json_decode($perfil['comissoes_json'], true) ?: [];
  if ($comissoes): ?>
<div class="sec-hdr"><h2 class="sec-ttl">🏛️ Comissões Parlamentares</h2></div>
<div class="card"><ul class="mlist">
<?php foreach (array_slice($comissoes,0,8) as $c): ?>
  <li>
    <div class="mn"><?=htmlspecialchars($c['orgDes'] ?? '')?><small><?=htmlspecialchars($c['timDes'] ?? '')?></small></div>
  </li>
<?php endforeach; ?>
</ul></div>
<?php endif; endif; ?>

<div class="sec-hdr">
  <h2 class="sec-ttl">📋 Iniciativas Subscritas <?php if($total_iniciativas>30):?>(últimas 30 de <?=$total_iniciativas?>)<?php endif; ?></h2>
</div>
<?php if ($iniciativas): ?>
<div class="card">
<div style="overflow-x:auto">
<table class="tbl">
<thead><tr>
  <th style="width:60px">Tipo</th>
  <th>Título</th>
  <th style="width:110px">Autoria</th>
  <th style="width:90px">Data</th>
  <th style="width:90px">Resultado</th>
  <th style="width:60px">Link</th>
</tr></thead>
<tbody>
<?php foreach ($iniciativas as $i): ?>
<tr>
  <td><span class="tipo"><?=htmlspecialchars($i['tipo']??'?')?></span></td>
  <td style="font-size:.82rem"><?=htmlspecialchars(mb_substr($i['titulo']??'',0,90))?><?=mb_strlen($i['titulo']??'')>90?'…':''?></td>
  <td style="font-size:.75rem;color:var(--mut)"><?=htmlspecialchars($i['autoria_gp']??'—')?></td>
  <td style="font-size:.76rem;color:var(--mut)"><?=htmlspecialchars(substr($i['data_entrada']??'',0,10))?></td>
  <td>
    <?php if ($i['resultado']==='Aprovado'): ?><span class="badge bg">Aprovado</span>
    <?php elseif ($i['resultado']==='Rejeitado'): ?><span class="badge br">Rejeitado</span>
    <?php else: ?><span style="color:var(--mut);font-size:.75rem">Em tramitação</span>
    <?php endif; ?>
  </td>
  <td><?php if ($i['url_ar']): ?><a href="<?=htmlspecialchars($i['url_ar'])?>" target="_blank" style="font-size:.75rem">↗ AR</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="empty"><div class="ei">📋</div><h3>Sem iniciativas subscritas registadas</h3></div>
<?php endif; ?>

<div class="sec-hdr"><h2 class="sec-ttl">🗳️ Presenças</h2></div>
<div class="card" style="padding:16px 18px">
  <?php if ($presencas && $presencas['total']): ?>
    <?=(int)$presencas['presentes']?> presenças em <?=(int)$presencas['total']?> sessões registadas
    (<?=(int)$total_sessoes?> sessões na <?=LEG_NUM?> Legislatura até agora)
    <?=bar(($presencas['presentes']/$presencas['total'])*100, '#16a34a')?>
  <?php else: ?>
    <span style="color:var(--mut)">Sem dados de presença registados</span>
  <?php endif; ?>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">🎁 Ofertas, Deslocações e Hospitalidades</h2></div>
<p style="font-size:.76rem;color:var(--mut);margin:-4px 0 8px">Registo público oficial — distinto do registo de rendimentos/património, que não pode ser reproduzido por lei.</p>
<?php if ($ofertas): ?>
<div class="card" style="padding:4px 0">
<?php foreach ($ofertas as $o): ?>
  <div style="padding:12px 20px;border-bottom:1px solid var(--bord);font-size:.85rem">
    <span class="tipo"><?=htmlspecialchars($o['categoria'])?></span>
    <strong><?=htmlspecialchars($o['descricao'] ?? '')?></strong>
    <?php if ($o['valor']): ?><span style="color:var(--acc)"> · <?=htmlspecialchars($o['valor'])?></span><?php endif; ?>
    <div style="font-size:.78rem;color:var(--mut);margin-top:3px">
      <?php if ($o['local']): ?>📍 <?=htmlspecialchars($o['local'])?> · <?php endif; ?>
      Ofertante: <?=htmlspecialchars($o['ofertante'] ?? '—')?>
      <?php if ($o['representacao']): ?> · <?=htmlspecialchars($o['representacao'])?><?php endif; ?>
      · <?=htmlspecialchars($o['data_registo'] ?? '')?>
      <?php if ($o['duracao']): ?> · <?=htmlspecialchars($o['duracao'])?><?php endif; ?>
      <?php if ($o['destino_final']): ?> · <em><?=htmlspecialchars($o['destino_final'])?></em><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty"><div class="ei">🎁</div><h3>Sem registos de ofertas, deslocações ou hospitalidades</h3></div>
<?php endif; ?>

</div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
