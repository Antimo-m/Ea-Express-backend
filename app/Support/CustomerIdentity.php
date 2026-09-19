<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class CustomerIdentity
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'sender_type' => ['sometimes', 'required', Rule::in(['business', 'private', 'online_shop'])],
            'business_type' => ['nullable', 'string', 'max:100'],
            'business_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function normalize(array $data, string $fallback = 'business'): array
    {
        $data['sender_type'] = $data['sender_type'] ?? $fallback;
        if ($data['sender_type'] === 'private') {
            $data['business_type'] = null;
            $data['business_description'] = null;
        }

        return $data;
    }
}
