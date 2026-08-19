#!/bin/bash
# Corre importar_ar.py --iniciativas repetidamente até atingir ~2114 registos
# ou esgotar as tentativas. O site parlamento.pt tem timeouts (60s) frequentes
# a partir de certas páginas; o script já grava progresso parcial a cada corrida
# (skip_existing incremental), isto só automatiza o "correr outra vez".
cd /var/www/html/politica-pt
ALVO=2100
TENTATIVAS=15

for i in $(seq 1 $TENTATIVAS); do
    n=$(sqlite3 transparencia_pt.db "SELECT COUNT(*) FROM iniciativas;")
    echo "[auto_retry] tentativa $i — $n/$ALVO na BD"
    if [ "$n" -ge "$ALVO" ]; then
        echo "[auto_retry] alvo atingido ($n) — a parar"
        break
    fi
    python3 importar_ar.py --iniciativas >> logs/iniciativas_auto_retry.log 2>&1
    sleep 3
done

n_final=$(sqlite3 transparencia_pt.db "SELECT COUNT(*) FROM iniciativas;")
echo "[auto_retry] terminado — $n_final registos na BD"
