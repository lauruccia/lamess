#!/usr/bin/env python3
"""
Normalizza gli export Odoo di Lamèss in CSV puliti (UTF-8, separatore ';') per l'importatore del portale.

Uso:  python3 odoo_etl.py <cartella_export> <cartella_output>

Cartella export (nomi dei file senza prefisso hash): Contatto_Partner_al_2809.xlsx, Contatto_res.partner_1.xlsx,
Movimento_PR_Lamess_lamess.pv.movement_01102026.xlsx, FATTURE_AL_289.xlsx
Output: consulenti.csv, clienti.csv, movimenti.csv, rapporto.txt
"""
import csv
import glob
import os
import re
import sys
from collections import Counter

import pandas as pd

SRC, OUT = sys.argv[1], sys.argv[2]
os.makedirs(OUT, exist_ok=True)


def find(pattern):
    hits = sorted(glob.glob(os.path.join(SRC, f'*{pattern}*')))
    if not hits:
        raise SystemExit(f'File non trovato: {pattern}')
    return hits[-1]


COUNTRIES = {'Italia': 'IT', 'Olanda': 'NL', 'Spagna': 'ES', 'Germania': 'DE', 'Francia': 'FR', 'Panama': 'PA',
             'Irlanda': 'IE', 'Tunisia': 'TN', 'Lituania': 'LT', 'Honduras': 'HN'}
PROVINCES = {
    'Roma': 'RM', 'Perugia': 'PG', 'Teramo': 'TE', 'Ascoli Piceno': 'AP', 'Frosinone': 'FR', 'Reggio Emilia': 'RE',
    'Chieti': 'CH', 'Arezzo': 'AR', 'Lucca': 'LU', 'Caltanissetta': 'CL', 'Agrigento': 'AG', 'Latina': 'LT',
    'Forlì-Cesena': 'FC', 'Modena': 'MO', 'Fermo': 'FM', 'Firenze': 'FI', 'Taranto': 'TA', 'Rimini': 'RN',
    'Bergamo': 'BG', 'Prato': 'PO', 'Lecce': 'LE', 'Milano': 'MI', 'Bologna': 'BO', 'Terni': 'TR', 'Napoli': 'NA',
    'Mantova': 'MN', 'Savona': 'SV', 'Cagliari': 'CA', 'Potenza': 'PZ', 'Pisa': 'PI', 'Macerata': 'MC',
    'Avellino': 'AV', 'Salerno': 'SA', 'Siena': 'SI', 'Catania': 'CT', 'Verona': 'VR', 'Pescara': 'PE',
    'Imperia': 'IM', 'Treviso': 'TV', 'Gorizia': 'GO', 'Vercelli': 'VC', 'Foggia': 'FG', 'La Spezia': 'SP',
    'Pavia': 'PV', 'Pordenone': 'PN', 'Udine': 'UD', 'Sassari': 'SS', 'Palermo': 'PA', 'Parma': 'PR',
    'Pesaro e Urbino': 'PU', 'Varese': 'VA', 'Catanzaro': 'CZ', 'Venezia': 'VE', 'Reggio Calabria': 'RC',
    'Lecco': 'LC', 'Torino': 'TO', 'Bari': 'BA', 'Como': 'CO', 'Brescia': 'BS',
}
CONSULTANT_STATUS = {'Attivo': 'active', 'Sospeso': 'suspended', 'Inattivo': 'suspended', "Bloccato per fiscalita'": 'suspended'}
KYC = {'Verificato': 'verified', 'Rifiutato': 'rejected', 'Documenti inviati': 'submitted', 'Non iniziato': 'none'}
MONTHS = {'Gennaio': 1, 'Febbraio': 2, 'Marzo': 3, 'Aprile': 4, 'Maggio': 5, 'Giugno': 6, 'Luglio': 7, 'Agosto': 8,
          'Settembre': 9, 'Ottobre': 10, 'Novembre': 11, 'Dicembre': 12}


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


def norm_name(v):
    return re.sub(r'\s+', ' ', clean(v)).strip(' ,')


def phone(v):
    s = clean(v).lstrip("'").strip()
    return s


def dt(v):
    s = clean(v)
    return s[:19] if s else ''


def split_name(full):
    parts = full.split()
    if len(parts) <= 1:
        return full, ''
    return parts[0], ' '.join(parts[1:])


