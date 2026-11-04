<?php

namespace App\Rules;

use App\Support\OrderContent;
use App\Support\PortalTextPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class AllowedPortalText implements ValidationRule
{
    public function __construct(private bool $contentCategory = false) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $policy = app(PortalTextPolicy::class);
        if ($this->contentCategory && is_string($value)) {
            $value = OrderContent::Categories[$value] ?? $value;
        }
        if (is_string($value) && ! $policy->allows($value)) {
            $fail($policy->definition()['message']);
        }
    }
}
