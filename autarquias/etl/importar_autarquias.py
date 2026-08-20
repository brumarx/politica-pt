#!/usr/bin/env python3
"""
importar_autarquias.py — ETL Autarquias PT (nível concelho)
Fonte: INE (API JSON directa, indicadores DGAL) — sem scraping HTML,
sem bloqueio anti-bot (ao contrário de transparencia.gov.pt, que tem
WAF activo e bloqueou os pedidos automatizados durante a investigação).

Indicadores usados:
  0009481 — Despesas (€) das câmaras municipais, por categoria económica
  0013908 — Receitas (€) das câmaras municipais, por categoria económica
Nota: o `geocod` NÃO é consistente entre os dois indicadores do INE
(ex: despesas usa "1701511" para Sesimbra, receitas usa "1B01511") —
por isso o município é casado por NOME (geodsg), não por geocod.

Uso:
    python3 importar_autarquias.py --all
    python3 importar_autarquias.py --financas
"""

import argparse
import json
import logging
import sqlite3
import sys
from datetime import datetime
from pathlib import Path

import requests

ROOT = Path(__file__).parent.parent
DB_PATH = ROOT / "autarquias_pt.db"
SCHEMA_SQL = ROOT / "schema.sql"

INE_DESPESAS_URL = "https://www.ine.pt/ine/json_indicador/pindica.jsp?op=2&varcd=0009481&lang=PT"
INE_RECEITAS_URL = "https://www.ine.pt/ine/json_indicador/pindica.jsp?op=2&varcd=0013908&lang=PT"

