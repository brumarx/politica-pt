# Transparência Política PT

Dashboard de transparência política da Assembleia da República.

## Stack

| Camada | Tecnologia |
|--------|-----------|
| Servidor | Ubuntu 24 · Apache |
| BD | SQLite (WAL mode) |
| ETL | Python 3.12 |
| Frontend | PHP 8 · CSS custom · JS vanilla |
| Fontes | DM Sans + Instrument Serif |

## Estrutura

```
transparencia-pt/
├── schema.sql          # Estrutura da BD SQLite
├── importar_ar.py      # ETL principal (AR + Base)
├── dashboard.php       # Frontend
├── requirements.txt    # Dependências Python
└── transparencia_pt.db # BD (criada automaticamente)
```

## Instalação

```bash
# Dependências Python
pip install -r requirements.txt

# Inicializar e importar (primeira vez)
python importar_ar.py --test         # testar endpoints AR
python importar_ar.py --all          # importar tudo

# Só deputados (mais rápido)
python importar_ar.py --deputados

# Com enriquecimento biográfico (mais lento)
python importar_ar.py --deputados --enrich
```

## ETL — Comandos

```bash
python importar_ar.py --all            # importar tudo + scores
python importar_ar.py --deputados      # só deputados
python importar_ar.py --presencas      # só presenças
python importar_ar.py --iniciativas    # só iniciativas
python importar_ar.py --scores         # recalcular scores
python importar_ar.py --test           # testar endpoints sem gravar
```

## Cron (actualização diária)

```cron
0 6 * * * cd /var/www/transparencia-pt && python importar_ar.py --all >> /var/log/etl.log 2>&1
```

## Score de Transparência

| Componente | Peso | Fonte |
|-----------|------|-------|
| Presença em Plenário | 40% | AR — presenças |
| Iniciativas Legislativas | 30% | AR — iniciativas |
| Contratos Públicos (NIF) | 30% | Portal Base |

Score de 0–100. Mais contratos associados ao NIF do deputado = score mais baixo.

## Fontes de Dados

- **AR** — `app.parlamento.pt/webutils/` (JSON/XML) + scraping HTML
- **Portal Base** — `api.base.gov.pt` (contratos por NIF)
- **Entidade da Transparência** — PDFs com `pdfplumber` (scrapers separados)
- **CNE** — CSVs eleitorais

## Padrões de Código

- `array_values()` em todos os `execute($params)` no PHP
- `cache_get/cache_set` com TTL (1h padrão, 24h estático)
- Queries com JOIN: sempre alias de tabela
- Filtros com último ano/legislatura por defeito
- ETL com `ThreadPoolExecutor` onde possível
