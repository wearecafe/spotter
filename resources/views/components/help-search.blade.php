@props(['query' => '', 'large' => false])

{{-- Search with suggestions as you type. Works without JavaScript as a plain form. --}}
<form method="GET" action="{{ route('help.search') }}" role="search"
    {{ $attributes->class(['relative w-full']) }}
    x-data="{
        q: @js($query),
        results: [],
        open: false,
        active: -1,
        timer: null,
        lookup() {
            clearTimeout(this.timer);
            const q = this.q.trim();
            if (q.length < 2) { this.results = []; this.open = false; return; }
            this.timer = setTimeout(async () => {
                try {
                    const res = await fetch('{{ route('help.suggest') }}?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                    if (! res.ok) return;
                    const data = await res.json();
                    if (data.query !== this.q.trim()) return;
                    this.results = data.results; this.active = -1; this.open = true;
                } catch (e) {}
            }, 150);
        },
        move(step) {
            if (! this.open || ! this.results.length) return;
            this.active = (this.active + step + this.results.length) % this.results.length;
        },
        go(event) {
            if (this.open && this.active >= 0) { event.preventDefault(); window.location = this.results[this.active].url; }
        },
     }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="open = false">
    <label for="help-search-{{ $large ? 'hero' : 'bar' }}" class="sr-only">{{ __('Search help') }}</label>
    <div class="relative">
        <svg class="pointer-events-none absolute start-3.5 top-1/2 size-5 -translate-y-1/2 text-zinc-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.45 4.39l3.08 3.08a.75.75 0 1 1-1.06 1.06l-3.08-3.08A7 7 0 0 1 2 9Z" clip-rule="evenodd" /></svg>
        <input id="help-search-{{ $large ? 'hero' : 'bar' }}" name="q" type="search" autocomplete="off"
            value="{{ $query }}"
            x-model="q" x-on:input="lookup()" x-on:focus="results.length && (open = true)"
            x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)" x-on:keydown.enter="go($event)"
            role="combobox" aria-autocomplete="list" :aria-expanded="open" aria-controls="help-suggestions-{{ $large ? 'hero' : 'bar' }}"
            placeholder="{{ __('Search help, e.g. progression rules, group session, MacroFactor') }}"
            @class([
                'w-full rounded-xl border-0 bg-white ps-11 pe-4 text-ink shadow-sm ring-1 ring-zinc-300 placeholder:text-zinc-400 focus:ring-2 focus:ring-lime focus:outline-none',
                'h-14 text-base' => $large,
                'h-11 text-sm' => ! $large,
            ]) />
    </div>

    <div x-cloak x-show="open" x-transition.opacity id="help-suggestions-{{ $large ? 'hero' : 'bar' }}" role="listbox"
        class="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-xl bg-white text-start text-ink shadow-xl ring-1 ring-zinc-200">
        <template x-for="(result, i) in results" :key="result.category + result.slug">
            <a :href="result.url" role="option" :aria-selected="active === i"
                :class="active === i ? 'bg-zinc-100' : ''"
                class="block border-b border-zinc-100 px-4 py-3 last:border-0 hover:bg-zinc-50" x-on:mouseenter="active = i">
                <span class="block text-xs font-semibold uppercase tracking-wide text-brand" x-text="result.categoryTitle"></span>
                <span class="block font-semibold">
                    <template x-for="part in result.titleParts"><span :class="part.hit ? 'bg-lime/60 rounded-sm' : ''" x-text="part.text"></span></template>
                </span>
                <span class="mt-0.5 block line-clamp-2 text-sm text-zinc-600">
                    <template x-for="part in result.snippetParts"><span :class="part.hit ? 'font-semibold text-ink' : ''" x-text="part.text"></span></template>
                </span>
            </a>
        </template>
        <p x-show="! results.length" class="px-4 py-3 text-sm text-zinc-600">{{ __('No matches yet. Press Enter to search everything.') }}</p>
        <button type="submit" x-show="results.length" class="block w-full bg-zinc-50 px-4 py-2.5 text-start text-sm font-semibold text-brand hover:bg-zinc-100">{{ __('See all results') }} →</button>
    </div>
</form>
