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
        ['q' => 'LAMÈSS è un medicinale?', 'a' => 'No. È un integratore alimentare e non è destinato a prevenire, trattare o curare malattie.'],
        ['q' => 'Contiene zuccheri?', 'a' => 'No, è senza zuccheri aggiunti.'],
        ['q' => 'Come si prepara e quante buste posso prendere?', 'a' => 'Si scioglie una bustina in acqua calda o fredda. La dose massima giornaliera è di 2 buste: segui sempre le indicazioni e le avvertenze in etichetta.'],
        ['q' => 'Come si ordina?', 'a' => 'Ti registri gratuitamente, scegli la box e paghi con carta, bonifico o KMoney. Puoi ordinare una volta sola oppure attivare l’abbonamento, che puoi mettere in pausa o interrompere quando vuoi.'],
        ['q' => 'Devo acquistare qualcosa per registrarmi?', 'a' => 'No. La registrazione è gratuita e non c’è nessun obbligo di acquisto.'],
        ['q' => 'Chi è un Incaricato LAMÈSS?', 'a' => 'È una persona che ha scelto di far conoscere LAMÈSS e che ti accompagna nella scelta. Non è un medico né un consulente sanitario: per qualunque dubbio sulla tua salute chiedi sempre al tuo medico.'],
        ['q' => 'Dove trovo ingredienti e avvertenze?', 'a' => 'Nella scheda di ogni prodotto nel negozio e sulla confezione.'],
    ],
    'disclaimer' => 'LAMÈSS è un integratore alimentare e non è un medicinale: non è destinato a prevenire, trattare o curare malattie. Gli effetti possono variare da persona a persona. Un’alimentazione varia ed equilibrata e uno stile di vita sano sono importanti. Le informazioni su questa pagina non sostituiscono il parere del medico. I contenuti personali di questa pagina sono dell’Incaricato e sono controllati dal Consorzio.',
];
