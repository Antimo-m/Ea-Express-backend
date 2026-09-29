<?php

namespace App\Support;

use Illuminate\Support\Str;

class RecipientIdentity
{
    public const Fields = ['recipient_name', 'recipient_phone', 'delivery_address', 'delivery_street_number', 'delivery_postal_code', 'delivery_city', 'delivery_province'];

    public static function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))));
    }

    /** @param array<string, mixed> $data @return array{phone_key: ?string, address_key: ?string} */
    public static function keys(array $data): array
    {
        $name = self::normalize($data['recipient_name'] ?? '');
        $rawPhone = trim($data['recipient_phone'] ?? '');
        $phone = preg_replace('/\D/', '', $rawPhone);
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        } elseif (! str_starts_with($rawPhone, '+') && (str_starts_with($phone, '0') || (str_starts_with($phone, '3') && strlen($phone) === 10))) {
            $phone = '39'.$phone;
        }
        $street = self::normalize($data['delivery_address'] ?? '');
        $number = self::normalize($data['delivery_street_number'] ?? '');
        if ($number !== '' && ! str_ends_with(' '.$street, ' '.$number)) {
            $street .= ' '.$number;
        }
        $city = self::normalize($data['delivery_city'] ?? '');
        $postal = preg_replace('/\s/', '', $data['delivery_postal_code'] ?? '');

        return [
            'phone_key' => $name !== '' && strlen($phone) >= 8 && strlen($phone) <= 15 ? hash('sha256', $name.'|'.$phone) : null,
            'address_key' => $name !== '' && $street !== '' && preg_match('/\d/', $street) && $city !== '' && preg_match('/^\d{5}$/', $postal) ? hash('sha256', $name.'|'.$street.'|'.$postal.'|'.$city) : null,
        ];
    }
}
