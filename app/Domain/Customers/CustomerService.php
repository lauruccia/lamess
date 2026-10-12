<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Consultant;
use App\Models\Customer;
use App\Models\Profile;
use App\Models\User;
use InvalidArgumentException;

/** Registrazione gratuita di FAN (privati) e Clienti Azienda (RF-001/002). */
final class CustomerService
{
    public function __construct(private readonly ReferralResolver $resolver) {}

    /**
     * @param  array<string,mixed>  $data  email, password, type (private|company), dati anagrafici
     */
    public function register(array $data, ?string $link = null, ?string $cookie = null, ?string $formCode = null): Customer
    {
        $type = $data['type'] ?? 'private';
        if (! in_array($type, ['private', 'company'], true)) {
            throw new InvalidArgumentException('Tipo cliente non valido.');
        }
        if ($type === 'company' && (blank($data['company_name'] ?? null) || blank($data['vat_number'] ?? null) || (blank($data['sdi_code'] ?? null) && blank($data['pec'] ?? null)))) {
            throw new InvalidArgumentException('Per le aziende servono ragione sociale, P.IVA e codice SDI o PEC.');
        }
        if ($type === 'private' && (blank($data['first_name'] ?? null) || blank($data['last_name'] ?? null))) {
            throw new InvalidArgumentException('Servono nome e cognome.');
        }

        [$consultant, $source] = $this->resolver->resolve($link, $cookie, $formCode);

        $profileData = array_intersect_key($data, array_flip([
            'first_name', 'last_name', 'company_name', 'tax_code', 'vat_number', 'sdi_code', 'pec', 'birth_date', 'birth_place',
            'phone', 'address', 'city', 'zip', 'province', 'country_code',
        ]));
        $user = User::create([
            'name' => $type === 'company' ? $data['company_name'] : trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'], 'password' => $data['password'],
        ]);
        Profile::create(['user_id' => $user->id, 'type' => $type] + $profileData);

        return Customer::create([
            'user_id' => $user->id, 'assigned_consultant_id' => $consultant?->id, 'source' => $source, 'registered_at' => now(),
        ]);
    }

    /** Il cliente (ri)diventa tale: usato dopo rifiuto della domanda o cessazione (RF-015/023). */
    public function ensureCustomer(User $user, ?Consultant $assignTo): Customer
    {
        return Customer::firstOrCreate(
            ['user_id' => $user->id],
            ['assigned_consultant_id' => $assignTo?->id, 'source' => 'manual', 'registered_at' => now()],
        );
    }
}
