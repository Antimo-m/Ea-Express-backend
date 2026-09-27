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
        return ['shipping_type' => ['sometimes', 'required', 'in:regional,external'], 'carrier_name' => ['nullable', 'string', 'max:100'], 'carrier_cost' => ['nullable', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'max_weight_kg' => ['nullable', 'numeric', 'min:0.01', 'max:10000'], 'max_dimension_cm' => ['nullable', 'numeric', 'min:1', 'max:500'], 'delivery_days_min' => ['nullable', 'integer', 'min:1', 'max:365'], 'delivery_days_max' => ['required_with:delivery_days_min', 'nullable', 'integer', 'gte:delivery_days_min', 'max:365'], 'city' => ['required', 'string', 'max:100'], 'area' => ['nullable', 'string', 'max:100'], 'zone' => ['nullable', 'string', 'max:100'], 'street' => ['nullable', 'string', 'max:255'], 'postal_code' => ['nullable', 'regex:/^[0-9]{5}$/D'], 'price' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'delivery_time' => ['nullable', 'string', 'max:100'], 'source_reference' => ['required', 'string', 'max:255'], 'active' => ['required', 'boolean']];
    }
}
