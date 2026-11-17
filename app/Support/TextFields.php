<?php

namespace App\Support;

use App\Rules\AllowedPortalText;
use App\Rules\PlainText;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation presets shared by staff and customer forms.
 *
 * Identity and descriptive fields go through the offensive-content filter; addresses only get the
 * technical checks, because real street names may contain words that the filter would reject.
 */
class TextFields
{
    /** @return list<ValidationRule> */
    public static function identity(): array
    {
        return [new PlainText(markup: false), new AllowedPortalText];
    }

    /** @return list<ValidationRule> */
    public static function description(bool $multiline = false): array
    {
        return [new PlainText($multiline), new AllowedPortalText];
    }

    /** @return list<ValidationRule> */
    public static function address(): array
    {
        return [new PlainText];
    }
}
