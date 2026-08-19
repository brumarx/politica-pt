#!/usr/bin/env python3
"""
importar_biografico.py — Importa dados biográficos e registo de interesses da AR
Fonte: Dados Abertos AR (RegistoBiograficoXVII_json.txt + RegistoInteressesXVII_json.txt)
Via Playwright (os URLs são gerados dinamicamente pelo SharePoint)

Uso:
    venv/bin/python importar_biografico.py
"""
import json, logging, sqlite3, sys, time
from pathlib import Path

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s", datefmt="%H:%M:%S")
log = logging.getLogger("importar_biografico")

DB_PATH = Path(__file__).parent / "transparencia_pt.db"
LEG_ID  = 17
LEG_DES = "XVII"

def get_db():
    db = sqlite3.connect(DB_PATH, timeout=30)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA journal_mode=WAL")
    return db

def to_str(v, maxlen=200):
    if v is None: return None
    if isinstance(v, list): return ", ".join(str(x) for x in v if x)[:maxlen]
    if isinstance(v, dict): return json.dumps(v, ensure_ascii=False)[:maxlen]
    return str(v)[:maxlen]

def download_dados_biograficos():
    from playwright.sync_api import sync_playwright
    log.info("A descarregar dados biográficos via Playwright...")
    XVII_URL = "https://www.parlamento.pt/Cidadania/Paginas/DARegistoBiografico.aspx"
    bio_data = inter_data = None

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        context = browser.new_context(
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
            accept_downloads=True
        )
        page = context.new_page()
        log.info("  A carregar página de dados abertos...")
        page.goto(XVII_URL, timeout=60000, wait_until="domcontentloaded")
        page.wait_for_timeout(2000)

        links = {}
        for l in page.query_selector_all("a[href*='webutils']"):
            txt = l.inner_text().strip()
            href = l.get_attribute("href")
            if href and "XVII" in txt:
                links[txt] = href
                log.info("  Encontrado: %s", txt)

        for nome, url in links.items():
            captured = []
            def on_resp(r, _n=nome):
                if "webutils" in r.url and r.status == 200:
                    try:
                        body = r.body()
                        if len(body) > 1000: captured.append(body)
                    except: pass
            p2 = context.new_page()
            p2.on("response", on_resp)
            try:
                log.info("  A descarregar %s...", nome)
                p2.goto(url, timeout=90000, wait_until="domcontentloaded")
                p2.wait_for_timeout(3000)
                if captured:
                    raw = captured[0].decode("utf-8", errors="replace")
                    try:
                        parsed = json.loads(raw)
                        log.info("  %s: %d registos", nome, len(parsed))
                        if "Biogr" in nome: bio_data = parsed
                        elif "Interesses" in nome: inter_data = parsed
                    except json.JSONDecodeError as e:
                        log.warning("  Erro %s: %s", nome, e)
                else:
                    log.warning("  Sem dados para %s", nome)
            except Exception as e:
                log.warning("  Erro %s: %s", nome, e)
            finally:
                p2.close()
            time.sleep(1)
        browser.close()
    return bio_data, inter_data

def init_tabela(db):
    db.execute("""
        CREATE TABLE IF NOT EXISTS perfis_deputados (
            dep_id          INTEGER PRIMARY KEY REFERENCES deputados(id),
            profissao       TEXT,
            habilitacoes    TEXT,
            nascimento      DATE,
            sexo            TEXT,
            titulos         TEXT,
            circulo         TEXT,
            exclusividade   TEXT,
            comissoes_json  TEXT,
            cargos_json     TEXT,
            updated_at      DATETIME DEFAULT (datetime('now'))
        )
    """)
    db.commit()
    log.info("Tabela perfis_deputados OK")

