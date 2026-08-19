#!/usr/bin/env python3
"""
importar_declaracoes.py — ETL Declarações de Rendimentos e Património
Fonte: Entidade da Transparência (PDFs scraping)

Uso:
    python importar_declaracoes.py --all
    python importar_declaracoes.py --ano 2024
    python importar_declaracoes.py --dep-id 123
"""

import argparse
import json
import logging
import re
import sqlite3
import sys
import time
from datetime import datetime
from pathlib import Path
from typing import Optional, Dict, List, Any
from urllib.parse import urljoin

import requests
import pdfplumber
from bs4 import BeautifulSoup

# ─── Config ───────────────────────────────────────────────────────────────────

DB_PATH    = Path(__file__).parent / "transparencia_pt.db"
BASE_URL   = "https://www.entidadetransparencia.pt"
AR_BASE    = "https://www.parlamento.pt"

# URLs alternativas caso principal não funcione
ET_FALLBACK = "https://transparencia.gov.pt"
AR_FALLBACK = "https://www.parlamento.pt"

TIMEOUT     = 30
DELAY       = 2.0  # entre requests

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger("importar_declaracoes")

# ─── BD ───────────────────────────────────────────────────────────────────────

def get_db():
    db = sqlite3.connect(DB_PATH, timeout=30, check_same_thread=False)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA journal_mode=WAL")
    db.execute("PRAGMA foreign_keys=ON")
    return db

# ─── Helpers ─────────────────────────────────────────────────────────────────

def clean_text(text: str) -> str:
    """Limpa texto extraído de PDFs"""
    if not text:
        return ""
    return re.sub(r'\s+', ' ', text.strip())

def parse_monetary(value: str) -> float:
    """Converte valor monetário para float"""
    if not value:
        return 0.0
    # Remove símbolos e espaços
    clean = re.sub(r'[€\s\.]', '', value.replace(',', '.'))
    try:
        return float(clean)
    except ValueError:
        return 0.0

# ─── Entidade da Transparência ───────────────────────────────────────────────

def get_deputados_from_db() -> List[Dict]:
    """Busca deputados activos na BD"""
    db = get_db()
    deps = db.execute("""
        SELECT id, nome_completo, nome_curto, nif, gp_sigla, url_parlamento 
        FROM deputados 
        WHERE activo=1 AND legislatura_id=17
        ORDER BY nome_curto
    """).fetchall()
    return [dict(dep) for dep in deps]

def search_declaracao_et(dep_name: str, year: int) -> Optional[str]:
    """Procura declaração na Entidade da Transparência e AR"""
    
    # A página do AR tem o conteúdo mas os PDFs podem estar em outra secção
    urls_to_try = [
        f"{AR_BASE}/RegistoInteresses/Paginas/deputados-e-membrosgoverno.aspx",
        # Tenta também páginas de deputados individuais
        f"{AR_BASE}/DeputadoGP/Paginas/Biografia.aspx",
        # Página de busca do AR
        f"{AR_BASE}/Pages/Pesquisa.aspx"
    ]
    
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'pt-PT,pt;q=0.9',
        'Connection': 'keep-alive',
    }
    
    for search_url in urls_to_try:
        try:
            log.info(f"Tentando URL: {search_url}")
            response = requests.get(search_url, headers=headers, timeout=TIMEOUT)
            response.raise_for_status()
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Procura todos os links na página (não só PDFs)
            all_links = []
            for link in soup.find_all('a', href=True):
                href = link.get('href', '')
                text = link.get_text().strip()
                
                all_links.append({
                    'url': urljoin(search_url, href),
                    'text': text,
                    'href': href
                })
            
            log.info(f"Encontrados {len(all_links)} links na página")
            
            # Procura links que mencionam o deputado
            for link in all_links:
                text_lower = link['text'].lower()
                dep_lower = dep_name.lower()
                
                # Correspondência mais flexível
                if (dep_lower in text_lower or 
                    any(word in text_lower for word in dep_lower.split() if len(word) > 2)):
                    
                    log.info(f"Link encontrado para {dep_name}: {link['text']} -> {link['url']}")
                    
                    # Se for PDF, retorna diretamente
                    if link['href'].endswith('.pdf'):
                        return link['url']
                    
                    # Se for página, tenta extrair PDFs de lá
                    if 'aspx' in link['href'] or 'Biografia' in link['text']:
                        pdf_url = extract_pdf_from_deputado_page(link['url'], dep_name, year)
                        if pdf_url:
                            return pdf_url
            
            # Se não encontrou por nome, mostra alguns links para debug
            if all_links:
                log.info(f"Primeiros links encontrados: {[l['text'] for l in all_links[:5]]}")
            
        except Exception as e:
            log.warning(f"Erro na URL {search_url}: {e}")
            continue
    
    return None

