#!/usr/bin/env python3
"""
Normalizza gli export Odoo di ordini e fatture in CSV puliti (UTF-8, separatore ';') per `lamess:importa-storico`.

Uso:  python3 odoo_storico_etl.py <cartella_export> <cartella_output>

Legge: «Ordini di vendita al 289.xlsx» e «FATTURE AL 289.xlsx» (ultima versione trovata per nome).
Scrive: ordini.csv, fatture.csv. Gli export non hanno le righe: le righe d'ordine si ricostruiscono dai movimenti (movimenti.csv).
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
    hits = sorted(glob.glob(os.path.join(SRC, f'*{pattern}*')))
    if not hits:
        raise SystemExit(f'File non trovato: {pattern}')
    return hits[-1]


def tag(s):
    m = re.search(r'\[([^\]]+)\]\s*$', str(s)) if isinstance(s, str) else None
    return m.group(1).strip() if m else ''


def untag(s):
    return re.sub(r'\s*\[[^\]]+\]\s*$', '', str(s)).strip() if isinstance(s, str) else ''


def clean(v):
    if v is None or (isinstance(v, float) and pd.isna(v)) or v is pd.NaT:
        return ''
    s = str(v).strip()
    return '' if s.lower() in ('nan', 'nat', 'none') else s


def money(v):
    s = clean(v)
    return f'{float(s):.2f}' if s != '' else '0.00'


def dt(v):
    s = clean(v)
    return s[:19] if s else ''


def write(name, header, rows):
    with open(os.path.join(OUT, name), 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f, delimiter=';', quoting=csv.QUOTE_MINIMAL)
        w.writerow(header)
        w.writerows(rows)


orders = pd.read_excel(find('Ordini di vendita al'))
o_rows = []
for _, r in orders.iterrows():
    o_rows.append([
        clean(r['Riferimento ordine']), dt(r['Data creazione']), untag(clean(r['Cliente'])), tag(clean(r['Cliente'])),
        untag(clean(r['Networker (Sponsor)'])), tag(clean(r['Networker (Sponsor)'])), clean(r['Azienda']),
        clean(r['PR']), money(r['Importo imponibile']), money(r['Imposte']), money(r['Totale']),
        clean(r['Stato']), clean(r['Stato fattura']), clean(r['Riferimento cliente']),
    ])
write('ordini.csv', ['ref', 'data', 'cliente_nome', 'cliente_ref', 'sponsor_nome', 'sponsor_ref', 'azienda', 'pr', 'imponibile', 'imposte', 'totale', 'stato', 'stato_fattura', 'rif_cliente'], o_rows)

inv = pd.read_excel(find('FATTURE AL'))
i_rows = []
for _, r in inv.iterrows():
    i_rows.append([
        clean(r['Numero']), dt(r['Data fattura']), untag(clean(r['Nome visualizzato partner fattura'])), tag(clean(r['Nome visualizzato partner fattura'])),
        clean(r['Nazione']), clean(r['Codice Fiscale']), clean(r['PR']), money(r['Valuta imponibile con segno']), money(r['Imposta con segno']),
        money(r['Totale firmato in valuta']), clean(r['Stato SDI']), clean(r['Stato in pagamento']),
    ])
write('fatture.csv', ['numero', 'data', 'partner_nome', 'partner_ref', 'paese', 'codice_fiscale', 'pr', 'imponibile', 'imposta', 'totale', 'stato_sdi', 'stato_pagamento'], i_rows)
print(f'ordini: {len(o_rows)}; fatture: {len(i_rows)}')
