<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Fattura o nota di credito in formato FatturaPA, allegata in XML. */
final class InvoiceXmlMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $filename, public readonly string $xml) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invoice-xml');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->xml, $this->filename)->withMime('application/xml')];
    }
}
