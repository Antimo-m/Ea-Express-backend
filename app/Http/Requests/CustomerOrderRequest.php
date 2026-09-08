<?php

namespace App\Http\Requests;

use App\UserRole;

class CustomerOrderRequest extends StoreOrderRequest
{
    public function authorize(): bool
    {
        return $this->user('customer')?->role === UserRole::Customer;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['store_name'],$rules['contact_email'],$rules['notes']);
        $rules['customer_notes'] = ['nullable', 'string', 'max:2000'];
        if ($this->isMethod('PATCH')) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