# Whitelist dos 306 concelhos de Portugal (continente + Açores + Madeira) —
# necessária porque os indicadores do INE misturam concelhos reais com
# agregados regionais (NUTS III/II, "Portugal", "Continente"...) usando
# formatos de geocod inconsistentes entre datasets (verificado: despesas
# usa geocod numérico "DDCCFFF", receitas mistura isso com códigos NUTS
# alfanuméricos "1C31202" mesmo para concelhos reais — o geocod não serve
# para distinguir agregado de concelho). Nome é o critério fiável.
CONCELHOS = {
    "Abrantes","Aguiar da Beira","Alandroal","Albergaria-a-Velha","Albufeira","Alcanena",
    "Alcobaça","Alcochete","Alcoutim","Alcácer do Sal","Alenquer","Alfândega da Fé",
    "Alijó","Aljezur","Aljustrel","Almada","Almeida","Almeirim","Almodôvar","Alpiarça",
    "Alter do Chão","Alvaiázere","Alvito","Amadora","Amarante","Amares","Anadia",
    "Angra do Heroísmo","Ansião","Arcos de Valdevez","Arganil","Armamar","Arouca",
    "Arraiolos","Arronches","Arruda dos Vinhos","Aveiro","Avis","Azambuja","Baião",
    "Barcelos","Barrancos","Barreiro","Batalha","Beja","Belmonte","Benavente","Bombarral",
    "Borba","Boticas","Braga","Bragança","Cabeceiras de Basto","Cadaval",
    "Caldas da Rainha","Calheta","Caminha","Campo Maior","Cantanhede",
    "Carrazeda de Ansiães","Carregal do Sal","Cartaxo","Cascais","Castanheira de Pêra",
    "Castelo Branco","Castelo de Paiva","Castelo de Vide","Castro Daire","Castro Marim",
    "Castro Verde","Celorico da Beira","Celorico de Basto","Chamusca","Chaves","Cinfães",
    "Coimbra","Condeixa-a-Nova","Constância","Coruche","Corvo","Covilhã","Crato","Cuba",
    "Câmara de Lobos","Elvas","Entroncamento","Espinho","Esposende","Estarreja",
    "Estremoz","Fafe","Faro","Felgueiras","Ferreira do Alentejo","Ferreira do Zêzere",
    "Figueira da Foz","Figueira de Castelo Rodrigo","Figueiró dos Vinhos",
    "Fornos de Algodres","Freixo de Espada à Cinta","Fronteira","Funchal","Fundão",
    "Gavião","Golegã","Gondomar","Gouveia","Grândola","Guarda","Guimarães","Góis","Horta",
    "Idanha-a-Nova","Lagoa","Lagos","Lajes das Flores","Lajes do Pico","Lamego","Leiria",
    "Lisboa","Loulé","Loures","Lourinhã","Lousada","Lousã","Macedo de Cavaleiros",
    "Machico","Madalena","Mafra","Maia","Mangualde","Manteigas","Marco de Canaveses",
    "Marinha Grande","Marvão","Matosinhos","Mação","Mealhada","Melgaço","Mesão Frio",
    "Mira","Miranda do Corvo","Miranda do Douro","Mirandela","Mogadouro",
    "Moimenta da Beira","Moita","Monchique","Mondim de Basto","Monforte","Montalegre",
    "Montemor-o-Novo","Montemor-o-Velho","Montijo","Monção","Mora","Mortágua","Moura",
    "Mourão","Murtosa","Murça","Mértola","Mêda","Nazaré","Nelas","Nisa","Nordeste",
    "Odemira","Odivelas","Oeiras","Oleiros","Olhão","Oliveira de Azeméis",
    "Oliveira de Frades","Oliveira do Bairro","Oliveira do Hospital","Ourique","Ourém",
    "Ovar","Palmela","Pampilhosa da Serra","Paredes","Paredes de Coura",
    "Paços de Ferreira","Pedrógão Grande","Penacova","Penafiel","Penalva do Castelo",
    "Penamacor","Penedono","Penela","Peniche","Peso da Régua","Pinhel","Pombal",
    "Ponta Delgada","Ponta do Sol","Ponte da Barca","Ponte de Lima","Ponte de Sor",
    "Portalegre","Portel","Portimão","Porto","Porto Moniz","Porto Santo","Porto de Mós",
    "Povoação","Proença-a-Nova","Póvoa de Lanhoso","Póvoa de Varzim","Redondo",
    "Reguengos de Monsaraz","Resende","Ribeira Brava","Ribeira Grande","Ribeira de Pena",
    "Rio Maior","Sabrosa","Sabugal","Salvaterra de Magos","Santa Comba Dão","Santa Cruz",
    "Santa Cruz da Graciosa","Santa Cruz das Flores","Santa Maria da Feira",
    "Santa Marta de Penaguião","Santana","Santarém","Santiago do Cacém","Santo Tirso",
    "Sardoal","Seia","Seixal","Sernancelhe","Serpa","Sertã","Sesimbra","Setúbal",
    "Sever do Vouga","Silves","Sines","Sintra","Sobral de Monte Agraço","Soure","Sousel",
    "Sátão","São Brás de Alportel","São João da Madeira","São João da Pesqueira",
    "São Pedro do Sul","São Roque do Pico","São Vicente","Tabuaço","Tarouca","Tavira",
    "Terras de Bouro","Tomar","Tondela","Torre de Moncorvo","Torres Novas",
    "Torres Vedras","Trancoso","Trofa","Tábua","Vagos","Vale de Cambra","Valença",
    "Valongo","Valpaços","Velas","Vendas Novas","Viana do Alentejo","Viana do Castelo",
    "Vidigueira","Vieira do Minho","Vila Flor","Vila Franca de Xira",
    "Vila Franca do Campo","Vila Nova da Barquinha","Vila Nova de Cerveira",
    "Vila Nova de Famalicão","Vila Nova de Foz Côa","Vila Nova de Gaia",
    "Vila Nova de Paiva","Vila Nova de Poiares","Vila Pouca de Aguiar","Vila Real",
    "Vila Real de Santo António","Vila Velha de Ródão","Vila Verde","Vila Viçosa",
    "Vila da Praia da Vitória","Vila de Rei","Vila do Bispo","Vila do Conde",
    "Vila do Porto","Vimioso","Vinhais","Viseu","Vizela","Vouzela","Águeda","Évora",
    "Ílhavo","Óbidos",
}

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s", datefmt="%H:%M:%S")
log = logging.getLogger("importar_autarquias")


