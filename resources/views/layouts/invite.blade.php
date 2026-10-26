<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-100 font-sans text-ink antialiased">
        <div class="mx-auto flex min-h-svh w-full max-w-md flex-col bg-zinc-100 sm:my-8 sm:min-h-0 sm:overflow-hidden sm:rounded-3xl sm:shadow-xl">
            {{ $slot }}
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
