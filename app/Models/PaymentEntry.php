<?php

namespace App\Models;

use App\Support\HasPublicSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEntry extends Model
{
    use HasPublicSlug;

    protected function casts(): array
    {
        return ['ea_amount_cents' => 'integer', 'amount_cents' => 'integer', 'cash_amount_cents' => 'integer'];
    }

    public function originalEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_entry_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(ReceiptReversal::class, 'receipt_reversal_id');
    }

    public function label(): string
    {
        return match ($this->kind) {
            'reversal' => 'Storno contabile',
            'restoration' => 'Ripristino contabile',
            'refund' => 'Rimborso pagato',
            'recollection' => 'Nuovo incasso dopo rimborso',
            'cash_reconciliation' => 'Riconciliazione della cassa storica',
            default => $this->amount_cents < 0 ? 'Storno storico · da verificare' : 'Incasso',
        };
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
