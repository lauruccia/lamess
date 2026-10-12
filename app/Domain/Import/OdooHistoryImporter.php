<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Importa lo storico di Odoo (ordini, fatture, nota di credito) prodotto da database/import/odoo_storico_etl.py.
 * Gli export non contengono le righe prodotto: ogni ordine/fattura ha UNA riga riepilogativa (descrizione ricavata dai
 * movimenti PV). Tutto ciò che non torna finisce in `import_reconciliations`, da approvare a mano.
 * Idempotente. Non invia email e non genera nuovi movimenti PV: aggancia quelli già importati da OdooImporter.
 * Le fatture storiche nascono «inviate» (sink_ref = odoo): il gestionale non le riceve una seconda volta.
 */
final class OdooHistoryImporter
{
    private const TZ = 'Europe/Rome';

    private const PLACEHOLDER_SKU = 'STORICO-ODOO';

    private const TOLERANCE = 0.011;

    /** @var array<string,int> */
    private array $counts = [];

    /** @var array<string,array<string,mixed>> chiave code|ref */
    private array $issues = [];

    /** @return array{counts: array<string,int>, issues: array<string,int>} */
    public function import(string $dir, bool $dryRun = false): array
    {
        $orders = $this->rows($dir, 'ordini.csv');
        $invoices = $this->rows($dir, 'fatture.csv');
        $movements = $this->rows($dir, 'movimenti.csv');
        $clients = $this->rows($dir, 'clienti.csv');
        $consultants = $this->rows($dir, 'consulenti.csv');

        $this->counts = array_fill_keys([
            'ordini_creati', 'ordini_gia_presenti', 'ordini_annullati', 'preventivi_saltati', 'ordini_senza_acquirente',
            'fatture_create', 'fatture_gia_presenti', 'fatture_agganciate_per_importo', 'fatture_senza_ordine', 'fatture_non_importate',
            'note_credito_create', 'movimenti_agganciati', 'pagamenti_creati',
        ], 0);
        $this->issues = [];

        DB::beginTransaction();
        try {
            $users = $this->userMaps($clients, $consultants);
            $productId = $this->placeholderProduct();
            $byOrder = [];
            foreach ($movements as $m) {
                if ($m['ordine'] !== '') {
                    $byOrder[$m['ordine']][] = $m;
                }
            }
            $orderRows = [];
            foreach ($orders as $o) {
                $orderRows[$o['ref']] = $o;
            }
            $this->checkMovementsWithoutOrder($movements, $orderRows, $invoices);

            $orderIds = $this->importOrders($orders, $byOrder, $users, $productId);
            $invoiceOrder = $this->linkInvoices($invoices, $movements, $orders, $orderIds);
            $this->importInvoices($invoices, $orderRows, $orderIds, $invoiceOrder, $users);
            $this->linkMovements($movements, $orderIds);
            $this->compareTotals($orderRows, $orderIds, $byOrder);
            $this->syncIssues();
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $perCode = [];
        foreach ($this->issues as $i) {
            $perCode[$i['code']] = ($perCode[$i['code']] ?? 0) + 1;
        }
        ksort($perCode);

        return ['counts' => $this->counts, 'issues' => $perCode];
    }

    /**
     * @param  list<array<string,string>>  $clients
     * @param  list<array<string,string>>  $consultants
     * @return array{ref: array<string,int>, name: array<string,int>, consultantOfUser: array<int,int>, refToConsultant: array<string,int>}
     */
    private function userMaps(array $clients, array $consultants): array
    {
        $byEmail = User::query()->pluck('id', 'email')->mapWithKeys(fn ($id, $e) => [strtolower((string) $e) => $id])->all();
        $ref = [];
        foreach (array_merge($clients, $consultants) as $r) {
            if ($r['ref'] === '') {
                continue;
            }
            $email = strtolower(trim($r['email']));
            $id = ($email !== '' ? ($byEmail[$email] ?? null) : null) ?? ($byEmail[Str::slug($r['ref']).'@import.lamess.invalid'] ?? null);
            if ($id !== null) {
                $ref[$r['ref']] = (int) $id;
            }
        }
        $refToConsultant = DB::table('consultants')->whereNotNull('referral_code')->pluck('id', 'referral_code')->all();
        $consultantUsers = DB::table('consultants')->whereNotNull('user_id')->get(['id', 'user_id', 'referral_code']);
        $consultantOfUser = [];
        foreach ($consultantUsers as $c) {
            $consultantOfUser[$c->user_id] = $c->id;
            if ($c->referral_code !== null) {
                $ref[$c->referral_code] = (int) $c->user_id;
            }
        }
        $name = [];
        foreach (User::query()->get(['id', 'name']) as $u) {
            $k = mb_strtolower(trim((string) $u->name));
            $name[$k] = isset($name[$k]) ? 0 : (int) $u->id; // 0 = nome ambiguo
        }

        return ['ref' => $ref, 'name' => $name, 'consultantOfUser' => $consultantOfUser, 'refToConsultant' => $refToConsultant];
    }

    private function placeholderProduct(): int
    {
        $p = Product::query()->where('sku', self::PLACEHOLDER_SKU)->first();
        if ($p === null) {
            $p = Product::query()->create([
                'sku' => self::PLACEHOLDER_SKU, 'name' => 'Storico Odoo (riga riepilogativa)', 'net_price' => 0, 'vat_rate' => 22,
                'active' => false, 'description' => 'Segnaposto per gli ordini storici importati da Odoo: gli export non contengono le righe prodotto.',
            ]);
        }

        return (int) $p->id;
    }

    /**
     * @param  list<array<string,string>>  $orders
     * @param  array<string,list<array<string,string>>>  $byOrder
     * @param  array<string,mixed>  $users
     * @return array<string,int> ref ordine → orders.id
     */
    private function importOrders(array $orders, array $byOrder, array $users, int $productId): array
    {
        $ids = DB::table('orders')->where('source', 'odoo')->pluck('id', 'number')->all();
        $invoicePaid = [];
        foreach ($orders as $o) {
            if (str_starts_with($o['stato'], 'Preventivo')) {
                $this->counts['preventivi_saltati']++;
                $this->issue('preventivo_non_importato', 'info', $o['ref'], $o['cliente_nome'], "Preventivo Odoo ({$o['stato']}) non importato: non è un ordine.", $this->orderSnapshot($o));

                continue;
            }
            if (isset($ids[$o['ref']])) {
                $this->counts['ordini_gia_presenti']++;

                continue;
            }
            $userId = $this->resolveBuyer($o['cliente_ref'], $o['cliente_nome'], $users);
            if ($userId === null) {
                $this->counts['ordini_senza_acquirente']++;
                $this->issue('ordine_senza_acquirente', 'error', $o['ref'], $o['cliente_nome'], 'Acquirente non riconosciuto tra clienti e incaricati importati: ordine NON importato.', $this->orderSnapshot($o));

                continue;
            }
            $cancelled = $o['stato'] === 'Annullato';
            $net = (float) $o['imponibile'];
            $vat = (float) $o['imposte'];
            $rate = $net > 0 ? round($vat / $net * 100, 2) : 22.0;
            $when = $this->utc($o['data']);
            $isConsultant = isset($users['consultantOfUser'][$userId]);
            $mv = $byOrder[$o['ref']] ?? [];
            $cv = array_sum(array_map(fn ($m) => $m['tipo'] === 'pvf' ? (float) $m['cv'] : 0.0, $mv));
            $description = collect($mv)->pluck('prodotto')->filter()->unique()->take(4)->implode(' + ') ?: 'Storico Odoo';
            $sponsor = $users['refToConsultant'][$o['sponsor_ref']] ?? ($users['consultantOfUser'][$userId] ?? null);
            $orderId = DB::table('orders')->insertGetId([
                'number' => $o['ref'], 'source' => 'odoo', 'buyer_user_id' => $userId, 'buyer_type' => $isConsultant ? 'consultant' : 'customer',
                'beneficiary_consultant_id' => $sponsor,
                'status' => $cancelled ? 'cancelled' : ($o['stato_fattura'] === 'Da fatturare' ? 'pending_payment' : 'paid'),
                'country_code' => 'IT', 'payment_method' => 'transfer',
                'subtotal_net' => $net, 'shipping_net' => 0, 'shipping_vat_rate' => 22, 'vat_total' => $vat, 'total' => (float) $o['totale'],
                'pv_total' => (float) $o['pr'], 'cv_total' => $cv, 'paid_at' => ($cancelled || $o['stato_fattura'] === 'Da fatturare') ? null : $when,
                'billing' => json_encode(['name' => $o['cliente_nome'], 'odoo_ref' => $o['cliente_ref'], 'azienda_venditrice' => $o['azienda']]),
                'notes' => 'Importato da Odoo ('.$o['stato'].' · '.$o['stato_fattura'].')'.($o['rif_cliente'] !== '' ? ' · rif. cliente '.$o['rif_cliente'] : ''),
                'created_at' => $when, 'updated_at' => $when,
            ]);
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'product_id' => $productId, 'sku' => self::PLACEHOLDER_SKU, 'name' => Str::limit($description, 240, ''),
                'qty' => 1, 'unit_net' => $net, 'vat_rate' => $rate, 'pv_unit_frozen' => (float) $o['pr'], 'cv_unit_frozen' => $cv,
            ]);
            $ids[$o['ref']] = $orderId;
            $this->counts['ordini_creati']++;
            if ($cancelled) {
                $this->counts['ordini_annullati']++;
            }
            if ($o['stato_fattura'] === 'Da fatturare' && ! $cancelled && (float) $o['totale'] > 0) {
                $this->issue('ordine_da_fatturare', 'warning', $o['ref'], $o['cliente_nome'], 'Ordine confermato in Odoo ma ancora «Da fatturare»: importato come in attesa di pagamento, senza fattura.', $this->orderSnapshot($o));
            }
            if ($o['stato_fattura'] === 'Nulla da fatturare' && ! $cancelled && (float) $o['totale'] > 0) {
                $this->issue('ordine_senza_fattura_con_importo', 'warning', $o['ref'], $o['cliente_nome'], 'Ordine con importo ma «Nulla da fatturare» in Odoo.', $this->orderSnapshot($o));
            }
        }

