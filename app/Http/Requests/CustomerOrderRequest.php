<?php

namespace App\Http\Requests;

use App\Rules\AllowedPortalText;
use App\Support\CustomerIdentity;
use App\Support\PortalTextPolicy;
use App\Support\TextFields;
use App\UserRole;

class CustomerOrderRequest extends StoreOrderRequest
{
    protected function pickupLeadMinutes(): int
    {
        return 1;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(PortalTextPolicy::normalizeFields($this->only(['store_name'])));
        if ($this->user('customer')?->role === UserRole::Customer) {
            $this->merge(CustomerIdentity::profile($this->user('customer')));
        }

        parent::prepareForValidation();
    }

    public function authorize(): bool
    {
        if ($this->route('order') && $this->user('customer')?->role === UserRole::Customer) {
            abort_unless($this->route('order')->customer_id === $this->user('customer')->id, 404);
        }

        return $this->user('customer')?->role === UserRole::Customer;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['category'][] = new AllowedPortalText(contentCategory: true);
        unset($rules['contact_email'], $rules['notes'], $rules['customer_id']);
        $rules['store_name'] = ['sometimes', 'required', 'string', 'max:150', ...TextFields::identity()];
        $rules['customer_notes'] = ['nullable', 'string', 'max:2000', ...TextFields::description(multiline: true)];
        $rules['checkout_token'] = [$this->routeIs('customer.checkout', 'customer.orders.review') ? 'nullable' : 'required', 'string', 'max:30000'];
        if ($this->route('order')) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
