<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Orders\OrderException;
use App\Models\ShippingAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** Rubrica degli indirizzi di spedizione di un utente (massimo lamess.max_shipping_addresses) e scelta dell'indirizzo per un ordine o un abbonamento. */
final class ShippingAddressBook
{
    public function max(): int
    {
        return (int) config('lamess.max_shipping_addresses');
    }

    /** @return Collection<int, ShippingAddress> predefinito per primo */
    public function list(User $user)
    {
        return ShippingAddress::where('user_id', $user->id)->orderByDesc('is_default')->orderBy('id')->get();
    }

    /** @param array<string,mixed> $d label, recipient, address, city, zip, province, phone */
    public function add(User $user, array $d): ShippingAddress
    {
        foreach (['address', 'city', 'zip'] as $f) {
            if (blank($d[$f] ?? null)) {
                throw new OrderException('Completa l\'indirizzo di spedizione: servono indirizzo, città e CAP.');
            }
        }
        $this->assertDeliverable($d['address']);
        $count = ShippingAddress::where('user_id', $user->id)->count();
        if ($count >= $this->max()) {
            throw new OrderException("Puoi salvare al massimo {$this->max()} indirizzi di spedizione: elimina quelli che non usi più.");
        }

        return ShippingAddress::create([
            'user_id' => $user->id, 'is_default' => $count === 0,
            'label' => $this->clean($d['label'] ?? null, 60), 'recipient' => $this->clean($d['recipient'] ?? null, 190),
            'address' => $this->clean($d['address'], 190), 'city' => $this->clean($d['city'], 120), 'zip' => $this->clean($d['zip'], 10),
            'province' => $this->clean($d['province'] ?? null, 3), 'country_code' => $this->country($d['country_code'] ?? null), 'phone' => $this->clean($d['phone'] ?? null, 30),
        ]);
    }

    /** @param array<string,mixed> $d */
    public function update(User $user, ShippingAddress $a, array $d): ShippingAddress
    {
        $this->own($user, $a);
        foreach (['address', 'city', 'zip'] as $f) {
            if (blank($d[$f] ?? null)) {
                throw new OrderException('Completa l\'indirizzo di spedizione: servono indirizzo, città e CAP.');
            }
        }
        $this->assertDeliverable($d['address']);
        $a->update([
            'label' => $this->clean($d['label'] ?? null, 60), 'recipient' => $this->clean($d['recipient'] ?? null, 190),
            'address' => $this->clean($d['address'], 190), 'city' => $this->clean($d['city'], 120), 'zip' => $this->clean($d['zip'], 10),
            'province' => $this->clean($d['province'] ?? null, 3), 'country_code' => $this->country($d['country_code'] ?? null), 'phone' => $this->clean($d['phone'] ?? null, 30),
        ]);

        return $a->refresh();
    }

    public function remove(User $user, ShippingAddress $a): void
    {
        $this->own($user, $a);
        $wasDefault = $a->is_default;
        $a->delete();
        if ($wasDefault) {
            ShippingAddress::where('user_id', $user->id)->orderBy('id')->first()?->update(['is_default' => true]);
        }
    }

    public function makeDefault(User $user, ShippingAddress $a): void
    {
        $this->own($user, $a);
        ShippingAddress::where('user_id', $user->id)->update(['is_default' => false]);
        $a->update(['is_default' => true]);
    }

