<?php

namespace App\Http\Requests;

use App\Models\FinancialMovement;
use App\Rules\PlainText;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancialMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('kind') === 'cash') {
            $this->merge(['description' => 'Registrazione contanti', 'occurred_on' => $this->route('movement')?->occurred_on->toDateString() ?? now('Europe/Rome')->toDateString(), 'customer_id' => null, 'order_id' => null]);
        }
    }

    /**
     * Tipi utilizzabili: le correzioni contabili (rettifiche) non si creano più né si ottengono cambiando tipo;
     * una rettifica già registrata conserva il proprio tipo, così lo storico resta modificabile e i saldi invariati.
     *
     * @return list<string>
     */
    public static function allowedKinds(?FinancialMovement $movement = null): array
    {
        $kinds = array_values(array_diff(array_keys(FinancialMovement::Kinds), FinancialMovement::AdjustmentKinds));
        if ($movement && in_array($movement->kind, FinancialMovement::AdjustmentKinds, true)) {
            $kinds[] = $movement->kind;
        }

        return $kinds;
    }

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['version' => ['required', 'integer', 'min:1']];
        }

        return ['kind' => ['required', Rule::in(self::allowedKinds($this->route('movement')))], 'description' => ['required', 'string', 'max:500', new PlainText], 'amount' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->toDateString()], 'notes' => ['nullable', 'string', 'max:4000', new PlainText(multiline: true)], 'customer_id' => ['nullable', Rule::exists('users', 'slug')->where('role', 'customer'), 'uuid'], 'order_id' => ['nullable', 'uuid', 'exists:orders,slug'], 'version' => [$this->isMethod('PATCH') ? 'required' : 'nullable', 'integer', 'min:1'], 'submission_key' => [$this->isMethod('POST') ? 'required' : 'nullable', 'uuid']];
    }
}
