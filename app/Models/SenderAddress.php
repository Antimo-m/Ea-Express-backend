<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pickup_address', 'pickup_street_number', 'pickup_postal_code', 'pickup_city'])]
class SenderAddress extends Model
{
    use HasFactory;

    public const Fields = ['pickup_address', 'pickup_street_number', 'pickup_postal_code', 'pickup_city'];

    /** @param array<string, mixed> $data */
    public function matchesPickup(array $data): bool
    {
        foreach (self::Fields as $field) {
            $pattern = $field === 'pickup_street_number' ? '/[^\p{L}\p{N}\/-]+/u' : '/[^\p{L}\p{N}]+/u';
            $normalize = static fn (mixed $value): string => mb_strtolower(preg_replace($pattern, '', trim((string) $value)));
            if ($normalize($this->{$field}) !== $normalize($data[$field] ?? '')) {
                return false;
            }
        }

        return true;
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
