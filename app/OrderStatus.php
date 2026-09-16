<?php

namespace App;

enum OrderStatus: string
{
    case Received = 'received';
    case Accepted = 'accepted';
    case PickupScheduled = 'pickup_scheduled';
    case RiderArriving = 'rider_arriving';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryAttempted = 'delivery_attempted';
    case Rescheduled = 'rescheduled';
    case DeliveryIssue = 'delivery_issue';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Richiesta ricevuta', self::Accepted => 'Ordine confermato', self::PickupScheduled => 'Ritiro programmato', self::RiderArriving => 'Rider in arrivo', self::PickedUp => 'Pacco ritirato', self::InTransit => 'In transito', self::OutForDelivery => 'In consegna', self::Delivered => 'Consegnato', self::DeliveryAttempted => 'Tentativo di consegna', self::Rescheduled => 'Consegna riprogrammata', self::DeliveryIssue => 'Problema nella consegna', self::Rejected => 'Rifiutato', self::Cancelled => 'Annullato',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::Accepted => 'Prendi in carico', self::PickupScheduled => 'Programma il ritiro',
            self::RiderArriving => 'Parti per il ritiro', self::PickedUp => 'Conferma pacco ritirato',
            self::InTransit => 'Avvia il trasporto', self::OutForDelivery => 'Inizia la consegna',
            self::Delivered => 'Conferma consegna', self::DeliveryAttempted => 'Destinatario assente',
            self::DeliveryIssue => 'Segnala un problema', self::Rescheduled => 'Riprogramma',
            self::Rejected => 'Rifiuta richiesta', self::Cancelled => 'Annulla spedizione',
            self::Received => 'Recupera richiesta',
        };
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Received => [self::Accepted, self::Rejected, self::Cancelled],
            self::Accepted => [self::PickupScheduled, self::RiderArriving, self::Cancelled],
            self::PickupScheduled => [self::RiderArriving, self::Cancelled],
            self::RiderArriving => [self::PickedUp, self::DeliveryIssue, self::Cancelled],
            self::PickedUp => [self::InTransit, self::OutForDelivery, self::DeliveryIssue],
            self::InTransit => [self::OutForDelivery, self::DeliveryIssue],
            self::OutForDelivery => [self::Delivered, self::DeliveryAttempted, self::DeliveryIssue],
            self::DeliveryAttempted, self::DeliveryIssue => [self::Rescheduled, self::Cancelled],
            self::Rescheduled => [self::RiderArriving, self::OutForDelivery, self::DeliveryIssue, self::Cancelled],
            self::Rejected => [self::Received],
            default => [],
        };
    }

    /** @return list<string> */
    public static function closed(): array
    {
        return [self::Delivered->value, self::Rejected->value, self::Cancelled->value];
    }

    public function tone(): string
    {
        return match ($this) {
            self::Delivered => 'green', self::Rejected, self::Cancelled, self::DeliveryIssue, self::DeliveryAttempted => 'danger', self::Received => 'blue', default => 'orange'
        };
    }
}
