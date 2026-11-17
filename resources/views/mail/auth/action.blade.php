{{-- Notification email with a single action button and no copy-paste fallback link. --}}
<x-mail::message>
@if (! empty($greeting))
# {{ $greeting }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif
</x-mail::message>
