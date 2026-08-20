-- =============================================================
-- Autarquias PT — schema.sql
-- Município e freguesia. BD separada da AR (autarquias_pt.db) —
-- domínios de dados diferentes, sem sobreposição com deputados/
-- iniciativas.
-- =============================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS distritos (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    nome    TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS municipios (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    geocod              TEXT NOT NULL UNIQUE,   -- código geográfico INE, ex "1701511"
    nome                TEXT NOT NULL,
    distrito_id         INTEGER REFERENCES distritos(id),
    presidente_camara    TEXT,
    presidente_partido   TEXT,
    populacao           INTEGER,
    area_km2            REAL,
    url_site            TEXT,
    updated_at          DATETIME DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_municipios_distrito ON municipios(distrito_id);

-- Indicadores financeiros por município/ano/categoria — fonte: INE
-- (indicadores "Despesas/Receitas das câmaras municipais", DGAL).
-- Dados anuais, tipicamente com 3-4 anos de atraso (fonte oficial mais
-- recente disponível, não é "ao vivo").
CREATE TABLE IF NOT EXISTS municipio_financas (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    municipio_id    INTEGER NOT NULL REFERENCES municipios(id),
    ano             INTEGER NOT NULL,
    tipo            TEXT    NOT NULL CHECK(tipo IN ('despesa','receita')),
    categoria_cod   TEXT,                        -- ex "D11", "D2"
    categoria_nome  TEXT,                         -- ex "Despesas com pessoal"
    valor_milhares  REAL,                         -- em milhares de €, conforme a fonte INE
    updated_at      DATETIME DEFAULT (datetime('now')),
    UNIQUE(municipio_id, ano, tipo, categoria_cod)
);
CREATE INDEX IF NOT EXISTS idx_mfin_municipio ON municipio_financas(municipio_id);
CREATE INDEX IF NOT EXISTS idx_mfin_ano ON municipio_financas(ano);

-- Contratos públicos cruzados do Portal Base por entidade adjudicante
-- (câmara municipal) — mesma fonte usada no politica-pt.
CREATE TABLE IF NOT EXISTS municipio_contratos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    municipio_id    INTEGER NOT NULL REFERENCES municipios(id),
    objecto         TEXT,
    adjudicatario    TEXT,
    valor           REAL,
    data_publicacao DATE,
    url_base        TEXT,
    updated_at      DATETIME DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_mcont_municipio ON municipio_contratos(municipio_id);

-- Freguesias — nível mais granular, sem fonte nacional agregada
-- conhecida; populado caso-a-caso (piloto: Quinta do Conde/Sesimbra).
CREATE TABLE IF NOT EXISTS freguesias (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    municipio_id        INTEGER NOT NULL REFERENCES municipios(id),
    nome                TEXT NOT NULL,
    presidente_junta     TEXT,
    presidente_partido   TEXT,
    populacao           INTEGER,
    url_site            TEXT,
    fonte_dados         TEXT,                     -- nota sobre de onde vieram os dados (caso a caso)
    updated_at          DATETIME DEFAULT (datetime('now')),
    UNIQUE(municipio_id, nome)
);
CREATE INDEX IF NOT EXISTS idx_freguesias_municipio ON freguesias(municipio_id);

CREATE TABLE IF NOT EXISTS etl_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    fonte       TEXT    NOT NULL,
    iniciado_em DATETIME NOT NULL,
    concluido_em DATETIME,
    registos    INTEGER DEFAULT 0,
    erros       INTEGER DEFAULT 0,
    detalhes    TEXT
);

CREATE TABLE IF NOT EXISTS cache (
    cache_key   TEXT PRIMARY KEY,
    valor       TEXT,
    expires_at  DATETIME
);
