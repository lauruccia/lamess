<?php

return [
    // Sito aperto al pubblico. Chiuso (predefinito): i visitatori vedono la pagina «Lamèss apre presto» (503); il back-office
    // (/admin) e chi ha già un accesso al back-office continuano a vedere tutto. Si apre da Impostazioni di sistema → Sito.
    'open' => (bool) env('LAMESS_SITE_OPEN', false),
];
