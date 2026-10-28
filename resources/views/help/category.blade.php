<x-help-layout :title="__($category['title'])" :description="__($category['summary'])" :nav="$nav" :current="$current" :support-email="$supportEmail">
    <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-1.5 text-sm text-zinc-500">
        <a href="{{ route('help.index') }}" class="hover:text-ink">{{ __('Help') }}</a>
        <span aria-hidden="true">/</span>
        <span class="text-ink">{{ __($category['title']) }}</span>
    </nav>

    <div class="mt-6 flex items-start gap-4">
        <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-lime/40 text-brand-dark">
            <flux:icon :icon="$category['icon']" class="size-6" />
        </span>
        <div>
            <h1 class="font-display text-3xl font-extrabold tracking-tight">{{ __($category['title']) }}</h1>
            <p class="mt-1 text-zinc-600">{{ __($category['summary']) }}</p>
        </div>
    </div>

    <ol class="mt-8 divide-y divide-zinc-200 rounded-2xl border border-zinc-200">
        @foreach ($articles as $article)
            <li>
                <a href="{{ $article->url() }}" class="group flex items-center justify-between gap-4 px-5 py-4 hover:bg-zinc-50">
                    <span>
                        <span class="block font-semibold group-hover:text-brand">{{ $article->title }}</span>
                        <span class="mt-0.5 block text-sm text-zinc-600">{{ $article->summary }}</span>
                    </span>
                    <span class="text-zinc-400 group-hover:text-brand" aria-hidden="true">→</span>
                </a>
            </li>
        @endforeach
    </ol>
</x-help-layout>
