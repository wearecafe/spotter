<x-help-layout :title="$article->title" :description="$article->summary" :nav="$nav" :current="$current" :support-email="$supportEmail">
    <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-sm text-zinc-500">
        <a href="{{ route('help.index') }}" class="hover:text-ink">{{ __('Help') }}</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('help.category', $category['slug']) }}" class="hover:text-ink">{{ __($category['title']) }}</a>
    </nav>

    <div class="mt-6 grid gap-10 lg:grid-cols-[14rem_minmax(0,1fr)_13rem]">
        {{-- This topic's articles. --}}
        <aside class="hidden lg:block" aria-label="{{ __('In this topic') }}">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __($category['title']) }}</p>
            <ul class="mt-3 flex flex-col gap-0.5 text-sm">
                @foreach ($siblings as $sibling)
                    <li>
                        <a href="{{ $sibling->url() }}" @class([
                            'block rounded-lg px-3 py-1.5',
                            'bg-lime/40 font-semibold text-ink' => $sibling->path() === $article->path(),
                            'text-zinc-600 hover:bg-zinc-100 hover:text-ink' => $sibling->path() !== $article->path(),
                        ]) @if ($sibling->path() === $article->path()) aria-current="page" @endif>{{ $sibling->title }}</a>
                    </li>
                @endforeach
            </ul>
        </aside>

        <article class="min-w-0">
            <h1 class="font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ $article->title }}</h1>
            <p class="mt-2 text-lg text-zinc-600">{{ $article->summary }}</p>

            @if (count($article->toc) > 2)
                <details class="mt-6 rounded-xl border border-zinc-200 px-4 py-3 lg:hidden">
                    <summary class="cursor-pointer text-sm font-semibold">{{ __('On this page') }}</summary>
                    <ul class="mt-2 flex flex-col gap-1 text-sm">
                        @foreach ($article->toc as $heading)
                            <li><a href="#{{ $heading['id'] }}" class="text-zinc-600 hover:text-brand">{{ $heading['title'] }}</a></li>
                        @endforeach
                    </ul>
                </details>
            @endif

            <div class="help-prose mt-8">{!! $article->html !!}</div>

            <nav aria-label="{{ __('More in this topic') }}" class="mt-12 grid gap-3 border-t border-zinc-200 pt-6 sm:grid-cols-2">
                @if ($previous)
                    <a href="{{ $previous->url() }}" class="rounded-xl border border-zinc-200 p-4 hover:border-zinc-400">
                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">← {{ __('Previous') }}</span>
                        <span class="mt-1 block font-semibold">{{ $previous->title }}</span>
                    </a>
                @else
                    <span></span>
                @endif
                @if ($next)
                    <a href="{{ $next->url() }}" class="rounded-xl border border-zinc-200 p-4 text-end hover:border-zinc-400">
                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Next') }} →</span>
                        <span class="mt-1 block font-semibold">{{ $next->title }}</span>
                    </a>
                @endif
            </nav>

            @if ($related)
                <section class="mt-10" aria-labelledby="related">
                    <h2 id="related" class="font-display text-lg font-bold">{{ __('Related articles') }}</h2>
                    <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($related as $item)
                            <li>
                                <a href="{{ $item->url() }}" class="block h-full rounded-xl bg-zinc-50 p-4 hover:bg-zinc-100">
                                    <span class="block font-semibold">{{ $item->title }}</span>
                                    <span class="mt-0.5 block text-sm text-zinc-600">{{ $item->summary }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </article>

        @if (count($article->toc) > 1)
            <aside class="hidden lg:block" aria-label="{{ __('On this page') }}">
                <div class="sticky top-6">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __('On this page') }}</p>
                    <ul class="mt-3 flex flex-col gap-1.5 border-s border-zinc-200 text-sm">
                        @foreach ($article->toc as $heading)
                            <li><a href="#{{ $heading['id'] }}" class="-ms-px block border-s border-transparent ps-3 text-zinc-600 hover:border-brand hover:text-ink">{{ $heading['title'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </aside>
        @endif
    </div>
</x-help-layout>
