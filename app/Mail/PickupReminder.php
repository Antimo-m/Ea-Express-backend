<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PickupReminder extends Mailable
{
    /**
     * @param  array{date: string, orders: list<array{id: int, reference: string, time: string, pickup: string, recipient: string, destination: string, parcels: int}>}  $snapshot
     */
    public function __construct(public array $snapshot, public string $kind) {}

    public function envelope(): Envelope
    {
        $count = count($this->snapshot['orders']);
        $pickups = $count === 1 ? '1 ritiro' : $count.' ritiri';

        return new Envelope(subject: 'EA-Express · Promemoria: '.($this->kind === 'evening' ? 'domani' : 'oggi').' '.$pickups.' da preparare');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.pickup-reminder', with: ['portalUrl' => rtrim((string) config('customer.frontend_url'), '/').'/pickups']);
    }
}
