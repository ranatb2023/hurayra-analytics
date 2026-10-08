<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A plain operational alert: a subject line and a short message. */
class AlertMail extends Mailable
{
    public function __construct(
        public readonly string $alertSubject,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->body)));
    }
}
