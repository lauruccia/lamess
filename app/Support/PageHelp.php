<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Plan\LegacyPlan;
use Filament\Facades\Filament;
use Illuminate\Support\HtmlString;

/**
 * Guida in pagina del back-office: «a cosa serve», significato delle colonne non ovvie e delle azioni.
 * I testi sono sintetici di proposito: i dettagli operativi stanno nel manuale. Per cambiare un testo si modifica solo questo file.
 */
final class PageHelp
{
    /** @return array<string, array{scopo: string, colonne?: array<string,string>, azioni?: array<string,string>, nota?: string}> */
    private static function entries(): array
    {
        return [
            // ---------- Pagine ----------
            'cruscotto' => ['scopo' => "Colpo d'occhio sul mese scelto: fatturato, punti, compensi, incaricati e pratiche in attesa. Cambia il periodo dal menu in alto a destra.",
                'colonne' => ['Fatturato (CV)' => 'Volume commissionabile del mese: base su cui si calcolano i compensi.', 'PV' => 'Punti volume generati dagli acquisti confermati.', 'Payout' => 'Quota del fatturato che diventa compenso (compensi / CV).', 'Compensi trattenuti' => 'Compensi maturati ma non pagabili finché manca un requisito (es. onboarding o KYC).', 'Cron del server' => 'Se è «Mai partito» o «Fermo», canoni, fatture, abbonamenti e chiusure automatiche non girano.'],
                'nota' => 'I riquadri gialli («da accettare», «da verificare», «da incassare») indicano lavoro in coda per il tuo team.'],
            'albero-rete' => ['scopo' => 'Mostra la struttura degli incaricati: chi ha sponsorizzato chi. Serve a controllare la rete, trovare un ramo e verificare linee e livelli.',
                'nota' => 'Gli spostamenti di ramo si fanno solo dalle operazioni dedicate e lasciano sempre una traccia nel log di audit.'],
            'impostazioni' => ['scopo' => 'Dati e regole generali del sistema: dati del venditore (fatture e statini), automatismi, pagamenti e parametri di funzionamento.',
                'nota' => 'Completa per prima cosa i dati del venditore: compaiono su fatture, statini e ricevute.'],
            'verifica-audit' => ['scopo' => 'Controlla che il log di audit non sia stato alterato: ogni riga è legata alla precedente, quindi una modifica successiva si nota.',
                'nota' => 'Eseguila periodicamente e prima di una chiusura importante; un esito negativo va segnalato subito al responsabile tecnico.'],
            'backup' => ['scopo' => 'Crea e scarica una copia di sicurezza del database. Conserva le copie fuori dal server.',
                'nota' => 'Fai un backup prima di operazioni importanti: chiusura di un periodo, import da Odoo, aggiornamenti del programma.'],
            'funzionalita' => ['scopo' => 'Accende o spegne funzioni e viste del portale (per esempio wallet o ricevute) senza intervenire sul codice.',
                'nota' => 'Spegnere una funzione la nasconde agli utenti ma non cancella i dati già registrati.'],
            'controllo-messa-online' => ['scopo' => 'Lista di controllo prima della messa online: segnala ciò che manca o è impostato in modo non sicuro (cron, dati del venditore, chiavi, backup).',
                'nota' => 'Ogni voce non verde va risolta prima di aprire il portale agli incaricati.'],

            // ---------- Rete ----------
            'ConsultantResource' => ['scopo' => 'Elenco di tutti gli incaricati con stato, qualifica e andamento del mese. È il punto di partenza per aprire la scheda completa di una persona.',
                'colonne' => ['Qualifica' => 'Livello raggiunto nel piano compensi.', 'Attività mese' => 'Se l’incaricato è «attivo» nel mese (soglia di punti prevista dal piano): serve per maturare i bonus.', 'Sponsor' => 'Incaricato che lo ha introdotto.', 'Diretti' => 'Incaricati sponsorizzati direttamente.', 'PR pers. / gruppo' => 'Punti ricompensa personali e dell\'intero gruppo.', 'Da pagare' => 'Compensi maturati non ancora pagati.', 'KYC' => 'Verifica dell\'identità: serve per poter pagare.', 'Onb.' => 'Onboarding completato (documento, IBAN, dati fiscali).', 'Referral' => 'Codice del link con cui porta nuovi clienti.']],
            'CustomerResource' => ['scopo' => 'Clienti registrati (FAN) con l’incaricato di riferimento e il loro storico di acquisti.',
                'colonne' => ['Incaricato' => 'A chi vanno i punti dei suoi acquisti.', 'Origine' => 'Come è stato assegnato: link referral, codice inserito, assegnazione predefinita o manuale.', 'Ordini / Speso' => 'Solo ordini pagati (pagato, spedito, parzialmente rimborsato).', 'Ultimo acq.' => 'Data dell\'ultimo ordine pagato; «mai» se non ha acquistato.'],
                'azioni' => ['Riassegna' => 'Sposta il cliente a un altro incaricato (anche in blocco dalla selezione); il motivo resta nello storico.']],
            'IvdContractResource' => ['scopo' => 'Domande di chi vuole diventare incaricato: lettera d\'incarico firmata da accettare o rifiutare.',
                'colonne' => ['Stato' => 'In attesa di firma, firmata (da esaminare), accettata, rifiutata o scaduta.', 'Scade il' => 'Fine di validità dell\'incarico.'],
                'azioni' => ['Accetta' => 'Attiva l\'account come incaricato.', 'Rifiuta' => 'L\'account torna Cliente; i punti maturati nel frattempo restano allo sponsor proposto.', 'Lettera firmata' => 'Apre il PDF della lettera.']],
            'KycDocumentResource' => ['scopo' => 'Documenti d\'identità caricati dagli incaricati. Senza verifica approvata non si possono pagare i compensi.',
                'azioni' => ['Apri' => 'Mostra il documento caricato.', 'Approva' => 'L’incaricato risulta verificato.', 'Rifiuta' => 'L’incaricato dovrà caricare di nuovo il documento.'],
                'nota' => 'I documenti sono dati personali: aprili solo per la verifica.'],
            'AnnualFeeResource' => ['scopo' => 'Canoni annuali degli incaricati, con scadenza e stato di incasso.',
                'colonne' => ['Stato' => 'Da incassare, pagato o scaduto.', 'Metodo' => 'Come è stato pagato.'],
                'azioni' => ['Segna pagato' => 'Registra l\'incasso del canone.']],
            'AccountTransferResource' => ['scopo' => 'Richieste di cessione di un codice incaricato da una persona a un\'altra.',
                'colonne' => ['Cedente / Cessionario' => 'Chi cede e chi riceve il codice.', 'Fee €' => 'Importo dovuto per la cessione.'],
                'azioni' => ['Fee pagata' => 'Registra l\'incasso della fee.', 'Approva' => 'Esegue la cessione.', 'Respingi' => 'Chiude la richiesta senza cedere il codice.']],
            'InheritanceCaseResource' => ['scopo' => 'Pratiche di successione per incaricati deceduti: il codice e i compensi passano agli aventi diritto.',
                'colonne' => ['Compensi €' => 'Importo maturato oggetto della pratica.']],

            // ---------- Compensi ----------
            'PeriodResource' => ['scopo' => 'Mesi di calcolo dei compensi. Ogni periodo passa da aperto a in calcolo, in revisione e chiuso; da chiuso i numeri non cambiano più.',
                'colonne' => ['Fatturato (CV)' => 'Volume commissionabile del mese.', 'Compensi' => 'Totale maturato secondo l\'ultimo calcolo.', 'Payout' => 'Compensi / fatturato.', 'Movimenti' => 'Movimenti confermati; sotto, quelli ancora da pagare.', 'Ultimo calcolo' => '«Ufficiale» è quello che vale; «Stima» è provvisorio.'],
                'nota' => 'La riga evidenziata è il periodo ancora in corso.'],
            'PvMovementResource' => ['scopo' => 'Registro dei punti: ogni vendita, reso o rettifica genera un movimento che alimenta il calcolo.',
                'colonne' => ['Tipo / Causale' => 'Vendita, reso, rettifica o migrazione e relativo motivo.', 'PV / CV' => 'Punti volume e volume commissionabile (negativi per i resi).', 'Stato' => 'In attesa di pagamento, confermato o annullato: solo i confermati contano.', 'Escluso' => 'Movimento escluso dai bonus.'],
                'azioni' => ['Conferma' => 'Rende il movimento valido per il calcolo.', 'Annulla' => 'Lo toglie dal calcolo.']],
            'CompPlanVersionResource' => ['scopo' => 'Versioni del piano compensi (qualifiche, percentuali, regole). Ogni periodo usa la versione attiva in quel momento.',
                'colonne' => ['Efficace dal' => 'Data da cui la versione si applica.', 'Attivato il' => 'Quando è stata resa operativa.'],
                'nota' => 'Una versione attivata non si modifica: per cambiare le regole se ne crea una nuova.'],
            'SettlementResource' => ['scopo' => 'Liquidazioni mensili per incaricato: cosa è maturato e cosa è trattenuto.',
                'colonne' => ['Qualifica' => 'Qualifica effettiva dell’incaricato nel mese (dal calcolo ufficiale).', 'Attivo' => 'Se l’incaricato era attivo: se non lo è, i bonus di qualifica possono essere trattenuti.', 'Righe' => 'Numero di righe di compenso della liquidazione.', 'Maturato' => 'Compensi del mese (negativo = recuperi).', 'Trattenuto' => 'Parte non pagabile finché manca un requisito.', 'Saldo' => 'Tutto ciò che l’incaricato deve ancora ricevere, mesi precedenti compresi.', 'Bonifico' => 'Importo del bonifico che comprende la liquidazione, al netto delle trattenute.', 'Pagato / CRO' => 'Data e riferimento del bonifico eseguito.', 'IBAN / KYC' => 'Requisiti che, se mancano, bloccano il pagamento.', 'Situazione' => 'Pronto, in pagamento, pagato, nel wallet, sotto soglia, negativo o bloccato (passa il mouse sul badge per il motivo).'],
                'azioni' => ['Statino' => 'Apre l\'estratto conto dell’incaricato per quel mese.']],
            'PayoutResource' => ['scopo' => 'Pagamenti dei compensi: gruppi di liquidazioni da pagare con bonifico.',
                'colonne' => ['Stato' => 'Da pagare, pagato o annullato.', 'Esportato' => 'Se è già finito nel file bancario (SEPA).'],
                'azioni' => ['Segna pagato' => 'Conferma che il bonifico è stato eseguito.', 'Annulla' => 'Le liquidazioni tornano disponibili per un nuovo pagamento.']],
            'WithdrawalRequestResource' => ['scopo' => 'Richieste di prelievo dal wallet fatte dagli incaricati.',
                'colonne' => ['Ritenuta / INPS' => 'Trattenute fiscali e previdenziali.', 'Commissione' => 'Costo del bonifico.', 'Da pagare entro' => 'Termine entro cui eseguire il pagamento.'],
                'azioni' => ['Approva' => 'Ricalcola le trattenute e crea il pagamento da eseguire: poi compare in Pagamenti e nel file SEPA.', 'Rifiuta' => 'Chiude la richiesta; l\'importo resta nel wallet.']],
            'WalletTransactionResource' => ['scopo' => 'Registro dei movimenti del wallet degli incaricati, dal più recente.',
                'colonne' => ['Importo €' => 'Positivo = accredito, negativo = addebito.', 'Saldo €' => 'Saldo dopo il movimento.']],
            'CommissionReceiptResource' => ['scopo' => 'Ricevute provvigionali emesse agli incaricati con le trattenute di legge.',
                'colonne' => ['Regime' => 'Regime fiscale dell’incaricato.', 'Ritenuta € / INPS €' => 'Trattenute applicate.', 'Netto €' => 'Importo effettivamente pagato.'],
                'azioni' => ['PDF' => 'Scarica la ricevuta.']],

            // ---------- Vendite ----------
            'OrderResource' => ['scopo' => 'Ordini del negozio, dalla richiesta alla consegna, con resi e rimborsi. Clicca una riga per aprire il dettaglio con righe, indirizzi, pagamenti, documenti e cronologia.',
                'colonne' => ['Tipo' => 'Cliente o incaricato.', 'PV a' => 'Incaricato a cui vanno i punti.', 'Stato' => 'In attesa di pagamento, pagato, in preparazione, spedito, consegnato, annullato o reso.', 'PV' => 'Punti generati dall\'ordine.'],
                'azioni' => ['Conferma pagamento' => 'Conferma l\'incasso (bonifico): genera punti e fattura.', 'In preparazione' => 'Il magazzino sta preparando il pacco.', 'Spedisci' => 'Segna l\'ordine spedito con corriere e tracking; il cliente riceve un\'email.', 'Segna consegnato' => 'Chiude l\'evasione.', 'Annulla' => 'Annulla un ordine non pagato.', 'Reso e rimborso' => 'Emette nota di credito, storna i punti, rimette la merce in giacenza e restituisce i soldi sul metodo con cui ha pagato (carta e KMoney in automatico; il bonifico si rimborsa a mano).', 'Rimborso eseguito' => 'Segna come fatto un rimborso eseguito fuori dal portale.', 'Riprova rimborso' => 'Ritenta un rimborso automatico non riuscito.', 'Aggiungi nota' => 'Nota interna: la vede solo il personale.', 'Esporta CSV' => 'Scarica gli ordini filtrati in un file per Excel.'],
                'nota' => 'La spedizione non si rimborsa nel reso (decisione OPEN-33): la nota di credito riguarda solo le righe prodotto.'],
            'negozio-pagamenti' => ['scopo' => 'Metodi di pagamento offerti ai clienti, istruzioni per il bonifico, documento per i privati e regole degli abbonamenti.',
                'nota' => 'Le chiavi di Stripe e di KMoney non si cambiano da qui: sono nel file .env del server.'],
            'SubscriptionResource' => ['scopo' => 'Abbonamenti dei clienti: acquisti ricorrenti con impegno minimo.',
                'colonne' => ['Impegno (mesi)' => 'Durata minima concordata.', 'Cicli' => 'Addebiti già eseguiti.', 'Ultimo errore' => 'Motivo dell\'ultimo addebito non riuscito.', 'Disdetta anticipata' => 'Se è stato chiesto di chiudere prima dell\'impegno.'],
                'azioni' => ['Pausa' => 'Sospende gli addebiti.', 'Riprendi' => 'Riattiva gli addebiti.', 'Annulla' => 'Chiude l\'abbonamento.']],
            'InvoiceResource' => ['scopo' => 'Fatture emesse dal negozio, con invio al Sistema di Interscambio.',
                'colonne' => ['Tipo' => 'Fattura o altro documento.', 'Consegna' => 'Stato dell\'invio elettronico.'],
                'azioni' => ['PDF' => 'Copia leggibile.', 'XML' => 'File FatturaPA.', 'Reinvia' => 'Ripete l\'invio se la consegna è fallita.']],
            'CreditNoteResource' => ['scopo' => 'Note di credito emesse per resi e rimborsi, collegate alla fattura originale.',
                'azioni' => ['PDF' => 'Copia leggibile.', 'XML' => 'File FatturaPA.', 'Reinvia' => 'Ripete l\'invio.']],
            'ProductResource' => ['scopo' => 'Il catalogo del negozio: crea e modifica i prodotti, carica le foto, decidi cosa è visibile e tieni d\'occhio la giacenza.',
                'colonne' => ['Prezzo IVA incl.' => 'Prezzo che vede il cliente; sotto, il listino barrato se impostato.', 'Giacenza' => 'Pezzi disponibili; «—» = non gestita, sempre disponibile.', 'Pubblicato' => 'Interruttore: spento = nascosto dal negozio e non acquistabile.', 'PV / CV' => 'Punti del prodotto (visibili con il selettore colonne).'],
                'azioni' => ['Duplica' => 'Crea una copia nascosta da completare.', 'Nascondi / Pubblica' => 'Dalla selezione di più righe.'],
                'nota' => 'Cambiare prezzo o punti vale per i nuovi ordini, non per quelli già fatti. Un prodotto già venduto non si cancella: si nasconde.'],
            'ProductCategoryResource' => ['scopo' => 'Le categorie con cui il cliente filtra il negozio, con le loro sottocategorie (un solo livello). Servono anche per limitare uno sconto a un gruppo di prodotti.',
                'colonne' => ['Categoria' => 'Le sottocategorie stanno sotto la madre, con la freccia ↳.', 'Sottocategorie' => 'Quante ne ha; «—» se nessuna.', 'Prodotti' => 'Prodotti assegnati direttamente a quella categoria.', 'Visibile' => 'Spenta: la categoria sparisce dal negozio insieme alle sue sottocategorie, i prodotti restano.'],
                'azioni' => ['Aggiungi sottocategoria' => 'Il «+» sulla riga di una categoria principale: apre il modulo con la madre già scelta.'],
                'nota' => 'Una categoria con sottocategorie non si elimina: prima eliminale o spostale. Nel negozio, aprendo una categoria si vedono anche i prodotti delle sue sottocategorie, e uno sconto su una categoria vale anche per le sue sottocategorie.'],
            'DiscountResource' => ['scopo' => 'Codici promozionali e sconti automatici: percentuale, importo fisso, scaglioni di quantità («più compri meno paghi») e spedizione gratuita.',
                'colonne' => ['Utilizzi' => 'Quante volte è stato usato, sul massimo se impostato.', 'Stato' => 'Attivo, programmato, scaduto, esaurito o disattivato.'],
                'nota' => 'Importi fissi e spese minime sono IVA inclusa. Se due sconti sono applicabili vince il più conveniente, a meno che siano entrambi cumulabili. I PV delle righe scontate seguono la politica D02 del piano compensi.'],
            'BundleRuleResource' => ['scopo' => 'Regole di omaggio: acquistando una certa quantità di un prodotto se ne riceve un altro in regalo.',
                'colonne' => ['Acquisti / N' => 'Prodotto e quantità da acquistare.', 'Omaggio / M' => 'Prodotto e quantità regalati.', 'Ripetibile' => 'Se l\'omaggio si ripete a ogni multiplo.']],
            'ShippingRateResource' => ['scopo' => 'Costo di spedizione per paese.', 'colonne' => ['Netto €' => 'Costo senza IVA.']],

            // ---------- Assistenza ----------
            'TicketResource' => ['scopo' => 'Richieste di assistenza e reclami degli incaricati.',
                'colonne' => ['Stato' => 'Aperto, in lavorazione o chiuso.', 'In carico a' => 'Operatore che se ne occupa.', 'Ultimo messaggio' => 'Quando è arrivata l\'ultima risposta.']],
            'ComplianceCaseResource' => ['scopo' => 'Segnalazioni di condotte non conformi alle regole (materiali, comunicazioni, comportamenti).',
                'colonne' => ['Categoria' => 'Tipo di violazione contestata.']],
            'SanctionResource' => ['scopo' => 'Provvedimenti presi verso gli incaricati (richiami, sospensioni).',
                'colonne' => ['Dal / Fino al' => 'Periodo in cui il provvedimento è in vigore.', 'Caso' => 'Caso di conformità da cui deriva.'],
                'azioni' => ['Revoca' => 'Annulla il provvedimento prima della scadenza.']],
            'NewsPostResource' => ['scopo' => 'Novità pubblicate nell\'area degli incaricati e dei clienti.', 'colonne' => ['Rivolta a' => 'Chi le vede.', 'Evidenza' => 'In primo piano.']],
            'CorporateEventResource' => ['scopo' => 'Eventi e corsi con iscrizioni.',
                'azioni' => ['Iscritti' => 'Elenco di chi è iscritto.', 'Riempi i posti liberi' => 'Assegna i posti rimasti.']],
            'DownloadResource' => ['scopo' => 'Materiali scaricabili dagli utenti, con regole su chi può vederli.',
                'colonne' => ['Per' => 'Destinatari.', 'Qualifica min.' => 'Qualifica richiesta.', 'Filigrana' => 'Se il file viene marchiato con il nome di chi lo scarica.']],
            'DownloadLogResource' => ['scopo' => 'Registro di chi ha scaricato cosa e quando.'],
            'PromoMaterialResource' => ['scopo' => 'Materiali promozionali proposti dagli incaricati, da controllare prima che siano usati.',
                'azioni' => ['Approva' => 'Autorizza l\'uso del materiale.', 'Non approvare' => 'Lo respinge con motivazione.']],

            // ---------- Sistema ----------
            'AuditLogResource' => ['scopo' => 'Registro non modificabile delle operazioni sensibili: chi ha fatto cosa e quando.', 'colonne' => ['Chi' => 'Utente che ha eseguito l\'azione.', 'IP' => 'Indirizzo da cui è partita.']],
            'ImportReconciliationResource' => ['scopo' => 'Differenze emerse nell\'importazione da Odoo, da controllare una a una.',
                'colonne' => ['Gravità' => 'Importanza dell\'anomalia.', 'Riferimento' => 'Documento o record di origine.'],
                'azioni' => ['Approva' => 'Segna l\'anomalia come verificata (anche più righe insieme).']],
            'UserResource' => ['scopo' => 'Utenti del back-office e relativi ruoli.',
                'colonne' => ['Ruolo' => 'Cosa può vedere e fare.', '2FA' => 'Se ha attivato la verifica in due passaggi.'],
                'azioni' => ['Nuova password' => 'Imposta una password temporanea.', 'Azzera 2FA' => 'Permette di configurare di nuovo la verifica a due passaggi.', 'Revoca accesso' => 'Disattiva l\'utente.']],
        ];
    }