def get_db():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    db = sqlite3.connect(DB_PATH, timeout=30)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA journal_mode=WAL")
    db.execute("PRAGMA foreign_keys=ON")
    return db


def init_db(db):
    db.executescript(SCHEMA_SQL.read_text())
    db.commit()


def log_etl(db, fonte, registos, erros, detalhes=None):
    db.execute(
        "INSERT INTO etl_log(fonte,iniciado_em,concluido_em,registos,erros,detalhes) VALUES(?,?,?,?,?,?)",
        (fonte, datetime.now(), datetime.now(), registos, erros, json.dumps(detalhes or {}))
    )
    db.commit()


def fetch_ine(url):
    r = requests.get(url, headers={"User-Agent": "Mozilla/5.0"}, timeout=60)
    r.raise_for_status()
    return r.json()[0]


def parse_valor(ind):
    """Converte '54 292' / '21,0' (string INE, milhares/decimais PT) para float."""
    v = ind.get("valor")
    if v in (None, ""):
        return None
    try:
        return float(str(v).replace(" ", "").replace(",", "."))
    except ValueError:
        return None


def importar_financas(db):
    log.info("── Importar Finanças Municipais (INE/DGAL) ─────────")
    gravados = erros = 0
    municipio_ids = {}  # nome -> id (cache local desta execução)

    for tipo, url in (("despesa", INE_DESPESAS_URL), ("receita", INE_RECEITAS_URL)):
        log.info("  A obter %s...", tipo)
        try:
            dados = fetch_ine(url)
        except Exception as e:
            log.error("  Falhou %s: %s", tipo, e)
            erros += 1
            continue

        anos = sorted(dados["Dados"].keys())
        ano_mais_recente = anos[-1]
        registos = dados["Dados"][ano_mais_recente]
        log.info("  %s: ano %s, %d registos", tipo, ano_mais_recente, len(registos))

        for r in registos:
            nome = r.get("geodsg", "").strip()
            if not nome or nome not in CONCELHOS:
                continue  # agregado regional (NUTS/país), não um concelho real
            if nome not in municipio_ids:
                db.execute(
                    "INSERT INTO municipios(geocod,nome) VALUES(?,?) "
                    "ON CONFLICT(geocod) DO NOTHING",
                    (r.get("geocod"), nome)
                )
                row = db.execute("SELECT id FROM municipios WHERE nome=?", (nome,)).fetchone()
                if not row:
                    # geocod já usado por outro nome (colisão rara) — insere só por nome
                    db.execute("INSERT OR IGNORE INTO municipios(geocod,nome) VALUES(?,?)",
                               (f"nome:{nome}", nome))
                    row = db.execute("SELECT id FROM municipios WHERE nome=?", (nome,)).fetchone()
                municipio_ids[nome] = row["id"] if row else None
            municipio_id = municipio_ids.get(nome)
            if not municipio_id:
                erros += 1
                continue

            valor = parse_valor(r)
            try:
                db.execute("""
                    INSERT INTO municipio_financas
                        (municipio_id,ano,tipo,categoria_cod,categoria_nome,valor_milhares,updated_at)
                    VALUES(?,?,?,?,?,?,datetime('now'))
                    ON CONFLICT(municipio_id,ano,tipo,categoria_cod) DO UPDATE SET
                        valor_milhares = excluded.valor_milhares,
                        categoria_nome = excluded.categoria_nome,
                        updated_at     = datetime('now')
                """, (municipio_id, int(ano_mais_recente), tipo, r.get("dim_3"), r.get("dim_3_t"), valor))
                gravados += 1
            except Exception as e:
                log.warning("  %s/%s: %s", nome, r.get("dim_3"), e)
                erros += 1

        db.commit()

    log.info("✓ Finanças: %d registos gravados, %d erros", gravados, erros)
    log_etl(db, "INE_financas", gravados, erros)


