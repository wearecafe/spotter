<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Try the demo')])
        <meta name="description" content="{{ __('Explore Spotter as a gym owner, a coach or a client at North Shore Strength, a demo gym with real-looking programs, check-ins and messages. No sign-up.') }}">
        <meta name="robots" content="noindex">
    </head>
    <body class="min-h-screen bg-ink font-sans text-white antialiased">
        <div class="mx-auto flex min-h-svh max-w-3xl flex-col px-4 py-10 sm:px-6 sm:py-16">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 self-start">
                <span class="flex size-8 items-center justify-center rounded-lg bg-lime text-ink"><x-app-logo-icon class="size-5" /></span>
                <span class="font-display text-lg font-extrabold tracking-tight">Spotter</span>
            </a>

            <div class="mt-12">
                <p class="text-sm font-semibold uppercase tracking-wider text-lime">{{ __('Demo gym') }}</p>
                <h1 class="mt-2 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Explore :team', ['team' => $team->name]) }}</h1>
                <p class="mt-3 max-w-xl text-zinc-300">{{ __('A made-up gym with three coaches and eleven clients, all dated to this week. Pick someone to sign in as. Change anything you like: it all resets overnight, and the demo never sends email.') }}</p>
            </div>

            <ul class="mt-10 grid gap-4 sm:grid-cols-2">
                @foreach ($accounts as $account)
                    <li>
                        <form method="POST" action="{{ route('demo.login') }}" class="h-full">
                            @csrf
                            <input type="hidden" name="account" value="{{ $account['key'] }}">
                            <button type="submit" data-test="demo-{{ $account['key'] }}" class="group flex h-full w-full flex-col rounded-2xl border border-white/10 bg-white/5 p-5 text-start transition hover:border-lime/60 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lime">
                                <span class="flex items-center gap-3">
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-lime font-bold text-ink">{{ \Illuminate\Support\Str::initials($account['name']) }}</span>
                                    <span>
                                        <span class="block font-semibold">{{ $account['name'] }}</span>
                                        <span class="block text-sm text-lime">{{ __($account['title']) }}</span>
                                    </span>
                                </span>
                                <span class="mt-4 block text-sm text-zinc-300">{{ __($account['description']) }}</span>
                                <span class="mt-auto pt-4 text-sm font-semibold text-white group-hover:text-lime">{{ __('Sign in as :name', ['name' => \Illuminate\Support\Str::before($account['name'], ' ')]) }} →</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <div class="mt-12 flex flex-col gap-3 border-t border-white/10 pt-8 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-zinc-400">{{ __('Ready to coach your own clients?') }}</p>
                <a href="{{ route('register') }}" class="inline-flex h-11 items-center justify-center rounded-xl bg-lime px-5 font-bold text-ink hover:bg-lime/90">{{ __('Create your coach account') }}</a>
            </div>
        </div>
    </body>
</html>
