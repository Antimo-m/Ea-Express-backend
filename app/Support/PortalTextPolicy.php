<?php

namespace App\Support;

class PortalTextPolicy
{
    /** @return array{version: int, message: string, substitutions: array<string, string>, terms: list<string>} */
    public function definition(): array
    {
        return config('portal_text_policy');
    }

    public function allows(string $value): bool
    {
        $definition = $this->definition();
        $normalized = preg_replace('/[\p{Mn}\p{Cf}]/u', '', strtr(mb_strtolower($value, 'UTF-8'), $definition['substitutions']));
        foreach ($definition['terms'] as $term) {
            $letters = preg_split('//u', preg_replace('/[^\p{L}\p{N}]/u', '', $term), -1, PREG_SPLIT_NO_EMPTY);
            $pattern = '/(?<![\p{L}\p{N}])'.implode('[^\p{L}\p{N}]*', array_map(fn (string $letter): string => preg_quote($letter, '/'), $letters)).'(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $normalized) === 1) {
                return false;
            }
        }

        return true;
    }
}