    /** Guida della pagina corrente, oppure null se non c'è. Con il vecchio piano nascosto toglie i riferimenti ai punti. */
    public static function current(): ?array
    {
        $h = self::resolve();

        return $h !== null && LegacyPlan::hidden() ? self::withoutLegacy($h) : $h;
    }

    /**
     * @param  array<string, mixed>  $h
     * @return array<string, mixed>|null
     */
    private static function withoutLegacy(array $h): ?array
    {
        if (str_starts_with((string) ($h['scopo'] ?? ''), 'Registro dei punti')) {
            return null;
        }
        $swap = [
            ', punti,' => ',', ' soglia di punti prevista dal piano' => ' requisiti previsti dal piano', 'A chi vanno i punti dei suoi acquisti.' => 'Incaricato a cui fa capo il cliente.',
            'Incaricato a cui vanno i punti.' => 'Incaricato a cui fa capo l\'ordine.', 'Cambiare prezzo o punti vale' => 'Cambiare il prezzo vale',
            ' I PV delle righe scontate seguono la politica D02 del piano compensi.' => '',
        ];
        $clean = static fn (string $s): string => strtr($s, $swap);
        foreach (['scopo', 'nota'] as $k) {
            if (isset($h[$k])) {
                $h[$k] = $clean((string) $h[$k]);
            }
        }
        foreach (['colonne', 'azioni'] as $group) {
            if (! isset($h[$group])) {
                continue;
            }
            $out = [];
            foreach ($h[$group] as $name => $text) {
                if (in_array($name, ['PV', 'PV / CV', 'PV a', 'PR pers. / gruppo'], true)) {
                    continue;
                }
                $out[$name] = $clean((string) $text);
            }
            $h[$group] = $out;
        }

        return $h;
    }

