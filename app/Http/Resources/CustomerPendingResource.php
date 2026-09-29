<?php

namespace App\Http\Resources;

use App\Models\PendingSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPendingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'direction_label' => $this->direction === 'incoming' ? 'Devi pagare EA Express' : 'EA Express deve pagarti',
            'subject' => $this->subject,
            'description' => $this->description,
            'amount_cents' => $this->amount_cents,
            'settled_cents' => $this->settled_cents,
            'remaining_cents' => $this->state === 'cancelled' ? 0 : $this->amount_cents - $this->settled_cents,
            'state' => $this->state,
            'status_label' => $this->resource->statusLabel(),
            'occurred_on' => $this->occurred_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'settlements' => $this->whenLoaded('settlements', fn () => $this->settlements->map(fn (PendingSettlement $settlement): array => [
                'id' => $settlement->id,
                'amount_cents' => $settlement->amount_cents,
                'created_at' => $settlement->created_at->toIso8601String(),
                'updated_at' => $settlement->updated_at->toIso8601String(),
            ])),
        ];
    }
}
