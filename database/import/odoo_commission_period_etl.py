#!/usr/bin/env python3
"""
Normalizza l'export Odoo «Commission Period» in `commission_period.csv` per `lamess:verifica-odoo --bonus`.

Uso:  python3 odoo_commission_period_etl.py <cartella_export> <cartella_output>
Legge «*Commission*Period*.xlsx». Scrive una riga per mese con i totali dei bonus (Odoo li stima finché il periodo è aperto).
"""
import csv, glob, os, sys
import pandas as pd

SRC, OUT = sys.argv[1], sys.argv[2]
os.makedirs(OUT, exist_ok=True)
hits = sorted(glob.glob(os.path.join(SRC, '*Commission*Period*.xlsx')))
if not hits:
    raise SystemExit('File non trovato: Commission Period')
df = pd.read_excel(hits[-1])
with open(os.path.join(OUT, 'commission_period.csv'), 'w', newline='', encoding='utf-8') as f:
    w = csv.writer(f, delimiter=';')
    w.writerow(['periodo', 'stato', 'direct', 'team', 'matching', 'rank', 'fast_start', 'totale', 'fatturato'])
    for _, r in df.iterrows():
        w.writerow([f"{int(r['Year'])}-{int(r['Month']):02d}", r['State'], f"{r['Direct bonus']:.2f}", f"{r['Team bonus']:.2f}", f"{r['Matching bonus']:.2f}",
                    f"{r['Rank bonus']:.2f}", f"{r['Fast Start']:.2f}", f"{r['Totale bonus stimati']:.2f}", f"{r['Totale fatturato mese']:.2f}"])
print(f'commission_period.csv: {len(df)} mesi')
