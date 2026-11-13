<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Production URLs and accepted Host headers derive only from configuration, never from the request.
 */
class CanonicalUrl
{
    /** @return list<string> */
    public static function trustedHostPatterns(): array
    {
        return collect([config('app.url'), config('customer.frontend_url')])
            ->map(fn (mixed $url): ?string => is_string($url) ? parse_url($url, PHP_URL_HOST) : null)
            ->filter()
            ->map(fn (string $host): string => '^'.preg_quote(strtolower($host)).'$')
            ->unique()
            ->values()
            ->all();
    }

    public static function enforce(): void
    {
        $root = rtrim((string) config('app.url'), '/');
        if ($root === '') {
            return;
        }
        URL::forceRootUrl($root);
        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
