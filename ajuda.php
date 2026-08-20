<?php
/**
 * ajuda.php — documentação do site + stats em tempo real
 */
require __DIR__ . '/db.php';

$stats = db_one("
    SELECT
      (SELECT COUNT(*) FROM deputados WHERE activo=1 AND legislatura_id=" . LEG_ID . ") as deputados,
      (SELECT COUNT(*) FROM iniciativas WHERE legislatura_id=" . LEG_ID . ") as iniciativas,
      (SELECT COUNT(*) FROM iniciativas_autores) as pares_autoria,
      (SELECT COUNT(DISTINCT iniciativa_id) FROM iniciativas_autores) as iniciativas_com_autor,
      (SELECT COUNT(*) FROM sessoes_plenarias WHERE legislatura_id=" . LEG_ID . ") as sessoes,
      (SELECT COUNT(*) FROM presencas) as presencas,
      (SELECT COUNT(*) FROM scores) as scores,
      (SELECT COUNT(*) FROM perfis_deputados) as perfis
", []);

$ultimos_etl = db_query("SELECT fonte, MAX(iniciado_em) as ultima,
    (SELECT registos FROM etl_log e2 WHERE e2.fonte=e1.fonte ORDER BY e2.iniciado_em DESC LIMIT 1) as total_registos
    FROM etl_log e1 GROUP BY fonte ORDER BY ultima DESC", []);

$pct_autoria = $stats['iniciativas'] > 0 ? round($stats['iniciativas_com_autor'] / $stats['iniciativas'] * 100, 1) : 0;

$tab = 'ajuda';
$page_title = 'Ajuda';
require __DIR__ . '/_header.php';
?>
<main class="wrap">
<div style="padding:28px 0 80px;max-width:900px">

<h1 style="font-family:var(--serif);font-size:2rem;margin-bottom:6px">Ajuda &amp; Documentação</h1>
<p style="color:var(--mut);margin-bottom:28px">Como este site funciona, de onde vêm os dados, e as limitações conhecidas.</p>

<div class="stats">
  <div class="scard"><div class="sval acc"><?=number_format($stats['deputados'])?></div><div class="slbl">Deputados activos</div></div>
  <div class="scard"><div class="sval"><?=number_format($stats['iniciativas'])?></div><div class="slbl">Iniciativas legislativas</div></div>
  <div class="scard"><div class="sval"><?=$pct_autoria?>%</div><div class="slbl">Com autoria individual</div></div>
  <div class="scard"><div class="sval"><?=number_format($stats['sessoes'])?></div><div class="slbl">Sessões plenárias</div></div>
  <div class="scard"><div class="sval"><?=number_format($stats['presencas'])?></div><div class="slbl">Registos de presença</div></div>
  <div class="scard"><div class="sval"><?=number_format($stats['perfis'])?></div><div class="slbl">Perfis biográficos</div></div>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">📊 O que é este site</h2></div>
<div class="card" style="padding:18px 20px">
  <p style="font-size:.88rem;line-height:1.6">
    Dashboard de transparência sobre a actividade da <strong>Assembleia da República</strong> (<?=LEG_NUM?> Legislatura),
    construído sobre dados publicamente disponíveis no site do parlamento. Algumas métricas têm limitações
    estruturais impostas pelas próprias fontes de dados portuguesas — ver secção "Limitações" abaixo.
  </p>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">📋 Separadores</h2></div>
<div class="card" style="padding:4px 0">
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>📊 Visão Geral</strong> — KPIs globais, deputados mais/menos presentes, mais activos em iniciativas.
  </div>
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>🏆 Score</strong> — ranking de transparência 0–100. Fórmula e pesos explicados na própria tab
    (os pesos ajustam-se automaticamente consoante que componentes têm dados disponíveis).
  </div>
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>📋 Iniciativas</strong> — todas as iniciativas legislativas da legislatura, com tipo, autoria
    (grupo parlamentar) e link para a página oficial.
  </div>
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>🎯 Partidos</strong> / <strong>🏛️ Grupos</strong> — bancada actual, gastos e scores agregados por
    grupo parlamentar.
  </div>
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>💼 Declarações</strong> — ver "Limitações" abaixo. Não vai ter dados de rendimento/património.
  </div>
  <div style="padding:14px 20px">
    <strong>👤 Perfil de deputado</strong> (<code>deputado.php?id=X</code>) — score, iniciativas subscritas,
    comissões parlamentares e presenças de uma pessoa específica.
  </div>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">⚠️ Limitações conhecidas</h2></div>
<div class="card" style="padding:4px 0">
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>Declarações de rendimentos/património — indisponível por lei.</strong> A "Entidade para a
    Transparência" (<a href="https://entidadetransparencia.pt" target="_blank">entidadetransparencia.pt</a>)
    disponibiliza consulta pública dos registos de interesses dos deputados, mas com um aviso legal explícito:
    a <strong>reprodução</strong> dos elementos de rendimento e património é proibida (Lei n.º 52/2019).
    Por isso este site não tenta replicar essa informação.
  </div>
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>Contratos Públicos — não é possível cruzar por deputado.</strong> O Portal Base
    (<a href="https://www.base.gov.pt" target="_blank">base.gov.pt</a>) pesquisa contratos por <strong>NIPC</strong>
    (pessoa colectiva/empresa), não por NIF de pessoa singular — que também não é público em Portugal.
    Ligar um deputado a uma empresa contratada exigiria o Registo Central do Beneficiário Efectivo (RCBE),
    que não tem acesso livre (é preciso provar "interesse legítimo"). Por isso o componente "Contratos
    Públicos" do score nunca vai ficar activo tal como está desenhado hoje.
  </div>
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>Autoria por deputado — subscrição, não autoria exclusiva.</strong> Quando um Grupo Parlamentar
    assina uma iniciativa colectivamente, todos os deputados listados como autores recebem o mesmo crédito
    — não há forma de distinguir quem escreveu o texto de quem apenas assinou. O score usa um tecto (5
    iniciativas = 100%) para atenuar a distorção que isto causa a favor de partidos que assinam tudo em bloco.
  </div>
  <div style="padding:14px 20px">
    <strong>Cobertura de iniciativas — <?=$pct_autoria?>%, não 100%.</strong> A paginação da listagem
    de iniciativas da AR tem um bug próprio (repete o último bloco de resultados perto do fim da lista);
    <?=number_format($stats['iniciativas'])?> de ~2114 iniciativas foram recolhidas com sucesso.
  </div>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">🔄 Pipeline de dados</h2></div>
<div class="card">
<div style="overflow-x:auto">
<table class="tbl">
<thead><tr><th>Fonte</th><th>Última execução</th><th style="text-align:right">Registos (última corrida)</th></tr></thead>
<tbody>
<?php foreach ($ultimos_etl as $e): ?>
<tr>
  <td><?=htmlspecialchars($e['fonte'])?></td>
  <td style="color:var(--mut);font-size:.8rem"><?=htmlspecialchars($e['ultima'])?></td>
  <td style="text-align:right"><?=number_format($e['total_registos'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<p style="font-size:.78rem;color:var(--mut);margin-top:10px">
  Actualização automática todas as noites (seg–sáb, 3h) via <code>importar_ar.py --all</code>.
</p>

<div class="sec-hdr"><h2 class="sec-ttl">📡 Fontes de dados</h2></div>
<div class="card" style="padding:4px 0">
  <div style="padding:12px 20px;border-bottom:1px solid var(--bord);font-size:.85rem">
    <strong>Assembleia da República</strong> — <a href="https://www.parlamento.pt" target="_blank">parlamento.pt</a>
    (deputados, iniciativas, autoria, presenças — scraping HTML, não há API JSON pública)
  </div>
  <div style="padding:12px 20px;font-size:.85rem">
    <strong>Entidade para a Transparência</strong> — <a href="https://entidadetransparencia.pt" target="_blank">entidadetransparencia.pt</a>
    (consulta pública apenas — ver limitações acima)
  </div>
</div>

</div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
