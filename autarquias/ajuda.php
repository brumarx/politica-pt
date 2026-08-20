<?php
/**
 * autarquias/ajuda.php — documentação do módulo Autarquias
 */
require __DIR__ . '/db.php';

$stats = aut_one("
    SELECT
      (SELECT COUNT(*) FROM municipios) as municipios,
      (SELECT COUNT(*) FROM municipio_financas) as registos_financas,
      (SELECT COUNT(DISTINCT municipio_id) FROM municipio_financas WHERE tipo='despesa') as com_despesa,
      (SELECT COUNT(DISTINCT municipio_id) FROM municipio_financas WHERE tipo='receita') as com_receita,
      (SELECT COUNT(*) FROM freguesias) as freguesias
");

$tab = 'ajuda';
$ambito = 'autarquias';
$page_title = 'Ajuda';
require __DIR__ . '/../_header.php';
?>
<main class="wrap">
<div style="padding:28px 0 80px;max-width:900px">

<h1 style="font-family:var(--serif);font-size:2rem;margin-bottom:6px">Ajuda &amp; Documentação</h1>
<p style="color:var(--mut);margin-bottom:28px">Autarquias PT — dados financeiros dos municípios portugueses.</p>

<div class="stats">
  <div class="scard"><div class="sval acc"><?=number_format($stats['municipios'])?></div><div class="slbl">Municípios (de 308)</div></div>
  <div class="scard"><div class="sval"><?=number_format($stats['com_despesa'])?></div><div class="slbl">Com dados de despesa</div></div>
  <div class="scard"><div class="sval"><?=number_format($stats['com_receita'])?></div><div class="slbl">Com dados de receita</div></div>
  <div class="scard"><div class="sval"><?=number_format($stats['freguesias'])?></div><div class="slbl">Freguesias (piloto)</div></div>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">📊 O que é este módulo</h2></div>
<div class="card" style="padding:18px 20px">
  <p style="font-size:.88rem;line-height:1.6">
    Indicadores financeiros dos 308 municípios portugueses — despesas e receitas por
    categoria económica. É um domínio de dados <strong>completamente separado</strong> da
    Assembleia da República (deputados, iniciativas): aqui trata-se de autarquias locais
    (câmaras municipais), com fontes, actualização e limitações próprias.
  </p>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">⚠️ Limitações conhecidas</h2></div>
<div class="card" style="padding:4px 0">
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>Dados desactualizados (2-3 anos de atraso).</strong> Ao contrário da AR (dados
    quase em tempo real), as finanças municipais publicadas pelo INE/DGAL têm sempre um
    atraso significativo — no momento em que este texto foi escrito, despesas estavam
    disponíveis até 2021 e receitas até 2023 (a fonte oficial não sincroniza os dois
    indicadores, nem os actualiza ao mesmo ritmo).
  </div>
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong><a href="https://transparencia.gov.pt" target="_blank">transparencia.gov.pt</a>
    tem mais indicadores (dívida per capita, nº funcionários, taxas de IRS/IRC locais) mas
    está atrás de um WAF que bloqueia acesso automatizado</strong> — não conseguimos
    integrar essa fonte de forma fiável. Os dados aqui vêm directamente da API pública do
    INE (sem bloqueio), que tem menos indicadores mas é tecnicamente acessível.
  </div>
  <div style="padding:14px 20px;border-bottom:1px solid var(--bord)">
    <strong>Sabemos qual o partido de cada câmara, não o nome do presidente.</strong> Os
    resultados das Autárquicas 2025 (<a href="https://www.eleicoes.mai.gov.pt" target="_blank">eleicoes.mai.gov.pt</a>,
    API pública) dão votos/mandatos/partido vencedor por município — não o nome da pessoa
    eleita. Contratos públicos por município (Portal Base) ainda não implementados.
  </div>
  <div style="padding:14px 20px">
    <strong>Freguesia — sem fonte nacional agregada.</strong> Ao contrário do nível
    concelho, não existe um indicador INE/DGAL equivalente para juntas de freguesia.
    Qualquer dado a esse nível teria de vir do site de cada junta individualmente (se
    existir), scraping caso-a-caso sem garantia de generalizar às ~3092 freguesias do país.
  </div>
</div>

<div class="sec-hdr"><h2 class="sec-ttl">📡 Fontes de dados</h2></div>
<div class="card" style="padding:4px 0">
  <div style="padding:12px 20px;border-bottom:1px solid var(--bord);font-size:.85rem">
    <strong>INE — Instituto Nacional de Estatística</strong> — indicadores 0009481 (despesas)
    e 0013908 (receitas) das câmaras municipais, fonte original DGAL
    (<a href="https://www.ine.pt" target="_blank">ine.pt</a>)
  </div>
  <div style="padding:12px 20px;border-bottom:1px solid var(--bord);font-size:.85rem">
    <strong>dados.gov.pt</strong> — catálogo nacional de dados abertos, usado para localizar
    os indicadores INE correctos
  </div>
  <div style="padding:12px 20px;font-size:.85rem">
    <strong>eleicoes.mai.gov.pt</strong> — resultados das Eleições Autárquicas 2025
    (Secretaria-Geral do Ministério da Administração Interna), API JSON pública
  </div>
</div>

</div>
</main>
<?php require __DIR__ . '/../_footer.php'; ?>
