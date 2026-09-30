<?php

namespace App\Support;

use Illuminate\Http\Request;

class IntendedDestination
{
    public static function take(Request $request): string
    {
        $destination = $request->session()->pull('url.intended');
        if (! is_string($destination) || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $destination)) {
            return '/dashboard';
        }
        $parts = parse_url($destination);
        $trusted = parse_url(config('app.url'));
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return '/dashboard';
        }
        if (isset($parts['host']) && (! isset($parts['scheme']) || strtolower($parts['host']) !== strtolower($trusted['host'] ?? '') || $parts['scheme'] !== ($trusted['scheme'] ?? '') || ($parts['port'] ?? null) !== ($trusted['port'] ?? null))) {
            return '/dashboard';
        }
        $path = $parts['path'] ?? '/dashboard';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/dashboard';
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
