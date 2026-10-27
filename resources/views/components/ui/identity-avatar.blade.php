@props(['name'])
<span {{ $attributes->class(['identity-avatar']) }} aria-hidden="true">{{ collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))->implode('') }}</span>
