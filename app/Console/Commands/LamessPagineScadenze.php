<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pages\PersonalPageService;
use Illuminate\Console\Command;

final class LamessPagineScadenze extends Command
{
    protected $signature = 'lamess:pagine-scadenze';

    protected $description = 'Promemoria agli incaricati la cui attivazione della pagina personale sta per scadere';

    public function handle(PersonalPageService $pages): int
    {
        $this->info('Promemoria pagine personali inviati: '.$pages->sendExpiryReminders());

        return self::SUCCESS;
    }
}
