<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Mail\InvoiceXmlMail;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Come il sink «file», in più spedisce l'XML in allegato all'indirizzo configurato
 * (casella di importazione del gestionale, commercialista o PEC verso lo SdI).
 */
final class MailInvoiceSink extends FileInvoiceSink
{
    public function name(): string
    {
        return 'mail';
    }

    public function send(Invoice|CreditNote $document): SinkResult
    {
        $to = trim((string) config('lamess.sdi.mail_to'));
        if ($to === '') {
            return new SinkResult(false, null, 'Indirizzo di destinazione delle fatture non impostato (LAMESS_SDI_MAIL_TO).');
        }
        if (! config('lamess.mail_enabled')) {
            return new SinkResult(false, null, 'L\'invio email è spento: la fattura resta in coda e partirà quando lo accendi.');
        }
        try {
            $stored = $this->store($document);
            if ($stored['xml'] === '') {
                return $stored['result'];
            }
            $subject = ($document instanceof CreditNote ? 'Nota di credito ' : 'Fattura ').$document->number;
            Mail::to($to)->send(new InvoiceXmlMail($subject, $stored['filename'], $stored['xml']));

            return new SinkResult(true, $stored['filename']);
        } catch (BillingException $e) {
            return new SinkResult(false, null, $e->getMessage());
        } catch (Throwable $e) {
            return new SinkResult(false, null, 'Invio email non riuscito: '.$e->getMessage());
        }
    }
}
