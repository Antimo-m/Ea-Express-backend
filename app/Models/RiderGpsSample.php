<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderGpsSample extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['position' => 'encrypted:array', 'recorded_at' => 'datetime', 'captured_at' => 'datetime'];
    }
}