def place(row):
    prov = clean(row.get('Provincia'))
    nation = clean(row.get('Nazione'))
    country = COUNTRIES.get(nation, '')
    m = re.search(r'\(([A-Z]{2})\)\s*$', prov)
    if not country and m:
        country = m.group(1)
    country = country or 'IT'
    sigla = ''
    if country == 'IT':
        sigla = PROVINCES.get(re.sub(r'\s*\([A-Z]{2}\)\s*$', '', prov).strip(), '')
    return country, sigla


rep = []
P = pd.read_excel(find('Contatto_Partner_al_2809'))
P = P[P['Nome completo'].notna()].copy()
R = pd.read_excel(find('Contatto_res.partner_1'))
M = pd.read_excel(find('pv.movement_01102026'))
M = M[M['Data evento'].notna()].copy()
rep.append(f'Partner (righe con nome): {len(P)}; consulenti (R): {len(R)}; movimenti: {len(M)}')

P['code'] = P['Referral Code'].map(clean)
P_by_code = {c: r for c, r in ((r['code'], r) for _, r in P.iterrows()) if c}


def score(r):
    return sum(1 for k in ('E-mail', 'Codice Fiscale', 'Indirizzo', 'Telefono', 'Sponsor') if clean(r.get(k)))


# ---------------- Consulenti (da R, arricchiti con P) ----------------
cons = []
name_to_p = {}
for _, r in P.iterrows():
    name_to_p.setdefault(clean(r['Nome completo']).lower(), []).append(r)
used_codes = set()
for _, r in R.iterrows():
    name = norm_name(r['Nome'])
    code = clean(r['Referral Code'])
    p = P_by_code.get(code) if code else None
    if p is None:
        cands = [x for x in name_to_p.get(name.lower(), []) if clean(x['Is Networker']) in ('1', '1.0')]
        p = max(cands, key=score) if cands else None
    p = p if p is not None else pd.Series(dtype=object)
    country, prov = place(p) if len(p) else ('IT', '')
    since = dt(r.get('Data accettazione incarico')) or dt(r.get('Data ingresso network')) or dt(r.get('Data registrazione network')) or dt(p.get('Data creazione'))
    status = CONSULTANT_STATUS.get(clean(r['Stato consulente']), 'active')
    cons.append({
        'ref': code or '', 'nome': name, 'email': clean(p.get('E-mail')).lower(), 'telefono': phone(p.get('Telefono')),
        'indirizzo': clean(p.get('Indirizzo')), 'citta': clean(p.get('Città')), 'cap': clean(p.get('CAP')).replace('.0', ''),
        'provincia': prov, 'paese': country, 'codice_fiscale': clean(p.get('Codice Fiscale')).upper(),
        'tipo': 'company' if clean(p.get('Profilo fiscale')) == 'Registrato con P.IVA' else 'private',
        'sponsor_ref': tag(r['Sponsor']), 'sponsor_nome': untag(r['Sponsor']),
        'stato': status, 'stato_odoo': clean(r['Stato consulente']), 'kyc': KYC.get(clean(r['Stato KYC']), 'none'),
        'dal': since, 'incarico': dt(r.get('Data accettazione incarico')), 'ingresso_rete': dt(r.get('Data ingresso network')),
        'rank_odoo': clean(r['Current Rank']), 'rank_max_odoo': clean(r.get('Highest Rank Ever')),
        'pr_carriera_odoo': clean(r['PR Career (lifetime)']), 'attivita_mensile_odoo': clean(r['Monthly Activity Status']),
        'fast_start': dt(r.get('Data inizio Fast Start')),
    })
