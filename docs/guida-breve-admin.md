# Guida breve per l'amministratore

Una pagina per chi gestisce il portale ogni giorno. I dettagli sono nel Manuale amministratore. Nel portale si dice **Incaricati** (non più «Consulenti»). Il portale è ancora **chiuso al pubblico** (pagina «Lamèss apre presto»): il back-office funziona, l'apertura la decide la titolare.

## Entrare
`https://tuodominio.it/admin` con email, password e codice dell'app di autenticazione. Se smarrisci la password: «Hai smarrito la password?».

## Chi può fare cosa
- **Superadmin** (solo la titolare): vede e modifica tutto, compresi utenti, impostazioni di sistema e **log di audit** (visibili solo a lei).
- **Admin** (il cliente): fa solo ciò che gli è stato concesso e vede solo le voci di menu scelte per lui; non vede né tocca i superadmin.

## Il menu in alto
- **Rete**: Incaricati, Albero della rete, Elenco per livelli, Domande IVD, Documenti KYC, Canoni annuali, Clienti (FAN).
- **Compensi**: Piano compensi, Periodi, Liquidazioni, Pagamenti, Bonus di Struttura.
- **Vendite**: Ordini, Abbonamenti, Prodotti, Sconti, Spedizioni, Fatture, Note di credito.
- **Assistenza**: Ticket e reclami, Novità, Materiali, Eventi, Casi di conformità, Sanzioni.
- **Sistema** (solo titolare): Utenti, Impostazioni di sistema, Log di audit, Backup, Controllo messa online.

## Ogni giorno
1. **Cruscotto**: «Cron del server» deve essere **Attivo**. Guarda contratti da accettare, documenti da verificare, canoni da incassare.
2. **Rete → Domande IVD** e **Documenti KYC**: accetta le lettere firmate, verifica i documenti (o chiedili con **Richiedi documenti**).
3. **Vendite → Ordini**: conferma i bonifici ricevuti, segna le spedizioni. Carta e KMoney si confermano da soli. Il filtro **«Solo abbonamenti / Solo ordini singoli»** separa i due tipi.
4. **Assistenza → Ticket e reclami**: rispondi e decidi i reclami entro 10 giorni. **Rete → Cessioni / Successioni** se ce ne sono in corso.
5. **Vendite → Fatture**: controlla che non ci siano fatture in «errore» (dati mancanti) e usa **Reinvia**.

## Ogni mese
1. Il portale calcola il mese da solo nel giorno e ora scelti nelle Impostazioni (di norma il 1°, ore 01:00).
2. **Compensi → Periodi → il mese**: controlla i numeri, poi **Chiudi periodo**.
3. Dal 15 il portale prepara i pagamenti. **Compensi → Pagamenti**: **Esporta file SEPA (XML)**, caricalo in banca, poi **Segna pagato**.
4. Gli incaricati senza IBAN o senza documenti verificati non sono pagabili: restano «bloccati» e gli importi si cumulano.
5. Compensi sul **Piano Incaricati**: niente PV, la qualifica vale appena raggiunta (aggiornata ogni notte). Ricevute provvigionali e ritenuta d'acconto sono **spente** fino alla risposta del commercialista.

## Azioni utili
- **Mantieni qualifica** (scheda incaricato, menu riga, in massa, «a tutti»): congela la qualifica fino alla data scelta; resta nel log.
- **Rimborsa KMoney** (ordine in attesa con quota KMoney già addebitata) e **Reso e rimborso**.
- Abbonamento: spedizione gratuita, impegno di 12 mesi non vincolante; il cliente può mettere in pausa o saltare un mese, tu hai **Pausa / Riprendi / Annulla**.

## Quando qualcosa non torna
- Mese sbagliato dopo la chiusura: solo la titolare può **Riaprire il mese** (non se ci sono importi già pagati).
- Un pagamento sbagliato non ancora pagato: **Annulla** con motivo.
- Il piano a PR/PV si nasconde con un click (*Compensi → Visibilità piano precedente*, solo titolare): i dati restano.

## Aggiornare il sito (cPanel, senza terminale)
Prima fai il backup (**Sistema → Backup → Scarica**). Poi cPanel → **Git Version Control**: **Update from Remote**, quindi **Deploy HEAD Commit**. Infine, per svuotare la cache, da **File Manager** cancella i file in `bootstrap/cache` (tranne `.gitignore`) e in `storage/framework/views`. I passi con `php artisan` (migrazioni, ruoli) sono **da fare in sviluppo**.
