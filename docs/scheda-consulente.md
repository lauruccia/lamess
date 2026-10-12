# Scheda consulente 360° e «Funzionalità e viste»

Aggiornato il 05/10/2026. Specifica di partenza: `Addendum_Scheda_Consulente_360.md` (progetto Lamèss).

## La scheda (Rete → Consulenti → apri un consulente)

In alto: codice, nome, **stato consulente** (contratto: attivo, sospeso, cessato), **qualifica**, **attività del mese**, KYC, **da pagare** (liquidazioni aperte), codice referral, sponsor, diretti.

**Pulsanti-contatore** (Diretti, Clienti, Ordini, Movimenti PV, Liquidazioni, Pagamenti, Contratti IVD, Documenti KYC, Canoni): ogni pulsante apre la lista già filtrata su quel consulente. Compaiono solo se la lista è accesa nel registro funzionalità e se chi guarda ha il permesso di aprirla.

**Tab**: Riepilogo e carriera · Anagrafica e contratto · Rete · Volumi e rank · Commissioni · Liquidazioni e pagamenti · Ordini e movimenti PV · Documenti e KYC · Audit (solo super_admin). Ogni tab mostra i dati recenti e ha il link «Apri la lista…» per vedere tutto.

**Storico** (colonna a destra): tutti gli eventi in ordine di data — cambi di stato, sponsor, qualifiche e premi, contratto, KYC, canoni, liquidazioni, pagamenti, ordini, clienti riassegnati — più le **note interne**. Le note fissate stanno in cima. Le note si aggiungono con «Aggiungi nota»; non si modificano né si cancellano. Il registro di audit non compare qui (contiene l'identità del super_admin): sta nella tab Audit.

**IBAN**: sempre mascherato. «Mostra IBAN» è spento di default; acceso, mostra l'IBAN per intero e ogni visualizzazione finisce nel log.

**Apertura della scheda**: registrata nel log di audit (una volta ogni 10 minuti per la stessa coppia utente/consulente).

**Sposta / cambia sponsor**: solo super_admin, in casi estremi (motivo obbligatorio, finisce nel log). L'admin non lo vede, nemmeno con il permesso «ristrutturare la rete».

## L'elenco consulenti (Rete → Consulenti)
Un clic su una riga apre la scheda. Qualifica, attività del mese e PR vengono dall'ultimo calcolo ufficiale (se manca, mostrano «—»). «Da pagare» è la somma delle liquidazioni aperte non ancora in un pagamento. Le schede per stato, le colonne PR e «Da pagare» si accendono da «Funzionalità e viste».

## Funzionalità e viste (Sistema → Funzionalità e viste)

Si accende e si spegne: tab, contatori, storico, note, singoli pulsanti della scheda e **gli elenchi del menu** (Consulenti, Clienti, Ordini…).

- **Spegnere = nascondere e bloccare.** L'indirizzo diretto risponde «vietato»; anche l'azione e l'esportazione della stessa funzione. I dati e i calcoli non cambiano. Riaccendendo si ritrova tutto.
- **Collegate**: spegnendo un elemento si spengono i suoi figli (es. «Pulsanti-contatore» → tutti i contatori).
- **Eccezioni**: per un ruolo o per un singolo utente la regola può essere diversa da quella «per tutti». Vince l'utente, poi il ruolo, poi «per tutti», poi il valore predefinito.
- **Protette** (lucchetto): non si spengono da nessuno.
- **Solo super_admin**: alcune voci (Audit, Sposta sponsor) l'admin non le vede mai.
- **Blocca** (solo super_admin): una regola bloccata l'admin non può cambiarla.
- **Chi può usare il pannello**: il super_admin e gli admin a cui il super_admin concede il permesso «Funzionalità e viste» (`features.manage`), utente per utente.
- Il super_admin vede sempre tutto, anche ciò che è spento per gli altri, così non si chiude fuori.
- Ogni cambio è nel log di audit (chi, quando, prima/dopo, motivo facoltativo).

**Aggiornamento di un'installazione esistente**: eseguire le migrazioni (`php artisan migrate`) e `php artisan lamess:ruoli` per creare il nuovo permesso.

## Per gli sviluppatori

- Elenco chiuso delle funzionalità: `App\Domain\Features\FeatureRegistry`. Per aggiungerne una si dichiara lì (chiave, tipo, gruppo, genitore, default) e nel codice si chiede `Feature::enabled('chiave')`. Una chiave non dichiarata è sempre abilitata.
- Le liste del menu si spengono con la chiave `backoffice.resource.<slug>`: il trait `GatedByPermission` la controlla da solo.
- Il controllo deve stare lato server (route, azioni, esportazioni), non solo nella vista.
- Dati della scheda: `App\Domain\Consultants\Consultant360` (sola lettura, nessun calcolo nuovo). Viste: `resources/views/partials/c360-*.blade.php`.
- Filtro delle liste per consulente: `App\Filament\Support\ConsultantFilter` (URL `?filters[consultant][value]=ID`).

## Non ancora fatto

- Gli interruttori sulla **dashboard del consulente** (area pubblica): oggi coprono solo il back-office.
- Fast Start nella scheda (in attesa della revisione del cliente), attività/to-do con scadenza, PDF della scheda.
- Ordine e nomi delle tab personalizzabili, profili salvati, accensione pianificata.
- Caricamento delle tab «a richiesta»: oggi si leggono tutte all'apertura (poche righe ciascuna).