used_p = {clean(c['ref']) for c in cons if c['ref']}
extra = 0
for _, p in P.iterrows():
    st = clean(p['Stato consulente'])
    nm = norm_name(p['Nome completo'])
    if st not in CONSULTANT_STATUS and st != "Bloccato per fiscalita'":
        continue
    if clean(p['Is Networker']) in ('1', '1.0') or ', ' in nm or tag(nm) or (p['code'] and p['code'] in used_p):  # tag nel nome = contatto figlio
        continue
    if any(c['nome'].lower() == nm.lower() for c in cons):
        continue
    country, prov = place(p)
    extra += 1
    cons.append({
        'ref': p['code'], 'nome': nm, 'email': clean(p.get('E-mail')).lower(), 'telefono': phone(p.get('Telefono')),
        'indirizzo': clean(p.get('Indirizzo')), 'citta': clean(p.get('Città')), 'cap': clean(p.get('CAP')).replace('.0', ''),
        'provincia': prov, 'paese': country, 'codice_fiscale': clean(p.get('Codice Fiscale')).upper(),
        'tipo': 'company' if clean(p.get('Profilo fiscale')) == 'Registrato con P.IVA' else 'private',
        'sponsor_ref': tag(p['Sponsor']), 'sponsor_nome': untag(p['Sponsor']),
        'stato': CONSULTANT_STATUS.get(st, 'suspended'), 'stato_odoo': st,
        'kyc': 'verified' if clean(p.get('KYC verificato')) in ('1', '1.0') else 'none',
        'dal': dt(p.get('Data accettazione incarico')) or dt(p.get('Data ingresso network')) or dt(p.get('Data creazione')),
        'incarico': dt(p.get('Data accettazione incarico')), 'ingresso_rete': dt(p.get('Data ingresso network')),
        'rank_odoo': clean(p.get('Current Rank')), 'rank_max_odoo': '', 'pr_carriera_odoo': '',
        'attivita_mensile_odoo': '', 'fast_start': dt(p.get('Data inizio Fast Start')),
    })
rep.append(f'Consulenti aggiunti da Partner (stato consulente ma senza flag networker): {extra}')
cons_df = pd.DataFrame(cons)
cons_df = cons_df.sort_values(['dal', 'nome'], kind='stable').reset_index(drop=True)

codes_c = set(c for c in cons_df['ref'] if c)
names_c = Counter(cons_df['nome'].str.lower())
unres = sorted({s for s in cons_df['sponsor_ref'] if s and s not in codes_c})
cons_df['nota'] = ''
cons_df.loc[cons_df['sponsor_ref'].isin(unres), 'nota'] = 'sponsor ' + cons_df['sponsor_nome'] + ' non presente nei dati: importato senza sponsor'
cons_df.loc[cons_df['sponsor_ref'].isin(unres), 'sponsor_ref'] = ''
rep.append(f'Consulenti: {len(cons_df)} (senza codice referral: {(cons_df.ref == "").sum()}); sponsor non risolti: {unres}')
rep.append(f"Consulenti per stato: {cons_df['stato_odoo'].value_counts().to_dict()}")
rep.append(f"Consulenti con email: {(cons_df.email != '').sum()}; con CF: {(cons_df.codice_fiscale != '').sum()}; radici (senza sponsor): {(cons_df.sponsor_ref == '').sum()}")

# ---------------- Clienti (da P, esclusi consulenti e contatti figli) ----------------
cons_names = set(cons_df['nome'].str.lower())
cons_codes = codes_c
cust_rows = {}
skipped_children = 0
for _, r in P.iterrows():
    name = clean(r['Nome completo'])
    if clean(r['Is Networker']) in ('1', '1.0') or r['code'] in cons_codes or (r['code'] == '' and clean(r['Nome completo']).lower() in cons_names):
        continue
    if ', ' in name or tag(name) or '(presso' in name.lower():
        skipped_children += 1
        continue
    key = name.lower()
    if key in cust_rows and score(cust_rows[key]) >= score(r):
        continue
    cust_rows[key] = r
cust = []
for key, r in cust_rows.items():
    country, prov = place(r)
    cust.append({
        'ref': r['code'], 'nome': clean(r['Nome completo']), 'email': clean(r['E-mail']).lower(), 'telefono': phone(r['Telefono']),
        'indirizzo': clean(r['Indirizzo']), 'citta': clean(r['Città']), 'cap': clean(r['CAP']).replace('.0', ''),
        'provincia': prov, 'paese': country, 'codice_fiscale': clean(r['Codice Fiscale']).upper(),
        'tipo': 'company' if clean(r['Profilo fiscale']) == 'Registrato con P.IVA' else 'private',
        'consulente_ref': tag(r['Sponsor']), 'consulente_nome': untag(r['Sponsor']),
        'registrato': dt(r['Data creazione']), 'stato_odoo': clean(r['Stato consulente']),
    })
cust_df = pd.DataFrame(cust).sort_values(['registrato', 'nome'], kind='stable').reset_index(drop=True)
unres_c = sorted({s for s in cust_df['consulente_ref'] if s and s not in cons_codes})
rep.append(f'Clienti: {len(cust_df)} (contatti figli scartati: {skipped_children}); con consulente: {(cust_df.consulente_ref != "").sum()}; consulenti di riferimento non risolti: {unres_c}')
rep.append(f"Clienti con 'Candidatura in corso'/altro stato: {cust_df['stato_odoo'].value_counts().to_dict()}")

