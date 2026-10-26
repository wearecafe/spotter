<?php

use App\Mail\ClientInvitation;
use App\Models\Import;
use App\Models\User;
use App\Services\ProgramImporter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Set up Spotter')] class extends Component {
    use WithFileUploads;

    public const SWATCHES = ['#0A7A55' => 'Green', '#1D5FD1' => 'Blue', '#B4410C' => 'Rust', '#6B3FD4' => 'Violet', '#111418' => 'Black'];

    #[Url]
    public int $step = 1;

    // Step 1
    public string $businessName = '';

    public string $currency = 'CAD';

    public string $languages = 'en';

    public string $clientCount = '';

    // Step 2
    public string $source = 'trainerize';

    public int $monthsLeft = 0;

    public $clientsFile = null;

    /** @var array<int, mixed> */
    public array $programFiles = [];

    // Step 4
    public string $brandColor = '#0A7A55';

    public string $subdomain = '';

    public $logo = null;

    // Step 5
    /** @var array<int, bool> */
    public array $invite = [];

    public function mount(): void
    {
        $coach = $this->coach;
        $this->step = min(max($this->step, 1), 5);
        $this->businessName = (string) $coach->business_name;
        $this->currency = $coach->currency;
        $this->languages = $coach->coaching_languages;
        $this->clientCount = (string) $coach->client_count_band;
        $this->source = $coach->import_source ?? 'trainerize';
        $this->monthsLeft = $coach->contract_months_left;
        $this->brandColor = $coach->brand_color;
        $this->subdomain = $coach->subdomain ?? Str::slug($coach->business_name ?: $coach->name);

        foreach ($this->pendingClients as $client) {
            $this->invite[$client->id] = $client->invited_at === null;
        }
    }

    #[Computed]
    public function coach(): User
    {
        return Auth::user();
    }

    #[Computed]
    public function pendingClients()
    {
        return $this->coach->clients()->whereNull('invitation_accepted_at')->orderBy('name')->get();
    }

    #[Computed]
    public function latestImport(): ?Import
    {
        return $this->coach->imports()->latest()->first();
    }

    /**
     * What each platform's export actually contains, so coaches know what to expect.
     *
     * @return array<string, array<int, array{0: bool, 1: string}>>
     */
    public function transfers(): array
    {
        return [
            'truecoach' => [
                [true, __('Client names and emails from TrueCoach’s client CSV export')],
                [true, __('Programs, read from workouts you email to yourself or print to PDF')],
                [false, __('Logged workout history only comes across if it’s in the files you export')],
            ],
            'trainerize' => [
                [true, __('Client names and contact details from the client CSV')],
                [true, __('Programs, read from the PDFs you save and matched to our exercise library')],
                [false, __('Trainerize’s export leaves out workout history, so past logs don’t come across')],
            ],
            'sheets' => [
                [true, __('Programs written as one exercise per line, like "Back Squat 4x5 75kg"')],
                [true, __('Client list from any sheet with names and emails (save as CSV)')],
                [true, __('Past logs aren’t imported yet; keep the sheet for reference')],
            ],
            'fresh' => [
                [true, __('Add clients one at a time from the Clients page')],
                [true, __('Build programs with progression rules from scratch')],
            ],
        ];
    }

    public function go(int $step): void
    {
        $this->step = min(max($step, 1), 5);
    }

    public function saveBusiness(): void
    {
        $this->validate([
            'businessName' => ['required', 'string', 'max:120'],
            'currency' => ['required', 'in:CAD,USD'],
            'languages' => ['required', 'in:en,fr,both'],
            'clientCount' => ['nullable', 'in:1-15,16-50,51-150,150+'],
        ], attributes: ['businessName' => __('business name')]);

        $this->coach->update([
            'business_name' => $this->businessName,
            'currency' => $this->currency,
            'coaching_languages' => $this->languages,
            'client_count_band' => $this->clientCount ?: null,
            'locale' => $this->languages === 'fr' ? 'fr' : $this->coach->locale,
        ]);

        if ($this->subdomain === '' || $this->subdomain === Str::slug($this->coach->name)) {
            $this->subdomain = Str::slug($this->businessName);
        }

        $this->step = 2;
    }

    public function saveSource(): void
    {
        $this->validate([
            'source' => ['required', Rule::in(array_keys(Import::SOURCES))],
            'monthsLeft' => ['integer', 'between:0,12'],
        ]);

        $this->coach->update(['import_source' => $this->source, 'contract_months_left' => $this->monthsLeft]);
    }

    public function import(ProgramImporter $importer): void
    {
        $this->saveSource();

        $this->validate([
            'clientsFile' => ['nullable', 'file', 'mimes:csv,txt', 'max:2048'],
            'programFiles' => ['array', 'max:200'],
            'programFiles.*' => ['file', 'mimes:pdf,txt,csv', 'max:10240'],
        ], attributes: ['clientsFile' => __('client list'), 'programFiles.*' => __('program file')]);

        if (! $this->clientsFile && $this->programFiles === []) {
            $this->addError('clientsFile', __('Add your client list, program files, or both.'));

            return;
        }

        $clients = $this->clientsFile ? $importer->parseClientsCsv($this->clientsFile->get()) : collect();

        if ($this->clientsFile && $clients->isEmpty()) {
            $this->addError('clientsFile', __('We couldn’t find names and emails in that file. It needs an Email column and a Name (or First Name and Last Name) column.'));

            return;
        }

        $created = $importer->createClients($this->coach, $clients);

        $import = $this->coach->imports()->create([
            'source' => $this->source,
            'stats' => ['clients_in_file' => $clients->count(), 'clients_created' => $created],
        ]);

        if ($this->programFiles !== []) {
            \App\Jobs\ProcessImportFiles::start($import, $this->programFiles);
        }

        $this->reset('clientsFile', 'programFiles');
        $this->redirectRoute('coach.imports.show', $import, navigate: true);
    }

    public function continueFromSource(): void
    {
        $this->saveSource();
        $this->step = 3;
    }

    public function saveBrand(): void
    {
        $this->subdomain = Str::slug($this->subdomain);

        $this->validate([
            'brandColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'subdomain' => ['required', 'alpha_dash', 'min:3', 'max:40', Rule::notIn(['www', 'app', 'admin', 'api', 'mail']), Rule::unique('users', 'subdomain')->ignore($this->coach->id)],
            'logo' => ['nullable', 'image', 'max:2048'],
        ], attributes: ['brandColor' => __('brand colour'), 'subdomain' => __('web address')]);

        $data = ['brand_color' => strtoupper($this->brandColor), 'subdomain' => $this->subdomain];

        if ($this->logo) {
            $data['logo_path'] = $this->logo->store('logos', config('filesystems.media_disk'));
        }

        $this->coach->update($data);
        $this->logo = null;
        $this->step = 5;
    }

    public function sendInvites(): void
    {
        $ids = collect($this->invite)->filter()->keys();
        $clients = $this->pendingClients->whereIn('id', $ids);

        foreach ($clients as $client) {
            Mail::to($client)->send(new ClientInvitation($client));
            $client->update(['invited_at' => now()]);
        }

        $this->finish();

        Flux::toast(variant: 'success', text: trans_choice('Invite sent.|:count invites sent.', $clients->count()));
    }

    public function finish(): void
    {
        $this->coach->update(['onboarded_at' => $this->coach->onboarded_at ?? now()]);
        $this->redirectRoute('coach.dashboard', navigate: true);
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-8 lg:flex-row lg:items-start">
    <nav aria-label="{{ __('Setup steps') }}" class="lg:w-64 lg:shrink-0">
        <ol class="flex gap-1 overflow-x-auto lg:flex-col">
            @foreach ([
                1 => [__('Your business'), __('Name, language, size')],
                2 => [__('Bring your clients'), __('Import from your old app')],
                3 => [__('Payments'), __('0% platform fee')],
                4 => [__('Your brand'), __('Colour, logo, web address')],
                5 => [__('Invite clients'), __('In English or French')],
            ] as $n => [$label, $sub])
                <li class="shrink-0">
                    <button type="button" wire:click="go({{ $n }})" aria-current="{{ $step === $n ? 'step' : 'false' }}"
                        @class(['flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start', 'bg-white shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-700' => $step === $n])>
                        <span @class([
                            'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                            'bg-brand text-white' => $n < $step,
                            'bg-ink text-white dark:bg-lime dark:text-ink' => $n === $step,
                            'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200' => $n > $step,
                        ])>{{ $n < $step ? '✓' : $n }}</span>
                        <span class="hidden flex-col sm:flex">
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</span>
                            <span class="text-xs text-zinc-500">{{ $sub }}</span>
                        </span>
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>

    <flux:card class="flex min-w-0 flex-1 flex-col gap-6 p-6 sm:p-8">
        @if ($step === 1)
            <div>
                <flux:heading size="xl" level="1">{{ __('Tell us about your coaching') }}</flux:heading>
                <flux:text class="mt-1">{{ __('This sets your defaults. You can change any of it later.') }}</flux:text>
            </div>
            <form wire:submit="saveBusiness" class="flex flex-col gap-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="businessName" :label="__('Business name')" :placeholder="__('North Shore Strength')" required />
                    <flux:select wire:model="currency" :label="__('Billing currency')">
                        <flux:select.option value="CAD">{{ __('CAD, Canadian dollar') }}</flux:select.option>
                        <flux:select.option value="USD">{{ __('USD, US dollar') }}</flux:select.option>
                    </flux:select>
                </div>
                <flux:radio.group wire:model="languages" :label="__('Languages you coach in')" variant="segmented">
                    <flux:radio value="en" label="English" />
                    <flux:radio value="fr" label="Français" />
                    <flux:radio value="both" :label="__('Both')" />
                </flux:radio.group>
                <flux:radio.group wire:model="clientCount" :label="__('How many active clients do you coach?')" variant="segmented">
                    <flux:radio value="1-15" :label="__('1 to 15')" />
                    <flux:radio value="16-50" :label="__('16 to 50')" />
                    <flux:radio value="51-150" :label="__('51 to 150')" />
                    <flux:radio value="150+" :label="__('150+')" />
                </flux:radio.group>
                <div class="flex justify-end border-t border-zinc-100 pt-5 dark:border-zinc-700">
                    <flux:button type="submit" variant="primary" data-test="setup-continue">{{ __('Continue') }}</flux:button>
                </div>
            </form>
        @elseif ($step === 2)
            <div>
                <flux:heading size="xl" level="1">{{ __('Bring your clients with you') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Pick where you coach today. Here’s exactly what can come across.') }}</flux:text>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="radiogroup" aria-label="{{ __('Where you coach today') }}">
                @foreach (\App\Models\Import::SOURCES as $key => $label)
                    <button type="button" role="radio" aria-checked="{{ $source === $key ? 'true' : 'false' }}" wire:click="$set('source', '{{ $key }}')"
                        @class(['rounded-xl border-2 p-4 text-start transition', 'border-brand ring-4 ring-brand/10 dark:border-lime' => $source === $key, 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700' => $source !== $key])>
                        <span class="font-display font-bold text-zinc-900 dark:text-white">{{ __($label) }}</span>
                    </button>
                @endforeach
            </div>

            <div class="rounded-xl bg-zinc-50 p-5 dark:bg-zinc-900">
                <flux:heading>{{ __('What comes across') }}</flux:heading>
                <ul class="mt-3 flex flex-col gap-2">
                    @foreach ($this->transfers()[$source] as [$ok, $text])
                        <li class="flex items-start gap-3 text-[15px]">
                            <span @class(['flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-extrabold', 'bg-brand/15 text-brand dark:text-lime' => $ok, 'bg-amber-100 text-amber-800' => ! $ok]) aria-hidden="true">{{ $ok ? '✓' : '!' }}</span>
                            <span class="text-zinc-800 dark:text-zinc-200">{{ $text }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($source !== 'fresh')
                <form wire:submit="import" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:heading>{{ __('Upload your exports') }}</flux:heading>
                    <flux:input type="file" wire:model="clientsFile" accept=".csv,text/csv" :label="__('Client list (CSV)')" :description="__('Needs an Email column and a Name (or First Name and Last Name) column.')" />
                    <flux:input type="file" wire:model="programFiles" multiple accept=".pdf,.txt,.csv" :label="__('Programs (one file per client)')" :description="config('services.anthropic.key') ? __('Name each file after the client, e.g. Maya Chen.pdf. PDFs and text both work.') : __('Name each file after the client, e.g. Maya Chen.txt. Use text files for now; PDF reading turns on when AI is configured.')" />
                    <div wire:loading wire:target="clientsFile,programFiles,import"><flux:text size="sm">{{ __('Working…') }}</flux:text></div>
                    <flux:button type="submit" variant="primary" class="self-start" wire:loading.attr="disabled" data-test="start-import">{{ __('Import and review') }}</flux:button>
                </form>
                @if ($this->latestImport)
                    <a href="{{ route('coach.imports.show', $this->latestImport) }}" wire:navigate class="text-sm font-semibold text-brand hover:underline dark:text-lime">{{ __('Open your last import (:status)', ['status' => $this->latestImport->status === 'finished' ? __('finished') : __('needs review')]) }}</a>
                @endif
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                @if (config('services.support.email'))
                    <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                        <flux:heading>{{ __('Want us to do it?') }}</flux:heading>
                        <flux:text size="sm">{{ __('Free done-for-you migration. We rebuild your programs and check every client before you invite them.') }}</flux:text>
                        <a href="mailto:{{ config('services.support.email') }}?subject={{ rawurlencode(__('Migration help for :name', ['name' => $this->coach->brandName()])) }}" class="text-sm font-semibold text-brand hover:underline dark:text-lime">{{ __('Email the migration team') }}</a>
                    </div>
                @endif
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:heading>{{ __('Still paying for your old plan?') }}</flux:heading>
                    <flux:select wire:model.live="monthsLeft" :label="__('Months left on your annual plan')">
                        @foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $m)
                            <flux:select.option :value="$m">{{ $m }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <p class="text-sm font-semibold text-brand dark:text-lime">
                        {{ $monthsLeft > 0 ? trans_choice('Your first month of Spotter is free, so you never pay twice.|Your first :count months of Spotter are free, so you never pay twice.', $monthsLeft) : __('No overlap, so you start on the normal free trial.') }}
                    </p>
                </div>
            </div>

            <div class="flex justify-between border-t border-zinc-100 pt-5 dark:border-zinc-700">
                <flux:button variant="ghost" wire:click="go(1)">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" wire:click="continueFromSource">{{ __('Continue') }}</flux:button>
            </div>
        @elseif ($step === 3)
            <div>
                <flux:heading size="xl" level="1">{{ __('Get paid, keep all of it') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Payments will go straight to your own Stripe account, with a 0% Spotter fee. You’ll pay only Stripe’s processing fee.') }}</flux:text>
            </div>
            <flux:callout icon="clock">
                <flux:callout.heading>{{ __('Payments are coming soon') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Keep billing clients the way you do today. We’ll let you know when you can connect Stripe here.') }}</flux:callout.text>
            </flux:callout>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ([[__('Monthly coaching'), __('Recurring subscriptions with automatic retries')], [__('Session packs'), __('Sell 5 or 10 sessions, tracked as they’re used')], [__('One-off programs'), __('Sell a 12-week plan from your public page')]] as [$title, $sub])
                    <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-900">
                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $title }}</div>
                        <flux:text size="sm">{{ $sub }}</flux:text>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between border-t border-zinc-100 pt-5 dark:border-zinc-700">
                <flux:button variant="ghost" wire:click="go(2)">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" wire:click="go(4)">{{ __('Continue') }}</flux:button>
            </div>
        @elseif ($step === 4)
            <div>
                <flux:heading size="xl" level="1">{{ __('Make the app yours') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Clients see your name and colours.') }}</flux:text>
            </div>
            <form wire:submit="saveBrand" class="flex flex-col gap-6">
                <div class="flex flex-col gap-8 md:flex-row md:items-start">
                    <div class="flex flex-1 flex-col gap-5">
                        <flux:input type="file" wire:model="logo" accept="image/*" :label="__('Logo (optional)')" />
                        <flux:field>
                            <flux:label>{{ __('Brand colour') }}</flux:label>
                            <div class="flex flex-wrap items-center gap-3">
                                @foreach ($this::SWATCHES as $hex => $name)
                                    <button type="button" wire:click="$set('brandColor', '{{ $hex }}')" aria-label="{{ __($name) }}" aria-pressed="{{ strtoupper($brandColor) === $hex ? 'true' : 'false' }}"
                                        class="size-11 rounded-full border-[3px] border-white" style="background: {{ $hex }}; box-shadow: 0 0 0 {{ strtoupper($brandColor) === $hex ? '2px '.$hex : '1px #d4d4d4' }}"></button>
                                @endforeach
                                <flux:input wire:model.live.debounce.400ms="brandColor" class="w-28" size="sm" :aria-label="__('Custom colour')" />
                            </div>
                            <flux:error name="brandColor" />
                        </flux:field>
                        <flux:input.group :label="__('Your web address')">
                            <flux:input wire:model="subdomain" />
                            <flux:input.group.suffix>.spotter.app</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="subdomain" />
                        <flux:text size="sm">{{ __('Reserved for you. Custom web addresses switch on before public launch.') }}</flux:text>
                    </div>

                    @php($previewColor = preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor) ? $brandColor : '#0A7A55')
                    <div role="img" aria-label="{{ __('Client app preview') }}" class="mx-auto flex h-96 w-52 shrink-0 flex-col overflow-hidden rounded-[2rem] border-8 border-ink bg-zinc-100">
                        <div class="flex flex-col gap-1 px-4 pb-3 pt-4 text-white" style="background: {{ $previewColor }}">
                            <span class="font-display text-sm font-extrabold">{{ $this->coach->brandName() }}</span>
                            <span class="text-[11px] opacity-90">{{ __('Monday · Lower A') }}</span>
                        </div>
                        <div class="flex flex-col gap-2 p-3">
                            <div class="h-16 rounded-lg bg-white"></div>
                            <div class="h-10 rounded-lg bg-white"></div>
                            <div class="h-10 rounded-lg bg-white"></div>
                            <div class="mt-2 h-9 rounded-lg" style="background: {{ $previewColor }}"></div>
                        </div>
                    </div>
                </div>
                <div class="flex justify-between border-t border-zinc-100 pt-5 dark:border-zinc-700">
                    <flux:button variant="ghost" wire:click="go(3)">{{ __('Back') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Continue') }}</flux:button>
                </div>
            </form>
        @else
            <div>
                <flux:heading size="xl" level="1">{{ __('Invite your clients') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Each client gets the invite in their own language. Invite everyone now or a few at a time.') }}</flux:text>
            </div>
            @if ($this->pendingClients->isEmpty())
                <flux:callout icon="user-plus">
                    <flux:callout.text>{{ __('No clients waiting for an invite. Import them in step 2 or add them from the Clients page.') }}</flux:callout.text>
                </flux:callout>
                <div class="flex justify-between border-t border-zinc-100 pt-5 dark:border-zinc-700">
                    <flux:button variant="ghost" wire:click="go(4)">{{ __('Back') }}</flux:button>
                    <flux:button variant="primary" wire:click="finish">{{ __('Finish setup') }}</flux:button>
                </div>
            @else
                <div class="flex flex-col divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->pendingClients as $client)
                        <label class="flex cursor-pointer items-center gap-3 px-4 py-3" wire:key="invite-{{ $client->id }}">
                            <flux:checkbox wire:model.live="invite.{{ $client->id }}" />
                            <span class="flex-1 font-medium text-zinc-900 dark:text-white">{{ $client->name }}</span>
                            <span class="text-sm text-zinc-500">{{ $client->invited_at ? __('Invited :when', ['when' => $client->invited_at->diffForHumans()]) : '' }}</span>
                            <flux:badge size="sm">{{ $client->locale === 'fr' ? 'Français' : 'English' }}</flux:badge>
                        </label>
                    @endforeach
                </div>
                <div class="flex justify-between border-t border-zinc-100 pt-5 dark:border-zinc-700">
                    <flux:button variant="ghost" wire:click="finish">{{ __('Skip for now') }}</flux:button>
                    @php($count = collect($invite)->filter()->count())
                    <flux:button variant="primary" wire:click="sendInvites" :disabled="$count === 0" data-test="send-invites">{{ trans_choice('Send :count invite|Send :count invites', $count) }}</flux:button>
                </div>
            @endif
        @endif
    </flux:card>
</div>
