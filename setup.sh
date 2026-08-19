#!/usr/bin/env bash
# =============================================================================
# setup.sh — Transparência Política PT
# ETL: importa deputados, presenças, iniciativas, biográfico e scores
#
# Uso:
#   chmod +x setup.sh
#   ./setup.sh              # importação completa
#   ./setup.sh --test       # só testar endpoints
#   ./setup.sh --deputados
#   ./setup.sh --presencas
#   ./setup.sh --iniciativas
#   ./setup.sh --scores
#   ./setup.sh --biografico
#   ./setup.sh --declaracoes
# =============================================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

log()  { echo -e "${BLUE}▶${NC} $*"; }
ok()   { echo -e "${GREEN}✓${NC} $*"; }
warn() { echo -e "${YELLOW}⚠${NC}  $*"; }
err()  { echo -e "${RED}✗${NC} $*" >&2; exit 1; }
hdr()  { echo -e "\n${BOLD}${CYAN}── $* ${NC}"; }

# ─── Config ──────────────────────────────────────────────────────────────────
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV_DIR="$APP_DIR/venv"
DB_FILE="$APP_DIR/transparencia_pt.db"
PYTHON="$VENV_DIR/bin/python"
LOG_DIR="$APP_DIR/logs"
LOG_FILE="$LOG_DIR/etl_$(date +%Y%m%d_%H%M%S).log"

# ─── Verificações ────────────────────────────────────────────────────────────
hdr "Verificações"

# Testar ligação ao parlamento.pt
PARL_TEST=$(curl -s --max-time 15 --compressed \
    -H "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36" \
    "https://www.parlamento.pt/DeputadoGP/Paginas/Deputadoslista.aspx" 2>/dev/null | wc -c)
if [[ "$PARL_TEST" -gt 1000 ]]; then
    ok "parlamento.pt acessível ($PARL_TEST bytes)"
else
    warn "parlamento.pt inacessível — ETL pode falhar"
fi

# Criar directório de logs e garantir permissões correctas
mkdir -p "$LOG_DIR"
chmod 775 "$LOG_DIR" 2>/dev/null || true
chown -f "$(stat -c '%U' "$APP_DIR")":www-data "$LOG_DIR" 2>/dev/null || true

[[ -f "$APP_DIR/importar_ar.py" ]]         || err "importar_ar.py não encontrado em $APP_DIR"
[[ -f "$APP_DIR/importar_biografico.py" ]] || warn "importar_biografico.py não encontrado"
[[ -f "$APP_DIR/importar_declaracoes.py" ]]|| warn "importar_declaracoes.py não encontrado"

# Python: usar venv se existir, senão criar
if [[ -f "$PYTHON" ]]; then
    ok "venv: $PYTHON"
else
    warn "venv não encontrado — a criar em $VENV_DIR"
    python3 -m venv "$VENV_DIR"
    "$VENV_DIR/bin/pip" install --upgrade pip --quiet
    if [[ -f "$APP_DIR/requirements.txt" ]]; then
        "$VENV_DIR/bin/pip" install -r "$APP_DIR/requirements.txt" --quiet
    else
        "$VENV_DIR/bin/pip" install --quiet requests beautifulsoup4 pandas pdfplumber lxml playwright
        "$VENV_DIR/bin/playwright" install chromium --quiet
    fi
    ok "venv criado"
fi

PY_VER=$("$PYTHON" --version 2>&1)
ok "$PY_VER"

# BD: inicializar se não existir
if [[ ! -s "$DB_FILE" ]]; then
    log "BD não existe — a inicializar..."
    [[ -f "$APP_DIR/schema.sql" ]] || err "schema.sql não encontrado"
    sqlite3 "$DB_FILE" < "$APP_DIR/schema.sql"
    sqlite3 "$DB_FILE" "PRAGMA journal_mode=WAL;" > /dev/null
    ok "BD criada: $DB_FILE"
