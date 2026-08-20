#!/usr/bin/env python3
"""
gerar_snapshots.py — pré-computa JSONs estáticos para o dashboard.

Escreve em data/snapshots/*.json. O dashboard.php lê estes ficheiros
directamente (zero queries SQLite) e só cai para query live se o
snapshot faltar ou for inválido — mesmo padrão do /var/www/html/politica.

Uso:
    python3 etl/gerar_snapshots.py
"""

import json
import logging
import sqlite3
from pathlib import Path

ROOT = Path(__file__).parent.parent
DB_PATH = ROOT / "transparencia_pt.db"
SNAP_DIR = ROOT / "data" / "snapshots"
LEG_ID = 17

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s", datefmt="%H:%M:%S")
log = logging.getLogger("gerar_snapshots")


def get_db():
    db = sqlite3.connect(DB_PATH, timeout=10)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA query_only = ON")  # snapshots nunca escrevem na BD
    return db


def write_json(name, data):
    SNAP_DIR.mkdir(parents=True, exist_ok=True)
    path = SNAP_DIR / f"{name}.json"
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    tmp.replace(path)  # escrita atómica — dashboard nunca lê ficheiro a meio
    log.info("  %s.json escrito (%d bytes)", name, path.stat().st_size)


def snap_globals(db):
    leg = LEG_ID
    stats = {
        "deputados": db.execute("SELECT COUNT(*) as n FROM deputados WHERE activo=1 AND legislatura_id=?", (leg,)).fetchone()["n"],
        "sessoes": db.execute("SELECT COUNT(*) as n FROM sessoes_plenarias WHERE legislatura_id=?", (leg,)).fetchone()["n"],
        "iniciativas": db.execute("SELECT COUNT(*) as n FROM iniciativas WHERE legislatura_id=?", (leg,)).fetchone()["n"],
        "presenca_media": db.execute("SELECT ROUND(AVG(taxa_presenca)*100,1) as n FROM scores WHERE legislatura_id=?", (leg,)).fetchone()["n"] or 0,
        "por_gp": [dict(r) for r in db.execute("""
            SELECT d.gp_sigla as gp, COUNT(d.id) as total,
                   ROUND(AVG(s.taxa_presenca)*100,1) as presenca_media,
                   SUM(s.n_iniciativas) as iniciativas,
                   ROUND(AVG(s.score_total),1) as score_medio
            FROM deputados d
            LEFT JOIN scores s ON s.dep_id=d.id AND s.legislatura_id=d.legislatura_id
            WHERE d.activo=1 AND d.legislatura_id=?
            GROUP BY d.gp_sigla ORDER BY total DESC
        """, (leg,)).fetchall()],
        "top_presenca": [dict(r) for r in db.execute("""
            SELECT d.id, d.nome_curto, d.gp_sigla, s.taxa_presenca, s.score_total
            FROM deputados d JOIN scores s ON s.dep_id=d.id
            WHERE d.activo=1 AND d.legislatura_id=?
            ORDER BY s.taxa_presenca DESC LIMIT 10
        """, (leg,)).fetchall()],
        "menos_presenca": [dict(r) for r in db.execute("""
            SELECT d.id, d.nome_curto, d.gp_sigla, s.taxa_presenca, s.score_total
            FROM deputados d JOIN scores s ON s.dep_id=d.id
            WHERE d.activo=1 AND d.legislatura_id=?
            ORDER BY s.taxa_presenca ASC LIMIT 10
        """, (leg,)).fetchall()],
        "mais_iniciativas": [dict(r) for r in db.execute("""
            SELECT d.id, d.nome_curto, d.gp_sigla, s.n_iniciativas, s.score_total
            FROM deputados d JOIN scores s ON s.dep_id=d.id
            WHERE d.activo=1 AND d.legislatura_id=?
            ORDER BY s.n_iniciativas DESC LIMIT 10
        """, (leg,)).fetchall()],
    }
    write_json("globals", stats)


