-- =============================================================
-- Transparência Política PT — schema.sql
-- SQLite com WAL mode
-- =============================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- -------------------------------------------------------------
-- Tabelas de referência / base
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS legislaturas (
    id          INTEGER PRIMARY KEY,
    numero      TEXT    NOT NULL,           -- ex: "XVII"
    inicio      DATE,
    fim         DATE,
    activa      INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS grupos_parlamentares (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sigla       TEXT    NOT NULL UNIQUE,    -- PS, PSD, CH, IL, BE, PCP, PAN, L
    nome        TEXT    NOT NULL,
    cor_hex     TEXT,                       -- #E63946
    legislatura_id INTEGER REFERENCES legislaturas(id)
);

CREATE TABLE IF NOT EXISTS circulos_eleitorais (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nome        TEXT    NOT NULL UNIQUE,
    codigo      TEXT
);

-- -------------------------------------------------------------
-- Deputados
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS deputados (
    id              INTEGER PRIMARY KEY,    -- BID da AR
    nome_completo   TEXT    NOT NULL,
    nome_curto      TEXT,
    genero          TEXT    CHECK(genero IN ('M','F',NULL)),
    data_nascimento DATE,
    profissao       TEXT,
    gp_sigla        TEXT    REFERENCES grupos_parlamentares(sigla),
    circulo_id      INTEGER REFERENCES circulos_eleitorais(id),
    legislatura_id  INTEGER REFERENCES legislaturas(id),
    activo          INTEGER NOT NULL DEFAULT 1,
    nif             TEXT,                   -- para cruzar com Base
    url_foto        TEXT,
    url_parlamento  TEXT,
    bio_resumo      TEXT,
    mandatos_json   TEXT,                   -- JSON blob com histórico
    cargos_json     TEXT,                   -- cargos actuais/passados
    updated_at      DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_deputados_gp      ON deputados(gp_sigla);
CREATE INDEX IF NOT EXISTS idx_deputados_leg     ON deputados(legislatura_id);
CREATE INDEX IF NOT EXISTS idx_deputados_activo  ON deputados(activo);

-- -------------------------------------------------------------
-- Sessões plenárias
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sessoes_plenarias (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    data            DATE    NOT NULL UNIQUE,
    numero          TEXT,
    sessao_leg      TEXT,                   -- ex: "1SL"
    legislatura_id  INTEGER REFERENCES legislaturas(id),
    tipo            TEXT    DEFAULT 'ordinaria'
);

CREATE INDEX IF NOT EXISTS idx_sessoes_data ON sessoes_plenarias(data);

-- -------------------------------------------------------------
-- Presenças
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS presencas (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    dep_id          INTEGER NOT NULL REFERENCES deputados(id),
    sessao_id       INTEGER NOT NULL REFERENCES sessoes_plenarias(id),
    estado          TEXT    NOT NULL CHECK(estado IN ('P','F','FJ','E')),
                    -- P=presente F=falta FJ=falta justificada E=escusa
    UNIQUE(dep_id, sessao_id)
);

CREATE INDEX IF NOT EXISTS idx_presencas_dep    ON presencas(dep_id);
CREATE INDEX IF NOT EXISTS idx_presencas_sessao ON presencas(sessao_id);

-- -------------------------------------------------------------
-- Iniciativas legislativas
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS iniciativas (
    id              INTEGER PRIMARY KEY,    -- BID da AR
    tipo            TEXT    NOT NULL,       -- PJL, PPL, PJR, etc.
    numero          TEXT,
    titulo          TEXT    NOT NULL,
    descricao       TEXT,
    data_entrada    DATE,
    data_aprovacao  DATE,
    estado          TEXT,
    resultado       TEXT,                   -- Aprovado, Rejeitado, Caducado
    resultado_checked_at DATETIME,          -- quando foi verificado (resultado pode ficar NULL = ainda em tramitação)
    legislatura_id  INTEGER REFERENCES legislaturas(id),
    url_ar          TEXT,
    autoria_gp      TEXT,                   -- siglas dos GP autores, ex: "PCP, L, BE"
    updated_at      DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_iniciativas_tipo  ON iniciativas(tipo);
CREATE INDEX IF NOT EXISTS idx_iniciativas_data  ON iniciativas(data_entrada);
CREATE INDEX IF NOT EXISTS idx_iniciativas_leg   ON iniciativas(legislatura_id);

-- autores de iniciativas (N:M)
CREATE TABLE IF NOT EXISTS iniciativas_autores (
    iniciativa_id   INTEGER NOT NULL REFERENCES iniciativas(id),
    dep_id          INTEGER NOT NULL REFERENCES deputados(id),
    PRIMARY KEY (iniciativa_id, dep_id)
);

CREATE INDEX IF NOT EXISTS idx_ini_autores_dep ON iniciativas_autores(dep_id);

-- -------------------------------------------------------------
-- Ofertas, Deslocações e Hospitalidades (registo público, distinto
-- do Registo de Interesses — sem restrição legal de reprodução)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ofertas_hospitalidades (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    dep_id          INTEGER NOT NULL REFERENCES deputados(id),
    categoria       TEXT    NOT NULL CHECK(categoria IN ('Ofertas','Deslocações','Hospitalidades')),
    descricao       TEXT,
    valor           TEXT,                   -- só "Ofertas" (texto p/ preservar "100,00 €")
    local           TEXT,                   -- só Deslocações/Hospitalidades
    ofertante       TEXT,
    representacao   TEXT,
    data_registo    TEXT,
    duracao         TEXT,
    destino_final   TEXT,                   -- só "Ofertas" (ex: "Devolução ao Deputado")
    updated_at      DATETIME DEFAULT (datetime('now')),
    UNIQUE(dep_id, categoria, descricao, data_registo)
);

CREATE INDEX IF NOT EXISTS idx_ofertas_dep ON ofertas_hospitalidades(dep_id);

-- Rastreio de quando cada deputado foi verificado (não quando teve o último
-- registo — precisa de existir mesmo para quem nunca teve nada) para que o
-- ETL seja incremental: só reverifica quem está desactualizado, não todos.
CREATE TABLE IF NOT EXISTS ofertas_check (
    dep_id      INTEGER PRIMARY KEY REFERENCES deputados(id),
    checked_at  DATETIME NOT NULL
);

-- -------------------------------------------------------------
-- Contratos públicos (Portal Base)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS contratos_base (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    numero_contrato TEXT,
    objeto          TEXT,
    valor           REAL,
    data_publicacao DATE,
    entidade_nif    TEXT,                   -- NIF do adjudicante
    adjudicatario   TEXT,
    adjudicatario_nif TEXT,
    tipo_procedimento TEXT,
    cpv             TEXT,
    dep_id          INTEGER REFERENCES deputados(id), -- se NIF cruzar
    updated_at      DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_contratos_nif    ON contratos_base(entidade_nif);
CREATE INDEX IF NOT EXISTS idx_contratos_dep    ON contratos_base(dep_id);
CREATE INDEX IF NOT EXISTS idx_contratos_data   ON contratos_base(data_publicacao);

-- -------------------------------------------------------------
-- Scores (calculados pelo ETL, guardados para performance)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS scores (
    dep_id          INTEGER PRIMARY KEY REFERENCES deputados(id),
    legislatura_id  INTEGER REFERENCES legislaturas(id),
    score_total     REAL    DEFAULT 0,      -- 0–100
    score_presenca  REAL    DEFAULT 0,      -- peso 40%
    score_iniciativas REAL  DEFAULT 0,      -- peso 30%
    score_contratos REAL    DEFAULT 0,      -- peso 30%
    -- componentes brutos
    taxa_presenca   REAL,                   -- 0.0–1.0
    n_iniciativas   INTEGER DEFAULT 0,
    n_contratos     INTEGER DEFAULT 0,
    valor_contratos REAL    DEFAULT 0,
    -- percentis para ranking
    percentil_presenca   REAL,
    percentil_iniciativas REAL,
    percentil_contratos  REAL,
    rank_geral      INTEGER,
    rank_gp         INTEGER,
    updated_at      DATETIME DEFAULT (datetime('now'))
);

-- -------------------------------------------------------------
-- Declarações de rendimentos (Entidade da Transparência — PDFs)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS declaracoes_rendimentos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    dep_id          INTEGER NOT NULL REFERENCES deputados(id),
    ano             INTEGER NOT NULL,
    tipo            TEXT,                   -- "mandato", "cargo"
    rendimento_total REAL,
    patrimonio_json TEXT,                   -- bens declarados
    url_pdf         TEXT,
    extraido_em     DATETIME,
    UNIQUE(dep_id, ano, tipo)
);

-- -------------------------------------------------------------
-- Cache genérica (key-value com TTL)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cache (
    cache_key   TEXT    PRIMARY KEY,
    valor       TEXT    NOT NULL,           -- JSON serializado
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_cache_expires ON cache(expires_at);

-- -------------------------------------------------------------
-- Log de importações (auditoria ETL)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS etl_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    fonte       TEXT    NOT NULL,           -- "AR_deputados", "AR_presencas", etc.
    iniciado_em DATETIME NOT NULL,
    concluido_em DATETIME,
    registos    INTEGER DEFAULT 0,
    erros       INTEGER DEFAULT 0,
    detalhes    TEXT                        -- JSON com stats/erros
);

-- -------------------------------------------------------------
-- Dados iniciais: legislatura actual (XVII)
-- -------------------------------------------------------------

INSERT OR IGNORE INTO legislaturas (id, numero, inicio, activa)
VALUES (17, 'XVII', '2024-03-10', 1);

INSERT OR IGNORE INTO grupos_parlamentares (sigla, nome, cor_hex, legislatura_id) VALUES
    ('AD',   'Aliança Democrática',       '#F97316', 17),
    ('PS',   'Partido Socialista',        '#E63946', 17),
    ('CH',   'Chega',                     '#1D3557', 17),
    ('IL',   'Iniciativa Liberal',        '#06B6D4', 17),
    ('BE',   'Bloco de Esquerda',         '#DC2626', 17),
    ('PCP',  'Partido Comunista Português','#B91C1C', 17),
    ('PAN',  'Pessoas-Animais-Natureza',  '#16A34A', 17),
    ('L',    'Livre',                     '#059669', 17);
