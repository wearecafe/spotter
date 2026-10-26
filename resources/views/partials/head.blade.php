<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.svg" type="image/svg+xml">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

{{-- Clients see their coach's brand colour. --}}
@if (($brand = auth()->user()?->brandOwner()?->brand_color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $brand) && strtoupper($brand) !== '#0A7A55')
    <style>:root { --color-brand: {{ $brand }}; --color-accent: {{ $brand }}; --color-accent-content: {{ $brand }}; }</style>
@endif
