# Messa online: controlli, migrazione e piano di ritorno

## 1. Prima di tutto: il «Controllo messa online»
In `/admin` → **Sistema → Controllo messa online** (solo super_admin), oppure da terminale `php artisan lamess:controlla`.
Elenca ogni controllo con **OK**, **ATTENZIONE** (da valutare) o **BLOCCO** (da sistemare prima di aprire ai clienti). Si ripete dopo ogni correzione fino a non avere più blocchi.

I blocchi tipici: debug acceso, indirizzo senza https, 2FA non obbligatoria, token di installazione ancora nel `.env`, cron non attivo, pagamento con carta e firma elettronica ancora «di prova», dati del venditore incompleti, catena del log compromessa.
Con i driver reali compaiono controlli in più: chiavi e webhook di Stripe (BLOCCO se manca `STRIPE_SECRET` o `STRIPE_WEBHOOK_SECRET`, ATTENZIONE se la chiave è di prova), verifica d'identità online (ATTENZIONE se è manuale, BLOCCO se attiva senza chiave), invio delle fatture (ATTENZIONE con `fake` o `file`, BLOCCO con `mail` senza destinatario) e completezza dei dati del venditore per la fattura elettronica (partita IVA a 11 cifre, CAP, comune, provincia).

## 2. Sequenza consigliata
1. **Prova generale sul sito di prova** (un sottodominio, es. `prova.tuodominio.it`, stesso procedimento dell'installazione): importazione, calcolo di un mese, confronto con Odoo (`lamess:verifica-odoo`).
2. **Controllo sicurezza**: Controllo messa online senza blocchi; verifica del log; backup creato e scaricato; prova del ripristino sul sito di prova.
3. **Finestra di migrazione** (consigliata di sera o nel fine settimana). Avvisare i consulenti.
4. **Congelamento di Odoo**: da quel momento non si registrano più ordini né movimenti in Odoo.
5. **Esportazione finale da Odoo** → importazione nel portale (`lamess:importa-odoo`, `lamess:importa-storico`), poi riconciliazione: i totali devono coincidere con quelli di Odoo (pagina Riconciliazioni).
6. **Mesi già pagati**: segnarli «storico già pagato» (scheda del periodo) prima di preparare qualsiasi pagamento.
7. **Backup subito dopo l'importazione** (Sistema → Backup → Crea) e scaricarlo.
8. **Cambio del dominio**: il dominio punta al nuovo sito.
9. **Controlli dopo l'apertura**: accesso admin con 2FA, un ordine di prova, riquadro «Operazioni automatiche» = Attivo, Controllo messa online.
10. Solo a cose verificate: accendere l'invio email (Impostazioni → Email → «Invio email attivo»).

## 3. Piano di ritorno (se qualcosa non va)
**Prima dell'apertura** nulla è irreversibile: Odoo resta in funzione e il vecchio dominio non è cambiato. Basta non cambiare il dominio.

**Dopo l'apertura, entro poche ore (nessun ordine vero nel portale)**
1. Rimettere il dominio sul vecchio sito (cPanel → Domini).
2. Odoo riprende a lavorare; nulla è stato perso perché i dati nel portale erano una copia.

**Dopo l'apertura, con ordini già entrati nel portale**
1. Non tornare indietro alla cieca: gli ordini nuovi esistono solo nel portale. Si mette il sito in manutenzione (pagina «Manutenzione in corso», `php artisan down` oppure un file `.htaccess` di cortesia).
2. Si scarica un backup (Sistema → Backup) e lo si conserva.
3. Si corregge il problema, oppure si ripristina l'ultimo backup buono:
   cPanel → **phpMyAdmin** → scegli il database → **Importa** → file `lamess-….sql.gz` (si importa anche compresso). L'importazione sostituisce tutte le tabelle e ricrea i trigger di protezione del log.
4. Dopo il ripristino: Controllo messa online e verifica del log.
5. Gli ordini entrati dopo il backup vanno riportati a mano o ricostruiti dai pagamenti.

**Aggiornamenti del codice**: si carica il nuovo ZIP sopra il vecchio. Se il nuovo rilascio dà problemi, si ricarica lo ZIP precedente (conservare sempre l'ultimo pacchetto buono) e, se il rilascio aveva migrazioni del database, si ripristina anche il backup fatto prima dell'aggiornamento.

## 4. Cosa serve dal cliente prima di aprire (stato al 5 ottobre 2026)
**Il codice è pronto; mancano credenziali e decisioni.**
- **Carta (Stripe)**: account del cliente, chiavi `sk_live_…` e segreto del webhook; provare prima con le chiavi di prova e una carta di prova. Poi `LAMESS_PAYMENT_DRIVER=stripe`. Limite noto: gli **abbonamenti** non si rinnovano da soli con Stripe (servono le carte salvate, non ancora realizzate): il primo addebito si fa dall'ordine con il cliente presente.
- **Verifica d'identità online (facoltativa)**: `LAMESS_KYC_DRIVER=stripe_identity`; ha un costo per verifica. Senza, i documenti si controllano a mano.
- **Fatture elettroniche**: scegliere come trasmetterle. Il portale produce l'XML FatturaPA (validato contro lo schema 1.2.2). Opzioni: `file` (XML in una cartella, da ritirare), `mail` (spedito a una casella: gestionale, commercialista o PEC verso lo SdI). **GestionaleK**: nei documenti non risulta nessuna interfaccia; servono le loro specifiche per un collegamento diretto. Da decidere con il commercialista: privati in fattura o in ricevuta (le ricevute non vanno allo SdI).
- **Dati del venditore**: ragione sociale, partita IVA (11 cifre), indirizzo, CAP, comune, provincia, regime fiscale.
- **Parametri fiscali** (ritenuta, quota imponibile, INPS, commissione sui prelievi) e **testi** (Lettera IVD, Regolamento): da far validare al commercialista e al Consorzio; ricevute e trattenute restano spente finché non sono validati.
- **Firma elettronica reale** della lettera d'incarico: oggi è un sistema di prova (OTP finto).
- **IBAN dei consulenti**: li inseriscono loro dall'area personale.
- **Prova su MariaDB** delle migrazioni nuove e della suite, sul sito di prova.

## 5. Primo giorno dopo l'apertura
1. Un ordine vero con carta (importo minimo) e verifica che l'ordine passi a «pagato» da solo: significa che il webhook funziona. Se resta «in attesa», controlla l'endpoint e il segreto in Stripe (Sviluppatori → Webhook → consegne).
2. Controlla che la fattura sia «inviata» (Vendite → Fatture, colonna dell'invio) e che l'XML si scarichi (azione XML).
3. Controlla in Assistenza → Ticket che l'area consulente permetta di aprire una richiesta, e che il cron mostri «Attivo».
4. Dopo la prima chiusura di mese: estratti conto pubblicati (Compensi), ricevute e prelievi se il piano è «su richiesta».