# ---------------- Movimenti PV ----------------
ben = 'Consulente (beneficiario commissioni)'
mv = []
unmatched = []
name_c = {}
for _, c in cons_df.iterrows():
    name_c.setdefault(c['nome'].lower(), []).append(c)
for _, r in M.iterrows():
    t = tag(r[ben])
    nome = untag(r[ben])
    if t and t in cons_codes:
        bref, bname = t, nome
    else:
        c = name_c.get(nome.lower(), [])
        if len(c) == 1:
            bref, bname = c[0]['ref'], c[0]['nome']
        else:
            unmatched.append((clean(r['Riga ordine origine']), clean(r[ben])))
            continue
    pvf, pvc = float(r['Importo PVf'] or 0), float(r['Importo PVc'] or 0)
    kind = 'pvf' if pvf > 0 else 'pvc'
    pv = pvf if pvf > 0 else pvc
    per = clean(r['Periodo commissionale'])
    mm = re.match(r'(\w+)\s+(\d{4})', per)
    period = f"{mm.group(2)}-{MONTHS[mm.group(1)]:02d}" if mm else dt(r['Data evento'])[:7]
    mv.append({
        'rif_esterno': clean(r['Riga ordine origine']), 'periodo': period, 'data': dt(r['Data evento']),
        'beneficiario_ref': bref, 'beneficiario_nome': norm_name(bname), 'tipo': kind, 'pv': f'{pv:.2f}',
        'cv': f"{float(r['PR commissionabile'] or 0):.2f}", 'escluso_bonus': '1' if float(r['Escludi da bonus'] or 0) else '0',
        'ordine': clean(r['Ordine di vendita']), 'fattura': clean(r['Fattura / Nota di credito']),
        'prodotto': clean(r['Prodotto']), 'acquirente': clean(r['Partner frontline']),
    })
mv_df = pd.DataFrame(mv)
seen = Counter()
refs = []
for v in mv_df['rif_esterno']:
    if not v:
        refs.append('')
        continue
    seen[v] += 1
    refs.append(v if seen[v] == 1 else f'{v} #{seen[v]}')
mv_df['rif_esterno'] = refs
mv_df['manuale'] = (mv_df['acquirente'].str.contains('Rettifica manuale') | (mv_df['rif_esterno'] == '')).map({True: '1', False: '0'})
dup = mv_df[mv_df.rif_esterno != '']['rif_esterno'].duplicated().sum()
rep.append(f'Movimenti: {len(mv_df)} su {len(M)}; non assegnabili: {unmatched}; riferimenti duplicati: {dup}; senza periodo: {(mv_df.periodo == "").sum()}')
rep.append(f"Movimenti per periodo (n, PV): " + str({k: (int(v['count']), float(v['sum'])) for k, v in mv_df.assign(p=mv_df.pv.astype(float)).groupby('periodo')['p'].agg(['count', 'sum']).iterrows()}))
rep.append(f"PV totali: pvf={mv_df[mv_df.tipo == 'pvf'].pv.astype(float).sum():.0f} pvc={mv_df[mv_df.tipo == 'pvc'].pv.astype(float).sum():.0f}")

# ---------------- Ultimo numero fattura ----------------
try:
    F = pd.read_excel(find('FATTURE_AL_289'))
    nums = [int(m.group(1)) for n in F['Numero'].dropna() if (m := re.match(r'FT/2026/(\d+)$', str(n)))]
    rep.append(f'Ultima fattura FT/2026: {max(nums) if nums else "n/d"} ({len(nums)} fatture FT/2026)')
    last_ft = max(nums) if nums else 0
except SystemExit:
    last_ft = 0

for name, df in (('consulenti', cons_df), ('clienti', cust_df), ('movimenti', mv_df)):
    df.to_csv(os.path.join(OUT, f'{name}.csv'), sep=';', index=False, quoting=csv.QUOTE_MINIMAL, encoding='utf-8')
with open(os.path.join(OUT, 'contatori.csv'), 'w', encoding='utf-8') as f:
    f.write(f'chiave;valore\nFT-2026;{last_ft}\n')
with open(os.path.join(OUT, 'rapporto.txt'), 'w', encoding='utf-8') as f:
    f.write('\n'.join(rep) + '\n')
print('\n'.join(rep))
