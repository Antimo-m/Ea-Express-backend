<?php

namespace App\Http\Requests;

use App\Support\BookingRules;
use App\Support\CustomerIdentity;
use App\Support\OrderContent;
use App\Support\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    public function attributes(): array
    {
        return ['pickup_street_number' => 'numero civico di ritiro', 'pickup_postal_code' => 'CAP di ritiro', 'delivery_street_number' => 'numero civico di consegna', 'delivery_postal_code' => 'CAP di consegna', 'package_type' => 'caratteristiche del pacco', 'package_description' => 'descrizione delle caratteristiche'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['pickup_date', 'pickup_from', 'pickup_to'])) {
                return;
            }
            try {
                app(BookingRules::class)->validate($this->all(), $this->route('order'));
            } catch (ValidationException $exception) {
                $validator->errors()->add('pickup_from', $exception->errors()['pickup_from'][0]);
            }
        }];
    }

    public function messages(): array
    {
        return ['parcel_value.required' => 'Inserisci il valore del pacco, anche se è zero.', 'parcel_value.regex' => 'Inserisci un importo non negativo con al massimo due decimali.'];
    }

    public function rules(): array
    {
        return [...CustomerIdentity::rules(),
            'delivery_zone' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['required', Rule::in(array_keys(PaymentMethod::Labels))],
            'pickup_street_number' => ['required', 'string', 'max:20'], 'pickup_postal_code' => ['required', 'regex:/^[0-9]{5}$/D'],
            'delivery_street_number' => ['required', 'string', 'max:20'], 'delivery_postal_code' => ['required', 'regex:/^[0-9]{5}$/D'],
            'package_type' => ['required', 'in:standard,fragile,other'], 'package_description' => ['required_if:package_type,other', 'nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'customer')->where('is_active', true)],
            'packages' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:100', 'size:'.$this->integer('parcel_count')],
            'packages.*' => ['required', 'array:weight_kg,length_cm,width_cm,height_cm'],
            'packages.*.weight_kg' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'packages.*.length_cm' => ['required', 'numeric', 'min:1', 'max:500'],
            'packages.*.width_cm' => ['required', 'numeric', 'min:1', 'max:500'],
            'packages.*.height_cm' => ['required', 'numeric', 'min:1', 'max:500'],
            'parcel_value' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
            'store_name' => ['required', 'string', 'max:150'], 'contact_email' => ['nullable', 'email', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:150'], 'recipient_phone' => ['required', 'string', 'max:30', 'regex:/^[+0-9 ()\-]{6,30}$/D'],
            'pickup_address' => ['required', 'string', 'max:255'], 'pickup_city' => ['required', 'string', 'max:100'],
            'delivery_address' => ['required', 'string', 'max:255'], 'delivery_city' => ['required', 'string', 'max:100'],
            'pickup_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.(app(BookingRules::class)->unchanged($this->all(), $this->route('order')) ? $this->route('order')->pickup_date->toDateString() : now('Europe/Rome')->toDateString())],
            'pickup_from' => ['required', 'date_format:H:i'], 'pickup_to' => ['required', 'date_format:H:i', 'after:pickup_from'],
            'delivery_window' => ['nullable', 'date_format:H:i'], 'parcel_count' => ['required', 'integer', 'between:1,100'],
            'category' => ['required', Rule::in(array_keys(OrderContent::Categories))],
            'content_description' => ['nullable', 'string', 'max:255'],
            'urgency' => ['required', Rule::in(['standard', 'urgent'])], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
