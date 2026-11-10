<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\BookingRules;
use App\Support\CustomerIdentity;
use App\Support\OrderContent;
use App\Support\PostalCodeResolver;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    /** @var array<string, array<int, string>> */
    private array $locationErrors = [];

    protected function prepareForValidation(): void
    {
        $category = $this->input('category');
        if (is_string($category) && $category !== 'custom' && isset(OrderContent::Categories[$category])
            && $this->input('content_description') === OrderContent::Categories[$category]) {
            $this->merge(['content_description' => null]);
        }
        if ($this->missing('sender_type') && $this->user()?->role === UserRole::Customer) {
            $this->merge(['sender_type' => $this->user()->sender_type]);
        }
        if ($this->missing('sender_type') && $this->user()?->role === UserRole::Admin && $this->filled('customer_id') && is_scalar($this->input('customer_id'))) {
            $customer = User::where('role', UserRole::Customer)->where('is_active', true)->where('slug', $this->input('customer_id'))->first();
            if ($customer) {
                $this->merge(['sender_type' => $customer->sender_type]);
            }
        }

        if (! is_string($this->input('delivery_city')) || ! $this->filled('delivery_postal_code') || ! is_string($this->input('delivery_postal_code'))) {
            return;
        }
        foreach (['delivery_zone', 'delivery_address', 'shipping_type', 'delivery_province', 'delivery_region'] as $field) {
            if ($this->input($field) !== null && ! is_string($this->input($field))) {
                return;
            }
        }
        try {
            $place = app(PostalCodeResolver::class)->canonical($this->input('delivery_city'), $this->input('delivery_postal_code'), $this->input('delivery_province'), $this->input('delivery_region'));
            $location = ['delivery_city' => $place['name'], 'delivery_postal_code' => $place['postal_code']];
            foreach (['province', 'region'] as $field) {
                if ($this->filled('delivery_'.$field)) {
                    $location['delivery_'.$field] = $place[$field];
                }
            }
            $this->merge($location);
        } catch (ValidationException $exception) {
            $this->locationErrors = $exception->errors();
        }
    }

    public function validated(mixed $key = null, mixed $default = null): mixed
    {
        $data = ShippingQuote::measurements(parent::validated());
        validator($data, [
            'weight_kg' => ['nullable', 'numeric', 'min:0.01', 'max:10000'],
            'max_dimension_cm' => ['nullable', 'numeric', 'min:1', 'max:500'],
        ])->validate();

        return data_get($data, $key, $default);
    }

    protected function getRedirectUrl(): string
    {
        return $this->routeIs('orders.store', 'orders.checkout.edit') ? route('orders.create') : parent::getRedirectUrl();
    }

    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function attributes(): array
    {
        return ['pickup_street_number' => 'numero civico di ritiro', 'pickup_postal_code' => 'CAP di ritiro', 'delivery_street_number' => 'numero civico di consegna', 'delivery_postal_code' => 'CAP di consegna', 'delivery_province' => 'provincia di consegna', 'delivery_region' => 'regione di consegna', 'package_type' => 'caratteristiche del pacco', 'package_description' => 'descrizione delle caratteristiche'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->locationErrors as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
            if ($validator->errors()->hasAny(['pickup_date', 'pickup_from', 'pickup_to'])) {
                return;
            }
            try {
                app(BookingRules::class)->validate($this->all(), $this->route('order'), $this->pickupLeadMinutes());
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }

    protected function pickupLeadMinutes(): int
    {
        return 0;
    }

    public function messages(): array
    {
        return ['parcel_value.required' => 'Inserisci il valore del pacco, anche se è zero.', 'parcel_value.regex' => 'Inserisci un importo non negativo con al massimo due decimali.'];
    }

    public function rules(): array
    {
        return [...CustomerIdentity::rules(),
            'checkout_token' => ['nullable', 'string', 'max:30000'],
            'shipping_type' => ['sometimes', 'required', 'in:regional,external'],
            'delivery_region' => ['required', 'string', 'max:100'],
            'delivery_province' => ['required', 'string', 'max:100'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.01', 'max:10000'],
            'max_dimension_cm' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'delivery_zone' => ['nullable', 'string', 'max:100'],
            'pickup_street_number' => ['required', 'string', 'max:20'], 'pickup_postal_code' => ['required', 'regex:/^[0-9]{5}$/D'],
            'delivery_street_number' => ['required', 'string', 'max:20'], 'delivery_postal_code' => ['required', 'regex:/^[0-9]{5}$/D'],
            'package_type' => ['required', 'in:standard,fragile,other'], 'package_description' => ['required_if:package_type,other', 'nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', Rule::exists('users', 'slug')->where('role', 'customer')->where('is_active', true), 'uuid'],
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
            'delivery_window' => ['exclude'], 'parcel_count' => ['required', 'integer', 'between:1,100'],
            'category' => ['required', Rule::in(array_keys(OrderContent::Categories))],
            'content_description' => ['required_if:category,custom', 'nullable', 'string', 'max:255'],
            'urgency' => ['required', Rule::in(['standard', 'urgent'])], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
