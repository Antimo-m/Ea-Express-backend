<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class PendingSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['ea_amount' => ['nullable', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'amount' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'submission_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']];
    }
}
