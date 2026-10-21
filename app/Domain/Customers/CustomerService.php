<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Consultant;
use App\Models\Customer;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Registrazione gratuita di FAN (privati) e Clienti Azienda (RF-001/002). */
final class CustomerService
{
    public function __construct(private readonly ReferralResolver $resolver, private readonly ReferralInviteService $invites) {}

    /**
     * @param  array<string,mixed>  $data  email, password, type (private|company), dati anagrafici
     */
    public function register(array $data, ?string $link = null, ?string $cookie = null, ?string $formCode = null, ?string $inviteToken = null): Customer
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
        $invite = $this->invites->findForRegistration($inviteToken, $data['email'] ?? null);
        // chi era stato invitato ma arriva senza referral (né link, né cookie, né codice) va a chi l'ha invitato
        if ($invite !== null && in_array($source, ['default', 'manual'], true) && $this->resolver->find($invite->consultant->referral_code) !== null) {
            [$consultant, $source] = [$invite->consultant, 'invite'];
        }

        $profileData = array_intersect_key($data, array_flip([
            'first_name', 'last_name', 'company_name', 'tax_code', 'vat_number', 'sdi_code', 'pec', 'birth_date', 'birth_place',
            'phone', 'address', 'city', 'zip', 'province', 'country_code',
        ]));
        $user = User::create([
            'name' => $type === 'company' ? $data['company_name'] : trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'], 'password' => $data['password'],
        ]);
        Profile::create(['user_id' => $user->id, 'type' => $type] + $profileData);

        $customer = Customer::create([
            'user_id' => $user->id, 'assigned_consultant_id' => $consultant?->id, 'source' => $source, 'registered_at' => now(),
        ]);
        if ($invite !== null) {
            $this->invites->markRegistered($invite, $user);
        }

        return $customer;
    }

    /**
     * Nuovo cliente inserito dall'amministrazione (admin e super admin): stessi controlli della registrazione, ma senza
     * referral e con l'incaricato di riferimento facoltativo (senza, vale l'incaricato «casa» dell'azienda, se c'è). Senza password se ne genera una casuale, che nessuno conosce:
     * la persona sceglie la sua dal link di «password dimenticata» (o dall'invio che parte subito dal pannello).
     *
     * @param  array<string,mixed>  $data  email, type (private|company), dati anagrafici, password facoltativa
     */
    public function createByAdmin(array $data, ?Consultant $assignTo = null): Customer
    {
        $email = trim((string) ($data['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Serve un indirizzo email valido.');
        }
        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('Esiste già una persona con questa email.');
        }
        if ($assignTo !== null && $assignTo->status === 'terminated') {
            throw new InvalidArgumentException("L'incaricato {$assignTo->code} è cessato: non può avere clienti.");
        }
        $password = filled($data['password'] ?? null) ? (string) $data['password'] : Str::password(24);

        $customer = $this->register(['email' => $email, 'password' => $password] + $data);
        $customer->update($assignTo !== null ? ['assigned_consultant_id' => $assignTo->id, 'source' => 'manual'] : ['source' => 'manual']);

        return $customer;
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
