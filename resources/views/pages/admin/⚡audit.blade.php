<?php

use App\Models\StaffAuditLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::admin')] #[Title('Audit log')] class extends Component {
    use WithPagination;

    /**
     * Staff only, checked on every request (initial load and every action), not just the route.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->is_staff, 404);
    }

    #[Computed]
    public function entries()
    {
        return StaffAuditLog::query()->with(['staff:id,email', 'team:id,name'])->latest('created_at')->latest('id')->paginate(50);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Audit log') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every change Spotter staff make to a tenant.') }}</flux:text>
    </div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
        <table class="w-full min-w-[48rem] text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500">
                <tr><th class="px-4 py-3 text-start font-semibold">{{ __('When') }}</th><th class="px-3 py-3 text-start font-semibold">{{ __('Staff') }}</th><th class="px-3 py-3 text-start font-semibold">{{ __('Tenant') }}</th><th class="px-3 py-3 text-start font-semibold">{{ __('Action') }}</th><th class="px-4 py-3 text-start font-semibold">{{ __('Details') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($this->entries as $entry)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2 text-zinc-600">{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2">{{ $entry->staff?->email ?? __('system') }}</td>
                        <td class="px-3 py-2">@if ($entry->team)<a href="{{ route('admin.tenants.show', $entry->team) }}" wire:navigate class="hover:underline">{{ $entry->team->name }}</a>@else — @endif</td>
                        <td class="px-3 py-2 font-medium">{{ str_replace('_', ' ', $entry->action) }}</td>
                        <td class="px-4 py-2 text-zinc-600">{{ collect($entry->details ?? [])->map(fn ($v, $k) => $k.': '.$v)->implode(', ') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">{{ __('Nothing yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->entries->links() }}
</div>
