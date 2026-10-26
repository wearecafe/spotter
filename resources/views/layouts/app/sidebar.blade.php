<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @if (auth()->user()->isCoach())
                    <flux:sidebar.group :heading="__('Coaching')" class="grid">
                        <flux:sidebar.item icon="home" :href="route('coach.dashboard')" :current="request()->routeIs('coach.dashboard')" wire:navigate>
                            {{ __('Overview') }}
                        </flux:sidebar.item>
                        @php($inboxCount = auth()->user()->inboxItems()->actionable()->count())
                        <flux:sidebar.item icon="inbox-stack" :href="route('coach.inbox')" :current="request()->routeIs('coach.inbox*')" :badge="$inboxCount ?: null" wire:navigate>
                            {{ __('Inbox') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('coach.clients.index')" :current="request()->routeIs('coach.clients.*')" wire:navigate>
                            {{ __('Clients') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group :heading="__('Library')" class="grid">
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('coach.templates')" :current="request()->routeIs('coach.templates') || request()->routeIs('coach.workouts.*')" wire:navigate>
                            {{ __('Workout templates') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="bolt" :href="route('coach.exercises')" :current="request()->routeIs('coach.exercises')" wire:navigate>
                            {{ __('Exercises') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @else
                    <flux:sidebar.group :heading="__('Training')" class="grid">
                        <flux:sidebar.item icon="calendar-days" :href="route('training.index')" :current="request()->routeIs('training.index') || request()->routeIs('training.workout')" wire:navigate>
                            {{ __('My workouts') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('training.check-in')" :current="request()->routeIs('training.check-in')" wire:navigate>
                            {{ __('Check-in') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="chat-bubble-left-right" :href="route('training.messages')" :current="request()->routeIs('training.messages')" wire:navigate>
                            {{ __('Messages') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            @if (auth()->user()->isClient() && auth()->user()->coach)
                <div class="px-2 pb-2 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Coached by :name', ['name' => auth()->user()->coach->name]) }}
                </div>
            @endif

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