def extract_pdf_from_deputado_page(page_url: str, dep_name: str, year: int) -> Optional[str]:
    """Extrai PDFs da página de um deputado específico"""
    try:
        headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        }
        
        response = requests.get(page_url, headers=headers, timeout=TIMEOUT)
        response.raise_for_status()
        
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Procura PDFs nesta página
        for link in soup.find_all('a', href=True):
            href = link.get('href', '')
            text = link.get_text().strip().lower()
            
            if href.endswith('.pdf'):
                if ('declarac' in text or 'rendimento' in text or 'patrimonio' in text or
                    str(year) in text or dep_name.lower() in text):
                    full_url = urljoin(page_url, href)
                    log.info(f"PDF encontrado na página do deputado: {full_url}")
                    return full_url
        
        return None
        
    except Exception as e:
        log.error(f"Erro a extrair PDF da página {page_url}: {e}")
        return None

def extract_pdf_data(pdf_url: str) -> Dict[str, Any]:
    """Extrai dados do PDF da declaração"""
    try:
        headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        }
        
        response = requests.get(pdf_url, headers=headers, timeout=TIMEOUT)
        response.raise_for_status()
        
        # Salva PDF temporariamente
        import tempfile
        with tempfile.NamedTemporaryFile(suffix='.pdf', delete=False) as tmp:
            tmp.write(response.content)
            tmp_path = tmp.name
        
        # Extrai texto com pdfplumber
        data = {
            'rendimento_total': 0.0,
            'imoveis': [],
            'veiculos': [],
            'aplicacoes': [],
            'actividades': []
        }
        
        with pdfplumber.open(tmp_path) as pdf:
            for page in pdf.pages:
                text = page.extract_text()
                if text:
                    # Procura padrões específicos
                    lines = text.split('\n')
                    
                    for line in lines:
                        line = clean_text(line)
                        
                        # Rendimento total
                        if any(keyword in line.lower() for keyword in ['rendimento total:', 'total de rendimentos:', 'vencimento:']):
                            valor_match = re.search(r'(\d+[.,]\d+)', line)
                            if valor_match:
                                data['rendimento_total'] = parse_monetary(valor_match.group(1))
                        
                        # Imóveis
                        if any(keyword in line.lower() for keyword in ['imóvel', 'apartamento', 'moradia', 'casa']):
                            # Procura valor na mesma linha ou linhas seguintes
                            valor_match = re.search(r'(\d+[.,]\d+)\s*€', line)
                            if valor_match:
                                data['imoveis'].append({
                                    'tipo': 'Imóvel',
                                    'valor': parse_monetary(valor_match.group(1))
                                })
                        
                        # Veículos
                        if any(keyword in line.lower() for keyword in ['viatura', 'veículo', 'carro', 'automóvel']):
                            marca_match = re.search(r'(Toyota|BMW|Mercedes|Volvo|Renault|Peugeot|Volkswagen|Seat|Nissan|Hyundai)', line, re.IGNORECASE)
                            valor_match = re.search(r'(\d+[.,]\d+)\s*€', line)
                            
                            if marca_match and valor_match:
                                data['veiculos'].append({
                                    'marca': marca_match.group(1),
                                    'valor': parse_monetary(valor_match.group(1))
                                })
        
        # Limpa arquivo temporário
        Path(tmp_path).unlink(missing_ok=True)
        
        return data
        
    except Exception as e:
        log.error(f"Erro a extrair dados do PDF {pdf_url}: {e}")
        return {}

