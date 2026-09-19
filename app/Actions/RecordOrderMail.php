<?php

namespace App\Actions;

use App\Models\Order;
use App\Support\OrderContent;
use Illuminate\Support\Facades\DB;

class RecordOrderMail
{
    public function handle(Order $order, string $event): void
    {
        if (! $order->contact_email) {
            return;
        }
        $snapshot = [
            'reference' => $order->reference, 'sender' => $order->store_name,
            'recipient' => $order->recipient_name,
            'content' => OrderContent::label($order->category, $order->content_description),
            'pickup' => trim($order->pickup_address.' '.$order->pickup_street_number).', '.trim($order->pickup_postal_code.' '.$order->pickup_city),
            'delivery' => trim($order->delivery_address.' '.$order->delivery_street_number).', '.trim($order->delivery_postal_code.' '.$order->delivery_city),
            'date' => $order->pickup_date->format('d/m/Y'),
            'pickup_time' => substr($order->pickup_from, 0, 5).' – '.substr($order->pickup_to, 0, 5),
            'delivery_time' => $order->delivery_window,
            'delivered_at' => $order->delivered_at?->timezone('Europe/Rome')->format('d/m/Y H:i'),
            'tracking_url' => route('tracking.public', $order->tracking_token),
        ];
        DB::table('order_mail_deliveries')->insertOrIgnore([
            'order_id' => $order->id, 'event' => $event, 'recipient' => $order->contact_email,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'state' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
