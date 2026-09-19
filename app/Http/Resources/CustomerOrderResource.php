<?php

namespace App\Http\Resources;

use App\Support\OrderContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'reference' => $this->reference, 'status' => $this->status->value, 'status_label' => $this->status->label(), 'version' => $this->version,
            'store_name' => $this->store_name, 'sender_type' => $this->sender_type, 'business_type' => $this->business_type, 'business_description' => $this->business_description, 'parcel_value_cents' => $this->parcel_value_cents,
            'recipient_name' => $this->recipient_name, 'recipient_phone' => $this->recipient_phone,
            'pickup_address' => $this->pickup_address, 'pickup_city' => $this->pickup_city, 'delivery_address' => $this->delivery_address, 'delivery_city' => $this->delivery_city,
            'pickup_date' => $this->pickup_date->toDateString(), 'pickup_from' => substr($this->pickup_from, 0, 5), 'pickup_to' => substr($this->pickup_to, 0, 5),
            'pickup_street_number' => $this->pickup_street_number, 'pickup_postal_code' => $this->pickup_postal_code, 'delivery_street_number' => $this->delivery_street_number, 'delivery_postal_code' => $this->delivery_postal_code,
            'package_type' => $this->package_type, 'package_description' => $this->package_description,
            'pricing_version' => $this->pricing_version, 'quoted_price_cents' => $this->quoted_price_cents, 'price_state' => $this->price_state, 'price_label' => $this->resource->priceLabel(), 'rate_snapshot' => $this->rate_snapshot,
            'price_proposals' => $this->whenLoaded('priceProposals', fn () => $this->priceProposals->map(fn ($p) => ['id' => $p->id, 'price_cents' => $p->price_cents, 'previous_price_cents' => $p->previous_price_cents, 'reason' => $p->reason, 'state' => $p->state, 'created_at' => $p->created_at->toIso8601String(), 'responded_at' => $p->responded_at?->toIso8601String(), 'proposed_by' => $p->proposed_by])),
            'delivery_window' => $this->delivery_window, 'parcel_count' => $this->parcel_count, 'packages' => $this->packages, 'category' => $this->category, 'content_description' => $this->content_description, 'category_label' => OrderContent::label($this->category, $this->content_description), 'urgency' => $this->urgency, 'customer_notes' => $this->customer_notes,
            'delivery_zone' => $this->delivery_zone, 'total_cents' => $this->price_cents === null ? null : ($this->parcel_value_cents ?? 0) + $this->price_cents,
            'payment' => $this->resource->paymentData(),
            'price_cents' => $this->price_cents, 'tracking_active' => $this->tracking_started_at !== null, 'estimated_at' => $this->estimated_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(), 'created_at' => $this->created_at->toIso8601String(),
            'messages_count' => $this->whenCounted('messages'), 'unread_messages_count' => $this->whenCounted('unread_messages'),
            'can_edit' => $this->resource->customerEditable(), 'can_cancel' => $this->resource->customerEditable(),
            'courier' => $this->whenLoaded('rider', fn () => $this->rider ? ['id' => $this->rider->id, 'name' => $this->rider->name] : null),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => ['id' => $event->id, 'status' => $event->status->value, 'label' => $event->status->label(), 'message' => $event->public_note, 'created_at' => $event->created_at->toIso8601String()])),
        ];
    }
}
