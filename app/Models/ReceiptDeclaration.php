<?php

namespace App\Models;

use App\Support\HasPublicSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conferma d'incasso inviata dal Rider: segnala all'Admin di aver ricevuto un pagamento.
 * Non è un movimento contabile e non entra nei saldi: l'incasso effettivo lo registra solo l'Admin.
 */
#[Fillable(['amount_cents', 'note'])]
class ReceiptDeclaration extends Model
{
    use HasPublicSlug;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'handled_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }
}