def snap_score_ranking(db):
    rows = [dict(r) for r in db.execute("""
        SELECT d.id, d.nome_curto, d.nome_completo, d.gp_sigla, d.url_foto,
               COALESCE(s.score_total,0) as score_total,
               COALESCE(s.taxa_presenca,0) as taxa_presenca,
               COALESCE(s.n_iniciativas,0) as n_iniciativas,
               COALESCE(s.rank_geral,9999) as rank_geral
        FROM deputados d
        LEFT JOIN scores s ON s.dep_id=d.id AND s.legislatura_id=d.legislatura_id
        WHERE d.legislatura_id=?
        ORDER BY score_total DESC, d.nome_curto ASC
    """, (LEG_ID,)).fetchall()]
    write_json("score_ranking", rows)


def snap_grupos(db):
    leg = LEG_ID
    rows = [dict(r) for r in db.execute("""
        SELECT g.sigla, g.nome, g.cor_hex,
               COUNT(d.id) as n_deputados,
               COUNT(CASE WHEN d.activo=1 THEN 1 END) as n_activos,
               ROUND(AVG(CASE WHEN d.activo=1 THEN s.taxa_presenca END)*100,1) as presenca_media,
               ROUND(AVG(CASE WHEN d.activo=1 THEN s.score_total END),1) as score_medio,
               SUM(CASE WHEN d.activo=1 THEN s.n_iniciativas END) as total_iniciativas,
               MAX(CASE WHEN d.activo=1 THEN s.taxa_presenca END) as melhor_presenca,
               MIN(CASE WHEN d.activo=1 THEN s.taxa_presenca END) as pior_presenca,
               COALESCE(
                   (SELECT d2.nome_curto FROM deputados d2 WHERE d2.id=g.lider_bid LIMIT 1),
                   (SELECT d2.nome_curto FROM deputados d2 LEFT JOIN scores s2 ON s2.dep_id=d2.id WHERE d2.gp_sigla=g.sigla AND d2.activo=1 AND d2.legislatura_id=? ORDER BY COALESCE(s2.score_total,0) DESC LIMIT 1)
               ) as lider_nome,
               COALESCE(
                   (SELECT d2.url_foto FROM deputados d2 WHERE d2.id=g.lider_bid LIMIT 1),
                   (SELECT d2.url_foto FROM deputados d2 LEFT JOIN scores s2 ON s2.dep_id=d2.id WHERE d2.gp_sigla=g.sigla AND d2.activo=1 AND d2.legislatura_id=? ORDER BY COALESCE(s2.score_total,0) DESC LIMIT 1)
               ) as lider_foto,
               COALESCE(
                   (SELECT d2.id FROM deputados d2 WHERE d2.id=g.lider_bid LIMIT 1),
                   (SELECT d2.id FROM deputados d2 LEFT JOIN scores s2 ON s2.dep_id=d2.id WHERE d2.gp_sigla=g.sigla AND d2.activo=1 AND d2.legislatura_id=? ORDER BY COALESCE(s2.score_total,0) DESC LIMIT 1)
               ) as lider_id
        FROM grupos_parlamentares g
        LEFT JOIN deputados d ON d.gp_sigla=g.sigla AND d.legislatura_id=?
        LEFT JOIN scores s ON s.dep_id=d.id AND s.legislatura_id=?
        WHERE g.legislatura_id=?
        GROUP BY g.sigla
        HAVING n_activos > 0
        ORDER BY n_activos DESC
    """, (leg, leg, leg, leg, leg, leg)).fetchall()]
    write_json("grupos", rows)


def main():
    log.info("── Gerar Snapshots ──────────────────────────")
    db = get_db()
    try:
        snap_globals(db)
        snap_score_ranking(db)
        snap_grupos(db)
    finally:
        db.close()
    log.info("✓ Snapshots gerados em %s", SNAP_DIR)


if __name__ == "__main__":
    main()
