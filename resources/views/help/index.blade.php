<x-help-layout :title="__('Help center')" :description="__('Guides for coaches, gyms and the clients they train on Spotter: programs, progression, running sessions, the Coach Inbox, nutrition, teams and switching from TrueCoach or Trainerize.')" :nav="$nav" :support-email="$supportEmail">
    <x-slot:hero>
        <h1 class="font-display text-3xl font-extrabold tracking-tight sm:text-5xl">{{ __('How can we help?') }}</h1>
        <p class="mx-auto mt-3 max-w-xl text-zinc-300">{{ __('Guides for coaches, gyms and the people you train.') }}</p>
        <x-help-search :large="true" class="mx-auto mt-8 max-w-2xl" />
    </x-slot:hero>

    @if ($popular)
        <section aria-labelledby="popular">
            <h2 id="popular" class="font-display text-xl font-bold">{{ __('Popular questions') }}</h2>
            <ul class="mt-4 grid gap-x-8 gap-y-1 sm:grid-cols-2">
                @foreach ($popular as $article)
                    <li>
                        <a href="{{ $article->url() }}" class="group flex items-start gap-2 rounded-lg py-2 text-[15px] font-medium hover:text-brand">
                            <span class="mt-0.5 text-lime-600 group-hover:text-brand" aria-hidden="true">→</span>
                            {{ $article->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section aria-labelledby="topics" class="mt-12">
        <h2 id="topics" class="font-display text-xl font-bold">{{ __('Browse by topic') }}</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($categories as $category)
                <div class="flex flex-col rounded-2xl border border-zinc-200 p-5 transition hover:border-zinc-400">
                    <a href="{{ route('help.category', $category['slug']) }}" class="group">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-lime/40 text-brand-dark">
                            <flux:icon :icon="$category['icon']" class="size-5" />
                        </span>
                        <h3 class="mt-4 font-display text-lg font-bold group-hover:text-brand">{{ __($category['title']) }}</h3>
                        <p class="mt-1 text-sm text-zinc-600">{{ __($category['summary']) }}</p>
                    </a>
                    <ul class="mt-4 flex flex-col gap-1.5 border-t border-zinc-100 pt-4 text-sm">
                        @foreach ($category['top'] as $article)
                            <li><a href="{{ $article->url() }}" class="text-zinc-700 hover:text-brand hover:underline">{{ $article->title }}</a></li>
                        @endforeach
                    </ul>
                    <a href="{{ route('help.category', $category['slug']) }}" class="mt-auto pt-4 text-sm font-semibold text-brand hover:underline">
                        {{ trans_choice('{1} See the article|[2,*] See all :count articles', $category['count'], ['count' => $category['count']]) }} →
                    </a>
                </div>
            @endforeach
        </div>
    </section>
</x-help-layout>
