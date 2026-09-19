<?php

namespace App\Http\Requests;

use App\Support\PaymentMethod;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PendingSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'method' => ['required', Rule::in(array_keys(PaymentMethod::Labels))], 'submission_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']];
    }
}
