<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['identity_key'])]
class RecipientRiskProfile extends Model
{
    use HasFactory;

    public function incidents(): HasMany
    {
        return $this->hasMany(RecipientIncident::class);
    }

    public function latestIncident(): HasOne
    {
        return $this->hasOne(RecipientIncident::class)->ofMany(['occurred_at' => 'max', 'id' => 'max']);
    }
}
