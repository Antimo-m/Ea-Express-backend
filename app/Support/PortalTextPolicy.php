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
        $definition = $this->definition();
        $text = self::comparable($value, $definition['substitutions']);
        foreach ($definition['terms'] as $term) {
            $stem = str_ends_with($term, '*');
            $letters = preg_split('//u', self::comparable(rtrim($term, '*'), $definition['substitutions'], letters: true), -1, PREG_SPLIT_NO_EMPTY);
            $pattern = '/(?<![\p{L}\p{N}])'.implode('[^\p{L}\p{N}]*', array_map(fn (string $letter): string => preg_quote($letter, '/'), $letters)).($stem ? '' : '(?![\p{L}\p{N}])').'/u';
            if (preg_match($pattern, $text) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical form used only for matching: compatibility characters, case, accents, look-alike
     * symbols, invisible characters and repeated letters are folded. The stored value is never changed.
     *
     * @param  array<string, string>  $substitutions
     */
    public static function comparable(string $value, array $substitutions, bool $letters = false): string
    {
        $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        $value = strtr(mb_strtolower(self::normalize($value), 'UTF-8'), $substitutions);
        $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
        $value = preg_replace('/[\p{Mn}\p{Cf}]/u', '', $value);
        if ($letters) {
            $value = preg_replace('/[^\p{L}\p{N}]/u', '', $value);
        }

        return preg_replace('/(\p{L})\1+/u', '$1', $value);
    }
}
