<?php

namespace App\Http\Requests;

use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class PendingAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:update,cancel'], 'amount' => ['sometimes', 'required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'subject' => ['required_if:action,update', 'string', 'max:150'], 'description' => ['required_if:action,update', 'string', 'max:500'], 'due_on' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:4000']];
    }
}
