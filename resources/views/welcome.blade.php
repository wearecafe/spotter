<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Coaching, without the spreadsheets')])
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-900 dark:text-white">
        <header class="mx-auto flex max-w-5xl items-center justify-between px-6 py-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                <span class="flex size-8 items-center justify-center rounded-md bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                    <x-app-logo-icon class="size-5" />
                </span>
                {{ config('app.name') }}
            </a>
            <nav class="flex items-center gap-2">
                @auth
                    <flux:button :href="route('dashboard')" variant="primary" size="sm">{{ __('Open Spotter') }}</flux:button>
                @else
                    <flux:button :href="route('login')" variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
                    @if (Route::has('register'))
                        <flux:button :href="route('register')" variant="primary" size="sm">{{ __('Start coaching') }}</flux:button>
                    @endif
                @endauth
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-6 pb-24 pt-16">
            <div class="max-w-2xl">
                <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">{{ __('Program, track and coach — all in one place.') }}</h1>
                <p class="mt-6 text-lg text-zinc-600 dark:text-zinc-300">
                    {{ __('Spotter gives personal trainers a workout builder, a shared exercise library and a live view of how every client is doing. Clients get a simple place to see today\'s workout and log every set.') }}
                </p>
                <div class="mt-8 flex gap-3">
                    <flux:button :href="route('register')" variant="primary">{{ __('Create a coach account') }}</flux:button>
                    <flux:button :href="route('login')">{{ __('I\'m a client') }}</flux:button>
                </div>
            </div>

            <div class="mt-20 grid gap-8 sm:grid-cols-3">
                @foreach ([
                    ['icon' => 'clipboard-document-list', 'title' => __('Build once, assign anywhere'), 'text' => __('Turn your programming into reusable templates and drop them onto any client\'s calendar.')],
                    ['icon' => 'chart-bar', 'title' => __('See who needs you'), 'text' => __('Compliance, missed sessions and client feedback surface automatically on your overview.')],
                    ['icon' => 'device-phone-mobile', 'title' => __('Logging clients actually do'), 'text' => __('Clients see sets, reps and load targets and log results right from their phone.')],
                ] as $feature)
                    <div>
                        <flux:icon :name="$feature['icon']" class="size-6 text-zinc-500" />
                        <h2 class="mt-3 font-semibold">{{ $feature['title'] }}</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $feature['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </main>

        @fluxScripts
    </body>
</html>
