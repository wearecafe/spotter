<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body class="min-h-screen bg-zinc-50 font-sans text-ink antialiased">
        <header class="border-b border-white/10 bg-ink text-white">
            <div class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
                <div class="flex items-center gap-6">
                    <a href="{{ route('admin.tenants') }}" wire:navigate class="flex items-center gap-2">
                        <span class="flex size-7 items-center justify-center rounded-md bg-lime text-ink"><x-app-logo-icon class="size-4" /></span>
                        <span class="font-display font-extrabold">Spotter</span>
                        <span class="rounded bg-amber-400 px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-ink">{{ __('Staff') }}</span>
                    </a>
                    <nav class="flex gap-4 text-sm font-medium text-zinc-300">
                        <a href="{{ route('admin.tenants') }}" wire:navigate @class(['hover:text-white', 'text-white' => request()->routeIs('admin.tenants*')])>{{ __('Tenants') }}</a>
                        <a href="{{ route('admin.audit') }}" wire:navigate @class(['hover:text-white', 'text-white' => request()->routeIs('admin.audit')])>{{ __('Audit log') }}</a>
                    </nav>
                </div>
                <div class="flex items-center gap-4 text-sm text-zinc-300">
                    <span class="hidden sm:inline">{{ auth()->user()->email }}</span>
                    <a href="{{ route('dashboard') }}" class="hover:text-white">{{ __('Back to app') }}</a>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
            {{ $slot }}
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