def importar(bio_data, inter_data):
    db = get_db()
    init_tabela(db)

    inter_idx = {}
    if inter_data:
        for d in inter_data:
            v = d.get("RegistoInteressesV3") or d.get("RegistoInteressesV5") or {}
            bid = v.get("DGFNumber")
            if bid:
                inter_idx[str(int(float(bid)))] = v

    ok = erros = 0
    for dep in (bio_data or []):
        legis = dep.get("CadDeputadoLegis") or []
        is_xvii = any(l.get("LegDes") == LEG_DES for l in legis if isinstance(l, dict))
        if not is_xvii: continue
        bid = dep.get("CadId")
        if not bid: continue
        bid = int(float(bid))
        exists = db.execute("SELECT id FROM deputados WHERE id=?", (bid,)).fetchone()
        if not exists: continue

        profissao    = to_str(dep.get("CadProfissao"))
        habilitacoes = to_str(dep.get("CadHabilitacoes"))
        nascimento   = to_str(dep.get("CadDtNascimento"), 10)
        sexo         = to_str(dep.get("CadSexo"), 1)
        titulos      = to_str(dep.get("CadTitulos"))

        circulo = gp_sigla = None
        for l in legis:
            if isinstance(l, dict) and l.get("LegDes") == LEG_DES:
                circulo  = to_str(l.get("CeDes"))
                gp_sigla = to_str(l.get("GpSigla"))
                break

        if gp_sigla and ("PSD" in gp_sigla or "CDS" in gp_sigla):
            gp_sigla = "AD"

        cad_org = dep.get("CadActividadeOrgaos")
        comissoes = None
        if isinstance(cad_org, dict):
            coms = cad_org.get("actividadeCom") or []
            if isinstance(coms, list):
                comissoes = json.dumps([
                    {"sigla": c.get("orgSigla"), "nome": c.get("orgDes"), "tipo": c.get("timDes")}
                    for c in coms if isinstance(c, dict)
                ], ensure_ascii=False)[:2000]

        cargos_raw = dep.get("CadCargosFuncoes")
        cargos = None
        if isinstance(cargos_raw, list):
            cargos = json.dumps([
                {"descricao": to_str(c.get("FunDes"), 200)}
                for c in cargos_raw if isinstance(c, dict)
            ], ensure_ascii=False)[:2000]

        inter = inter_idx.get(str(bid), {})
        exclusividade = to_str(inter.get("Exclusivity"), 1)

        try:
            db.execute("""
                UPDATE deputados SET
                    profissao = COALESCE(NULLIF(profissao,''), ?),
                    genero    = COALESCE(genero, ?),
                    data_nascimento = COALESCE(data_nascimento, ?)
                WHERE id=?
            """, (profissao, sexo, nascimento, bid))
            db.execute("""
                INSERT INTO perfis_deputados
                    (dep_id, profissao, habilitacoes, nascimento, sexo,
                     titulos, circulo, exclusividade, comissoes_json, cargos_json)
                VALUES (?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(dep_id) DO UPDATE SET
                    profissao=excluded.profissao, habilitacoes=excluded.habilitacoes,
                    nascimento=excluded.nascimento, sexo=excluded.sexo, titulos=excluded.titulos,
                    circulo=excluded.circulo, exclusividade=excluded.exclusividade,
                    comissoes_json=excluded.comissoes_json, cargos_json=excluded.cargos_json,
                    updated_at=datetime('now')
            """, (bid, profissao, habilitacoes, nascimento, sexo,
                  titulos, circulo, exclusividade, comissoes, cargos))
            ok += 1
        except Exception as e:
            log.warning("  Erro BID %d: %s", bid, e)
            erros += 1

    db.commit()
    log.info("✓ Perfis: %d actualizados, %d erros", ok, erros)
    db.execute("UPDATE deputados SET gp_sigla='AD' WHERE gp_sigla IN ('PSD','CDS-PP') AND legislatura_id=17")
    db.commit()
    return ok

def main():
    log.info("── Importar Dados Biográficos ─────────────────────────────")
    bio_data, inter_data = download_dados_biograficos()
    if not bio_data:
        log.error("Sem dados biográficos — abortar")
        sys.exit(1)
    n = importar(bio_data, inter_data)
    log.info("✅ Concluído: %d perfis actualizados", n)

if __name__ == "__main__":
    main()
