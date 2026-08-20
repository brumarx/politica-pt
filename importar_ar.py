#!/usr/bin/env python3
"""
importar_ar.py — ETL Transparência Política PT
Fonte: www.parlamento.pt (scraping HTML — não existe API JSON pública)

Uso:
    python importar_ar.py --all
    python importar_ar.py --deputados
    python importar_ar.py --presencas
    python importar_ar.py --iniciativas
    python importar_ar.py --scores
    python importar_ar.py --deputados --enrich   # mais lento, mais dados
"""

import argparse
import json
import logging
import re
import sqlite3
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path
from typing import Optional

import subprocess
from bs4 import BeautifulSoup

# ─── Config ───────────────────────────────────────────────────────────────────

DB_PATH    = Path(__file__).parent / "transparencia_pt.db"
SCHEMA_SQL = Path(__file__).parent / "schema.sql"
LEG_ID     = 17
LEG_NUM    = "XVII"
MAX_WORKERS = 4   # paralelo nas presenças
TIMEOUT     = 60  # parlamento.pt é lento
DELAY       = 1.0 # entre requests (respeitar servidor)

BASE = "https://www.parlamento.pt"

CURL_HEADERS = [
    "-H", "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "-H", "Accept: text/html,application/xhtml+xml,*/*;q=0.8",
    "-H", "Accept-Language: pt-PT,pt;q=0.9",
    "-H", "Connection: keep-alive",
]

CIRCULOS = [
    "Aveiro","Beja","Braga","Bragança","Castelo Branco","Coimbra",
    "Évora","Faro","Guarda","Leiria","Lisboa","Portalegre","Porto",
    "Santarém","Setúbal","Viana do Castelo","Vila Real","Viseu",
    "Açores","Madeira","Europa","Fora da Europa"
]
GPS = ["PSD","PS","CH","IL","BE","PCP","PAN","L","CDS","AD"]

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger("importar_ar")

# ─── BD ───────────────────────────────────────────────────────────────────────

def get_db():
    db = sqlite3.connect(DB_PATH, timeout=30, check_same_thread=False)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA journal_mode=WAL")
    db.execute("PRAGMA foreign_keys=ON")
    return db

def init_db():
    """
    Inicialização incremental — nunca apaga dados existentes.
    Cria a BD se não existir; se existir, aplica só o que falta (tabelas novas).
    Seguro para correr quantas vezes quiser.
    """
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    db = get_db()

    # Tabelas que devem existir
    tabelas_esperadas = {
        "legislaturas", "grupos_parlamentares", "circulos_eleitorais",
        "deputados", "sessoes_plenarias", "presencas", "iniciativas",
        "iniciativas_autores", "contratos_base", "scores",
        "declaracoes_rendimentos", "cache", "etl_log",
    }
    tabelas_existentes = {
        r[0] for r in db.execute(
            "SELECT name FROM sqlite_master WHERE type='table'"
        ).fetchall()
    }
    tabelas_faltam = tabelas_esperadas - tabelas_existentes

    if tabelas_faltam:
        log.info("A criar tabelas em falta: %s", ", ".join(sorted(tabelas_faltam)))
        # executescript é seguro — usa CREATE TABLE IF NOT EXISTS
        db.executescript(SCHEMA_SQL.read_text())
        db.commit()
        log.info("BD inicializada: %s", DB_PATH)
    else:
        size = DB_PATH.stat().st_size / 1e6
        n_deps = db.execute("SELECT COUNT(*) FROM deputados").fetchone()[0]
        log.info("BD existente: %.1f MB | %d deputados", size, n_deps)

    db.close()

def log_etl(db, fonte, registos, erros, detalhes=None):
    db.execute(
        "INSERT INTO etl_log(fonte,iniciado_em,concluido_em,registos,erros,detalhes) "
        "VALUES(?,datetime('now'),datetime('now'),?,?,?)",
        (fonte, registos, erros, json.dumps(detalhes or {}))
    )
    db.commit()

# ─── HTTP ─────────────────────────────────────────────────────────────────────

def fetch_url(url, params=None, retry=3):
    """
    Fetch via curl do sistema — IP residencial Vodafone funciona directamente.
    """
    # Construir URL com params
    if params:
        from urllib.parse import urlencode
        url = url + ("&" if "?" in url else "?") + urlencode(params)

    for attempt in range(retry):
        time.sleep(DELAY)
        try:
            cmd = [
                "curl", "-s", "-L",
                "--max-time", str(TIMEOUT),
                "--compressed",
            ] + CURL_HEADERS + [url]

            result = subprocess.run(cmd, capture_output=True, timeout=TIMEOUT + 10)

            if result.returncode != 0:
                log.warning("curl erro %d [%d/%d]: %s", result.returncode, attempt+1, retry, url)
                time.sleep(min(2 ** attempt, 15))
                continue

            html = result.stdout.decode("utf-8", errors="replace")
            if len(html) < 100:
                log.warning("Resposta vazia [%d/%d]: %s", attempt+1, retry, url)
                time.sleep(min(2 ** attempt, 15))
                continue

            return BeautifulSoup(html, "html.parser")

        except subprocess.TimeoutExpired:
            log.warning("Timeout subprocess [%d/%d]: %s", attempt+1, retry, url)
            time.sleep(min(2 ** attempt, 15))
        except Exception as e:
            log.warning("Erro [%d/%d] %s: %s", attempt+1, retry, url, e)
            time.sleep(min(2 ** attempt, 15))

    log.error("Falhou após %d tentativas: %s", retry, url)
    return None


def get_html(url, params=None, retry=3):
    """Alias para compatibilidade."""
    return fetch_url(url, params, retry)

# ─── Deputados ────────────────────────────────────────────────────────────────

def scrape_lista_deputados():
    """
    Scraping confirmado de:
    https://www.parlamento.pt/DeputadoGP/Paginas/Deputados.aspx
    Contém 230 deputados com BID, nome, GP e círculo no HTML.
    """
    log.info("A obter lista de deputados (HTML scraping)...")

    # /Deputadoslista.aspx tem todos numa só página sem paginação JS
    soup = get_html(f"{BASE}/DeputadoGP/Paginas/Deputadoslista.aspx")
    if not soup:
        log.warning("Deputadoslista.aspx falhou, a tentar Deputados.aspx...")
        soup = get_html(f"{BASE}/DeputadoGP/Paginas/Deputados.aspx")
    if not soup:
        log.error("Não foi possível obter lista de deputados")
        return []

    deputados = []
    seen = set()

    for link in soup.find_all("a", href=re.compile(r"Biografia\.aspx\?BID=\d+", re.I)):
        m = re.search(r"BID=(\d+)", link["href"], re.I)
        if not m:
            continue
        bid = int(m.group(1))
        if bid in seen:
            continue
        seen.add(bid)

        nome = link.get_text(strip=True)
        if len(nome) < 3:
            continue

        # Extrair GP e círculo do contexto (linha da tabela)
        gp = circulo = ""
        row = link.find_parent("tr") or link.find_parent("li") or link.find_parent("div")
        if row:
            txt = row.get_text(" ", strip=True)
            for g in GPS:
                if re.search(r'\b' + re.escape(g) + r'\b', txt):
                    gp = g
                    break
            for c in CIRCULOS:
                if c in txt:
                    circulo = c
                    break

        deputados.append({
            "id":            bid,
            "nome_completo": nome,
            "nome_curto":    nome.split(",")[0].strip(),
            "gp_sigla":      gp or None,
            "circulo":       circulo or None,
            "activo":        1,
            "legislatura_id": LEG_ID,
            "url_foto":      f"https://app.parlamento.pt/webutils/getimage.aspx?id={bid}&type=deputado",
            "url_parlamento": f"{BASE}/DeputadoGP/Paginas/Biografia.aspx?BID={bid}",
        })

    log.info("Encontrados %d deputados", len(deputados))
    return deputados


def scrape_detalhe_dep(bid):
    """GP e círculo da página de biografia individual."""
    soup = get_html(f"{BASE}/DeputadoGP/Paginas/Biografia.aspx", {"BID": bid})
    if not soup:
        return {}
    result = {}
    txt = soup.get_text(" ", strip=True)
    for g in GPS:
        if re.search(r'\b' + re.escape(g) + r'\b', txt):
            result["gp_sigla"] = g
            break
    for c in CIRCULOS:
        if c in txt:
            result["circulo"] = c
            break
    return result


def _detectar_lideres(db):
    """Detecta automaticamente os líderes dos GPs via página da Conferência de Líderes da AR."""
    import re as _re
    url = f"{BASE}/DeputadoGP/Paginas/ConferenciaLideresII.aspx"
    soup = get_html(url)
    if not soup:
        log.warning("Não foi possível detectar líderes — usando fallback manual")
        # Fallback: líderes conhecidos por nome
        lideres = {
            'AD':'%Hugo Soares%','CH':'%André Ventura%','PS':'%Eurico Brilhante%',
            'IL':'%Mariana Leitão%','BE':'%Fabian Figueiredo%','PCP':'%Paulo Raimundo%',
            'PAN':'%Inês de Sousa Real%','L':'%Rui Tavares%','JPP':'%Filipe Sousa%',
        }
        for sigla, nome_like in lideres.items():
            row = db.execute("SELECT id FROM deputados WHERE nome_curto LIKE ? AND legislatura_id=? LIMIT 1",
                             (nome_like, LEG_ID)).fetchone()
            if row:
                db.execute("UPDATE grupos_parlamentares SET lider_bid=? WHERE sigla=?", (row[0], sigla))
        return

    updated = 0
    for a in soup.find_all("a", href=_re.compile(r"BID=\d+")):
        m = _re.search(r"BID=(\d+)", a.get("href",""))
        if not m:
            continue
        bid = int(m.group(1))
        row = db.execute(
            "SELECT gp_sigla FROM deputados WHERE id=? AND legislatura_id=?", (bid, LEG_ID)
        ).fetchone()
        if row and row[0]:
            db.execute("UPDATE grupos_parlamentares SET lider_bid=? WHERE sigla=?", (bid, row[0]))
            log.debug("  Líder %s: BID=%d (%s)", row[0], bid, a.get_text(strip=True))
            updated += 1

    log.info("✓ Líderes detectados automaticamente: %d", updated)


def importar_deputados(db, enrich=False):
    log.info("── Importar Deputados ──────────────────────────────")

    deps = scrape_lista_deputados()
    if not deps:
        log_etl(db, "AR_deputados", 0, 1)
        return

    if enrich:
        log.info("Enriquecimento: %d deputados...", len(deps))
        with ThreadPoolExecutor(max_workers=MAX_WORKERS) as pool:
            futures = {pool.submit(scrape_detalhe_dep, d["id"]): d["id"] for d in deps}
            extra = {}
            for f in as_completed(futures):
                try:
                    extra[futures[f]] = f.result()
                except Exception:
                    pass
        for d in deps:
            d.update(extra.get(d["id"], {}))

    gravados = erros = 0
    for d in deps:
        try:
            db.execute("""
                INSERT INTO deputados
                    (id,nome_completo,nome_curto,gp_sigla,activo,
                     legislatura_id,url_foto,url_parlamento,updated_at)
                VALUES(?,?,?,?,?,?,?,?,datetime('now'))
                ON CONFLICT(id) DO UPDATE SET
                    nome_completo  = COALESCE(excluded.nome_completo, nome_completo),
                    nome_curto     = COALESCE(excluded.nome_curto, nome_curto),
                    gp_sigla       = COALESCE(excluded.gp_sigla, gp_sigla),
                    activo         = excluded.activo,
                    legislatura_id = excluded.legislatura_id,
                    url_foto       = COALESCE(excluded.url_foto, url_foto),
                    url_parlamento = COALESCE(excluded.url_parlamento, url_parlamento),
                    updated_at     = datetime('now')
                -- nunca apaga dados enriquecidos manualmente
            """, (d["id"],d["nome_completo"],d["nome_curto"],d.get("gp_sigla"),
                  d["activo"],d["legislatura_id"],d["url_foto"],d["url_parlamento"]))
            gravados += 1
        except Exception as e:
            log.warning("Dep BID %s: %s", d.get("id"), e)
            erros += 1

    db.commit()
    log.info("✓ Deputados: %d gravados, %d erros", gravados, erros)
    log_etl(db, "AR_deputados", gravados, erros)

    # Normalizar GPs e líderes
    db.execute("UPDATE deputados SET gp_sigla='AD' WHERE gp_sigla IN ('PSD','CDS-PP') AND legislatura_id=?", (LEG_ID,))
    gps = [
        ('AD','Aliança Democrática','#F97316'),('PS','Partido Socialista','#E63946'),
        ('CH','Chega','#1D3557'),('IL','Iniciativa Liberal','#06B6D4'),
        ('BE','Bloco de Esquerda','#DC2626'),('PCP','Partido Comunista Português','#B91C1C'),
        ('PAN','Pessoas-Animais-Natureza','#16A34A'),('L','Livre','#059669'),
        ('JPP','Juntos pelo Povo','#7C3AED'),
    ]
    for sigla, nome, cor in gps:
        db.execute("""INSERT INTO grupos_parlamentares (sigla,nome,cor_hex,legislatura_id)
            VALUES (?,?,?,?) ON CONFLICT(sigla) DO UPDATE SET
            nome=excluded.nome, cor_hex=excluded.cor_hex, legislatura_id=excluded.legislatura_id
        """, (sigla, nome, cor, LEG_ID))
    # Detectar líderes automaticamente via página da Conferência de Líderes
    _detectar_lideres(db)
    db.commit()
    log.info("✓ GPs e líderes normalizados")

# ─── Presenças ────────────────────────────────────────────────────────────────

def importar_presencas(db):
    """
    Presenças via Playwright — sequencial (Playwright sync API não é thread-safe).
    ~230 deputados × ~30s = ~2h. Ideal para cron nocturno.
    Incremental: salta deputados já processados hoje.
    """
    log.info("── Importar Presenças ─────────────────────────────")

    # Incremental: só processar deputados sem presenças recentes
    # ou todos se for primeira vez
    n_presencas = db.execute("SELECT COUNT(*) FROM presencas").fetchone()[0]
    if n_presencas > 0:
        # Salta deputados já processados nas últimas 20h
        bids = [r[0] for r in db.execute("""
            SELECT d.id FROM deputados d
            WHERE d.activo=1 AND d.legislatura_id=?
            AND d.id NOT IN (
                SELECT DISTINCT p.dep_id FROM presencas p
                JOIN sessoes_plenarias s ON p.sessao_id=s.id
                WHERE s.data >= date('now','-1 day')
            )
        """, (LEG_ID,)).fetchall()]
        log.info("Modo incremental: %d deputados em falta", len(bids))
    else:
        bids = [r[0] for r in db.execute(
            "SELECT id FROM deputados WHERE activo=1 AND legislatura_id=?", (LEG_ID,)
        ).fetchall()]
        log.info("Primeira importação: %d deputados", len(bids))

    if not bids:
        log.info("Todos os deputados já têm presenças recentes — nada a fazer")
        return

    total_g = total_e = 0

    # Playwright deve correr na thread principal — sem ThreadPoolExecutor
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        try:
            for i, bid in enumerate(bids, 1):
                try:
                    time.sleep(DELAY)
                    page = browser.new_page(
                        user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                                   "AppleWebKit/537.36 (KHTML, like Gecko) "
                                   "Chrome/131.0.0.0 Safari/537.36"
                    )
                    try:
                        page.goto(
                            f"{BASE}/DeputadoGP/Paginas/PresencasReunioesPlenarias.aspx?BID={bid}",
                            timeout=60000, wait_until="domcontentloaded"
                        )
                        try:
                            page.wait_for_selector("img[src*='WaitingSpin']",
                                                   state="hidden", timeout=20000)
                        except Exception:
                            pass
                        page.wait_for_timeout(1500)
                        txt = page.inner_text("body")
                    finally:
                        page.close()

                    # Parsear presenças do texto
                    txt = txt.replace(" ", " ").replace(" ", " ")
                    blocos = re.findall(
                        r"Data\s+(\d{4}-\d{2}-\d{2}).*?/Falta\s*\n([^\n]+)",
                        txt, re.S | re.I
                    )
                    presencas = []
                    for data, estado_txt in blocos:
                        et = estado_txt.strip().upper()
                        if "JUSTIF" in et or "FJ" in et:    estado = "FJ"
                        elif "FALT" in et or et == "F":      estado = "F"
                        elif "ESCUS" in et or "MISS" in et: estado = "E"
                        elif "PRES" in et or et == "P":      estado = "P"
                        else:                                continue
                        presencas.append({"dep_id": bid, "data": data, "estado": estado})

                    if presencas:
                        for p in presencas:
                            db.execute(
                                "INSERT OR IGNORE INTO sessoes_plenarias(data,legislatura_id) VALUES(?,?)",
                                (p["data"], LEG_ID)
                            )
                            s = db.execute(
                                "SELECT id FROM sessoes_plenarias WHERE data=? AND legislatura_id=?",
                                (p["data"], LEG_ID)
                            ).fetchone()
                            if s:
                                db.execute(
                                    "INSERT OR REPLACE INTO presencas(dep_id,sessao_id,estado) VALUES(?,?,?)",
                                    (p["dep_id"], s[0], p["estado"])
                                )
                        db.commit()
                        total_g += len(presencas)

                    if i % 10 == 0:
                        log.info("  %d/%d deputados... (%d presenças)", i, len(bids), total_g)

                except Exception as e:
                    log.warning("BID %d: %s", bid, e)
                    total_e += 1
        finally:
            browser.close()

    log.info("✓ Presenças: %d registos, %d erros", total_g, total_e)
    log_etl(db, "AR_presencas", total_g, total_e)

# ─── Iniciativas ──────────────────────────────────────────────────────────────


def scrape_iniciativas(skip_existing=0):
    """
    Scraping de iniciativas via Playwright.
    skip_existing: número de iniciativas já na BD — salta as primeiras páginas.
    """
    log.info("A obter iniciativas (Playwright + paginação ASPX)...")
    url = f"{BASE}/ActividadeParlamentar/Paginas/IniciativasLegislativas.aspx"

    iniciativas = []
    seen = set()

    def extrair_da_pagina(pw_page):
        # Cada resultado é uma sequência de divs irmãos (sem <tr>/<table>):
        # rótulo "Tipo" -> valor, "Número" -> valor, "Sessão" -> valor,
        # "Autoria" -> valor (siglas GP), "Título" -> valor (link BID).
        # Percorre a árvore em ordem e vai acumulando o campo mais recente
        # de cada rótulo até encontrar "Título", que fecha um registo.
        soup = BeautifulSoup(pw_page.content(), "html.parser")
        campos = {}
        novos = 0
        for label_span in soup.find_all("span", class_="TextoRegular-Titulo"):
            rotulo = label_span.get_text(strip=True)
            br = label_span.find_next_sibling("br")
            valor_span = br.find_next_sibling("span") if br else None
            if not valor_span:
                continue
            if rotulo == "Título":
                link = valor_span.find("a", href=re.compile(r"DetalheIniciativa\.aspx\?BID=\d+", re.I))
                titulo = valor_span.get_text(strip=True)
                if link and len(titulo) >= 5:
                    m = re.search(r"BID=(\d+)", link["href"], re.I)
                    if m:
                        bid = int(m.group(1))
                        if bid not in seen:
                            seen.add(bid)
                            iniciativas.append({
                                "id": bid,
                                "tipo": campos.get("Tipo", "?"),
                                "numero": campos.get("Número"),
                                "autoria_gp": campos.get("Autoria"),
                                "titulo": titulo[:500],
                                "legislatura_id": LEG_ID,
                                "url_ar": f"{BASE}/ActividadeParlamentar/Paginas/DetalheIniciativa.aspx?BID={bid}",
                            })
                            novos += 1
                campos = {}  # reset para o próximo resultado
            else:
                campos[rotulo] = valor_span.get_text(strip=True)
        return novos, soup

    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        page = browser.new_page(
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                       "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
        )
        try:
            log.info("  A carregar página 1...")
            page.goto(url, timeout=120000, wait_until="domcontentloaded")
            page.wait_for_timeout(3000)

            n, soup = extrair_da_pagina(page)
            log.info("  Página 1: %d iniciativas", n)

            # Calcular página de início baseado em iniciativas já existentes
            start_page = max(1, (skip_existing // 10))
            if start_page > 1:
                log.info("  A saltar para página %d (já temos %d iniciativas)...", start_page + 1, skip_existing)

            total = 1333
            txt_total = page.inner_text("body")
            m = re.search(r"(\d+)\s+registo", txt_total, re.I)
            if m:
                total = int(m.group(1))
            total_pags = (total // 10) + (1 if total % 10 else 0)
            log.info("  Total: %d iniciativas, %d páginas", total, total_pags)

            # Encontrar paginador base
            pager_base = None
            for a in soup.find_all("a", href=re.compile(r"__doPostBack")):
                m2 = re.search(r"__doPostBack\('([^']+)'", a.get("href", ""))
                if m2:
                    pid = m2.group(1)
                    if re.search(r"ctl\d+$", pid):
                        pager_base = re.sub(r"\$ctl\d+$", "", pid)
                        break
            log.info("  Paginador: %s", pager_base or "não encontrado")

            for pg in range(start_page + 1, total_pags + 1):
                time.sleep(DELAY)
                try:
                    # Encontrar o target correcto na página actual (muda a cada página)
                    soup_cur = BeautifulSoup(page.content(), "html.parser")
                    target = None
                    target_next = None  # link ">" para próximo bloco
                    for a in soup_cur.find_all("a", href=re.compile(r"__doPostBack")):
                        txt = a.get_text(strip=True)
                        m2 = re.search(r"__doPostBack\('([^']+)'", a.get("href", ""))
                        if not m2:
                            continue
                        if txt == str(pg):
                            target = m2.group(1)
                            break
                        if txt in (">", "...", "»"):
                            target_next = m2.group(1)

                    # Se não encontrou a página exacta, usar o "próximo bloco"
                    if not target and target_next:
                        log.info("  Página %d não visível — a avançar bloco via '>'", pg)
                        target = target_next
                    elif not target:
                        log.info("  Link para página %d não encontrado — a parar", pg)
                        break

                    # Submeter form com expect_navigation
                    with page.expect_navigation(wait_until="domcontentloaded", timeout=60000):
                        page.evaluate(f"""() => {{
                            document.getElementById('__EVENTTARGET').value = '{target}';
                            document.getElementById('__EVENTARGUMENT').value = '';
                            document.forms[0].submit();
                        }}""")
                    page.wait_for_timeout(3000)

                    n, _ = extrair_da_pagina(page)
                    log.info("  Página %d/%d: +%d (total: %d)", pg, total_pags, n, len(iniciativas))

                    if n == 0:
                        log.info("  Sem novas — a parar")
                        break
                    if len(iniciativas) >= total:
                        break
                except Exception as e:
                    log.warning("  Página %d erro: %s", pg, e)
                    break
        finally:
            browser.close()

    log.info("Total obtido: %d iniciativas", len(iniciativas))
    return iniciativas


def importar_iniciativas(db):
    log.info("── Importar Iniciativas ───────────────────────────")
    # Modo incremental: saber quantas já temos para continuar de onde parou
    n_existentes = db.execute("SELECT COUNT(*) FROM iniciativas WHERE legislatura_id=?", (LEG_ID,)).fetchone()[0]
    log.info("  Já existem %d iniciativas na BD", n_existentes)
    ini_list = scrape_iniciativas(skip_existing=n_existentes)
    if not ini_list:
        log.warning("Sem iniciativas")
        log_etl(db, "AR_iniciativas", 0, 0)
        return

    gravados = erros = 0
    for ini in ini_list:
        try:
            db.execute("""
                INSERT INTO iniciativas(id,tipo,numero,autoria_gp,titulo,legislatura_id,url_ar,updated_at)
                VALUES(?,?,?,?,?,?,?,datetime('now'))
                ON CONFLICT(id) DO UPDATE SET
                    tipo       = COALESCE(excluded.tipo, tipo),
                    numero     = COALESCE(excluded.numero, numero),
                    autoria_gp = COALESCE(excluded.autoria_gp, autoria_gp),
                    titulo     = COALESCE(excluded.titulo, titulo),
                    url_ar     = COALESCE(excluded.url_ar, url_ar),
                    updated_at = datetime('now')
            """, (ini["id"],ini["tipo"],ini.get("numero"),ini.get("autoria_gp"),
                  ini["titulo"],ini["legislatura_id"],ini["url_ar"]))
            gravados += 1
        except Exception as e:
            log.warning("Ini %s: %s", ini.get("id"), e)
            erros += 1

    db.commit()
    log.info("✓ Iniciativas: %d gravadas, %d erros", gravados, erros)
    log_etl(db, "AR_iniciativas", gravados, erros)


def importar_iniciativas_autores(db):
    """
    Autoria individual por deputado. A página de detalhe de cada iniciativa
    tem uma secção "Autoria" com um link por deputado apontando para
    /DeputadoGP/Paginas/Biografia.aspx?BID=<id> — esse BID é o mesmo id
    usado em deputados.id, por isso não precisa de fuzzy-match de nomes.
    """
    log.info("── Importar Autoria Individual das Iniciativas ─────────────")
    pendentes = db.execute("""
        SELECT i.id, i.url_ar FROM iniciativas i
        WHERE NOT EXISTS (SELECT 1 FROM iniciativas_autores ia WHERE ia.iniciativa_id = i.id)
        ORDER BY i.id
    """).fetchall()
    log.info("  %d iniciativas sem autoria individual", len(pendentes))
    if not pendentes:
        log_etl(db, "AR_iniciativas_autores", 0, 0)
        return

    deps_validos = {r[0] for r in db.execute("SELECT id FROM deputados").fetchall()}
    padrao_bid = re.compile(r"/DeputadoGP/Paginas/Biografia\.aspx\?BID=(\d+)", re.I)

    gravados = erros = ignorados = 0
    for idx, row in enumerate(pendentes, 1):
        ini_id, url = row["id"], row["url_ar"]
        soup = get_html(url)
        if not soup:
            erros += 1
            continue
        links = soup.find_all("a", href=padrao_bid)
        bids = set()
        for link in links:
            m = padrao_bid.search(link["href"])
            if m:
                bids.add(int(m.group(1)))
        bids_validos = bids & deps_validos
        if not bids_validos:
            ignorados += 1
        for dep_id in bids_validos:
            try:
                db.execute(
                    "INSERT OR IGNORE INTO iniciativas_autores(iniciativa_id, dep_id) VALUES(?,?)",
                    (ini_id, dep_id),
                )
                gravados += 1
            except Exception as e:
                log.warning("Autor ini=%s dep=%s: %s", ini_id, dep_id, e)
                erros += 1
        if idx % 10 == 0:
            db.commit()  # commits frequentes — transacções longas bloqueiam o dashboard (WAL só permite 1 escritor)
        if idx % 100 == 0:
            log.info("  %d/%d iniciativas processadas (%d autores gravados, %d sem autor válido)",
                      idx, len(pendentes), gravados, ignorados)

    db.commit()
    log.info("✓ Autoria: %d pares gravados, %d iniciativas sem autor válido, %d erros",
              gravados, ignorados, erros)
    log_etl(db, "AR_iniciativas_autores", gravados, erros)

# ─── Ofertas, Deslocações e Hospitalidades ──────────────────────────────────

_RDH_CAMPOS = ["Valor", "Ofertante", "Representação", "Data", "Duração", "Destino final", "Local"]
_RDH_CAMPOS_RE = "|".join(re.escape(c) for c in _RDH_CAMPOS)
_RDH_MARCADOR_RE = re.compile(
    r"(Ofertas(?=\s*Descrição)|Deslocações(?=\s*Descrição)|Hospitalidades(?=\s*Descrição)"
    r"|Não existem registos de (?:ofertas|deslocações|hospitalidades)(?: nem \w+)?)"
)


def parse_ofertas_hospitalidades(body_text):
    """
    Parser do texto simples (BeautifulSoup .get_text) da página
    RegistoDeslocacoesHospitalidades/Paginas/RDH.aspx?BID=X&lg=XVII.
    Estrutura confirmada: 3 secções (Ofertas/Deslocações/Hospitalidades),
    cada uma com 0+ itens "Descrição ... campo valor campo valor...", ou
    substituída por "Não existem registos de <categoria>" quando vazia —
    essa frase pode aparecer colada ao fim do bloco anterior, não só como
    cabeçalho próprio, por isso o parsing é feito por tokens sequenciais.
    """
    m = re.search(
        r"Ofertas, Deslocações e Hospitalidades - (.*?)\s"
        r"(?=Ofertas\b|Deslocações\b|Hospitalidades\b|Não existem)",
        body_text
    )
    if not m:
        return []
    resto = body_text[m.end():].split("A carregar...")[0]

    tokens = _RDH_MARCADOR_RE.split(resto)
    itens, categoria_actual = [], None
    for tok in tokens:
        tok = tok.strip()
        if not tok:
            continue
        if tok in ("Ofertas", "Deslocações", "Hospitalidades"):
            categoria_actual = tok
            continue
        if tok.startswith("Não existem"):
            categoria_actual = None
            continue
        if not categoria_actual:
            continue
        for parte in re.split(r"Descrição", tok)[1:]:
            sub = re.split(f"({_RDH_CAMPOS_RE})", parte)
            item = {"categoria": categoria_actual, "descricao": sub[0].strip()}
            for j in range(1, len(sub) - 1, 2):
                item[sub[j]] = sub[j + 1].strip()
            itens.append(item)
    return itens


OFERTAS_INTERVALO_DIAS = 7  # não vale a pena reverificar mais que semanalmente


def importar_ofertas(db):
    log.info("── Importar Ofertas/Deslocações/Hospitalidades ─────")
    deps = db.execute("""
        SELECT d.id FROM deputados d
        WHERE d.activo=1 AND d.legislatura_id=?
        AND NOT EXISTS (
            SELECT 1 FROM ofertas_check c
            WHERE c.dep_id=d.id AND c.checked_at >= datetime('now', ?)
        )
    """, (LEG_ID, f"-{OFERTAS_INTERVALO_DIAS} days")).fetchall()
    log.info("  %d deputados por verificar (intervalo: %dd)", len(deps), OFERTAS_INTERVALO_DIAS)
    if not deps:
        log_etl(db, "AR_ofertas", 0, 0)
        return

    gravados = erros = com_registo = 0
    for idx, (did,) in enumerate(deps, 1):
        soup = get_html(
            f"{BASE}/RegistoDeslocacoesHospitalidades/Paginas/RDH.aspx?BID={did}&lg={LEG_NUM}"
        )
        if not soup:
            erros += 1
            continue
        db.execute(
            "INSERT INTO ofertas_check(dep_id,checked_at) VALUES(?,datetime('now')) "
            "ON CONFLICT(dep_id) DO UPDATE SET checked_at=datetime('now')",
            (did,)
        )
        itens = parse_ofertas_hospitalidades(soup.get_text(" ", strip=True))
        if itens:
            com_registo += 1
        for it in itens:
            try:
                db.execute("""
                    INSERT OR REPLACE INTO ofertas_hospitalidades
                    (dep_id,categoria,descricao,valor,local,ofertante,representacao,
                     data_registo,duracao,destino_final,updated_at)
                    VALUES(?,?,?,?,?,?,?,?,?,?,datetime('now'))
                """, (did, it["categoria"], it.get("descricao"), it.get("Valor"),
                      it.get("Local"), it.get("Ofertante"), it.get("Representação"),
                      it.get("Data"), it.get("Duração"), it.get("Destino final")))
                gravados += 1
            except Exception as e:
                log.warning("Oferta dep=%s: %s", did, e)
                erros += 1
        if idx % 20 == 0:
            db.commit()
            log.info("  %d/%d deputados (%d com registos, %d itens gravados)",
                      idx, len(deps), com_registo, gravados)

    db.commit()
    log.info("✓ Ofertas: %d deputados com registos, %d itens, %d erros",
              com_registo, gravados, erros)
    log_etl(db, "AR_ofertas", gravados, erros)

# ─── Scores ───────────────────────────────────────────────────────────────────

def scrape_biografico(db):
    """
    Descarregar dados biográficos e registo de interesses XVII via Playwright.
    Fonte: DARegistoBiografico.aspx — ficheiros JSON com CadId=BID, profissão, habilitações, círculo.
    """
    log.info("── Importar Dados Biográficos ─────────────────────────────")
    log.info("A obter ficheiros biográficos via Playwright...")

    bio_data = None
    inter_data = None

    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        context = browser.new_context(
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                       "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
            accept_downloads=True
        )
        page = context.new_page()
        try:
            # Carregar página de dados abertos biográficos
            page.goto("https://www.parlamento.pt/Cidadania/Paginas/DARegistoBiografico.aspx",
                      timeout=60000, wait_until="domcontentloaded")
            page.wait_for_timeout(2000)

            # Encontrar links XVII (t=57465a = XVII em base64)
            links = {}
            for l in page.query_selector_all("a[href*='DARegistoBiografico'][href*='t=57465a']"):
                href = l.get_attribute("href") or ""
                if href:
                    # Navegar para a página com Path longo
                    p2 = context.new_page()
                    captured = {}
                    def on_resp(r, cap=captured):
                        if 'webutils' in r.url and r.status == 200:
                            try:
                                body = r.body()
                                if len(body) > 10000:
                                    cap[r.url] = body
                            except: pass
                    p2.on("response", on_resp)
                    full = "https://www.parlamento.pt" + href
                    p2.goto(full, timeout=60000, wait_until="networkidle")
                    p2.wait_for_timeout(2000)

                    # Apanhar URLs dos ficheiros JSON
                    for fl in p2.query_selector_all("a[href*='webutils']"):
                        txt = fl.inner_text().strip()
                        fhref = fl.get_attribute("href") or ""
                        if 'json' in txt.lower() or '_json' in txt.lower():
                            links[txt] = fhref

                    # Se temos links, descarregar em novas tabs
                    for nome, furl in links.items():
                        p3 = context.new_page()
                        cap3 = {}
                        def on_resp3(r, cap=cap3):
                            if 'webutils' in r.url and r.status == 200:
                                try:
                                    body = r.body()
                                    if len(body) > 10000:
                                        cap[r.url] = body
                                except: pass
                        p3.on("response", on_resp3)
                        p3.goto(furl, timeout=60000, wait_until="networkidle")
                        p3.wait_for_timeout(2000)
                        if cap3:
                            body = list(cap3.values())[0]
                            log.info("  %s: %d bytes", nome, len(body))
                            if 'Biograf' in nome:
                                bio_data = body
                            elif 'Interes' in nome:
                                inter_data = body
                        p3.close()
                    p2.close()
                    break  # só XVII
        except Exception as e:
            log.error("Playwright biográfico: %s", e)
        finally:
            browser.close()

    if not bio_data:
        log.warning("Não foi possível obter dados biográficos")
        return 0

    import json as _json
    bio = _json.loads(bio_data.decode('utf-8'))
    inter_idx = {}
    if inter_data:
        inter = _json.loads(inter_data.decode('utf-8'))
        for d in inter:
            v3 = d.get('RegistoInteressesV3') or {}
            bid = v3.get('DGFNumber')
            if bid:
                inter_idx[str(int(float(bid)))] = v3

    actualizados = 0
    for dep in bio:
        cad_id = dep.get('CadId')
        if not cad_id:
            continue
        bid = int(float(cad_id))

        # Verificar se é XVII
        legis = dep.get('CadDeputadoLegis') or []
        is_xvii = any(l.get('LegDes') == 'XVII' for l in legis)
        if not is_xvii:
            continue

        profissao   = dep.get('CadProfissao') or ''
        nascimento  = dep.get('CadDtNascimento') or ''
        habilitacoes = dep.get('CadHabilitacoes') or ''
        sexo        = dep.get('CadSexo') or ''
        genero      = 'M' if sexo == 'M' else ('F' if sexo == 'F' else None)

        # Exclusividade de mandato
        excl = inter_idx.get(str(bid), {}).get('Exclusivity')

        # Comissões em que participa
        org_data = dep.get('CadActividadeOrgaos') or {}
        comissoes = []
        for act in (org_data.get('actividadeCom') or []):
            if act.get('legDes') == 'XVII':
                comissoes.append(act.get('orgSigla',''))

        # Cargos anteriores
        cargos = dep.get('CadCargosFuncoes') or []
        cargos_json = _json.dumps([c.get('FunDes','') for c in cargos if c.get('FunDes')], ensure_ascii=False)

        try:
            db.execute("""
                UPDATE deputados SET
                    profissao=COALESCE(NULLIF(profissao,''), ?),
                    data_nascimento=COALESCE(NULLIF(data_nascimento,''), ?),
                    genero=COALESCE(NULLIF(genero,''), ?),
                    cargos_json=?,
                    updated_at=datetime('now')
                WHERE id=? AND legislatura_id=17
            """, [profissao, nascimento, genero, cargos_json, bid])
            if db.execute("SELECT changes()").fetchone()[0]:
                actualizados += 1
        except Exception as e:
            log.debug("Bio update BID %d: %s", bid, e)

    db.commit()
    log.info("✓ Biográfico: %d deputados actualizados", actualizados)
    return actualizados


def calcular_scores(db):
    log.info("── Calcular Scores ────────────────────────────────")

    deps = db.execute(
        "SELECT id FROM deputados WHERE activo=1 AND legislatura_id=?", (LEG_ID,)
    ).fetchall()
    if not deps:
        log.warning("Sem deputados")
        return

    # Pesos nominais só se aplicam a componentes com dados reais na BD.
    # Sem isto, "sem dados" fica indistinguível de "sem problemas" (ex:
    # contratos_base vazio dava 30/30 de score_contratos a toda a gente).
    tem_autores    = db.execute("SELECT 1 FROM iniciativas_autores LIMIT 1").fetchone() is not None
    tem_contratos  = db.execute("SELECT 1 FROM contratos_base LIMIT 1").fetchone() is not None
    pesos = {"presenca": 0.40, "iniciativas": 0.30 if tem_autores else 0.0,
             "contratos": 0.30 if tem_contratos else 0.0}
    soma_pesos = sum(pesos.values()) or 1.0
    pesos = {k: v / soma_pesos for k, v in pesos.items()}  # renormaliza p/ somar 1.0
    for nome, activo in (("iniciativas", tem_autores), ("contratos", tem_contratos)):
        if not activo:
            log.warning("  score_%s desactivado (sem dados em %s) — peso redistribuído",
                         nome, "iniciativas_autores" if nome == "iniciativas" else "contratos_base")

    total_sessoes = db.execute(
        "SELECT COUNT(*) FROM sessoes_plenarias WHERE legislatura_id=?", (LEG_ID,)
    ).fetchone()[0] or 1

    # Peso de cada estado de presença na "qualidade" usada no score — falta
    # sem justificação pesa 0, falta justificada conta a meio, escusa/missão
    # institucional (ainda é trabalho parlamentar, só não em plenário) quase
    # não penaliza. taxa_presenca (P puro) continua a ser guardada à parte —
    # é o que aparece na UI como "% presença", mais simples de entender.
    PESO_ESTADO = {"P": 1.0, "E": 0.9, "FJ": 0.5, "F": 0.0}

    raw = []
    for dep in deps:
        did = dep[0]
        contagens = dict(db.execute("""
            SELECT p.estado, COUNT(*) FROM presencas p
            JOIN sessoes_plenarias s ON p.sessao_id=s.id
            WHERE p.dep_id=? AND s.legislatura_id=?
            GROUP BY p.estado
        """, (did, LEG_ID)).fetchall())
        pres = contagens.get("P", 0)
        qualidade = sum(contagens.get(e, 0) * peso for e, peso in PESO_ESTADO.items()) / total_sessoes
        # Iniciativas do deputado via autores (0 se iniciativas_autores vazia —
        # nesse caso pesos["iniciativas"]==0 e este valor não afecta o score_total)
        n_ini = db.execute("""
            SELECT COUNT(*) FROM iniciativas_autores ia
            JOIN iniciativas i ON ia.iniciativa_id=i.id
            WHERE ia.dep_id=? AND i.legislatura_id=?
        """, (did, LEG_ID)).fetchone()[0]
        n_c, v_c = db.execute(
            "SELECT COUNT(*),COALESCE(SUM(valor),0) FROM contratos_base WHERE dep_id=?", (did,)
        ).fetchone()
        raw.append({"dep_id":did,"taxa":pres/total_sessoes,"qualidade":qualidade,
                    "ini":n_ini,"contr":n_c,"val":v_c})

    def pct(lst, v):
        return round(sum(1 for x in lst if x<=v)/len(lst)*100, 1) if lst else 50.0

    tp = [s["taxa"] for s in raw]
    ti = [s["ini"]  for s in raw]
    tc = [s["contr"] for s in raw]

    for s in raw:
        sp = s["qualidade"]*100*pesos["presenca"]
        si = min(s["ini"]/5,1.0)*100*pesos["iniciativas"]
        sc = max(0,1-min(s["contr"]/10,1.0))*100*pesos["contratos"]
        db.execute("""
            INSERT INTO scores
            (dep_id,legislatura_id,score_total,score_presenca,score_iniciativas,
             score_contratos,taxa_presenca,n_iniciativas,n_contratos,valor_contratos,
             percentil_presenca,percentil_iniciativas,percentil_contratos,updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))
            ON CONFLICT(dep_id) DO UPDATE SET
                score_total            = excluded.score_total,
                score_presenca         = excluded.score_presenca,
                score_iniciativas      = excluded.score_iniciativas,
                score_contratos        = excluded.score_contratos,
                taxa_presenca          = excluded.taxa_presenca,
                n_iniciativas          = excluded.n_iniciativas,
                n_contratos            = excluded.n_contratos,
                valor_contratos        = excluded.valor_contratos,
                percentil_presenca     = excluded.percentil_presenca,
                percentil_iniciativas  = excluded.percentil_iniciativas,
                percentil_contratos    = excluded.percentil_contratos,
                updated_at             = datetime('now')
        """, (s["dep_id"],LEG_ID,round(sp+si+sc,1),round(sp,1),round(si,1),round(sc,1),
              round(s["taxa"],4),s["ini"],s["contr"],s["val"],
              pct(tp,s["taxa"]),pct(ti,s["ini"]),pct(tc,s["contr"])))

    db.execute("""
        UPDATE scores SET rank_geral=(
            SELECT COUNT(*)+1 FROM scores s2
            WHERE s2.score_total>scores.score_total AND s2.legislatura_id=?
        ) WHERE legislatura_id=?
    """, (LEG_ID, LEG_ID))

    db.commit()
    log.info("✓ Scores: %d deputados", len(raw))

# ─── CLI ──────────────────────────────────────────────────────────────────────

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--all",         action="store_true")
    p.add_argument("--deputados",   action="store_true")
    p.add_argument("--presencas",   action="store_true")
    p.add_argument("--iniciativas", action="store_true")
    p.add_argument("--autores",     action="store_true", help="autoria individual das iniciativas")
    p.add_argument("--ofertas",     action="store_true", help="ofertas/deslocações/hospitalidades")
    p.add_argument("--scores",      action="store_true")
    p.add_argument("--snapshots",   action="store_true", help="regenerar JSONs pré-computados p/ o dashboard")
    p.add_argument("--enrich",      action="store_true")
    p.add_argument("--biografico",  action="store_true")
    args = p.parse_args()

    if not any(vars(args).values()):
        p.print_help()
        sys.exit(0)

    init_db()
    db = get_db()
    try:
        if args.all or args.deputados:   importar_deputados(db, enrich=args.enrich)
        if args.all or args.iniciativas: importar_iniciativas(db)
        if args.all or args.autores:     importar_iniciativas_autores(db)
        if args.all or args.ofertas:     importar_ofertas(db)
        if args.all or args.presencas:   importar_presencas(db)
        if args.all or args.scores:      calcular_scores(db)
        if args.all or args.biografico:  scrape_biografico(db)
    finally:
        db.close()
    if args.all or args.snapshots:
        subprocess.run([sys.executable, str(Path(__file__).parent / "etl" / "gerar_snapshots.py")], check=False)
    log.info("✅ Concluído.")

if __name__ == "__main__":
    main()
