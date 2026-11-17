<?php

namespace App\Http\Requests;

use App\OrderStatus;
use App\Support\OperationalAssignees;
use App\Support\TextFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $this->merge(['note' => trim($this->input('note'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['exclude_unless:status,cancelled', 'sometimes', 'required', 'in:other,recipient_absent'],
            'rider_id' => ['exclude_unless:status,accepted', 'required', 'uuid', app(OperationalAssignees::class)->rule($this->user())],
            'status' => ['required', Rule::enum(OrderStatus::class)], 'version' => ['required', 'integer', 'min:1'],
            'note' => ['required_if:status,rejected,cancelled,delivery_issue,delivery_attempted,rescheduled', 'nullable', 'string', Rule::when($this->input('status') === OrderStatus::Cancelled->value, ['min:5']), 'max:2000', ...TextFields::description(multiline: true)],
            'public_note' => ['nullable', 'string', 'max:500', ...TextFields::description()],
            'price' => [Rule::requiredIf(fn () => $this->input('status') === 'accepted' && $this->route('order')->pricing_version !== 1), 'nullable', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
            'estimated_at' => ['prohibited_unless:status,rescheduled', 'required_if:status,rescheduled', 'nullable', 'date_format:Y-m-d\TH:i', 'after:'.now('Europe/Rome')->format('Y-m-d H:i:s')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['note.min' => 'Indica il motivo dell’annullamento (almeno :min caratteri).', 'note.required_if' => 'Indica il motivo dell’operazione.'];
    }
}
