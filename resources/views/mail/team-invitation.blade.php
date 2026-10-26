<x-mail::message>
# {{ __('You’re invited to coach with :team', ['team' => $team->name]) }}

{{ __(':name has invited you to join their team on Spotter, where you’ll program and coach your clients alongside them.', ['name' => $inviter?->name ?? $team->name]) }}

<x-mail::button :url="$url" :color="'success'">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This invitation expires :date.', ['date' => $expires->translatedFormat('j F Y')]) }}
</x-mail::message>
