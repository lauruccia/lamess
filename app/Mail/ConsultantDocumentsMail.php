<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Documenti inviati dall'amministrazione all'incaricato (lettera, tesserino, estratti, ricevute…). */
final class ConsultantDocumentsMail extends Mailable
{
    /** @param list<array{filename:string, mime:string, data:string}> $files  @param list<string> $labels */
    public function __construct(public readonly string $name, public readonly array $labels, public readonly array $files, public readonly ?string $note = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Documenti dal Consorzio');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.consultant-documents');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(fn (array $f) => Attachment::fromData(fn () => $f['data'], $f['filename'])->withMime($f['mime']), $this->files);
    }
}
