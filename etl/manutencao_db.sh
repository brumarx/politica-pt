#!/usr/bin/env bash
set -euo pipefail
DB="/var/www/transparencia-pt/transparencia_pt.db"
echo "[$(date)] Limpeza de cache expirado..."
sqlite3 "$DB" "DELETE FROM cache WHERE expires_at < datetime('now');"
echo "[$(date)] VACUUM..."
sqlite3 "$DB" "VACUUM;"
echo "[$(date)] WAL checkpoint..."
sqlite3 "$DB" "PRAGMA wal_checkpoint(TRUNCATE);"
echo "[$(date)] Manutenção concluída."