else
    SIZE=$(du -h "$DB_FILE" | cut -f1)
    ok "BD existente: $DB_FILE ($SIZE)"
fi

# Permissões da BD (Apache precisa de escrever)
chmod 664 "$DB_FILE" 2>/dev/null || true
chown -f "$(stat -c '%U' "$APP_DIR")":www-data "$DB_FILE" 2>/dev/null || true

# ─── Arg parsing ─────────────────────────────────────────────────────────────
CMD="${1:---all}"

hdr "ETL: $CMD"
log "Log: $LOG_FILE"
log "Dir: $APP_DIR"
echo

# Wrapper para log sem erros de permissão
run() {
    "$@" 2>&1 | tee -a "$LOG_FILE" || true
}

# ─── Correr ETL ──────────────────────────────────────────────────────────────
cd "$APP_DIR"

case "$CMD" in
    --all)
        log "Deputados..."
        run "$PYTHON" importar_ar.py --deputados
        echo

        log "Biográfico (GP, profissão, habilitações)..."
        if [[ -f "$APP_DIR/importar_biografico.py" ]]; then
            run "$PYTHON" importar_biografico.py
        else
            warn "importar_biografico.py ausente — salto"
        fi
        echo

        log "Iniciativas..."
        run "$PYTHON" importar_ar.py --iniciativas
        echo

        log "Presenças (mais lento)..."
        run "$PYTHON" importar_ar.py --presencas
        echo

        log "Scores..."
        run "$PYTHON" importar_ar.py --scores
        echo

        log "A limpar cache..."
        sqlite3 "$DB_FILE" "DELETE FROM cache;" 2>/dev/null || true
        ok "Cache limpa"
        ;;

    --biografico)
        if [[ -f "$APP_DIR/importar_biografico.py" ]]; then
            run "$PYTHON" importar_biografico.py
        else
            err "importar_biografico.py não encontrado"
        fi
        ;;

    --declaracoes)
        if [[ -f "$APP_DIR/importar_declaracoes.py" ]]; then
            run "$PYTHON" importar_declaracoes.py --all
        else
            err "importar_declaracoes.py não encontrado"
        fi
        ;;

    --test|--deputados|--presencas|--iniciativas|--scores)
        run "$PYTHON" importar_ar.py "$CMD"
        ;;

    --cache)
        sqlite3 "$DB_FILE" "DELETE FROM cache;"
        ok "Cache limpa"
        ;;

    *)
        echo "Uso: ./setup.sh [--all|--test|--deputados|--presencas|--iniciativas|--scores|--biografico|--declaracoes|--cache]"
        exit 1
        ;;
esac

# ─── Sumário ─────────────────────────────────────────────────────────────────
echo
hdr "Resultado"

DEP=$(sqlite3  "$DB_FILE" "SELECT COUNT(*) FROM deputados;"              2>/dev/null || echo 0)
INI=$(sqlite3  "$DB_FILE" "SELECT COUNT(*) FROM iniciativas;"            2>/dev/null || echo 0)
PRE=$(sqlite3  "$DB_FILE" "SELECT COUNT(*) FROM presencas;"              2>/dev/null || echo 0)
SCO=$(sqlite3  "$DB_FILE" "SELECT COUNT(*) FROM scores;"                 2>/dev/null || echo 0)
DECL=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM declaracoes_rendimentos;" 2>/dev/null || echo 0)
PERF=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM perfis_deputados;"       2>/dev/null || echo 0)

echo -e "  Deputados:   ${BOLD}$DEP${NC}"
echo -e "  Iniciativas: ${BOLD}$INI${NC}"
echo -e "  Presenças:   ${BOLD}$PRE${NC}"
echo -e "  Scores:      ${BOLD}$SCO${NC}"
echo -e "  Perfis bio:  ${BOLD}$PERF${NC}"
echo -e "  Declarações: ${BOLD}$DECL${NC}"
echo -e "  Log:         $LOG_FILE"
echo
ok "Concluído."
