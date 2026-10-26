<x-mail::message>
# {{ __('Hi :name,', ['name' => $firstName]) }}

{{ trans_choice(':sender sent you a message on Spotter.|:sender sent you :count messages on Spotter.', $count, ['sender' => $sender, 'count' => $count]) }}

<x-mail::panel>
{{ $preview }}
</x-mail::panel>

<x-mail::button :url="$url" :color="'success'">
{{ __('Reply') }}
</x-mail::button>

{{ __('You’re getting this because the message is still unread after a few minutes.') }}
</x-mail::message>