    /** @return array<string, mixed>|null */
    private static function resolve(): ?array
    {
        $seg = array_values(array_filter(explode('/', trim(request()->path(), '/'))));
        $slug = $seg[1] ?? null;
        $entries = self::entries();

        if ($slug === null) {
            return $entries['cruscotto'] ?? null;
        }
        if (isset($entries[$slug])) {
            return $entries[$slug];
        }
        // solo l'elenco delle risorse (non scheda, modifica o creazione)
        if (isset($seg[2])) {
            return null;
        }
        foreach (Filament::getCurrentPanel()?->getResources() ?? [] as $resource) {
            if (! is_string($resource) || ! class_exists($resource)) {
                continue;
            }
            if ($resource::getSlug() === $slug) {
                return $entries[class_basename($resource)] ?? null;
            }
        }

        return null;
    }

    public static function render(): ?HtmlString
    {
        $h = self::current();
        if ($h === null) {
            return null;
        }
        $e = fn (string $s) => e($s);
        $out = '<aside class="lm-help" aria-label="Guida della pagina"><div class="lm-help-main"><span class="lm-help-title">A cosa serve</span><p>'.$e($h['scopo']).'</p></div>';
        $rows = '';
        foreach ($h['colonne'] ?? [] as $k => $v) {
            $rows .= '<div><dt>'.$e($k).'</dt><dd>'.$e($v).'</dd></div>';
        }
        $acts = '';
        foreach ($h['azioni'] ?? [] as $k => $v) {
            $acts .= '<div><dt>'.$e($k).'</dt><dd>'.$e($v).'</dd></div>';
        }
        if ($rows !== '' || $acts !== '' || isset($h['nota'])) {
            $out .= '<details class="lm-help-more"><summary>Colonne, azioni e avvertenze</summary>';
            if ($rows !== '') {
                $out .= '<h4>Colonne</h4><dl>'.$rows.'</dl>';
            }
            if ($acts !== '') {
                $out .= '<h4>Azioni</h4><dl>'.$acts.'</dl>';
            }
            if (isset($h['nota'])) {
                $out .= '<p class="lm-help-note"><strong>Da sapere.</strong> '.$e($h['nota']).'</p>';
            }
            $out .= '<p class="lm-help-foot">Istruzioni complete nel manuale utente.</p></details>';
        }

        return new HtmlString($out.'</aside>');
    }
}
