<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PendingAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['subject' => ['required', 'string', 'max:150'], 'direction' => ['required', 'in:incoming,outgoing'], 'description' => ['required', 'string', 'max:500'], 'amount' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'customer_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'customer')], 'order_id' => ['nullable', 'integer', 'exists:orders,id'], 'occurred_on' => ['required', 'date_format:Y-m-d'], 'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:occurred_on'], 'notes' => ['nullable', 'string', 'max:4000']];
    }
}
