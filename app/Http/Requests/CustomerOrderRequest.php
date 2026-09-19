<?php

namespace App\Http\Requests;

use App\UserRole;

class CustomerOrderRequest extends StoreOrderRequest
{
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
        unset($rules['contact_email'], $rules['notes'], $rules['customer_id']);
        $rules['store_name'] = ['sometimes', 'required', 'string', 'max:150'];
        $rules['customer_notes'] = ['nullable', 'string', 'max:2000'];
        $rules['checkout_token'] = [$this->routeIs('customer.checkout', 'customer.orders.review') ? 'nullable' : 'required', 'string', 'max:30000'];
        if ($this->route('order')) {
            if ($this->input('delivery_window') === $this->route('order')?->delivery_window) {
                $rules['delivery_window'] = ['nullable', 'string', 'max:150'];
            }
            $rules['version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
