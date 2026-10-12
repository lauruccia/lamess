<?php

declare(strict_types=1);

namespace App\Support;

/** Etichette italiane dei codici di stato mostrati nel back-office (i codici nel database restano in inglese). */
final class StatusLabels
{
    /** Nomi di colonna a cui si applica la traduzione automatica. */
    public const COLUMNS = ['status', 'severity', 'source', 'sink_status', 'engine_status', 'situation'];

    public const MAP = [
        'active' => 'Attivo', 'suspended' => 'Sospeso', 'terminated' => 'Cessato', 'closed' => 'Chiuso', 'open' => 'Aperto',
        'review' => 'In revisione', 'calculating' => 'In calcolo', 'draft' => 'Bozza', 'pending_approval' => 'In approvazione', 'archived' => 'Archiviato', 'scheduled' => 'Programmato',
        'paid' => 'Pagato', 'cancelled' => 'Annullato', 'canceled' => 'Annullato', 'pending_payment' => 'In attesa di pagamento', 'pending' => 'In attesa',
        'approved' => 'Approvato', 'rejected' => 'Rifiutato', 'accepted' => 'Accettato', 'to_pay' => 'Da pagare', 'confirmed' => 'Confermato',
        'paused' => 'In pausa', 'requested' => 'Richiesto', 'published' => 'Pubblicato', 'disputed' => 'Contestato', 'resolved' => 'Deciso', 'credited' => 'Accreditato', 'answered' => 'Risposto', 'info' => 'Informazione', 'warning' => 'Attenzione', 'error' => 'Errore', 'manual' => 'Manuale',
        'referral' => 'Con referral', 'sent' => 'Inviato', 'failed' => 'Non riuscito', 'verified' => 'Verificato', 'unverified' => 'Da verificare',
        'accrued' => 'Maturato', 'held' => 'Trattenuto', 'excluded' => 'Escluso', 'completed' => 'Completato', 'expired' => 'Scaduto',
    ];

    public static function label(mixed $state): mixed
    {
        return is_string($state) && isset(self::MAP[$state]) ? self::MAP[$state] : $state;
    }
}
