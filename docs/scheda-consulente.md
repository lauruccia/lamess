# Scheda incaricato 360° e «Funzionalità e viste»

Aggiornato il 14/10/2026. Specifica di partenza: `Addendum_Scheda_Consulente_360.md` (progetto Lamèss; il nome del file di specifica è rimasto quello originale).

## La scheda (Rete → Incaricati → apri un incaricato)

In alto: codice, nome, data di ingresso, codice referral, **stato** (attivo, sospeso, cessato), **qualifica** (solo il nome della pietra: Pearl, Crystal, Sapphire, Ruby, Emerald, Diamond), eventuale «Qualifica mantenuta fino al…», stato KYC, onboarding da completare, sponsor attuale (e originale, se diverso), **da pagare** (liquidazioni aperte). Il badge «Attività del mese» compare solo se il piano in vigore prevede lo stato attivo: nel Piano Incaricati non c'è.

**Pulsanti-contatore** (Team 1° liv., Clienti, Ordini, Liquidazioni, Pagamenti, Contratti IVD, Documenti KYC, Canoni; «Movimenti PV» solo se il piano precedente è visibile): ogni pulsante apre la lista già filtrata su quell'incaricato. Compaiono solo se la lista è accesa nel registro funzionalità e se chi guarda ha il permesso di aprirla.

**Azioni** (icone con descrizione al passaggio del mouse, ciascuna con il proprio permesso e interruttore):
- **Richiedi documenti KYC** (permesso `kyc.manage`): l'incaricato riceve una email e vede il riquadro in *Onboarding*; la richiesta si chiude da sola al caricamento di tutto, con promemoria dopo 3 e 6 giorni. Si può fare anche dall'elenco e in massa.
- **Invia documenti** (permesso `network.manage`): lettera firmata, certificato di firma, tesserino, estratti conto e ricevute, per email; ogni invio finisce nel log.
- **Stampa tesserino**: il tesserino per la vendita porta a porta (con il retro e il QR di verifica pubblica). **Nasce da solo alla firma della lettera d'incarico** (numero = codice attività, validità = durata della lettera), o all'iscrizione fatta dall'admin; non aspetta l'accettazione. Solo per incarichi attivi senza lettera in attesa di firma.
- **Certificato di firma**: PDF con le prove della firma (non disponibile per le lettere importate da Odoo).
- **Riassegna i clienti**, **Forza qualifica**, **Mantieni qualifica** (permesso `network.restructure`): «Mantieni qualifica» congela la qualifica attuale fino a una data, solo con il Piano Incaricati attivo; ogni cambio è nel log.
- **Sospendi / Riattiva / Cessa**, **Aggiungi nota**, **Mostra IBAN**, **Sposta / cambia sponsor** (vedi sotto).

**Tab**: Riepilogo · Anagrafica · Rete · Volumi e rank · Commissioni · Pagamenti · Ordini (con il piano precedente visibile: «Ordini e PV») · Documenti · Audit (solo super_admin). Ogni tab mostra i dati recenti e ha il link «Apri la lista…» per vedere tutto. Nel pannello «Funzionalità e viste» le stesse tab hanno nomi più descrittivi (es. «Riepilogo e carriera», «Liquidazioni e pagamenti»).

- **Riepilogo**: avanzamento di carriera. Con il Piano Incaricati mostra la qualifica, **cosa manca per la prossima** e l'elenco «Cosa fare». I requisiti sono quelli del nuovo piano: **clienti personali** (x / y), **incaricati Pearl al 1° livello**, **colonne con almeno N clienti** (per Ruby) e struttura di qualifiche richiesta. Non ci sono PR, PV, Rank Bonus né Matching.
- **Rete**: sponsor, storico sponsor, **«Team 1° liv.»** (gli incaricati sponsorizzati da lui; «Clienti diretti» sono invece i suoi clienti) con «Ordini del mese» e «Importo del mese» (ordini pagati nel mese, IVA inclusa) e i clienti diretti con gli stessi dati.
- **Documenti**: documenti KYC con stato di verifica, contratti IVD e canoni annuali (19 € IVA compresa, se il canone è attivo).

