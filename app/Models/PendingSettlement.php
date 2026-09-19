<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pending_account_id', 'user_id', 'amount_cents', 'method', 'note', 'submission_key'])]
class PendingSettlement extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PendingAccount::class, 'pending_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
