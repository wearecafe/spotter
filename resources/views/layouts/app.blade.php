<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        @if (\App\Support\Demo::isDemoUser(auth()->user()))
            <div class="-mt-2 mb-6 flex flex-col gap-2 rounded-xl border border-lime/40 bg-lime/15 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:bg-lime/10" data-test="demo-banner">
                <p class="text-zinc-800 dark:text-zinc-200">
                    <span class="font-semibold">{{ __('You’re exploring a demo gym as :name.', ['name' => auth()->user()->name]) }}</span>
                    {{ __('Change anything: it resets overnight and never sends email.') }}
                </p>
                <div class="flex shrink-0 gap-3">
                    <a href="{{ route('demo') }}" class="font-semibold text-brand underline-offset-4 hover:underline dark:text-lime">{{ __('Switch person') }}</a>
                    <a href="{{ route('demo.leave') }}" class="font-semibold text-brand underline-offset-4 hover:underline dark:text-lime">{{ __('Start your own') }}</a>
                </div>
            </div>
        @endif

        @if (auth()->user()->isReadOnlyClient())
            <div class="-mt-2 mb-6 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-zinc-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-zinc-200" data-test="archived-banner">
                <span class="font-semibold">{{ __('Your coaching is archived.') }}</span>
                {{ __('You can look back at your workouts, check-ins and messages, but nothing new can be logged. Ask :coach to reactivate you.', ['coach' => auth()->user()->coach?->name ?? __('your coach')]) }}
            </div>
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
