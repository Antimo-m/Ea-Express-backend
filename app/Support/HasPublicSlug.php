<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicSlug
{
    public static function bootHasPublicSlug(): void
    {
        static::creating(function (Model $model): void {
            $model->slug ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
