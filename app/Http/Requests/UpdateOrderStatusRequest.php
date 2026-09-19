<?php

namespace App\Http\Requests;

use App\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrderStatus::class)], 'version' => ['required', 'integer', 'min:1'],
            'note' => ['required_if:status,rejected,cancelled,delivery_issue,delivery_attempted,rescheduled', 'nullable', 'string', 'max:2000'],
            'public_note' => ['nullable', 'string', 'max:500'],
            'price' => [Rule::requiredIf(fn () => $this->input('status') === 'accepted' && $this->route('order')->pricing_version !== 1), 'nullable', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
            'estimated_at' => ['required_if:status,rescheduled', 'nullable', 'date_format:Y-m-d\TH:i', 'after:'.now('Europe/Rome')->format('Y-m-d H:i:s')],
        ];
    }
}
