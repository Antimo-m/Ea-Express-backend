<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    public function rules(): array
    {
        return ['submission_key' => ['nullable', 'uuid'], 'version' => [$this->isMethod('PATCH') ? 'required' : 'nullable', 'integer', 'min:1'], 'description' => ['required', 'string', 'max:200'], 'amount' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->toDateString()]];
    }
}
