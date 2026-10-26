<x-mail::message>
# {{ __('Hi :name,', ['name' => $firstName]) }}

@if ($isSwitch)
{{ __('I’m moving our coaching to a new app, Spotter. Your program is already set up there and nothing changes about how we work together.') }}
@else
{{ __('I’ve set you up on Spotter, the app I use for coaching. Your workouts, check-ins and messages all live there.') }}
@endif

<x-mail::button :url="$url" :color="'success'">
{{ __('Get started') }}
</x-mail::button>

{{ __('It opens in your browser, and you can add it to your home screen.') }}

{{ $coach->name }}<br>
{{ $coach->brandName() }}
</x-mail::message>
