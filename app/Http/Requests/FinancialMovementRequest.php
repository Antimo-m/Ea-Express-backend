<?php

namespace App\Http\Requests;

use App\Models\FinancialMovement;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancialMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['version' => ['required', 'integer', 'min:1']];
        }

        return ['kind' => ['required', Rule::in(array_keys(FinancialMovement::Kinds))], 'description' => ['required', 'string', 'max:500'], 'amount' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->toDateString()], 'notes' => ['nullable', 'string', 'max:4000'], 'customer_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'customer')], 'order_id' => ['nullable', 'integer', 'exists:orders,id'], 'version' => [$this->isMethod('PATCH') ? 'required' : 'nullable', 'integer', 'min:1'], 'submission_key' => [$this->isMethod('POST') ? 'required' : 'nullable', 'uuid']];
    }
}
