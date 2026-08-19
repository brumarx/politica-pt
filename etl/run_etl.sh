#!/usr/bin/env bash
set -euo pipefail
APP_DIR="/var/www/transparencia-pt"
VENV="/var/www/transparencia-pt/venv"
LOG_DIR="$APP_DIR/logs"
LOG_FILE="$LOG_DIR/etl_$(date +%Y%m%d_%H%M%S).log"
mkdir -p "$LOG_DIR"
CMD="${1:---all}"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] A iniciar ETL: $CMD" | tee -a "$LOG_FILE"
cd "$APP_DIR"
"$VENV/bin/python" importar_ar.py $CMD 2>&1 | tee -a "$LOG_FILE"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] ETL concluído" | tee -a "$LOG_FILE"
# Repor permissões após escrita
chown www-data:www-data "$APP_DIR/transparencia_pt.db" 2>/dev/null || true
chmod 664 "$APP_DIR/transparencia_pt.db" 2>/dev/null || true
