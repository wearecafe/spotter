@props([
    'title',
    'description' => null,
    'nav' => [],
    'current' => null,
    'supportEmail' => null,
    'query' => '',
])

<!DOCTYPE html>
<html lang="en">
    <head>
        @include('partials.head', ['title' => $title.' · '.__('Help')])
        @if ($description)
            <meta name="description" content="{{ $description }}">
        @endif
    </head>
    <body class="min-h-screen bg-white font-sans text-ink antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">{{ __('Skip to content') }}</a>

        <header class="bg-ink text-white">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <a href="{{ route('help.index') }}" class="flex items-center gap-2.5">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-lime text-ink"><x-app-logo-icon class="size-5" /></span>
                    <span class="font-display text-lg font-extrabold tracking-tight">Spotter <span class="font-semibold text-zinc-400">{{ __('Help') }}</span></span>
                </a>
                <div class="flex items-center gap-2">
                    <a href="{{ route('home') }}" class="hidden h-10 items-center rounded-lg px-3 text-sm font-semibold text-zinc-300 hover:bg-white/10 hover:text-white sm:inline-flex">{{ __('Spotter home') }}</a>
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex h-10 items-center rounded-lg bg-lime px-4 text-sm font-bold text-ink hover:bg-lime/90">{{ __('Open Spotter') }}</a>
                    @else
                        <a href="{{ route('login') }}" class="inline-flex h-10 items-center rounded-lg bg-lime px-4 text-sm font-bold text-ink hover:bg-lime/90">{{ __('Log in') }}</a>
                    @endauth
                </div>
            </div>
            @isset($hero)
                <div class="mx-auto max-w-3xl px-4 pb-14 pt-8 text-center sm:px-6">
                    {{ $hero }}
                </div>
            @else
                <div class="mx-auto max-w-6xl px-4 pb-5 sm:px-6">
                    <x-help-search :query="$query" class="max-w-xl" />
                </div>
            @endisset
        </header>

        <main id="main" class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
            {{ $slot }}

            @if ($supportEmail)
                <div class="mt-16 flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-6 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-display text-lg font-bold">{{ __('Still stuck?') }}</h2>
                        <p class="mt-1 text-sm text-zinc-600">{{ __('Tell us what you were trying to do and we’ll help you get there.') }}</p>
                    </div>
                    <a href="mailto:{{ $supportEmail }}" class="inline-flex h-11 shrink-0 items-center justify-center rounded-xl bg-ink px-5 font-semibold text-white hover:bg-ink/90">{{ __('Email support') }}</a>
                </div>
            @endif
        </main>

        <footer class="border-t border-zinc-200">
            <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8 text-sm text-zinc-500 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <span>© {{ now()->year }} Spotter</span>
                <nav aria-label="{{ __('Help topics') }}" class="flex flex-wrap gap-x-5 gap-y-2">
                    @foreach ($nav as $slug => $category)
                        <a href="{{ route('help.category', $slug) }}" @class(['hover:text-ink', 'font-semibold text-ink' => $current === $slug])>{{ __($category['title']) }}</a>
                    @endforeach
                </nav>
            </div>
        </footer>

        {{-- Alpine (for search suggestions) comes with Livewire's script. --}}
        @livewireScripts
        @fluxScripts
    </body>
</html>
