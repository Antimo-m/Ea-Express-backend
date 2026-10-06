<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;

class PublicReferences
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function ids(array $data): array
    {
        foreach (['customer_id' => User::class, 'rider_id' => User::class, 'order_id' => Order::class] as $field => $model) {
            if (! empty($data[$field])) {
                $data[$field] = $model::where('slug', $data[$field])->firstOrFail()->id;
            }
        }

        return $data;
    }
}
