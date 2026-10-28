<x-help-layout :title="$query !== '' ? __('Search: :q', ['q' => $query]) : __('Search')" :nav="$nav" :support-email="$supportEmail" :query="$query">
    <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-sm text-zinc-500">
        <a href="{{ route('help.index') }}" class="hover:text-ink">{{ __('Help') }}</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink">{{ __('Search') }}</span>
    </nav>

    <h1 class="mt-6 font-display text-3xl font-extrabold tracking-tight">
        @if ($query === '')
            {{ __('Search the help center') }}
        @else
            {{ trans_choice('{0} No results for “:q”|{1} 1 result for “:q”|[2,*] :count results for “:q”', count($results), ['count' => count($results), 'q' => $query]) }}
        @endif
    </h1>

    @if ($query !== '' && $results === [])
        <p class="mt-3 text-zinc-600">{{ __('Try fewer words, or a word you’d see on the screen, like “program”, “check-in” or “invite”.') }}</p>
        <p class="mt-6"><a href="{{ route('help.index') }}" class="font-semibold text-brand hover:underline">{{ __('Browse all topics') }} →</a></p>
    @endif

    @if ($results !== [] && ! $results[0]['complete'])
        <p class="mt-3 text-zinc-600">{{ __('Nothing matched every word, so these match most of them.') }}</p>
    @endif

    <ol class="mt-8 flex flex-col gap-3">
        @foreach ($results as $result)
            <li>
                <a href="{{ $result['url'] }}" class="block rounded-2xl border border-zinc-200 p-5 hover:border-zinc-400">
                    <span class="text-xs font-semibold uppercase tracking-wide text-brand">
                        {{ __($result['categoryTitle']) }}@if ($result['section']) · {{ $result['section']['title'] }}@endif
                    </span>
                    <span class="mt-1 block text-lg font-semibold">
                        @foreach ($result['titleParts'] as $part)<span @class(['rounded-sm bg-lime/60' => $part['hit']])>{{ $part['text'] }}</span>@endforeach
                    </span>
                    <span class="mt-1 block text-sm text-zinc-600">
                        @foreach ($result['snippetParts'] as $part)<span @class(['font-semibold text-ink' => $part['hit']])>{{ $part['text'] }}</span>@endforeach
                    </span>
                </a>
            </li>
        @endforeach
    </ol>
</x-help-layout>
