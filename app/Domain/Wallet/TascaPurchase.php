<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\PricedCart;
use App\Models\CommissionStatement;
use App\Models\Consultant;
use App\Models\Order;
use App\Models\WalletTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Compensi della Tasca usati per acquistare (Regolamento 6.2).
 *
 * Ogni € di compenso vale 1 + N % di prodotti a listino (N = `lamess.tasca_bonus_percent`, 25: 120 € pagano 150 €).
 * Si usano solo compensi dei mesi chiusi (accreditati nel wallet), la cui finestra di reclamo dell'estratto conto è chiusa
 * e non impegnati in prelievi; la scelta è irreversibile (il debito non torna prelevabile).
 * L'ordine resta a listino: provvigioni e «cliente» si calcolano sull'importo pieno. La maggiorazione non passa dal wallet:
 * è scritta sull'ordine (`tasca_bonus`) e sommata nel report «Utilizzo compensi».
 *
 * Movimenti nel wallet: `purchase_debit` (−), `purchase_release` (+, ordine annullato prima del pagamento: tutto come prima),
 * `purchase_refund` (+, reso dopo il pagamento: credito utilizzabile solo per nuovi acquisti, non prelevabile).
 */
final class TascaPurchase
{
    public function __construct(private readonly WalletService $wallet) {}

    public static function enabled(): bool
    {
        return (bool) config('lamess.tasca_purchase_enabled', true);
    }

    public static function bonusPercent(): int
    {
        return max(0, (int) config('lamess.tasca_bonus_percent', 25));
    }

    /** Valore a listino di 1 € di compenso (1,25 con il 25%). */
    public static function factor(): BigDecimal
    {
        return BigDecimal::one()->plus(BigDecimal::of((string) self::bonusPercent())->dividedBy(100, 4, RoundingMode::HalfUp));
    }

    /** Compensi che l'incaricato può usare adesso per acquistare. */
    public function usable(Consultant $c): BigDecimal
    {
        if (! self::enabled() || ! WithdrawalService::enabled() || $c->status !== 'active' || $c->bonus_hold) {
            return Num::zero();
        }
        $free = $this->wallet->balance($c->id)->minus($this->wallet->reserved($c->id))->minus($this->held($c->id));

        return Num::max(Num::zero(), Num::money($free));
    }

    /** Accrediti di mesi il cui estratto conto è ancora contestabile o contestato: non si usano finché non è definitivo. */
    public function held(int $consultantId): BigDecimal
    {
        $periodIds = CommissionStatement::where('consultant_id', $consultantId)
            ->where(fn ($q) => $q->where('status', CommissionStatement::DISPUTED)
                ->orWhere(fn ($w) => $w->where('status', CommissionStatement::PUBLISHED)->where('dispute_deadline_at', '>', now())))
            ->pluck('period_id');
        if ($periodIds->isEmpty()) {
            return Num::zero();
        }
        $sum = WalletTransaction::where('consultant_id', $consultantId)->where('type', 'commission_credit')->whereIn('period_id', $periodIds)->sum('amount');

        return Num::max(Num::zero(), Num::money(BigDecimal::of((string) ($sum ?? 0))));
    }

    /**
     * Quanto copre la Tasca: valore a listino (mai oltre la parte pagabile e il totale), compensi usati e maggiorazione.
     *
     * @return array{amount: BigDecimal, used: BigDecimal, bonus: BigDecimal}
     */
    public function cover(BigDecimal $usable, BigDecimal $eligibleGross, BigDecimal $orderTotal): array
    {
        $factor = self::factor();
        $max = $usable->multipliedBy($factor)->toScale(2, RoundingMode::Down);
        $amount = Num::min(Num::min($max, $eligibleGross), $orderTotal);
        if (! $amount->isPositive()) {
            return ['amount' => Num::zero(), 'used' => Num::zero(), 'bonus' => Num::zero()];
        }
        $used = Num::min($amount->dividedBy($factor, 2, RoundingMode::HalfUp), $usable);

        return ['amount' => Num::money($amount), 'used' => Num::money($used), 'bonus' => Num::money($amount->minus($used))];
    }

    /**
     * Piano di pagamento di un carrello: se si usa la Tasca la spedizione è gratuita (si usa il carrello senza spedizione).
     *
     * @param  PricedCart|null  $waived  lo stesso carrello con la spedizione gratuita (null se non serve)
     * @return array{priced: PricedCart, amount: BigDecimal, used: BigDecimal, bonus: BigDecimal, covers_all: bool}
     */
    public function plan(BigDecimal $usable, PricedCart $standard, ?PricedCart $waived = null): array
    {
        $pick = $standard;
        if ($waived !== null && $standard->shippingNet->isPositive()
            && $this->cover($usable, $waived->tascaEligibleGross(), Num::of($waived->totals()['total']))['amount']->isPositive()) {
            $pick = $waived;
        }
        $total = Num::of($pick->totals()['total']);
        $cover = $this->cover($usable, $pick->tascaEligibleGross(), $total);

        return $cover + ['priced' => $pick, 'covers_all' => $cover['amount']->isPositive() && $cover['amount']->isEqualTo($total)];
    }

    /** Addebita i compensi dell'ordine; ricontrolla il saldo con l'incaricato bloccato (due ordini insieme non usano gli stessi compensi). */
    public function debit(Consultant $c, Order $order, BigDecimal $used): void
    {
        DB::transaction(function () use ($c, $order, $used): void {
            $c = Consultant::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($used->isGreaterThan($this->usable($c))) {
                throw new OrderException('I compensi utilizzabili nella Tasca non bastano più: aggiorna la pagina e riprova.');
            }
            $this->wallet->post($c, 'purchase_debit', $used->negated(), "Ordine {$order->number} pagato con i compensi", ['order_id' => $order->id]);
        });
    }

    /** Ordine annullato prima del pagamento: i compensi tornano come prima (anche prelevabili). Idempotente. */
    public function release(Order $order): void
    {
        if (! Num::of((string) $order->tasca_used)->isPositive()) {
            return;
        }
        DB::transaction(function () use ($order): void {
            if (WalletTransaction::where('order_id', $order->id)->where('type', 'purchase_release')->exists()) {
                return;
            }
            $c = Consultant::findOrFail($order->beneficiary_consultant_id);
            $this->wallet->post($c, 'purchase_release', (string) $order->tasca_used, "Ordine {$order->number} annullato: compensi restituiti", ['order_id' => $order->id]);
            $order->payments()->where('method', 'tasca')->update(['status' => 'refunded']);
            AuditLog::record('tasca.released', "Compensi restituiti per l'ordine {$order->number}: {$order->tasca_used} €", $order);
        });
    }

    /**
     * Reso dopo il pagamento: restituisce la parte di compensi corrispondente al valore rimborsato (senza maggiorazione).
     * Il credito è utilizzabile solo per nuovi acquisti. @return BigDecimal compensi restituiti
     */
    public function refund(Order $order, BigDecimal|string $value): BigDecimal
    {
        $total = Num::of((string) $order->tasca_amount);
        $used = Num::of((string) $order->tasca_used);
        if (! $total->isPositive() || ! $used->isPositive()) {
            return Num::zero();
        }
        $credit = Num::money(Num::of((string) $value)->multipliedBy($used)->dividedBy($total, 2, RoundingMode::HalfUp));
        if ($credit->isPositive()) {
            $c = Consultant::findOrFail($order->beneficiary_consultant_id);
            $this->wallet->post($c, 'purchase_refund', $credit, "Reso ordine {$order->number}: compensi restituiti (solo per acquisti)", ['order_id' => $order->id]);
        }

        return $credit;
    }
}
