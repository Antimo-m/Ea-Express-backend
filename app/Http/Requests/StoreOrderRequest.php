<?php

namespace App\Http\Requests;

use App\Support\CustomerIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    public function rules(): array
    {
        return [...CustomerIdentity::rules(),
            'packages' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:100', 'size:'.$this->integer('parcel_count')],
            'packages.*' => ['required', 'array:weight_kg,length_cm,width_cm,height_cm'],
            'packages.*.weight_kg' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'packages.*.length_cm' => ['required', 'numeric', 'min:1', 'max:500'],
            'packages.*.width_cm' => ['required', 'numeric', 'min:1', 'max:500'],
            'packages.*.height_cm' => ['required', 'numeric', 'min:1', 'max:500'],
            'parcel_value' => ['nullable', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
            'store_name' => ['required', 'string', 'max:150'], 'contact_email' => ['nullable', 'email', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:150'], 'recipient_phone' => ['required', 'string', 'max:30', 'regex:/^[+0-9 ()\-]{6,30}$/D'],
            'pickup_address' => ['required', 'string', 'max:255'], 'pickup_city' => ['required', 'string', 'max:100'],
            'delivery_address' => ['required', 'string', 'max:255'], 'delivery_city' => ['required', 'string', 'max:100'],
            'pickup_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Europe/Rome')->toDateString()],
            'pickup_from' => ['required', 'date_format:H:i'], 'pickup_to' => ['required', 'date_format:H:i', 'after:pickup_from'],
            'delivery_window' => ['nullable', 'string', 'max:150'], 'parcel_count' => ['required', 'integer', 'between:1,100'],
            'category' => ['required', Rule::in(['clothing', 'documents', 'other'])], 'urgency' => ['required', Rule::in(['standard', 'urgent'])], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