    /**
     * Indirizzo di spedizione scelto in un modulo (checkout o abbonamento).
     * ship_to: «billing» (come la fatturazione), l'id di un indirizzo salvato, oppure «new» con i campi ship_* (e ship_save per salvarlo in rubrica).
     *
     * @param  array<string,mixed>  $in  campi del modulo
     * @param  array<string,mixed>  $billing  dati di fatturazione (per «come la fatturazione»)
     * @return array{id:?int,label:?string,recipient:?string,address:string,city:string,zip:string,province:?string,country_code:string,phone:?string}
     */
    public function resolve(User $user, array $in, array $billing): array
    {
        $to = (string) ($in['ship_to'] ?? 'billing');
        if ($to === 'new') {
            $data = [
                'label' => $in['ship_label'] ?? null, 'recipient' => $in['ship_recipient'] ?? null, 'address' => $in['ship_address'] ?? null,
                'city' => $in['ship_city'] ?? null, 'zip' => $in['ship_zip'] ?? null, 'province' => $in['ship_province'] ?? null, 'country_code' => $in['ship_country'] ?? null, 'phone' => $in['ship_phone'] ?? null,
            ];
            if (! empty($in['ship_save'])) {
                return $this->toArray($this->add($user, $data));
            }
            foreach (['address', 'city', 'zip'] as $f) {
                if (blank($data[$f])) {
                    throw new OrderException('Completa l\'indirizzo di spedizione: servono indirizzo, città e CAP.');
                }
            }
            $this->assertDeliverable($data['address']);

            $data['country_code'] = $this->country($data['country_code']);

            return ['id' => null] + array_map(fn ($v) => $v === null || $v === '' ? null : trim((string) $v), $data);
        }
        if (ctype_digit($to)) {
            $a = ShippingAddress::where('user_id', $user->id)->find((int) $to);
            if ($a === null) {
                throw new OrderException('Indirizzo di spedizione non trovato.');
            }
            if (($problem = AddressCheck::problem($a->address)) !== null) {
                throw new OrderException('L\'indirizzo salvato «'.($a->label ?: $a->address).'» non è completo. '.$problem.' Correggilo in «I miei dati» oppure scegli un altro indirizzo.');
            }

            return $this->toArray($a);
        }
        foreach (['address', 'city', 'zip'] as $f) {
            if (blank($billing[$f] ?? null)) {
                throw new OrderException('Completa l\'indirizzo di fatturazione e spedizione.');
            }
        }
        if (($problem = AddressCheck::problem((string) $billing['address'])) !== null) {
            throw new OrderException('Spediamo allo stesso indirizzo della fatturazione, ma non è completo. '.$problem.' Correggilo nei dati di fatturazione oppure scegli «Un nuovo indirizzo».');
        }

        return [
            'id' => null, 'label' => null, 'recipient' => null, 'address' => (string) $billing['address'], 'city' => (string) $billing['city'],
            'zip' => (string) $billing['zip'], 'province' => $billing['province'] ?? null, 'country_code' => $this->country($billing['country'] ?? null), 'phone' => null,
        ];
    }

    /** @return array{id:?int,label:?string,recipient:?string,address:string,city:string,zip:string,province:?string,country_code:string,phone:?string} */
    private function toArray(ShippingAddress $a): array
    {
        return ['id' => $a->id, 'label' => $a->label, 'recipient' => $a->recipient, 'address' => $a->address, 'city' => $a->city, 'zip' => $a->zip, 'province' => $a->province, 'country_code' => $a->country_code ?: 'IT', 'phone' => $a->phone];
    }

    private function own(User $user, ShippingAddress $a): void
    {
        if ($a->user_id !== $user->id) {
            throw new OrderException('Indirizzo di spedizione non trovato.');
        }
    }

    /** Un indirizzo senza via o senza numero civico non si consegna: meglio fermarsi prima dell'ordine che stampare un'etichetta vuota. */
    private function assertDeliverable(mixed $address): void
    {
        if (($problem = AddressCheck::problem((string) $address)) !== null) {
            throw new OrderException($problem);
        }
    }

    /** Nazione ISO a 2 lettere: vuota o non aperta nello shop = Italia. */
    public function country(mixed $v): string
    {
        $c = strtoupper(trim((string) $v));

        return in_array($c, (array) config('lamess.shop_countries'), true) ? $c : 'IT';
    }

    private function clean(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
