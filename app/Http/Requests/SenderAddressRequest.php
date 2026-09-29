<?php

namespace App\Http\Requests;

use App\Support\CustomerIdentity;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class SenderAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer')?->role === UserRole::Customer;
    }

    public function rules(): array
    {
        return [...CustomerIdentity::rules(),
            'sender_type' => ['required', 'in:business,private,online_shop'],
            'store_name' => ['required', 'string', 'max:150'],
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_street_number' => ['required', 'string', 'max:20'],
            'pickup_postal_code' => ['required', 'regex:/^[0-9]{5}$/D'],
            'pickup_city' => ['required', 'string', 'max:100'],
            'version' => ['required', 'integer', 'min:0'],
            'customer_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ];
    }
}
