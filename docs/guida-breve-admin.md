# Guida breve per l'amministratore

Questa è la guida di una pagina per chi gestisce il portale ogni giorno. I dettagli sono nel Manuale amministratore.

## Entrare
`https://tuodominio.it/admin` con email, password e codice dell'app di autenticazione. Se smarrisci la password: «Hai smarrito la password?».

## Il menu in alto
- **Rete**: consulenti, clienti, albero, contratti da accettare, documenti da verificare, canoni.
- **Compensi**: piano, periodi, movimenti PV, liquidazioni, pagamenti.
- **Vendite**: ordini, abbonamenti, prodotti, regole, spedizioni, fatture, note di credito.
- **Assistenza**: ticket e reclami, novità, materiali, eventi, casi di conformità, sanzioni, materiali da approvare.
- **Sistema** (solo titolare): utenti, impostazioni, log, backup, controllo messa online.

## Ogni giorno
1. **Cruscotto**: controlla che «Cron del server» sia **Attivo**. Guarda contratti da accettare, documenti da verificare, canoni da incassare.
2. **Rete → Contratti** e **Documenti**: accetta le lettere firmate, verifica i documenti.
3. **Vendite → Ordini**: conferma i bonifici ricevuti, segna le spedizioni. I pagamenti con carta si confermano da soli.
4. **Assistenza → Ticket e reclami**: rispondi alle richieste e decidi i reclami (entro i 10 giorni). **Rete → Cessioni / Successioni** se ce ne sono in corso.
5. **Vendite → Fatture**: controlla che non ci siano fatture in «errore» (dati mancanti) e scarica l'XML se serve.

## Ogni mese
1. Il portale calcola il mese da solo il giorno 1 (se l'impostazione è «Solo calcolo»).
2. **Compensi → Periodi → il mese**: controlla i numeri, poi **Chiudi periodo**.
3. Dal 15 il portale prepara i pagamenti. **Compensi → Pagamenti**: **Esporta file SEPA (XML)** e caricalo in banca. Fatto il bonifico, **Segna pagato**.
4. Se il piano è «su richiesta»: **Compensi → Prelievi su richiesta** da approvare e pagare entro 5 giorni lavorativi.
5. I consulenti senza IBAN o senza documenti verificati non sono pagabili: restano «bloccati» e i loro importi si cumulano.

## Quando qualcosa non torna
- Mese sbagliato dopo la chiusura: solo la titolare può **Riaprire il mese** (non se ci sono importi già pagati).
- Un pagamento sbagliato non ancora pagato: **Annulla** con motivo.
- Ogni azione importante è nel **Log di audit** e non si può modificare.

## Backup
Ogni notte il portale salva una copia del database. Ogni tanto, **Sistema → Backup → Scarica** e conserva il file sul tuo computer.
