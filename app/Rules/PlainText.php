<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects control characters, bidirectional overrides and (for identity fields) markup delimiters.
 * The submitted value is validated as-is and never rewritten.
 */
class PlainText implements ValidationRule
{
    public const Message = 'Il campo contiene caratteri non consentiti.';

    public function __construct(private bool $multiline = false, private bool $markup = true) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        $controls = $this->multiline ? '/[^\P{Cc}\t\n\r]/u' : '/\p{Cc}/u';
        if (! mb_check_encoding($value, 'UTF-8') || preg_match($controls, $value) === 1 || preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 1 || (! $this->markup && preg_match('/[<>]/', $value) === 1)) {
            $fail(self::Message);
        }
    }
}
