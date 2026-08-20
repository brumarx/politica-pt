<?php
/**
 * _header.php — cabeçalho partilhado (CSS + logo + nav)
 * Requer $page_title (opcional) definido antes do include.
 * $tab (opcional) controla qual separador da nav fica activo — só faz
 * sentido quando incluído a partir de dashboard.php.
 */
$tab = $tab ?? '';
$page_title = $page_title ?? 'Assembleia da República';
$ambito = $ambito ?? 'ar'; // 'ar' | 'autarquias' — controla o selector de âmbito e o nav activo
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transparência PT — <?= htmlspecialchars($page_title) ?></title>
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

/* Selector de âmbito (AR / Autarquias) */
.ambito-switch{display:flex;background:var(--surf2);border-radius:8px;padding:3px;gap:2px}
.ambito-switch a{padding:5px 10px;border-radius:6px;font-size:.75rem;font-weight:500;color:var(--mut);white-space:nowrap}
.ambito-switch a:hover{color:var(--txt);text-decoration:none}
.ambito-switch a.on{background:var(--surf);color:var(--acc);box-shadow:0 1px 2px rgba(0,0,0,.08)}
@media(max-width:640px){.ambito-switch a{padding:5px 7px;font-size:.68rem}.ambito-switch a span{display:none}}

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

/* Perfil de deputado */
.profile-hdr{display:flex;gap:20px;align-items:center;background:var(--surf);border:1px solid var(--bord);border-radius:var(--r);padding:24px;box-shadow:var(--sh);margin-bottom:20px;flex-wrap:wrap}
.profile-foto{width:96px;height:96px;border-radius:50%;object-fit:cover;background:var(--surf2);border:2px solid var(--bord);flex-shrink:0}
.profile-nome{font-family:var(--serif);font-size:1.8rem;line-height:1.1;margin-bottom:6px}
.profile-meta{font-size:.85rem;color:var(--mut);display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.profile-score{margin-left:auto;text-align:center}
.profile-score .sval{font-size:2.6rem}
.breadcrumb{font-size:.8rem;color:var(--mut);margin-bottom:14px}
</style>
</head>
<body>

<header class="hdr">
  <div class="wrap hdr-inner">
    <a href="<?= $ambito==='autarquias' ? '/politica-pt/autarquias/dashboard.php' : '/politica-pt/dashboard.php' ?>" class="logo">
      <div class="logo-icon"><?= $ambito==='autarquias' ? '🏘️' : '🏛' ?></div>
      <div>Transparência PT<span class="logo-sub"><?= $ambito==='autarquias' ? 'Autarquias · Municípios' : 'Assembleia da República · ' . (defined('LEG_NUM') ? LEG_NUM : 'XVII') . ' Legislatura' ?></span></div>
    </a>
    <div style="display:flex;align-items:center;gap:10px">
      <div class="ambito-switch">
        <a href="/politica-pt/dashboard.php" class="<?= $ambito==='ar'?'on':'' ?>">🏛 Assembleia da República</a>
        <a href="/politica-pt/autarquias/dashboard.php" class="<?= $ambito==='autarquias'?'on':'' ?>">🏘️ Autarquias</a>
      </div>
      <span class="hdr-badge">Beta · dados abertos</span>
    </div>
  </div>
  <div class="wrap">
    <nav class="tabs">
      <?php if ($ambito === 'autarquias'): ?>
      <a href="/politica-pt/autarquias/dashboard.php" class="tab <?= $tab==='municipios'?'on':'' ?>">🏘️ Municípios</a>
      <a href="/politica-pt/autarquias/ajuda.php" class="tab <?= $tab==='ajuda'?'on':'' ?>">❓ Ajuda</a>
      <?php else: ?>
      <a href="/politica-pt/dashboard.php?tab=visao" class="tab <?= $tab==='visao'?'on':'' ?>">📊 Visão Geral</a>
      <a href="/politica-pt/dashboard.php?tab=score" class="tab <?= $tab==='score'?'on':'' ?>">🏆 Score</a>
      <a href="/politica-pt/dashboard.php?tab=iniciativas" class="tab <?= $tab==='iniciativas'?'on':'' ?>">📋 Iniciativas</a>
      <a href="/politica-pt/dashboard.php?tab=partidos" class="tab <?= $tab==='partidos'?'on':'' ?>">🎯 Partidos</a>
      <a href="/politica-pt/dashboard.php?tab=grupos" class="tab <?= $tab==='grupos'?'on':'' ?>">🏛️ Grupos</a>
      <a href="/politica-pt/dashboard.php?tab=declaracoes" class="tab <?= $tab==='declaracoes'?'on':'' ?>">💼 Declarações</a>
      <a href="/politica-pt/ajuda.php" class="tab <?= $tab==='ajuda'?'on':'' ?>">❓ Ajuda</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
