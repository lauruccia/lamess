#!/usr/bin/env python3
"""Regola Odoo: chi NON ha un rank è solo cliente, non consulente.
Legge consulenti.csv / clienti.csv / movimenti.csv (prodotti da odoo_etl.py) e riscrive i tre file:
 - i "consulenti" senza rank passano in clienti.csv, assegnati al consulente (con rank) più vicino risalendo la linea di sponsor;
 - i loro movimenti PV vanno a quel consulente (nome originale in 'acquirente');
 - gli originali restano in *_prima_regola_rank.csv. Rieseguibile: parte sempre dagli originali.
Uso: python3 applica_regola_rank.py <cartella>"""
import csv, os, shutil, sys
d = sys.argv[1] if len(sys.argv) > 1 else 'database/data/odoo'
def rd(n):
    with open(os.path.join(d, n), encoding='utf-8-sig', newline='') as f:
        r = csv.DictReader(f, delimiter=';'); return r.fieldnames, list(r)
def wr(n, h, rows):
    with open(os.path.join(d, n), 'w', encoding='utf-8', newline='') as f:
        w = csv.DictWriter(f, h, delimiter=';', quoting=csv.QUOTE_MINIMAL, quotechar='"', escapechar=None)
        w.writeheader(); w.writerows(rows)
for n in ('consulenti', 'clienti', 'movimenti'):
    bk = os.path.join(d, n + '_prima_regola_rank.csv')
    if not os.path.exists(bk): shutil.copy(os.path.join(d, n + '.csv'), bk)
    shutil.copy(bk, os.path.join(d, n + '.csv'))
hc, cons = rd('consulenti.csv'); hk, cli = rd('clienti.csv'); hm, mov = rd('movimenti.csv')
key = lambda x: x['ref'] or 'nome:' + x['nome']
byref = {x['ref']: x for x in cons if x['ref']}; byname = {x['nome']: x for x in cons}
def sponsor(x):
    return byref.get(x['sponsor_ref']) or (byname.get(x['sponsor_nome']) if not x['sponsor_ref'] else None)
def owner(x):  # consulente con rank più vicino risalendo (escluso x stesso)
    seen = set(); s = sponsor(x)
    while s is not None and key(s) not in seen:
        if s['rank_odoo']: return s
        seen.add(key(s)); s = sponsor(s)
    return None
keep = [x for x in cons if x['rank_odoo']]; demoted = [x for x in cons if not x['rank_odoo']]
dest = {key(x): owner(x) for x in demoted}
# clienti già in clienti.csv assegnati a chi ora è cliente: passano al consulente con rank più vicino
remapped = 0
for c in cli:
    x = byref.get(c['consulente_ref']) or (byname.get(c['consulente_nome']) if not c['consulente_ref'] else None)
    if x is not None and not x['rank_odoo']:
        o = dest[key(x)]
        c['consulente_ref'], c['consulente_nome'] = (o['ref'], o['nome']) if o else ('', '')
        remapped += 1
newcli = []
for x in demoted:
    o = dest[key(x)]
    newcli.append({'ref': x['ref'], 'nome': x['nome'], 'email': x['email'], 'telefono': x['telefono'], 'indirizzo': x['indirizzo'],
        'citta': x['citta'], 'cap': x['cap'], 'provincia': x['provincia'], 'paese': x['paese'], 'codice_fiscale': x['codice_fiscale'],
        'tipo': x['tipo'], 'consulente_ref': o['ref'] if o else '', 'consulente_nome': o['nome'] if o else '',
        'registrato': x['dal'], 'stato_odoo': x['stato_odoo']})
cust_keys = {c['ref'] or c['nome'] for c in cli}
newcli = [c for c in newcli if (c['ref'] or c['nome']) not in cust_keys]
moved = 0
for m in mov:
    k = m['beneficiario_ref'] or 'nome:' + m['beneficiario_nome']
    x = byref.get(m['beneficiario_ref']) or byname.get(m['beneficiario_nome'])
    if x is not None and not x['rank_odoo']:
        o = dest[key(x)]
        if o:
            m['acquirente'] = m['acquirente'] or x['nome']
            m['beneficiario_ref'], m['beneficiario_nome'] = o['ref'], o['nome']; moved += 1
# i figli diretti dei consulenti senza rank non esistono più come consulenti: nessuno rimane con sponsor "cliente"
for x in keep:
    s = sponsor(x)
    assert s is None or s['rank_odoo'], x['nome']
wr('consulenti.csv', hc, keep); wr('clienti.csv', hk, cli + newcli); wr('movimenti.csv', hm, mov)
print(f'consulenti {len(cons)} -> {len(keep)}; clienti {len(cli)} -> {len(cli)+len(newcli)} (+{len(newcli)}); '
      f'senza consulente: {sum(1 for c in newcli if not c["consulente_ref"])}; movimenti riassegnati: {moved}; clienti riassegnati: {remapped}')
