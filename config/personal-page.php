<?php

/*
 * Parti comuni a tutte le pagine personali degli incaricati (/i/{slug}): le decide il Consorzio, l'incaricato non le modifica.
 * Testi allineati al sito pubblico (home, «Perché Lamèss», «Il prodotto»): Lamèss è un integratore alimentare, dosi come in etichetta,
 * nessuna promessa di risultato e nessuna cifra di guadagno. Per cambiarli basta modificare questo file.
 */
return [
    // Fascia scorrevole sotto l'apertura.
    'ribbon' => ['Cosmetic Coffee', 'Arabica 100% AAA', 'Zero zuccheri', 'Formulazione protetta', 'Stop Time'],

    // Apertura: frase d'impatto, punti di fiducia e foto del prodotto (mostrata nell'arco se l'incaricato non ha una foto sua).
    'hero_claim' => 'Il caffè che trasforma una pausa in un rituale di bellezza.',
    'hero_chips' => ['Arabica 100% AAA', 'Zero zuccheri', 'Registrazione gratuita'],
    'product_image' => '/img/front/6d7674f3-4694-4e1a-a026-df050525cb7a.webp',
    'hero_lead' => 'Ti accompagno nella scelta della tua box, con la cura che un gesto quotidiano merita.',

    // «Ti spiego Lamèss»: quello che l'incaricato direbbe a voce in apertura.
    'intro_kicker' => 'Ti spiego Lamèss',
    'intro_title' => 'Quello che ti direi a voce, in due minuti.',
    'intro' => [
        'Lamèss è un caffè solubile funzionale: caffè istantaneo 100% Arabica di classe tripla AAA, con collagene VERISOL®, acido ialuronico, coenzima Q10 e altri ingredienti conosciuti nella nutricosmetica.',
        'Si chiama «caffè cosmetico» perché richiama gli ingredienti che si trovano in cosmetica, ma non si applica sulla pelle: si beve. È un integratore alimentare, non un medicinale.',
        'L’idea è semplice: l’integrazione quotidiana funziona se la fai ogni giorno, e il caffè lo bevi già. Con tutto nella stessa bustina non hai niente in più da ricordare.',
    ],
    'intro_facts' => [
        ['value' => '50', 'label' => 'buste in ogni box'],
        ['value' => '2', 'label' => 'buste al giorno, al massimo'],
        ['value' => '0', 'label' => 'zuccheri aggiunti'],
    ],

    'why_kicker' => 'Perché Lamèss',
    'why_title' => 'Sei ragioni per fare di ogni pausa un rituale.',
    'why' => [
        ['title' => 'Un caffè vero', 'text' => 'Caffè istantaneo 100% Arabica di classe tripla AAA, con il suo gusto e il suo profumo: l’integrazione non toglie nulla al piacere della tazza.'],
        ['title' => 'Un solo gesto', 'text' => 'Caffè e integrazione nella stessa bustina. Il caffè lo bevi già: non c’è niente in più da ricordare.'],
        ['title' => 'Dosi scritte in etichetta', 'text' => 'Collagene VERISOL®, acido ialuronico e coenzima Q10 nelle quantità dichiarate, insieme a vitamine E e C, selenio, beta-carotene e biotina.'],
        ['title' => 'Zero zuccheri', 'text' => 'Nessuno zucchero nella bustina: gusto autentico, adatto a stili di vita salutari.'],
        ['title' => 'Ovunque, caldo o freddo', 'text' => 'Una bustina e acqua, calda o fredda. In ufficio, in viaggio, in hotel: niente macchina, niente capsule.'],
        ['title' => 'Formulazione protetta', 'text' => 'La tutela brevettuale riguarda gli aspetti innovativi della formulazione; il nome Lamèss è protetto dal marchio registrato.'],
    ],

    'dose_kicker' => 'Nella bustina',
    'dose_title' => 'Ingredienti scelti. Dosi dichiarate.',
    'dose_text' => 'Ogni valore è riferito alla dose massima giornaliera di 2 buste, come in etichetta. Le ricerche citate riguardano i singoli ingredienti e non il prodotto finito: le trovi, con le fonti, nella pagina dedicata.',
    'doses' => [
        ['value' => '2,5 g', 'label' => 'Collagene VERISOL®', 'text' => 'Peptidi bioattivi di collagene.'],
        ['value' => '190 mg', 'label' => 'Acido ialuronico', 'text' => 'Ingrediente cardine della nutricosmetica.'],
        ['value' => '20 mg', 'label' => 'Coenzima Q10', 'text' => 'Presente accanto a collagene e acido ialuronico.'],
    ],
    'dose_facts' => ['50 buste per box', 'Senza glutine e senza lattosio', 'Max 2 buste al giorno'],
    'prepare' => [
        ['title' => 'Calda', 'text' => 'Scioglila in acqua calda e mescola: il caffè resta caffè, con tutto il suo aroma.'],
        ['title' => 'Fredda', 'text' => 'Scioglila in acqua fredda, anche in borraccia. Con il ghiaccio, se ti va.'],
    ],

    // Ingrediente per ingrediente: dosi per la dose massima giornaliera (2 buste) e indicazioni sulla salute autorizzate (Reg. UE 432/2012), come sul sito.
    'ingredients_title' => 'Ingrediente per ingrediente',
    'ingredients' => [
        ['name' => 'Caffè istantaneo 100% Arabica AAA', 'dose' => '7000 mg', 'text' => 'La base: un vero caffè, con il suo gusto e il suo profumo.'],
        ['name' => 'Collagene idrolizzato VERISOL®', 'dose' => '2,5 g', 'text' => 'Peptidi bioattivi di collagene, nella quantità usata in uno studio su 114 donne.'],
        ['name' => 'Acido ialuronico', 'dose' => '190 mg', 'text' => 'Uno degli ingredienti cardine della nutricosmetica.'],
        ['name' => 'Coenzima Q10', 'dose' => '20 mg', 'text' => 'Presente insieme a collagene e acido ialuronico, in dose dichiarata.'],
        ['name' => 'Vitamina C da rosa canina', 'dose' => '80 mg · 100% VNR', 'text' => 'Contribuisce alla normale formazione del collagene per la normale funzione della pelle.'],
        ['name' => 'Vitamina E', 'dose' => '30 mg · 250% VNR', 'text' => 'Contribuisce alla protezione delle cellule dallo stress ossidativo.'],
        ['name' => 'Selenio', 'dose' => '50 mcg · 90,8% VNR', 'text' => 'Contribuisce alla protezione delle cellule dallo stress ossidativo.'],
        ['name' => 'Beta-carotene (vitamina A)', 'dose' => '3,7 mg · 78% VNR', 'text' => 'Apporta vitamina A, che contribuisce al mantenimento di una pelle normale.'],
        ['name' => 'Biotina', 'dose' => '50 mcg · 100% VNR', 'text' => 'Contribuisce al mantenimento di una pelle normale.'],
    ],
    'ingredients_note' => 'Valori per la dose massima giornaliera di 2 buste, come in etichetta. VNR: valori nutritivi di riferimento per adulti (Reg. UE 1169/2011).',
    'monodose' => 'Perché la bustina monodose? Gli ingredienti hanno granulometria, densità e peso specifico diversi: in un barattolo potrebbero distribuirsi in modo non uniforme. La bustina porta in ogni dose la stessa composizione.',

    'research_kicker' => 'Cosa dice la ricerca',
    'research_title' => 'Gli studi ci sono. Ti dico anche cosa non dimostrano.',
    'research_text' => 'Lo studio più citato riguarda il collagene VERISOL®: donne tra 45 e 65 anni che hanno assunto 2,5 g al giorno, la stessa quantità presente in Lamèss, e sono state confrontate con un placebo. Dopo otto settimane è stata osservata una riduzione del volume delle rughe perioculari.',
    'research_stats' => [
        ['value' => '114', 'unit' => '', 'label' => 'donne coinvolte nello studio'],
        ['value' => '8', 'unit' => '', 'label' => 'settimane di assunzione quotidiana'],
        ['value' => '2,5', 'unit' => ' g', 'label' => 'al giorno, come in Lamèss'],
    ],
    'research_limit' => 'Lo studio riguarda il solo ingrediente e non dimostra gli effetti del prodotto finito. Per questo non ti prometto risultati: gli effetti variano da persona a persona e non sono immediati. Contano età, alimentazione, stile di vita e costanza; negli studi i parametri sono valutati dopo 8–12 settimane di assunzione regolare.',

    'for_kicker' => 'Per chi è',
    'for_title' => 'Un gesto per te, non una promessa.',
    'for_yes_title' => 'È pensato per',
    'for_yes' => [
        'Adulti, donne e uomini: gli ingredienti non sono pensati solo per il pubblico femminile.',
        'Chi il caffè lo beve già e vuole farne un piccolo rituale di cura di sé.',
        'Chi si muove: una bustina entra in tasca, in ufficio, in viaggio, in hotel, in camper, anche in palestra.',
        'Chi preferisce un gusto autentico, senza zuccheri aggiunti, senza glutine e senza lattosio.',
    ],
    'for_care_title' => 'Ti dico anche questo',
    'for_care' => [
        'Contiene caffeina: se bevi altri caffè conta quella totale della giornata. Se sei sensibile, in gravidanza o allattamento, o prendi farmaci, chiedi prima al medico.',
        'Non è adatto a chi segue una dieta vegana: il collagene è di origine animale (bovina).',
        'Dose massima: 2 buste al giorno. Berne di più non dà risultati maggiori e aumenta la caffeina.',
        'Non sostituisce una dieta varia ed equilibrata, uno stile di vita sano, né trattamenti cosmetici o medici.',
    ],

    'terms_title' => 'Spedizione, abbonamento, pagamenti',
    'terms' => [
        ['title' => 'Spedizione', 'text' => 'Per l’ordine singolo di una box costa 9 €. Dalle 2 box e con ogni abbonamento è gratuita.'],
        ['title' => 'Abbonamento', 'text' => 'La box arriva ogni mese. Puoi saltare un mese, cambiare indirizzo o interrompere quando vuoi: parte con un impegno di 12 mesi che non è vincolante.'],
        ['title' => 'Pagamento', 'text' => 'Con carta, bonifico o KMoney. Tutti i prezzi che vedi sono IVA inclusa.'],
        ['title' => 'Anche all’estero', 'text' => 'Nel carrello scegli la nazione. Per l’Italia serve il codice fiscale.'],
    ],

    'trust_kicker' => 'Io e Lamèss',
    'trust_title' => 'Con me non sei solo davanti a uno schermo.',
    'trust_role_title' => 'Cosa faccio per te',
    'trust_role' => [
        ['title' => 'Ti aiuto a scegliere', 'text' => 'Ti spiego il prodotto e ti consiglio la box giusta, senza pressioni.'],
        ['title' => 'Rispondo alle tue domande', 'text' => 'Anche su dosi, avvertenze e modo d’uso: se non lo so, ti dico dove trovare la risposta.'],
        ['title' => 'Ti seguo dopo l’ordine', 'text' => 'Per ordini, spedizioni e abbonamento hai assistenza diretta dal tuo spazio personale.'],
        ['title' => 'Ti dico quando serve il medico', 'text' => 'Non sono un medico né un consulente sanitario: per la tua salute chiedi sempre al tuo medico.'],
    ],
    'trust_behind_title' => 'Chi c’è dietro Lamèss',
    'trust_behind_text' => 'Lamèss è sviluppato dal Consorzio Kadeo Group, con sede ad Arezzo. Il nome è un marchio registrato e la tutela brevettuale riguarda gli aspetti innovativi della formulazione. Il prodotto è nella banca dati Farmadati (codice 991106055), che farmacie e parafarmacie usano per identificare i prodotti.',
    'trust_facts' => [
        ['value' => '4', 'unit' => ' anni', 'label' => 'di ricerca e sviluppo'],
        ['value' => '51', 'unit' => '', 'label' => 'soci nel Consorzio'],
        ['value' => '1200', 'unit' => '+', 'label' => 'box distribuite a ottobre 2026'],
    ],

    'banner_kicker' => 'La nostra filosofia',
    'banner_title' => 'Stop Time.',
    'banner_text' => 'Un invito a rallentare e a vivere il tempo con maggiore consapevolezza: fermarsi, anche solo per un momento, e trasformare un gesto abituale in un rituale di bellezza personale.',
    'banner_note' => '«Stop Time» è il concept del progetto e non promette di fermare l’invecchiamento.',

    'steps_kicker' => 'Come ordinare',
    'steps_title' => 'Tre passi, e il primo è gratuito.',
    'steps' => [
        ['title' => 'Registrati con me', 'text' => 'Crei il tuo spazio in pochi minuti. È gratuito e non c’è nessun obbligo di acquisto.'],
        ['title' => 'Scegli la tua box', 'text' => 'Ordini quando vuoi, oppure attivi l’abbonamento: lo metti in pausa o lo interrompi in qualsiasi momento.'],
        ['title' => 'Vivi il rituale', 'text' => 'Segui ordini, spedizioni e abbonamenti dal tuo spazio personale, con assistenza diretta.'],
    ],

    // Invito finale a chi vuole entrare nel progetto: nessuna cifra, nessuna promessa di guadagno.
    'join_kicker' => 'Per chi vuole di più',
    'join_title' => 'Ti piace Lamèss? Fanne parte.',
    'join_text' => 'Se vuoi guadagnare facendo conoscere Lamèss, contattami: ti spiego come diventare incaricato, con una pagina personale come questa, un link referral tuo e un’area riservata. Compensi e condizioni si presentano in un incontro, insieme al Regolamento Incaricati.',
    'join_points' => ['Una pagina personale tua', 'Il tuo link referral', 'Un’area riservata per seguire i clienti'],

    'faq' => [
        ['q' => 'Che cos’è LAMÈSS?', 'a' => 'È un Cosmetic Coffee: caffè istantaneo 100% Arabica di classe tripla AAA con collagene VERISOL®, acido ialuronico e coenzima Q10. Si beve, non si applica sulla pelle.'],
        ['q' => 'Perché si chiama «caffè cosmetico»?', 'a' => 'Il nome richiama gli ingredienti che si trovano in cosmetica e nutraceutica. Lamèss non si applica sulla pelle: si beve.'],
        ['q' => 'LAMÈSS è un medicinale?', 'a' => 'No. È un integratore alimentare e non è destinato a prevenire, trattare o curare malattie.'],
        ['q' => 'Cosa contiene e in che dosi?', 'a' => 'Per la dose giornaliera di 2 buste: 2,5 g di VERISOL®, 190 mg di acido ialuronico e 20 mg di coenzima Q10, con caffè istantaneo, vitamine E e C, selenio, beta-carotene e biotina. La tabella completa è nella sezione degli ingredienti e nella pagina Prodotto.'],
        ['q' => 'Contiene zuccheri? È senza glutine e lattosio?', 'a' => 'È a zero zuccheri. La confezione riporta i simboli senza glutine e senza lattosio.'],
        ['q' => 'È adatto ai vegani?', 'a' => 'No: il collagene è di origine animale (bovina), quindi Lamèss non è adatto a chi segue una dieta vegana.'],
        ['q' => 'Contiene caffeina?', 'a' => 'Sì, la caffeina naturale del caffè. Se bevi altri caffè conta la caffeina totale della giornata. Se sei sensibile alla caffeina, sei in gravidanza o allattamento, o prendi farmaci, chiedi prima il parere del medico.'],
        ['q' => 'Come si prepara e quante buste posso prendere?', 'a' => 'Si scioglie una bustina in acqua calda o fredda, anche con il ghiaccio. La dose massima giornaliera è di 2 buste: segui sempre le indicazioni e le avvertenze in etichetta.'],
        ['q' => 'Quando si vedono i risultati?', 'a' => 'Non esiste un tempo uguale per tutti e non è un effetto immediato: contano età, alimentazione, stile di vita e costanza. Gli studi sui singoli ingredienti hanno osservato gli effetti dopo 8–12 settimane di assunzione quotidiana.'],
        ['q' => 'Lo studio dimostra che Lamèss funziona?', 'a' => 'No. Lo studio sul VERISOL® riguarda il solo ingrediente, nella stessa dose presente in Lamèss: non dimostra gli effetti del prodotto finito. Le fonti sono nella pagina La scienza.'],
        ['q' => 'Possono usarlo anche gli uomini?', 'a' => 'Sì. Lamèss è un integratore alimentare per adulti, non pensato solo per il pubblico femminile.'],
        ['q' => 'Come si ordina e come si paga?', 'a' => 'Ti registri gratuitamente, scegli la box e paghi con carta, bonifico o KMoney. Puoi ordinare una volta sola oppure attivare l’abbonamento. Tutti i prezzi sono IVA inclusa.'],
        ['q' => 'Quanto costa la spedizione?', 'a' => 'Per l’ordine singolo di una box costa 9 €. Dalle 2 box e con ogni abbonamento è gratuita. Spediamo anche all’estero: nel carrello scegli la nazione.'],
        ['q' => 'Come funziona l’abbonamento?', 'a' => 'Ogni mese la box viene addebitata in automatico con carta. Puoi sospendere un mese, cambiare indirizzo di spedizione o interrompere quando vuoi. Parte con un impegno di 12 mesi che non è vincolante.'],
        ['q' => 'Devo acquistare qualcosa per registrarmi?', 'a' => 'No. La registrazione è gratuita e non c’è nessun obbligo di acquisto.'],
        ['q' => 'Chi è un Incaricato LAMÈSS?', 'a' => 'È una persona che ha scelto di far conoscere LAMÈSS e che ti accompagna nella scelta. Non è un medico né un consulente sanitario: per qualunque dubbio sulla tua salute chiedi sempre al tuo medico.'],
        ['q' => 'Dove trovo ingredienti e avvertenze?', 'a' => 'Nella scheda di ogni prodotto nel negozio e sulla confezione.'],
    ],
    'disclaimer' => 'LAMÈSS è un integratore alimentare e non è un medicinale: non è destinato a prevenire, trattare o curare malattie. Gli effetti possono variare da persona a persona. Un’alimentazione varia ed equilibrata e uno stile di vita sano sono importanti. Le informazioni su questa pagina non sostituiscono il parere del medico. I contenuti personali di questa pagina sono dell’Incaricato e sono controllati dal Consorzio.',
];