MAI_BASE = "https://www.eleicoes.mai.gov.pt/autarquicas2025/assets/static"
MAI_ANO = 2025
ORGAO_CAMARA_MUNICIPAL = 4  # id do órgão "Câmara Municipal" na API do MAI


def fetch_mai(path):
    r = requests.get(f"{MAI_BASE}/{path}", headers={"User-Agent": "Mozilla/5.0"}, timeout=30)
    r.raise_for_status()
    return r.json()["data"]


def importar_eleicoes(db):
    log.info("── Importar Eleições Autárquicas %d (Câmara Municipal) ─", MAI_ANO)
    municipio_ids = dict(db.execute("SELECT nome, id FROM municipios").fetchall())

    # Nível 1: território nacional -> distritos/regiões autónomas
    distritos = fetch_mai("territory/children-electionId=1-territoryId=1.json")
    log.info("  %d distritos/regiões", len(distritos))

    gravados = erros = sem_match = 0
    for idx, distrito in enumerate(distritos, 1):
        try:
            concelhos = fetch_mai(f"territory/children-electionId=1-territoryId={distrito['id']}.json")
        except Exception as e:
            log.warning("  Distrito %s: %s", distrito.get("descriptionShort"), e)
            erros += 1
            continue

        for concelho in concelhos:
            nome = concelho.get("descriptionShort", "").strip()
            municipio_id = municipio_ids.get(nome)
            if not municipio_id:
                sem_match += 1
                continue
            try:
                resultado = fetch_mai(
                    f"result/territory-electionId=1-territoryId={concelho['id']}-organId={ORGAO_CAMARA_MUNICIPAL}.json"
                )
            except Exception as e:
                log.warning("  %s: %s", nome, e)
                erros += 1
                continue

            partidos = resultado.get("currentResults", {}).get("resultsParty", [])
            for p in partidos:
                try:
                    db.execute("""
                        INSERT INTO municipio_eleicoes
                            (municipio_id,ano,partido_sigla,votos,percentagem,mandatos,presidente_eleito,updated_at)
                        VALUES(?,?,?,?,?,?,?,datetime('now'))
                        ON CONFLICT(municipio_id,ano,partido_sigla) DO UPDATE SET
                            votos=excluded.votos, percentagem=excluded.percentagem,
                            mandatos=excluded.mandatos, presidente_eleito=excluded.presidente_eleito,
                            updated_at=datetime('now')
                    """, (municipio_id, MAI_ANO, p.get("acronym"), p.get("votes"),
                          p.get("percentage"), p.get("mandates"), 1 if p.get("presidents") else 0))
                    gravados += 1
                    if p.get("presidents"):
                        db.execute("UPDATE municipios SET presidente_partido=? WHERE id=?",
                                   (p.get("acronym"), municipio_id))
                except Exception as e:
                    log.warning("  %s/%s: %s", nome, p.get("acronym"), e)
                    erros += 1
        db.commit()
        log.info("  %d/%d distritos processados", idx, len(distritos))

    log.info("✓ Eleições: %d resultados gravados, %d municípios sem match, %d erros",
              gravados, sem_match, erros)
    log_etl(db, "MAI_eleicoes", gravados, erros)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--all", action="store_true")
    p.add_argument("--financas", action="store_true")
    p.add_argument("--eleicoes", action="store_true")
    args = p.parse_args()

    if not any(vars(args).values()):
        p.print_help()
        sys.exit(0)

    db = get_db()
    init_db(db)
    try:
        if args.all or args.financas:
            importar_financas(db)
        if args.all or args.eleicoes:
            importar_eleicoes(db)
    finally:
        db.close()
    log.info("✅ Concluído.")


if __name__ == "__main__":
    main()
