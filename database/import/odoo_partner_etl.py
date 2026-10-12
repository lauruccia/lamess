#!/usr/bin/env python3
"""
Normalizza l'export Odoo «Contatto (res.partner)» in due file per il portale (UTF-8, separatore ';'):

  anagrafica_odoo.csv        una riga per ogni contatto con codice referral: firma della lettera, stato KYC, accettazioni, qualifica, sponsor
  consulenti_integrazione.csv  chi ha firmato la lettera ma non ha qualifica: SOLO ELENCO per `lamess:declassa-senza-rank`; non è più importato come incaricati (senza rank = solo cliente)

Uso:  python3 odoo_partner_etl.py <file_contatti.xlsx> <cartella_dati_odoo>
Il file `consulenti.csv` della cartella serve solo a riconoscere chi c'è già (non viene modificato).
Rank «none» = ancora solo cliente: senza firma della lettera non diventa incaricato.
"""
import csv
import os
import re
import sys

import pandas as pd

SRC, OUT = sys.argv[1], sys.argv[2]
KYC = {'Verificato': 'verified', 'Rifiutato': 'rejected', 'Documenti inviati': 'submitted', 'Non iniziato': 'none'}


def clean(v):
    if v is None or (isinstance(v, float) and pd.isna(v)) or v is pd.NaT:
        return ''
    if isinstance(v, pd.Timestamp):
        return v.strftime('%Y-%m-%d %H:%M:%S')
    s = str(v).strip()
    return '' if s.lower() in ('nan', 'nat', 'false') and not isinstance(v, bool) else ('' if v is False else s)


def tag(s):
    m = re.search(r'\[([^\]]+)\]\s*$', s)
    return m.group(1) if m else ''


def flag(v):
    return '1' if clean(v) in ('1', '1.0', 'True') or v is True else '0'


df = pd.read_excel(SRC)
df = df[df['Nome completo'].notna()].copy()
known = set()
with open(os.path.join(OUT, 'consulenti.csv'), encoding='utf-8') as f:
    for r in csv.DictReader(f, delimiter=';'):
        if r['ref']:
            known.add(r['ref'])

ana, new, scartati = [], [], []
for _, p in df.iterrows():
    ref = clean(p.get('Referral Code'))
    if not ref:
        continue
    firma = clean(p.get('Firma incarico completata il'))[:10]  # solo il giorno: Odoo lo esporta alle 02:00
    firma = firma + ' 12:00:00' if firma else ''
    kyc = KYC.get(clean(p.get('Stato KYC')), 'verified' if flag(p.get('KYC verificato')) == '1' else 'none')
    sponsor = clean(p.get('Sponsor'))
    ana.append({
        'ref': ref, 'nome': clean(p['Nome completo']), 'email': clean(p.get('E-mail')), 'firma': firma,
        'richiesta_firma': clean(p.get('ID richiesta firma')).replace('.0', '') if clean(p.get('ID richiesta firma')) not in ('', '0', '0.0') else '',
        'kyc': kyc, 'kyc_flag_odoo': flag(p.get('KYC verificato')), 'kyc_rifiuto': clean(p.get('Motivo rifiuto KYC')),
        'documenti': clean(p.get('Numero documenti')).replace('.0', ''),
        'acc_dati': flag(p.get('Accettazione dati veritieri')), 'acc_privacy': flag(p.get('Accettazione disclaimer e privacy')),
        'acc_fiscali': flag(p.get("Accettazione responsabilita' fiscali")),
        'rank': clean(p.get('Current Rank')), 'sponsor_ref': tag(sponsor), 'sponsor_nome': re.sub(r'\s*\[[^\]]+\]\s*$', '', sponsor),
    })
    nome_email = (clean(p['Nome completo']) + ' ' + clean(p.get('E-mail'))).lower()
    if firma and ref not in known and re.search(r'test(?!a\b)', nome_email):
        scartati.append(clean(p['Nome completo']))
        continue_new = True
    else:
        continue_new = False
    if firma and ref not in known and not continue_new:
        new.append({
            'ref': ref, 'nome': clean(p['Nome completo']), 'email': clean(p.get('E-mail')), 'telefono': clean(p.get('Telefono')),
            'indirizzo': '', 'citta': '', 'cap': '', 'provincia': '', 'paese': 'IT', 'codice_fiscale': '', 'tipo': 'private',
            'sponsor_ref': tag(sponsor), 'sponsor_nome': re.sub(r'\s*\[[^\]]+\]\s*$', '', sponsor), 'stato': 'active', 'stato_odoo': 'Firma lettera (da contatti)',
            'kyc': kyc, 'dal': firma, 'incarico': firma, 'ingresso_rete': firma, 'rank_odoo': 'none', 'rank_max_odoo': 'none',
            'pr_carriera_odoo': '', 'attivita_mensile_odoo': '', 'fast_start': '',
            'nota': 'Creato dai contatti Odoo perché ha firmato la lettera: verificare che sia davvero un incaricato.',
        })


def write(name, rows):
    with open(os.path.join(OUT, name), 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0].keys()), delimiter=';')
        w.writeheader()
        w.writerows(rows)


write('anagrafica_odoo.csv', ana)
write('consulenti_integrazione.csv', new)
print('Esclusi perché sembrano account di prova:', ', '.join(scartati) or 'nessuno')
print(f'anagrafica_odoo.csv: {len(ana)} righe; consulenti_integrazione.csv: {len(new)} nuovi incaricati (firma senza essere in consulenti.csv)')
