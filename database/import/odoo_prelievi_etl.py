#!/usr/bin/env python3
"""
Normalizza gli export Odoo «Lamess Payout Request» e «autofatture» in `prelievi.csv` (UTF-8, separatore ';') per `lamess:importa-prelievi`.

Uso:  python3 odoo_prelievi_etl.py <cartella_export> <cartella_output>
Legge: «*Payout Request*.xlsx» e «autofatture*.xlsx». Scrive: prelievi.csv (una riga per richiesta di prelievo).
Le autofatture si agganciano al prelievo dal numero `COMM…/AAAA/MM/NNNN`; lo stato di pagamento dell'autofattura vale come verità per «pagato».
"""
import csv
import glob
import os
import re
import sys

import pandas as pd

SRC, OUT = sys.argv[1], sys.argv[2]
os.makedirs(OUT, exist_ok=True)


def find(pattern):
    hits = sorted(glob.glob(os.path.join(SRC, '*' + pattern.replace(' ', '*') + '*.xlsx')))
    if not hits:
        raise SystemExit(f'File non trovato: {pattern}')
    return hits[-1]


def tag(s):
    m = re.search(r'\[([^\]]+)\]\s*$', str(s)) if isinstance(s, str) else None
    return m.group(1) if m else ''


def name(s):
    return re.sub(r'\s*\[[^\]]+\]\s*$', '', str(s)).strip(' ,')


def num(v):
    return '' if pd.isna(v) else f'{float(v):.2f}'


def dt(v):
    return '' if pd.isna(v) else pd.Timestamp(v).strftime('%Y-%m-%d %H:%M:%S')


req = pd.read_excel(find('Payout Request'))
inv = pd.read_excel(find('autofatture'))
inv_by_num = {str(r['Numero']): r for _, r in inv.iterrows() if isinstance(r['Numero'], str) and r['Numero'].startswith('COMM0')}

cols = ['ref', 'nome', 'richiesta', 'lordo', 'tracciato', 'non_tracciato', 'netto', 'stato', 'revisionato_da', 'costo_amm', 'liquidazione',
        'autofattura', 'fattura_stato', 'fattura_data']
with open(os.path.join(OUT, 'prelievi.csv'), 'w', newline='', encoding='utf-8') as f:
    w = csv.writer(f, delimiter=';')
    w.writerow(cols)
    for _, r in req.iterrows():
        m = re.search(r'COMM\d+/\d{4}/\d{2}/\d{4}', str(r['Autofattura (bozza)']))
        number = m.group(0) if m else ''
        i = inv_by_num.get(number)
        w.writerow([
            tag(r['Consulente']), name(r['Consulente']), dt(r['Data richiesta']), num(r['Importo lordo richiesto (€)']),
            num(r['Importo tracciato da settlement (€)']), num(r['Importo non tracciato (€)']), num(r['Netto liquidabile (€)']),
            r['Stato'], '' if pd.isna(r['Revisionato da']) else r['Revisionato da'], num(r['Costo amministrativo (€)']), dt(r['Data liquidazione']),
            number, '' if i is None else i['Stato in pagamento'], '' if i is None else dt(i['Data fattura']),
        ])
print(f'prelievi.csv: {len(req)} righe')
