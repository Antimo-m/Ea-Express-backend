<?php

namespace App\Support;

class PortalTextPolicy
{
    public static function normalize(string $value): string
    {
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;

        return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function normalizeFields(array $data): array
    {
        foreach (['name', 'store_name', 'business_type', 'business_description'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = self::normalize($data[$field]);
            }
        }

        return $data;
    }

    /** @return array{version: int, message: string, substitutions: array<string, string>, terms: list<string>} */
    public function definition(): array
    {
        return config('portal_text_policy');
    }

    public function allows(string $value): bool
    {
        $value = self::normalize($value);
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
