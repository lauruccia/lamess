<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** Movimento PV confermato (pagamento riconciliato). Gli altri stati non entrano nel motore. */
final readonly class Movement
{
    public string $id;

    public string $ownerId;

    public ?string $reversesId;

    public ?string $orderId;

    public function __construct(
        int|string $id,
        int|string $ownerId,          // incaricato che genera i punti (acquirente incaricato o referente del cliente)
        public string $kind,          // pvf | pvc
        public BigDecimal $pv,
        public BigDecimal $cv,
        public DateTimeImmutable $at, // competenza provvigionale (conferma pagamento, OPEN-25)
        int|string|null $reversesId = null,
        public bool $excluded = false,
        public ?string $customerId = null, // cliente (FAN) acquirente, per i pvf: serve al piano Incaricati
        public ?BigDecimal $amountEur = null, // importo in euro IVA inclusa (piano Incaricati); null = movimento senza importo
        ?string $orderId = null,           // ordine di provenienza: gli importi di righe dello stesso ordine si sommano
        public ?BigDecimal $vapEur = null, // V.A.P. in euro deciso dal prodotto (null = quota V.A.P. del piano sull'importo)
        public ?int $validityMonths = null, // mesi di validità del cliente decisi dal prodotto (null = 1)
        public ?int $clientCount = null,    // quanti clienti vale l'acquisto, deciso dal prodotto (null = 1)
        public ?bool $makesClient = null,   // il prodotto rende cliente a prescindere dall'importo minimo (true) · null = no
    ) {
        $this->orderId = $orderId;
        $this->id = (string) $id;
        $this->ownerId = (string) $ownerId;
        $this->reversesId = $reversesId === null ? null : (string) $reversesId;
    }
}
