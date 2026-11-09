<?php

namespace App\Http\Requests\Auth;

use App\Rules\SafePasswordLength;
use App\Support\AuthProtection;
use App\Support\AuthSecurityStore;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => AuthSecurityStore::email($this->input('email'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', new SafePasswordLength],
            'cf-turnstile-response' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function authenticate(): void
    {
        app(AuthProtection::class)->attempt($this, 'web', [...$this->only('email', 'password'), 'is_active' => true, 'role' => ['admin', 'rider']]);
    }
}
