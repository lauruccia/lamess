<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Onboarding\KycService;
use Illuminate\Console\Command;

final class LamessKycPromemoria extends Command
{
    protected $signature = 'lamess:kyc-promemoria';

    protected $description = 'Promemoria agli incaricati che non hanno ancora caricato i documenti richiesti dall’amministrazione';

    public function handle(KycService $kyc): int
    {
        $this->info('Promemoria KYC inviati: '.$kyc->sendReminders());
        $this->info('Promemoria documenti rifiutati inviati: '.$kyc->sendRejectionReminders());

        return self::SUCCESS;
    }
}