        return $ids;
    }

    /**
     * Collega ogni fattura al suo ordine: prima col riferimento «(Kxxxxx)» presente nei movimenti, poi per acquirente+importo.
     *
     * @param  list<array<string,string>>  $invoices
     * @param  list<array<string,string>>  $movements
     * @param  list<array<string,string>>  $orders
     * @param  array<string,int>  $orderIds
     * @return array<string,array{order: string, how: string}>
     */
    private function linkInvoices(array $invoices, array $movements, array $orders, array $orderIds): array
    {
        $link = [];
        foreach ($movements as $m) {
            if (preg_match('/^(.*?)\s*\((K\d+)\)\s*$/', $m['fattura'], $x) === 1) {
                $link[$x[1]] ??= ['order' => $x[2], 'how' => 'riferimento'];
            } elseif ($m['fattura'] !== '' && $m['ordine'] !== '') {
                $link[$m['fattura']] ??= ['order' => $m['ordine'], 'how' => 'riferimento'];
            }
        }
        $taken = array_fill_keys(array_column($link, 'order'), true);
        // per importo: solo se l'abbinamento è univoco
        $free = [];
        foreach ($orders as $o) {
            if (isset($orderIds[$o['ref']]) && ! isset($taken[$o['ref']]) && $o['stato'] === 'Ordine di vendita') {
                $free[$o['cliente_nome'].'|'.$o['totale']][] = $o['ref'];
            }
        }
        foreach ($invoices as $f) {
            if (isset($link[$f['numero']]) || str_starts_with($f['numero'], 'RINV') || str_starts_with($f['numero'], 'DINV')) {
                continue;
            }
            $cands = $free[$f['partner_nome'].'|'.$f['totale']] ?? [];
            if (count($cands) === 1) {
                $link[$f['numero']] = ['order' => $cands[0], 'how' => 'importo'];
                unset($free[$f['partner_nome'].'|'.$f['totale']]);
            }
        }

        return $link;
    }

    /**
     * @param  list<array<string,string>>  $invoices
     * @param  array<string,array<string,string>>  $orderRows
     * @param  array<string,int>  $orderIds
     * @param  array<string,array{order: string, how: string}>  $invoiceOrder
     * @param  array<string,mixed>  $users
     */
    private function importInvoices(array $invoices, array $orderRows, array $orderIds, array $invoiceOrder, array $users): void
    {
        $existingInv = DB::table('invoices')->pluck('id', 'number')->all();
        $existingCn = DB::table('credit_notes')->pluck('id', 'number')->all();
        $usedOrders = DB::table('invoices')->whereNotNull('order_id')->pluck('order_id')->flip()->all();
        $byNumber = [];
        foreach ($invoices as $f) {
            $byNumber[$f['numero']] = $f;
        }
        usort($invoices, fn ($a, $b) => str_starts_with($a['numero'], 'RINV') <=> str_starts_with($b['numero'], 'RINV')); // le note di credito per ultime
        foreach ($invoices as $f) {
            $n = $f['numero'];
            if (str_starts_with($n, 'DINV')) {
                $this->counts['fatture_non_importate']++;
                $this->issue('fattura_bozza_non_importata', 'warning', $n, $f['partner_nome'], "Documento DINV (stato SdI «{$f['stato_sdi']}», pagamento «{$f['stato_pagamento']}») non importato: sembra una bozza/riemissione.", $this->invoiceSnapshot($f));

                continue;
            }
            $net = (float) $f['imponibile'];
            $vat = (float) $f['imposta'];
            $total = (float) $f['totale'];
            $when = $this->utc($f['data']);
            $buyer = ['name' => $f['partner_nome'], 'tax_code' => $f['codice_fiscale'], 'country' => $f['paese'], 'odoo_ref' => $f['partner_ref']];
            if (str_starts_with($n, 'RINV')) {
                $this->importCreditNote($f, $buyer, $byNumber, $orderRows, $orderIds, $existingCn, $existingInv);

                continue;
            }
            if (isset($existingInv[$n])) {
                $this->counts['fatture_gia_presenti']++;

                continue;
            }
            $orderRef = $invoiceOrder[$n]['order'] ?? null;
            $orderId = $orderRef !== null ? ($orderIds[$orderRef] ?? null) : null;
            if ($orderId !== null && isset($usedOrders[$orderId])) {
                $this->issue('ordine_con_piu_fatture', 'warning', $orderRef, $f['partner_nome'], "L'ordine ha già una fattura importata: {$n} resta senza ordine.", $this->invoiceSnapshot($f));
                $orderId = null;
            }
            if ($orderId === null) {
                $this->counts['fatture_senza_ordine']++;
                $this->issue('fattura_senza_ordine', str_starts_with($n, '2026/pri') ? 'info' : 'warning', $n, $f['partner_nome'], (str_starts_with($n, '2026/pri') ? 'Ricevuta «pri» non collegata a un ordine di vendita (probabile periodo precedente agli ordini Odoo). ' : '').'Nessun ordine riconosciuto per questa fattura (nessun riferimento nei movimenti, nessun abbinamento univoco per acquirente e importo).', $this->invoiceSnapshot($f));
            } else {
                $usedOrders[$orderId] = true;
                if (($invoiceOrder[$n]['how'] ?? '') === 'importo') {
                    $this->counts['fatture_agganciate_per_importo']++;
                    $this->issue('fattura_agganciata_per_importo', 'info', $n, $f['partner_nome'], "Agganciata all'ordine {$orderRef} solo per acquirente e importo uguali: verifica.", $this->invoiceSnapshot($f));
                }
            }
            $invoiceId = DB::table('invoices')->insertGetId([
                'order_id' => $orderId, 'number' => $n, 'source' => 'odoo', 'year' => (int) substr($f['data'], 0, 4),
                'type' => str_starts_with($n, 'FT/') ? 'invoice' : 'receipt', 'issued_at' => $when, 'buyer' => json_encode($buyer),
                'net' => $net, 'vat' => $vat, 'total' => $total, 'sink_status' => 'sent', 'sink_ref' => 'odoo', 'created_at' => $when, 'updated_at' => $when,
            ]);
            $itemId = $orderId !== null ? DB::table('order_items')->where('order_id', $orderId)->value('id') : null;
            $descr = $itemId !== null ? (string) DB::table('order_items')->where('id', $itemId)->value('name') : 'Storico Odoo';
            DB::table('invoice_lines')->insert([
                'invoice_id' => $invoiceId, 'order_item_id' => $itemId, 'description' => $descr, 'qty' => 1, 'unit_net' => $net,
                'vat_rate' => $net > 0 ? round($vat / $net * 100, 2) : 22, 'net' => $net, 'vat' => $vat, 'total' => $total,
            ]);
            $this->counts['fatture_create']++;
            if ($f['stato_sdi'] !== '' && str_contains($f['stato_sdi'], 'Rifiutato')) {
                $this->issue('fattura_sdi_rifiutata', 'warning', $n, $f['partner_nome'], 'Fattura «SdI Rifiutato» in Odoo: da verificare se è stata riemessa.', $this->invoiceSnapshot($f));
            } elseif (str_contains($f['stato_sdi'], 'fallito')) {
                $this->issue('fattura_inoltro_fallito', 'info', $n, $f['partner_nome'], '«SdI Accettato, inoltro al partner fallito»: accettata dallo SdI ma non recapitata al cliente.', $this->invoiceSnapshot($f));
            }
            if ($orderId !== null && $f['stato_pagamento'] === 'Pagata') {
                $this->pay($orderId, $total, $when);
            } elseif ($orderId !== null) {
                $this->issue('fattura_non_pagata', 'warning', $n, $f['partner_nome'], "Fattura in stato di pagamento «{$f['stato_pagamento']}».", $this->invoiceSnapshot($f));
            }
        }
    }

    /**
     * @param  array<string,string>  $f
     * @param  array<string,mixed>  $buyer
     * @param  array<string,array<string,string>>  $byNumber
     * @param  array<string,array<string,string>>  $orderRows
     * @param  array<string,int>  $orderIds
     * @param  array<string,int>  $existingCn
     * @param  array<string,int>  $existingInv
     */
    private function importCreditNote(array $f, array $buyer, array $byNumber, array $orderRows, array $orderIds, array $existingCn, array $existingInv): void
    {
        $n = $f['numero'];
        if (isset($existingCn[$n])) {
            return;
        }
        // fattura stornata: stesso partner, importo opposto
        $target = null;
        foreach ($byNumber as $inv) {
            if (! str_starts_with($inv['numero'], 'RINV') && ! str_starts_with($inv['numero'], 'DINV') && $inv['partner_nome'] === $f['partner_nome']
                && abs((float) $inv['totale'] + (float) $f['totale']) < self::TOLERANCE) {
                $target = $inv['numero'];
                break;
            }
        }
        $invRow = $target !== null ? DB::table('invoices')->where('number', $target)->first(['id', 'order_id']) : null;
        if ($invRow === null) {
            $this->issue('nota_credito_non_collegata', 'error', $n, $f['partner_nome'], 'Nota di credito non importata: non trovo la fattura che storna.', $this->invoiceSnapshot($f));

            return;
        }
        $when = $this->utc($f['data']);
        $net = abs((float) $f['imponibile']);
        $vat = abs((float) $f['imposta']);
        $cnId = DB::table('credit_notes')->insertGetId([
            'invoice_id' => $invRow->id, 'order_id' => $invRow->order_id, 'number' => $n, 'source' => 'odoo', 'year' => (int) substr($f['data'], 0, 4),
            'issued_at' => $when, 'reason' => 'Nota di credito storica importata da Odoo (storna '.$target.')',
            'net' => $net, 'vat' => $vat, 'total' => $net + $vat, 'sink_status' => 'sent', 'sink_ref' => 'odoo', 'created_at' => $when, 'updated_at' => $when,
        ]);
        $itemId = $invRow->order_id !== null ? DB::table('order_items')->where('order_id', $invRow->order_id)->value('id') : null;
        DB::table('credit_note_lines')->insert([
            'credit_note_id' => $cnId, 'order_item_id' => $itemId, 'description' => 'Storno '.$target, 'qty' => 1, 'unit_net' => $net,
            'vat_rate' => $net > 0 ? round($vat / $net * 100, 2) : 22, 'net' => $net, 'vat' => $vat, 'total' => $net + $vat,
        ]);
        if ($invRow->order_id !== null) {
            DB::table('orders')->where('id', $invRow->order_id)->update(['status' => 'refunded']);
        }
        $this->counts['note_credito_create']++;
        $this->issue('nota_credito_collegata', 'info', $n, $f['partner_nome'], "Nota di credito collegata alla fattura {$target} per importo e acquirente: ordine segnato come rimborsato (se presente). Verifica.", $this->invoiceSnapshot($f));
    }

    private function pay(int $orderId, float $amount, string $when): void
    {
        if (DB::table('payments')->where('order_id', $orderId)->exists()) {
            return;
        }
        DB::table('payments')->insert([
            'order_id' => $orderId, 'provider' => 'odoo', 'provider_ref' => null, 'method' => 'transfer', 'amount' => $amount,
            'status' => 'paid', 'paid_at' => $when, 'reconciled_at' => $when, 'created_at' => $when, 'updated_at' => $when,
        ]);
        $this->counts['pagamenti_creati']++;
    }

    /**
     * @param  list<array<string,string>>  $movements
     * @param  array<string,int>  $orderIds
     */
    private function linkMovements(array $movements, array $orderIds): void
    {
        $itemOf = DB::table('order_items')->whereIn('order_id', array_values($orderIds))->pluck('id', 'order_id')->all();
        foreach ($movements as $m) {
            $orderId = $orderIds[$m['ordine']] ?? null;
            if ($m['ordine'] === '' || $m['rif_esterno'] === '' || $orderId === null) {
                continue;
            }
            $n = DB::table('pv_movements')->where('external_ref', $m['rif_esterno'])->whereNull('order_id')
                ->update(['order_id' => $orderId, 'order_item_id' => $itemOf[$orderId] ?? null]); // solo collegamento: PV e periodo non cambiano
            $this->counts['movimenti_agganciati'] += $n;
        }
    }

    /**
     * @param  array<string,array<string,string>>  $orderRows
     * @param  array<string,int>  $orderIds
     * @param  array<string,list<array<string,string>>>  $byOrder
     */
    private function compareTotals(array $orderRows, array $orderIds, array $byOrder): void
    {
        foreach ($orderIds as $ref => $id) {
            $o = $orderRows[$ref] ?? null;
            if ($o === null || $o['stato'] === 'Annullato') {
                continue;
            }
            $pr = (float) $o['pr'];
            $mv = $byOrder[$ref] ?? [];
            $pvf = array_sum(array_map(fn ($m) => (float) $m['pv'], $mv)); // PR dell'ordine = PV dei movimenti (familiari o commerciali)
            $snap = $this->orderSnapshot($o) + ['pv_movimenti' => $pvf, 'movimenti' => count($mv)];
            if ($mv === [] && $pr > 0) {
                $this->issue('ordine_senza_movimenti', 'warning', $ref, $o['cliente_nome'], "Ordine con {$pr} PR in Odoo ma nessun movimento PV importato.", $snap);
            } elseif ($mv !== [] && abs($pvf - $pr) > self::TOLERANCE) {
                $this->issue('ordine_pr_diverso_movimenti', 'warning', $ref, $o['cliente_nome'], "PR ordine ({$pr}) diversi dai PV nei movimenti ({$pvf}).", $snap);
            }
            if ($pr == 0.0 && (float) $o['totale'] > 0) {
                $this->issue('ordine_importo_senza_pr', 'info', $ref, $o['cliente_nome'], 'Ordine con importo ma 0 PR (acquisto non qualificante o errore di anagrafica).', $snap);
            }
            $inv = DB::table('invoices')->where('order_id', $id)->first(['number', 'total', 'net']);
            if ($inv !== null && abs((float) $inv->total - (float) $o['totale']) > self::TOLERANCE) {
                $this->issue('fattura_importo_diverso', 'warning', $inv->number, $o['cliente_nome'], "Totale fattura {$inv->total} ≠ totale ordine {$ref} ({$o['totale']}).", $snap + ['fattura_totale' => (float) $inv->total]);
            }
        }
    }

    /**
     * @param  list<array<string,string>>  $movements
     * @param  array<string,array<string,string>>  $orderRows
     * @param  list<array<string,string>>  $invoices
     */
    private function checkMovementsWithoutOrder(array $movements, array $orderRows, array $invoices): void
    {
        $invNumbers = array_fill_keys(array_column($invoices, 'numero'), true);
        $missingOrders = [];
        $missingInvoices = [];
        foreach ($movements as $m) {
            if ($m['ordine'] !== '' && ! isset($orderRows[$m['ordine']])) {
                $missingOrders[$m['ordine']][] = $m;
            }
            if (preg_match('/^(FT\/\d{4}\/\d+|\d{4}\/pri\d+)/', $m['fattura'], $x) === 1 && ! isset($invNumbers[$x[1]])) {
                $missingInvoices[$x[1]][] = $m;
            }
        }
        foreach ($missingOrders as $ref => $list) {
            $this->issue('movimento_ordine_mancante', 'warning', $ref, $list[0]['acquirente'], 'I movimenti PV citano un ordine che non è nell\'export ordini: i PV restano, ma senza ordine collegato.', ['movimenti' => count($list), 'pv' => array_sum(array_map(fn ($m) => (float) $m['pv'], $list))]);
        }
        foreach ($missingInvoices as $num => $list) {
            $this->issue('movimento_fattura_mancante', 'warning', $num, $list[0]['acquirente'], 'I movimenti PV citano una fattura che non è nell\'export fatture.', ['movimenti' => count($list), 'pv' => array_sum(array_map(fn ($m) => (float) $m['pv'], $list))]);
        }
    }

    /** @param array<string,mixed> $users */
    private function resolveBuyer(string $ref, string $name, array $users): ?int
    {
        if ($ref !== '' && isset($users['ref'][$ref])) {
            return $users['ref'][$ref];
        }
        $id = $users['name'][mb_strtolower(trim($name))] ?? null;

        return $id !== null && $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $o @return array<string,mixed> */
    private function orderSnapshot(array $o): array
    {
        return ['ordine' => $o['ref'], 'data' => $o['data'], 'stato' => $o['stato'], 'stato_fattura' => $o['stato_fattura'], 'pr' => (float) $o['pr'], 'imponibile' => (float) $o['imponibile'], 'totale' => (float) $o['totale']];
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private function invoiceSnapshot(array $f): array
    {
        return ['fattura' => $f['numero'], 'data' => $f['data'], 'pr' => (float) $f['pr'], 'imponibile' => (float) $f['imponibile'], 'totale' => (float) $f['totale'], 'sdi' => $f['stato_sdi'], 'pagamento' => $f['stato_pagamento']];
    }

    /** @param array<string,mixed> $odoo */
    private function issue(string $code, string $severity, string $ref, ?string $name, string $message, array $odoo = []): void
    {
        $this->issues[$code.'|'.$ref] = compact('code', 'severity', 'ref', 'name', 'message', 'odoo');
    }

    /** Scrive le differenze: una differenza già nota si aggiorna senza perdere l'approvazione; nulla viene cancellato. */
    private function syncIssues(): void
    {
        $now = now();
        foreach ($this->issues as $i) {
            $existing = DB::table('import_reconciliations')->where(['source' => 'odoo', 'code' => $i['code'], 'subject_ref' => $i['ref']])->first();
            $data = ['severity' => $i['severity'], 'subject_name' => $i['name'], 'message' => $i['message'], 'odoo' => json_encode($i['odoo']), 'updated_at' => $now];
            if ($existing === null) {
                DB::table('import_reconciliations')->insert($data + ['source' => 'odoo', 'code' => $i['code'], 'subject_ref' => $i['ref'], 'status' => 'open', 'created_at' => $now]);
            } else {
                DB::table('import_reconciliations')->where('id', $existing->id)->update($data);
            }
        }
    }

    private function utc(string $local): string
    {
        return CarbonImmutable::parse($local, self::TZ)->utc()->format('Y-m-d H:i:s');
    }

    /** @return list<array<string,string>> */
    private function rows(string $dir, string $file): array
    {
        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            throw new InvalidArgumentException("File mancante: {$path} (genera ordini.csv e fatture.csv con database/import/odoo_storico_etl.py)");
        }
        $h = fopen($path, 'rb');
        $head = fgetcsv($h, 0, ';', '"', '');
        if ($head === false) {
            throw new InvalidArgumentException("File vuoto: {$path}");
        }
        $head[0] = ltrim((string) $head[0], "\xEF\xBB\xBF");
        $rows = [];
        while (($line = fgetcsv($h, 0, ';', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($head, array_pad(array_slice($line, 0, count($head)), count($head), ''));
        }
        fclose($h);

        return $rows;
    }
}
