<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderGpsSample extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['position' => 'encrypted:array', 'recorded_at' => 'datetime', 'captured_at' => 'datetime'];
    }
}
