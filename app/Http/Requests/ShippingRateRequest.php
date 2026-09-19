<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class ShippingRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['city' => ['required', 'string', 'max:100'], 'area' => ['nullable', 'string', 'max:100'], 'zone' => ['nullable', 'string', 'max:100'], 'street' => ['nullable', 'string', 'max:255'], 'postal_code' => ['nullable', 'regex:/^[0-9]{5}$/D'], 'price' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'delivery_time' => ['nullable', 'string', 'max:100'], 'source_reference' => ['required', 'string', 'max:255'], 'active' => ['required', 'boolean']];
    }
}
