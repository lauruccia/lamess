<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Customers\CustomerService;
use App\Domain\Network\NetworkService;
use App\Domain\Onboarding\IvdService;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\IvdContract;
use Illuminate\Support\Facades\Storage;

trait OnboardingDb
{
    use NetworkDb;

    protected function fanData(array $over = []): array
    {
        static $n = 0;
        $n++;

        return $over + [
            'email' => "fan{$n}@example.test", 'password' => 'password-123', 'type' => 'private',
            'first_name' => 'Mario', 'last_name' => "Rossi{$n}",
        ];
    }

    protected function fan(?Consultant $sponsor = null, array $over = []): Customer
    {
        Storage::fake();

        return app(CustomerService::class)->register($this->fanData($over), formCode: $sponsor?->code);
    }

    /** @return array<string,mixed> */
    protected function ivdData(array $over = []): array
    {
        return $over + [
            'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01', 'birth_place' => 'Reggio Calabria',
            'address' => 'Via Roma 1', 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC',
        ];
    }

    protected function activeSponsor(string $code = 'LM00001'): Consultant
    {
        return app(NetworkService::class)->enroll(['code' => $code, 'name' => "Sponsor {$code}", 'referral_code' => 'REF'.$code], null, $this->at('2026-01-01 10:00'));
    }

    protected function otpOf(string $hint): string
    {
        preg_match('/(\d{6})/', $hint, $m);

        return $m[1];
    }

    /** Domanda firmata (non ancora accettata). */
    protected function signedContract(Customer $fan, ?Consultant $sponsor = null, array $profile = []): IvdContract
    {
        $svc = app(IvdService::class);
        $c = $svc->apply($fan->user, $this->ivdData($profile), $sponsor);
        $req = $svc->requestSignature($c);

        return $svc->sign($c, $this->otpOf((string) $req->hint), '127.0.0.1', 'phpunit');
    }
}
