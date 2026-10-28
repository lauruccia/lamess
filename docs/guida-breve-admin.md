# Guida breve per l'amministratore

Una pagina per chi gestisce il portale ogni giorno. I dettagli sono nel Manuale amministratore. Nel portale si dice **Incaricati** (non più «Consulenti»). Il portale è ancora **chiuso al pubblico** (pagina «Lamèss apre presto»): il back-office funziona, l'apertura la decide la titolare.

## Entrare
`https://tuodominio.it/admin` con email, password e codice dell'app di autenticazione. Se smarrisci la password: «Hai smarrito la password?». L'email con la «è» (`nome@lamèss.it`) funziona anche da iPhone.

## Chi può fare cosa
- **Superadmin** (solo la titolare): vede e modifica tutto, compresi utenti, impostazioni di sistema e **log di audit** (visibili solo a lei).
- **Admin** (il cliente): fa solo ciò che gli è stato concesso e vede solo le voci di menu scelte per lui; non vede né tocca i superadmin.

## Il menu in alto
- **Rete**: Incaricati, Albero della rete, Elenco per livelli, Domande IVD, Documenti KYC, Canoni annuali, Clienti (FAN, solo non incaricati, con il pulsante «Nuovo cliente»), Lettera di incarico (modifica il testo e la fai firmare), Regolamento incaricati.
- **Compensi**: Piano compensi, Periodi, **Compensi del mese** (quanto matura ogni incaricato: stima nel mese aperto, importi definitivi nel chiuso; Esporta CSV), Liquidazioni, Pagamenti, Prelievi su richiesta e Movimenti Tasca, Bonus di Struttura, Agenti regalati (aiuto di qualifica: agenti virtuali, solo per i requisiti), Simulazione incaricati (prova «cosa succede se» su rete e qualifiche, senza scrivere nulla: all'apertura vedi la rete di oggi come albero o come elenco per livelli, con cosa manca a ciascuno per salire).
- **Vendite**: Ordini, Abbonamenti, Prodotti, Sconti, Spedizioni, Fatture, Note di credito.
- **Assistenza**: Ticket e reclami, Novità, Materiali, Eventi, Casi di conformità, Sanzioni.
- **Sistema**: Statistiche sito (chi è online, visite, ricerche; per chi ha il permesso), e solo per la titolare Utenti, Impostazioni di sistema, Log di audit, Backup, Controllo messa online.

## Ogni giorno
1. **Cruscotto**: «Cron del server» deve essere **Attivo**. Guarda contratti da accettare, documenti da verificare, canoni da incassare.
2. **Rete → Domande IVD** e **Documenti KYC**: accetta le lettere firmate, verifica i documenti (o chiedili con **Richiedi documenti**).
3. **Vendite → Ordini**: conferma i bonifici ricevuti, segna le spedizioni. Carta e KMoney si confermano da soli (carta in attesa: **Verifica su Stripe** dice perché). A ogni nuovo ordine arriva un'email agli admin. Il filtro **«Solo abbonamenti / Solo ordini singoli»** separa i due tipi.
4. **Assistenza → Ticket e reclami**: rispondi e decidi i reclami entro 10 giorni. **Rete → Cessioni / Successioni** se ce ne sono in corso.
5. **Vendite → Fatture**: controlla che non ci siano fatture in «errore» (dati mancanti) e usa **Reinvia**.

## Ogni mese
1. Il portale calcola il mese da solo nel giorno e ora scelti nelle Impostazioni (di norma il 1°, ore 01:00).
2. **Compensi → Periodi → il mese**: controlla i numeri, poi **Chiudi periodo**.
3. Alla chiusura gli ordini rimasti «in attesa di pagamento» si annullano da soli. Con il pagamento mensile automatico, dal 15 il portale prepara i pagamenti: **Compensi → Pagamenti**, **Esporta file SEPA (XML)**, caricalo in banca, poi **Segna pagato**. Con il pagamento **«su richiesta»** (Piano Incaricati dal 09/11/2026) alla chiusura il maturato va nella **Tasca** di ognuno e paghi le richieste di prelievo, approvate in *Prelievi su richiesta*. Gli IBAN **fuori area SEPA** non entrano nel file: usa **Bonifici fuori area SEPA (CSV)** (serve anche il BIC/SWIFT).
4. Gli incaricati senza IBAN o senza documenti verificati non sono pagabili: restano «bloccati» e gli importi si cumulano.
5. Compensi sul **Piano Incaricati**: niente PV, la qualifica vale appena raggiunta (aggiornata ogni notte e dopo ogni pagamento o reso). Ricevute provvigionali e ritenuta d'acconto sono **spente** fino alla risposta del commercialista.

## Azioni utili
- **Mantieni qualifica** (scheda incaricato, menu riga, in massa, «a tutti») e **Forza qualifica** (scheda): congelano la qualifica fino alla data scelta oppure «fino a revoca»; il ricalcolo parte subito; resta nel log.
- **Imposta password temporanea** (Incaricati, Clienti): se l'email non arriva; la password si vede una volta sola. **Sospendi o banna** / **Riattiva account** (Incaricati, Clienti, Utenti, con motivo): blocca l'accesso, filtro **Account**.
- **Invia reset password** (Incaricati, Clienti FAN, Utenti: sulla riga, sulle selezionate o «Reset password a tutti», conferma scrivendo INVIA): la persona riceve il link per scegliere una nuova password. Serve «Invio email attivo»; resta nel log.
- **Rimborsa KMoney** (ordine in attesa con quota KMoney già addebitata), **Reso e rimborso**, **Invia di nuovo i dati di pagamento** (ordine in attesa), **Modifica indirizzo di consegna** (finché non è spedito).
- Abbonamento: spedizione gratuita, impegno di 12 mesi non vincolante; il cliente può mettere in pausa o saltare un mese, tu hai **Pausa / Riprendi / Annulla**. Addebito non riuscito: nuovi tentativi dopo 1, 3 e 5 giorni, poi pausa; con KMoney serve la conferma del cliente (puoi rimandare l'email). Ogni giorno alle 08:15 arriva un riepilogo degli abbonamenti.

## Quando qualcosa non torna
- Una persona dice «Troppi tentativi di accesso»: dopo 5 password errate (stesso dispositivo) o 15 (qualsiasi dispositivo) l'accesso con quell'email si ferma 15 minuti; basta aspettare, poi riprovare con la password giusta (l'email «Password dimenticata» è utile per sceglierne una nuova, ma non toglie il blocco prima dei 15 minuti).
- Le fatture «Da inviare» o in errore si rimandano da *Vendite → Fatture* con **Reinvia**; il portale riprova da solo ogni ora (al massimo 200 per giro).
- Mese sbagliato dopo la chiusura: solo la titolare può **Riaprire il mese** (non se ci sono importi già pagati).
- Un pagamento sbagliato non ancora pagato: **Annulla** con motivo.
- Il Cruscotto si aggiorna al massimo ogni minuto: se un numero sembra vecchio, attendi e ricarica.
- Fatture: **Esporta per Easyfatt (XML)** in *Vendite → Fatture* (file da importare in Easyfatt, non per lo SdI).
- Il piano a PR/PV si nasconde con un click (*Compensi → Visibilità piano precedente*, solo titolare): i dati restano.

## Aggiornare il sito (cPanel, senza terminale)
Prima fai il backup (**Sistema → Backup → Scarica**). Poi cPanel → **Git Version Control**: **Update from Remote**, quindi **Deploy HEAD Commit**. Infine, per svuotare la cache, da **File Manager** cancella i file in `bootstrap/cache` (tranne `.gitignore`) e in `storage/framework/views`. I passi con `php artisan` (migrazioni, ruoli) sono **da fare in sviluppo**.
