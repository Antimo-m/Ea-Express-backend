<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderLifecycle extends Mailable
{
    /** @param array<string, string|null> $snapshot */
    public function __construct(public array $snapshot, public string $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'EA Express · '.($this->event === 'delivered' ? 'Ordine consegnato' : 'Ordine programmato').' · '.$this->snapshot['reference']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.lifecycle');
    }
}