**Storico** (colonna a destra): tutti gli eventi in ordine di data — cambi di stato, sponsor, qualifiche e premi, contratto, KYC, canoni, liquidazioni, pagamenti, ordini, clienti riassegnati — più le **note interne**. Le note fissate stanno in cima. Le note si aggiungono con «Aggiungi nota»; non si modificano né si cancellano. Il registro di audit non compare qui (contiene l'identità del super_admin): sta nella tab Audit.

**IBAN**: sempre mascherato. «Mostra IBAN» è spento di default; acceso, mostra l'IBAN per intero e ogni visualizzazione finisce nel log.

**Apertura della scheda**: registrata nel log di audit (una volta ogni 10 minuti per la stessa coppia utente/incaricato).

**Modifica dati** (pulsante in alto a destra nella scheda, o «Modifica dati» nel menu ⋮ dell'elenco; per chi ha il permesso `network.manage` e per il super_admin): una pagina a sezioni, con in alto il riepilogo (stato, qualifica, KYC) e il pulsante **Salva modifiche** sempre visibile in fondo. Le sezioni:
- **Anagrafica**: nome, cognome, codice fiscale, data e luogo di nascita (solo maggiorenni).
- **Contatti e residenza**: email di accesso, telefono, PEC, indirizzo, città, CAP, provincia, nazione. Se l'incaricato non ha ancora un accesso, scrivendo l'email lo si crea (con l'interruttore per inviare subito l'invito); data di nascita e recapiti si possono salvare solo insieme all'email.
- **Pagamenti e fisco**: IBAN (mostrato mascherato: per cambiarlo si scrive il nuovo, quello vecchio non viene mai mostrato per intero), intestatario, regime fiscale (ritenuta o partita IVA), verifica KYC (con il permesso `kyc.manage`).
- **Iscrizione e stato**: codice attività (**solo il super_admin lo cambia**), codice referral, date di firma e di ingresso in rete, onboarding completato, tesserino. Sponsor e stato si **vedono ma non si cambiano qui**: sospendere, riattivare, cessare e spostare lo sponsor restano nella scheda, con il loro motivo registrato.
- **Piano e compensi**: compensi bloccati, founder, qualifica massima consentita, «Mantieni qualifica» (data o fino a revoca, con motivo) e, solo per il super_admin con il piano precedente visibile, la qualifica del piano precedente.

Ogni salvataggio scrive nel log di audit i campi cambiati con i valori prima e dopo (l'IBAN solo mascherato).

**Sposta / cambia sponsor**: solo super_admin, in casi estremi (motivo obbligatorio, finisce nel log). L'admin non lo vede, nemmeno con il permesso «ristrutturare la rete».

## L'elenco incaricati (Rete → Incaricati)
Un clic su una riga apre la scheda. La colonna **Qualifica** mostra solo il nome della pietra; qualifica e attività del mese vengono dall'ultimo calcolo ufficiale (se manca, mostrano «—»). «Da pagare» è la somma delle liquidazioni aperte non ancora in un pagamento. Le schede per stato, la colonna «Da pagare» e (con il piano precedente visibile) le colonne PR si accendono da «Funzionalità e viste». La colonna «Qualifica mantenuta fino al» è nascosta di default.

**Albero della rete** (Rete → Albero della rete): ogni qualifica ha il **colore della sua pietra** (perla, azzurro cristallo, blu zaffiro, rosso rubino, verde smeraldo, argento diamante); il nome della qualifica è comunque sempre scritto. Il pannello laterale mostra «cosa manca per il livello successivo».

**Elenco per livelli** (Rete → Elenco per livelli, o pulsante nell'elenco): incaricati in ordine di ramo con rientro per livello, ricerca del punto di partenza, limite di livelli, filtro per stato e colonna «Hanno acquistato» (clienti con almeno un ordine pagato nel periodo scelto).

## Piano Incaricati e piano precedente
- Nel **Piano Incaricati** i PV non esistono più: compensi e clienti si calcolano sugli importi in euro (IVA inclusa) degli ordini pagati, e **la qualifica vale appena raggiunta**. Chi è cliente lo decide l'admin in *Compensi → Piano compensi → Base di calcolo*.
- Il **vecchio piano a PR** si può nascondere in blocco da *Compensi → Visibilità piano precedente*, **solo il super_admin**: spariscono menu, colonne, tab e testi che lo riguardano, senza cancellare né ricalcolare nulla (gli importi storici compaiono come «Compensi del periodo precedente»). Quando è nascosto, schede e area riservata non mostrano PV, PR, Team, Matching, Rank Bonus né Fast Start.
- I requisiti per la prossima qualifica col nuovo piano si vedono nella scheda (tab Riepilogo) e, per l'incaricato, in *Il mio avanzamento* nell'area riservata.
- **Ricevute provvigionali e ritenuta d'acconto sono preparate ma spente** (`LAMESS_RECEIPTS_ENABLED`, `LAMESS_WITHHOLDING_ENABLED`) finché non risponde il commercialista: finché lo sono, il bonifico è pari al maturato e non si emettono ricevute.
- **Canone annuale**: 19 € IVA compresa; importo modificabile e interruttore «Canone annuale attivo» nelle impostazioni (spento: nessun canone, rinnovi gratuiti).

## Funzionalità e viste (Sistema → Funzionalità e viste)

Si accende e si spegne: tab, contatori, storico, note, singoli pulsanti della scheda e **gli elenchi del menu** (Incaricati, Clienti, Ordini…).

- **Spegnere = nascondere e bloccare.** L'indirizzo diretto risponde «vietato»; anche l'azione e l'esportazione della stessa funzione. I dati e i calcoli non cambiano. Riaccendendo si ritrova tutto.
- **Collegate**: spegnendo un elemento si spengono i suoi figli (es. «Pulsanti-contatore» → tutti i contatori).
- **Eccezioni**: per un ruolo o per un singolo utente la regola può essere diversa da quella «per tutti». Vince l'utente, poi il ruolo, poi «per tutti», poi il valore predefinito.
- **Protette** (lucchetto): non si spengono da nessuno.
- **Solo super_admin**: alcune voci (Audit, Sposta sponsor) l'admin non le vede mai.
- **Blocca** (solo super_admin): una regola bloccata l'admin non può cambiarla.
- **Chi può usare il pannello**: il super_admin e gli admin a cui il super_admin concede il permesso «Funzionalità e viste» (`features.manage`), utente per utente.
- Il super_admin vede sempre tutto, anche ciò che è spento per gli altri, così non si chiude fuori.
- Con il piano precedente nascosto, le voci che lo riguardano non compaiono nel pannello.
- Ogni cambio è nel log di audit (chi, quando, prima/dopo, motivo facoltativo).

**Aggiornamento di un'installazione esistente**: eseguire le migrazioni (`php artisan migrate`) e `php artisan lamess:ruoli` per creare i nuovi permessi.

## Per gli sviluppatori

- Elenco chiuso delle funzionalità: `App\Domain\Features\FeatureRegistry`. Per aggiungerne una si dichiara lì (chiave, tipo, gruppo, genitore, default; `legacy` per quelle del piano precedente) e nel codice si chiede `Feature::enabled('chiave')`. Una chiave non dichiarata è sempre abilitata.
- Le liste del menu si spengono con la chiave `backoffice.resource.<slug>`: il trait `GatedByPermission` la controlla da solo.
- Il controllo deve stare lato server (route, azioni, esportazioni), non solo nella vista.
- Dati della scheda: `App\Domain\Consultants\Consultant360` (sola lettura, nessun calcolo nuovo). Avanzamento di carriera e requisiti: `App\Domain\Career\CareerProgress`. Viste: `resources/views/partials/c360-*.blade.php`.
- Visibilità del piano precedente: `App\Domain\Plan\LegacyPlan` (`hidden()` / `visible()`).
- Filtro delle liste per incaricato: `App\Filament\Support\ConsultantFilter` (URL `?filters[consultant][value]=ID`).

## Non ancora fatto

- Gli interruttori sulla **dashboard dell'incaricato** (area pubblica): oggi coprono solo il back-office.
- Attività/to-do con scadenza, PDF della scheda. Il Fast Start non è nel Piano Incaricati.
- Ordine e nomi delle tab personalizzabili, profili salvati, accensione pianificata.
- Caricamento delle tab «a richiesta»: oggi si leggono tutte all'apertura (poche righe ciascuna).