# ─── Importação ───────────────────────────────────────────────────────────────

def import_declaracao(dep_id: int, dep_data: Dict, year: int, tipo: str = 'mandato') -> bool:
    """Importa declaração para um deputado"""
    
    # Tenta procurar PDF na página específica do deputado primeiro
    if dep_data.get('url_parlamento'):
        dep_page_url = dep_data['url_parlamento']
        log.info(f"Tentando página específica do deputado: {dep_page_url}")
        
        pdf_url = extract_pdf_from_deputado_page(dep_page_url, dep_data['nome_curto'], year)
        if pdf_url:
            return process_and_save_declaracao(dep_id, dep_data, year, tipo, pdf_url)
    
    # Se não encontrou, tenta procura geral
    pdf_url = search_declaracao_et(dep_data['nome_curto'], year)
    
    if not pdf_url:
        log.warning(f"PDF não encontrado para {dep_data['nome_curto']} ({year})")
        return False
    
    return process_and_save_declaracao(dep_id, dep_data, year, tipo, pdf_url)

def process_and_save_declaracao(dep_id: int, dep_data: Dict, year: int, tipo: str, pdf_url: str) -> bool:
    """Processa PDF e guarda na BD"""
    try:
        # Extrai dados do PDF
        pdf_data = extract_pdf_data(pdf_url)
        
        if not pdf_data:
            log.warning(f"Não foi possível extrair dados do PDF para {dep_data['nome_curto']} ({year})")
            # Ainda assim guarda com dados mínimos
            pdf_data = {
                'rendimento_total': 0.0,
                'imoveis': [],
                'veiculos': [],
                'aplicacoes': [],
                'actividades': []
            }
        
        # Guarda na BD
        db = get_db()
        
        db.execute("""
            INSERT OR REPLACE INTO declaracoes_rendimentos 
            (dep_id, ano, tipo, rendimento_total, patrimonio_json, url_pdf, extraido_em)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        """, (
            dep_id,
            year,
            tipo,
            pdf_data['rendimento_total'],
            json.dumps(pdf_data),
            pdf_url,
            datetime.now()
        ))
        
        db.commit()
        log.info(f"Importada declaração: {dep_data['nome_curto']} ({year}) - €{pdf_data['rendimento_total']:,.2f}")
        return True
        
    except Exception as e:
        log.error(f"Erro a guardar declaração para {dep_data['nome_curto']} ({year}): {e}")
        return False

def import_all_declaracoes(year: int = None) -> None:
    """Importa todas as declarações"""
    
    if not year:
        year = datetime.now().year - 1  # Ano anterior por defeito
    
    log.info(f"A importar declarações para o ano {year}")
    
    deputados = get_deputados_from_db()
    total = len(deputados)
    sucesso = 0
    
    for i, dep in enumerate(deputados, 1):
        log.info(f"[{i}/{total}] {dep['nome_curto']} ({dep['gp_sigla']})")
        
        if import_declaracao(dep['id'], dep, year):
            sucesso += 1
        
        # Respeita rate limiting
        if i < total:
            time.sleep(DELAY)
    
    log.info(f"Concluído: {sucesso}/{total} declarações importadas com sucesso")

# ─── Main ───────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="ETL Declarações de Rendimentos e Património")
    parser.add_argument('--ano', type=int, help='Ano a importar (default: ano anterior)')
    parser.add_argument('--dep-id', type=int, help='ID específico de deputado')
    parser.add_argument('--all', action='store_true', help='Importar todos os deputados')
    
    args = parser.parse_args()
    
    if args.dep_id:
        # Importa deputado específico
        db = get_db()
        dep = db.execute("SELECT * FROM deputados WHERE id = ?", [args.dep_id]).fetchone()
        
        if dep:
            dep_data = dict(dep)
            year = args.ano or (datetime.now().year - 1)
            import_declaracao(dep['id'], dep_data, year)
        else:
            log.error(f"Deputado ID {args.dep_id} não encontrado")
    
    elif args.all or args.ano:
        import_all_declaracoes(args.ano)
    
    else:
        parser.print_help()

if __name__ == '__main__':
    main()
