<?php

namespace App\Http\Requests;

use App\Rules\UnchangedAccountEmail;
use App\Support\PortalTextPolicy;
use App\Support\TextFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(PortalTextPolicy::normalizeFields($this->only(['name'])));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', ...TextFields::identity()],
            'email' => [new UnchangedAccountEmail($this->user())],
        ];
    }
}
