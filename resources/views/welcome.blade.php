@php
    // Competitor figures as published on their pricing pages, October 2026 (USD). Re-check before changing.
    $pricesAsOf = 'October 2026';
    $phone = 'relative mx-auto w-full max-w-[260px] overflow-hidden rounded-[2.25rem] border-[10px] border-ink bg-ink shadow-2xl shadow-ink/20';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @include('partials.head', ['title' => __('Programming that keeps up with your clients')])
        <meta name="description" content="{{ __('Spotter is coaching software for personal trainers and gyms. Build a program once and it schedules itself, progressing from what each client actually lifts. Nutrition tracking, client chat, an 870+ exercise library with photos, and one inbox for check-ins, form checks and messages. English and French.') }}">
        @php($shareTitle = __('Spotter: programming that keeps up with your clients'))
        @php($shareText = __('Build a program once and it schedules itself, progressing from what each client actually lifts. Nutrition, an 870+ exercise library and one inbox for every check-in. For trainers and gyms, in English and French.'))
        <link rel="canonical" href="{{ url('/') }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Spotter">
        <meta property="og:url" content="{{ url('/') }}">
        <meta property="og:title" content="{{ $shareTitle }}">
        <meta property="og:description" content="{{ $shareText }}">
        <meta property="og:image" content="{{ asset('images/og-home.jpg') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ __('Spotter: programming that keeps up with your clients, next to a program grid where progressed loads are highlighted.') }}">
        <meta property="og:locale" content="en_CA">
        <meta property="og:locale:alternate" content="fr_CA">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $shareTitle }}">
        <meta name="twitter:description" content="{{ $shareText }}">
        <meta name="twitter:image" content="{{ asset('images/og-home.jpg') }}">
    </head>
    <body class="min-h-screen bg-white font-sans text-ink antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">{{ __('Skip to content') }}</a>

        {{-- Nav --}}
        <header class="sticky top-0 z-40 border-b border-white/10 bg-ink/95 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-white">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-lime text-ink"><x-app-logo-icon class="size-5" /></span>
                    <span class="font-display text-lg font-extrabold tracking-tight">Spotter</span>
                </a>
                <nav aria-label="{{ __('Main') }}" class="hidden items-center gap-7 text-sm font-medium text-zinc-300 md:flex">
                    <a href="#programs" class="hover:text-white">{{ __('Programs') }}</a>
                    <a href="#nutrition" class="hover:text-white">{{ __('Nutrition') }}</a>
                    <a href="#chat" class="hover:text-white">{{ __('Chat') }}</a>
                    <a href="#inbox" class="hover:text-white">{{ __('Inbox') }}</a>
                    <a href="#gyms" class="hover:text-white">{{ __('Gyms') }}</a>
                    <a href="#switch" class="hover:text-white">{{ __('Switching') }}</a>
                    <a href="#pricing" class="hover:text-white">{{ __('Pricing') }}</a>
                </nav>
                <div class="flex items-center gap-2">
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex h-10 items-center rounded-lg bg-lime px-4 text-sm font-bold text-ink hover:bg-lime/90">{{ __('Open Spotter') }}</a>
                    @else
                        @if (\App\Support\Demo::enabled())
                            <a href="{{ route('demo') }}" class="hidden h-10 items-center rounded-lg px-3 text-sm font-semibold text-white hover:bg-white/10 lg:inline-flex">{{ __('Try the demo') }}</a>
                        @endif
                        <a href="{{ route('login') }}" class="hidden h-10 items-center rounded-lg px-3 text-sm font-semibold text-white hover:bg-white/10 sm:inline-flex">{{ __('Log in') }}</a>
                        <a href="{{ route('register') }}" class="inline-flex h-10 items-center rounded-lg bg-lime px-4 text-sm font-bold text-ink hover:bg-lime/90">{{ __('Start free') }}</a>
                    @endauth
                </div>
            </div>
        </header>

        <main id="main">
            {{-- Hero --}}
            <section class="relative overflow-hidden bg-ink text-white">
                <div class="mx-auto grid max-w-6xl gap-12 px-4 pb-0 pt-16 sm:px-6 lg:pt-24">
                    <div class="mx-auto max-w-3xl text-center">
                        <p class="inline-flex items-center gap-2 rounded-full border border-white/15 px-3 py-1 text-sm text-zinc-300">
                            <span class="size-2 rounded-full bg-lime" aria-hidden="true"></span>{{ __('Now in early access for independent coaches') }}
                        </p>
                        <h1 class="mt-6 font-display text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-6xl">
                            {{ __('Programming that') }} <span class="text-lime">{{ __('keeps up with your clients.') }}</span>
                        </h1>
                        <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-zinc-300 sm:text-xl">
                            {{ __('Build a program once and Spotter puts every session on your client’s calendar, week after week, moving loads up from what they actually lift. Then handle every check-in, form check and message from one inbox, with replies already started.') }}
                        </p>
                        <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
                            <a href="{{ route('register') }}" class="inline-flex h-12 items-center justify-center rounded-xl bg-lime px-6 font-bold text-ink hover:bg-lime/90">{{ __('Create your coach account') }}</a>
                            <a href="#switch" class="inline-flex h-12 items-center justify-center rounded-xl border border-white/20 px-6 font-semibold text-white hover:bg-white/10">{{ __('Switching from TrueCoach or Trainerize?') }}</a>
                        </div>
                        <p class="mt-4 text-sm text-zinc-400">
                            {{ __('Free for up to 3 clients. No card needed.') }}
                            @if (\App\Support\Demo::enabled())
                                <a href="{{ route('demo') }}" class="font-semibold text-lime underline-offset-4 hover:underline">{{ __('Or explore a demo gym, no sign-up →') }}</a>
                            @endif
                        </p>
                    </div>

                    <div class="relative mx-auto w-full max-w-5xl">
                        <div class="absolute -inset-x-10 -top-10 bottom-0 rounded-full bg-brand/30 blur-3xl" aria-hidden="true"></div>
                        <img src="{{ asset('images/home/program.webp') }}" width="2048" height="1520"
                             alt="{{ __('The Spotter program editor: Lower A every Tuesday, with back squat, Romanian deadlift and split squats, each with sets, reps, a starting load and a rule to add load or reps each week.') }}"
                             class="relative hidden rounded-t-2xl border border-b-0 border-white/10 shadow-2xl sm:block">
                        <div class="relative mb-[-3rem] sm:hidden {{ $phone }}">
                            <img src="{{ asset('images/home/workout.webp') }}" width="780" height="1560" alt="{{ __('A client logging Back Squat sets in Spotter, with the rest timer running.') }}">
                        </div>
                    </div>
                </div>
            </section>

            {{-- Pain strip --}}
            <section class="border-b border-zinc-200 bg-zinc-50">
                <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:px-6 md:grid-cols-3">
                    @foreach ([
                        [__('Rebuilding every week by hand'), __('Copying sessions onto the calendar and bumping loads yourself. Build the program once: Spotter schedules every week and moves loads from what each client logged.')],
                        [__('Sunday night check-in pile'), __('Thirty check-ins, each needing a read and a reply. They land in one inbox, summarized, with the reply already started.')],
                        [__('Stuck because leaving is hard'), __('We import your client list and rebuild your programs from your exports, and tell you upfront what can’t come across.')],
                    ] as [$title, $text])
                        <div>
                            <h2 class="font-display text-lg font-bold">{{ $title }}</h2>
                            <p class="mt-2 leading-relaxed text-zinc-600">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Programs --}}
            <section id="programs" class="scroll-mt-20 bg-zinc-50">
                <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] lg:py-28">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wider text-brand">{{ __('Programs') }}</p>
                        <h2 class="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Build it once. It schedules itself.') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-zinc-600">{{ __('Pick the training days and weekdays, add exercises from the library and set a rule on each: add load, add reps, or hold. Every session for every week lands on your client’s calendar.') }}</p>
                        <ul class="mt-8 flex flex-col gap-4">
                            @foreach ([
                                __('Edit the program once and every upcoming session follows. Sessions your client has started stay exactly as they are.'),
                                __('Each time a session is done, the coming weeks are re-planned: targets hit means the load goes up by your step; missed reps or a missed session means it holds.'),
                                __('Need a one-off change? Edit a single session and the program leaves it alone. Skip a week with one click.'),
                                __('A calendar of every week shows what’s done, missed, planned or edited, with planned vs actual for each exercise.'),
                            ] as $point)
                                <li class="flex gap-3">
                                    <span class="mt-1 flex size-5 shrink-0 items-center justify-center rounded-full bg-lime text-xs font-extrabold text-ink" aria-hidden="true">✓</span>
                                    <span class="leading-relaxed text-zinc-700">{{ $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <figure class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xl">
                        <figcaption class="flex items-center justify-between border-b border-zinc-200 px-5 py-3">
                            <span class="font-display font-bold">{{ __('Maya · Lower A') }}</span>
                            <span class="text-sm text-zinc-500">{{ __('Week 1 → Week 2') }}</span>
                        </figcaption>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[34rem] text-left text-[15px]">
                                <thead class="text-xs uppercase tracking-wider text-zinc-500">
                                    <tr>
                                        <th scope="col" class="px-5 py-2.5 font-semibold">{{ __('Exercise') }}</th>
                                        <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('Logged in week 1') }}</th>
                                        <th scope="col" class="px-3 py-2.5 font-semibold">{{ __('Week 2') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100">
                                    @foreach ([
                                        [__('Back squat'), __('4 × 5 @ 75 kg, RPE 7.5'), '4 × 5 @ 77.5 kg', __('All sets at target: +2.5 kg'), true],
                                        [__('Romanian deadlift'), __('8, 8, 6 reps @ 60 kg'), '3 × 8 @ 60 kg', __('Held: reps came in under target'), false],
                                        [__('Split squat'), __('3 × 10 @ 2 × 16 kg'), '3 × 9–10', __('All sets hit: +1 rep'), true],
                                    ] as [$name, $logged, $next, $why, $changed])
                                        <tr class="align-top">
                                            <th scope="row" class="px-5 py-3 font-semibold">{{ $name }}</th>
                                            <td class="px-3 py-3 tabular-nums text-zinc-600">{{ $logged }}</td>
                                            <td class="px-3 py-3">
                                                <span @class(['inline-block rounded-md px-2 py-0.5 font-semibold tabular-nums', 'bg-lime/50' => $changed, 'bg-zinc-100' => ! $changed])>{{ $next }}</span>
                                                <span class="mt-1 block text-xs text-zinc-500">{{ $why }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </figure>
                </div>
                <div class="mx-auto max-w-6xl px-4 pb-20 sm:px-6 lg:pb-28">
                    <img src="{{ asset('images/home/calendar.webp') }}" width="2048" height="846" loading="lazy"
                         alt="{{ __('A program calendar: six weeks of Tuesday and Thursday sessions, week 1 done and weeks 2 to 6 planned.') }}"
                         class="w-full">

                    <div class="mt-16 grid items-center gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                        <div>
                            <h3 class="font-display text-2xl font-extrabold tracking-tight sm:text-3xl">{{ __('870+ exercises with photos and steps') }}</h3>
                            <p class="mt-3 text-lg leading-relaxed text-zinc-600">{{ __('A full library is ready from day one: start and end photos, step-by-step instructions, muscles, equipment and level. Filter by muscle or equipment, and add your own exercises for your team.') }}</p>
                            <p class="mt-3 leading-relaxed text-zinc-600">{{ __('Clients tap “How to” in their workout to see the photos and steps.') }}</p>
                        </div>
                        <img src="{{ asset('images/home/library.webp') }}" width="2048" height="1400" loading="lazy"
                             alt="{{ __('The Spotter exercise library: cards with a photo, muscle group, equipment and level for each exercise, with filters at the top.') }}"
                             class="rounded-2xl border border-zinc-200 shadow-xl">
                    </div>
                </div>
            </section>

            {{-- Client app --}}
            <section id="clients" class="scroll-mt-20">
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="text-sm font-bold uppercase tracking-wider text-brand">{{ __('For your clients') }}</p>
                        <h2 class="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('An app clients actually use, with your name on it.') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-zinc-600">{{ __('It opens in the browser, so there’s nothing to install. Clients can add it to their home screen, and they see your business name and brand colour.') }}</p>
                    </div>
                    <div class="mt-14 grid gap-10 sm:grid-cols-3">
                        @foreach ([
                            ['workout', __('Log every set'), __('Reps, load and effort for each set, with a rest timer that starts when they tick a set off. Film a set for a form check in one tap.'), __('A client logging Back Squat sets with the rest timer running at the bottom of the screen.')],
                            ['checkin', __('Weekly check-in in 2 minutes'), __('Weight, sleep, steps, energy, stress, notes and progress photos. You get the summary in your inbox.'), __('The weekly check-in form with energy and stress scales.')],
                            ['invite-fr', __('English and French'), __('Each client picks their language, and their app, invites and the replies started for them follow it.'), __('The client invite screen in French.')],
                        ] as [$img, $title, $text, $alt])
                            <figure class="flex flex-col items-center text-center">
                                <div class="{{ $phone }}">
                                    <img src="{{ asset('images/home/'.$img.'.webp') }}" width="780" height="1560" loading="lazy" alt="{{ $alt }}">
                                </div>
                                <figcaption class="mt-6 max-w-xs">
                                    <span class="block font-display text-lg font-bold">{{ $title }}</span>
                                    <span class="mt-1 block leading-relaxed text-zinc-600">{{ $text }}</span>
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Nutrition --}}
            <section id="nutrition" class="scroll-mt-20 bg-zinc-50">
                <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] lg:py-28">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wider text-brand">{{ __('Nutrition') }}</p>
                        <h2 class="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Nutrition without another app to manage.') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-zinc-600">{{ __('Clients log weight, calories, protein, carbs and fat once a day. Already tracking somewhere else? They upload their export from MacroFactor, MyFitnessPal or Lose It! and every day comes across.') }}</p>
                        <ul class="mt-8 flex flex-col gap-4">
                            @foreach ([
                                __('Set daily calorie and macro targets for each client.'),
                                __('See 7- and 30-day averages, days within 10% of calories, and weight trend at a glance.'),
                                __('Weekly check-in summaries include the week’s nutrition, so you see training and food together.'),
                            ] as $point)
                                <li class="flex gap-3">
                                    <span class="mt-1 flex size-5 shrink-0 items-center justify-center rounded-full bg-lime text-xs font-extrabold text-ink" aria-hidden="true">✓</span>
                                    <span class="leading-relaxed text-zinc-700">{{ $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="relative">
                        <img src="{{ asset('images/home/coach-nutrition.webp') }}" width="2048" height="1240" loading="lazy"
                             alt="{{ __('The coach’s nutrition view for a client: 7- and 30-day averages against targets, and a daily log with weight, calories and macros.') }}"
                             class="rounded-2xl border border-zinc-200 shadow-xl lg:me-28">
                        <div class="absolute -bottom-12 -end-6 hidden w-40 lg:block">
                            <div class="{{ $phone }} max-w-none border-8">
                                <img src="{{ asset('images/home/nutrition.webp') }}" width="780" height="1560" loading="lazy" alt="{{ __('The client’s nutrition screen on a phone, with daily targets and a form to log the day.') }}">
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Chat --}}
            <section id="chat" class="scroll-mt-20">
                <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] lg:py-28">
                    <div class="relative order-last lg:order-first lg:ps-24">
                        <img src="{{ asset('images/home/chat.webp') }}" width="2048" height="1360" loading="lazy"
                             alt="{{ __('The coach’s Messages screen: every client conversation in one list with unread counts, and a chat thread with read receipts.') }}"
                             class="rounded-2xl border border-zinc-200 shadow-xl">
                        <div class="absolute -bottom-12 -start-6 hidden w-40 lg:block">
                            <div class="{{ $phone }} max-w-none border-8">
                                <img src="{{ asset('images/home/chat-phone.webp') }}" width="780" height="1560" loading="lazy" alt="{{ __('The same conversation in the client’s app on a phone.') }}">
                            </div>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wider text-brand">{{ __('Chat') }}</p>
                        <h2 class="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Talk to clients without giving out your number.') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-zinc-600">{{ __('Every client has a direct line to you inside Spotter. Questions, meal photos and quick wins stay next to their program, not scattered across texts and DMs.') }}</p>
                        <ul class="mt-8 flex flex-col gap-4">
                            @foreach ([
                                __('Every conversation in one list, newest first, with unread counts.'),
                                __('Clients can send photos: a meal, a sore spot, the equipment they have today.'),
                                __('Read receipts, so you both know a message landed.'),
                                __('Not in the app? Spotter emails you when a message sits unread for 10 minutes.'),
                                __('In a gym, the history stays with the client if they change coach, and teammates can step in.'),
                            ] as $point)
                                <li class="flex gap-3">
                                    <span class="mt-1 flex size-5 shrink-0 items-center justify-center rounded-full bg-lime text-xs font-extrabold text-ink" aria-hidden="true">✓</span>
                                    <span class="leading-relaxed text-zinc-700">{{ $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </section>

            {{-- Inbox --}}
            <section id="inbox" class="scroll-mt-20 bg-zinc-50">
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
                    <div class="max-w-2xl">
                        <p class="text-sm font-bold uppercase tracking-wider text-brand">{{ __('Coach Inbox') }}</p>
                        <h2 class="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Everything that needs you, in one ranked queue.') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-zinc-600">{{ __('No more digging through chats, logs and notifications. Spotter watches what your clients do and brings you only what needs a coach.') }}</p>
                    </div>

                    <img src="{{ asset('images/home/inbox.webp') }}" width="2560" height="1640" loading="lazy"
                         alt="{{ __('Spotter Coach Inbox: a ranked list of client items on the left, and on the right Maya’s check-in with a summary, a suggested change to cut one set of squats, and a reply ready to review.') }}"
                         class="mt-12 rounded-2xl border border-zinc-200 shadow-xl">

                    <ol class="mt-14 grid gap-6 md:grid-cols-3">
                        @foreach ([
                            ['1', __('It lands'), __('Weekly check-ins, form-check videos, workout feedback, new personal records, messages, and clients who missed two sessions this week.')],
                            ['2', __('It’s started for you'), __('A short summary of what changed, a reply already started in your client’s language, and a suggested tweak to their next workout when the data supports it.')],
                            ['3', __('You decide'), __('Finish the reply in your own words and send it. Apply the program change in one click, snooze it until tomorrow, or mark it done.')],
                        ] as [$n, $title, $text])
                            <li class="relative rounded-2xl border border-zinc-200 p-6">
                                <span class="flex size-10 items-center justify-center rounded-full bg-ink font-display text-lg font-extrabold text-lime">{{ $n }}</span>
                                <h3 class="mt-5 font-display text-xl font-bold">{{ $title }}</h3>
                                <p class="mt-2 leading-relaxed text-zinc-600">{{ $text }}</p>
                            </li>
                        @endforeach
                    </ol>

                    <div class="mt-16 grid items-center gap-12 lg:grid-cols-[1fr_auto]">
                        <div class="max-w-xl">
                            <h3 class="font-display text-2xl font-bold">{{ __('Nothing goes out without you.') }}</h3>
                            <p class="mt-3 leading-relaxed text-zinc-600">{{ __('Every summary, reply and program change is a draft until you approve it. If a client mentions pain, the draft asks about it and eases off. It never diagnoses.') }}</p>
                            <h3 class="mt-8 font-display text-2xl font-bold">{{ __('Clear your inbox from your phone.') }}</h3>
                            <p class="mt-3 leading-relaxed text-zinc-600">{{ __('One card at a time: read, approve, next. Between sessions, on the gym floor, wherever you are.') }}</p>
                        </div>
                        <div class="{{ $phone }}">
                            <img src="{{ asset('images/home/focus.webp') }}" width="780" height="1560" loading="lazy" alt="{{ __('The inbox on a phone, showing one item at a time with a drafted reply and an Approve and send button.') }}">
                        </div>
                    </div>
                </div>
            </section>

            {{-- Gyms --}}
            <section id="gyms" class="scroll-mt-20">
                <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] lg:py-28">
                    <img src="{{ asset('images/home/team.webp') }}" width="2048" height="1120" loading="lazy"
                         alt="{{ __('The Team page: the gym owner and a coach, each with their role and client count, and a form to invite another coach.') }}"
                         class="order-last rounded-2xl border border-zinc-200 shadow-xl lg:order-first">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wider text-brand">{{ __('For gyms') }}</p>
                        <h2 class="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('One gym, a whole team of trainers.') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-zinc-600">{{ __('Invite your coaches and work from one shared exercise library and set of templates. Each coach sees their own clients; owners and admins see everyone.') }}</p>
                        <ul class="mt-8 flex flex-col gap-4">
                            @foreach ([
                                __('Reassign a client to another coach in one step. Their upcoming sessions and open inbox items go with them, and the message history stays.'),
                                __('When a coach leaves, hand their clients to someone else first. Nothing is lost.'),
                                __('Your gym’s name and colour on the client app.'),
                            ] as $point)
                                <li class="flex gap-3">
                                    <span class="mt-1 flex size-5 shrink-0 items-center justify-center rounded-full bg-lime text-xs font-extrabold text-ink" aria-hidden="true">✓</span>
                                    <span class="leading-relaxed text-zinc-700">{{ $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </section>

            {{-- Switching --}}
            <section id="switch" class="scroll-mt-20 bg-ink text-white">
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
                    <div class="max-w-2xl">
                        <p class="text-sm font-bold uppercase tracking-wider text-lime">{{ __('Switching') }}</p>
                        <h2 class="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Bring your clients. We’ll be honest about the rest.') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-zinc-300">{{ __('Upload your client export and your program files. Spotter rebuilds the programs, matches every exercise name to our library, and shows you anything it isn’t sure about before a single client is invited.') }}</p>
                    </div>

                    <div class="mt-12 grid gap-4 md:grid-cols-3">
                        @foreach ([
                            ['TrueCoach', [[true, __('Client list from the client CSV export')], [true, __('Programs from workouts you email to yourself or print to PDF')], [false, __('Past logs only if they’re in the files you export')]]],
                            ['ABC Trainerize', [[true, __('Client list from the client CSV')], [true, __('Programs from the PDFs you save')], [false, __('Trainerize’s export leaves out workout history')]]],
                            [__('Spreadsheets'), [[true, __('Client list from any sheet saved as CSV')], [true, __('Programs written one exercise per line, like “Back Squat 4x5 75kg”')], [true, __('Keep the sheet for past logs')]]],
                        ] as [$source, $items])
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                                <h3 class="font-display text-xl font-bold">{{ $source }}</h3>
                                <ul class="mt-4 flex flex-col gap-3 text-[15px]">
                                    @foreach ($items as [$ok, $text])
                                        <li class="flex gap-3">
                                            <span @class(['mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-xs font-extrabold', 'bg-lime text-ink' => $ok, 'bg-amber-300 text-ink' => ! $ok]) aria-hidden="true">{{ $ok ? '✓' : '!' }}</span>
                                            <span class="text-zinc-200"><span class="sr-only">{{ $ok ? __('Comes across:') : __('Limitation:') }}</span> {{ $text }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl bg-lime p-6 text-ink">
                            <h3 class="font-display text-xl font-extrabold">{{ __('Still paying for an annual plan?') }}</h3>
                            <p class="mt-2 leading-relaxed">{{ __('Tell us how many months you have left and you get that many months of Spotter free. You never pay twice.') }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                            <h3 class="font-display text-xl font-bold">{{ __('Your clients get a friendly heads-up') }}</h3>
                            <p class="mt-2 leading-relaxed text-zinc-300">{{ __('Each client gets an invite in their language explaining that their program is already set up and nothing changes about how you work together.') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Pricing --}}
            <section id="pricing" class="scroll-mt-20">
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="text-sm font-bold uppercase tracking-wider text-brand">{{ __('Pricing') }}</p>
                        <h2 class="mt-3 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Grows a little at a time. Everything included.') }}</h2>
                        <p class="mt-4 text-lg leading-relaxed text-zinc-600">{{ __('No price cliffs when you sign your next client, and no add-ons for the features that matter.') }}</p>
                    </div>

                    <div class="mt-14 grid gap-6 lg:grid-cols-3">
                        @foreach ([
                            ['name' => __('Starter'), 'price' => '$0', 'per' => __('forever'), 'blurb' => __('Try everything with your first clients.'), 'features' => [__('Up to 3 active clients'), __('Recurring programs with progression rules'), __('870+ exercise library with photos'), __('Nutrition tracking and imports'), __('Chat with photos and read receipts'), __('Client app in English and French'), __('Coach Inbox with drafted replies')], 'cta' => __('Start free'), 'featured' => false, 'soon' => false],
                            ['name' => __('Coach'), 'price' => '$39', 'per' => __('per month'), 'blurb' => __('Includes 15 active clients, then $1.50 per extra active client.'), 'features' => [__('Everything in Starter'), __('Unlimited programs and templates'), __('Import from TrueCoach, Trainerize or spreadsheets'), __('Your brand colour and name on the client app')], 'cta' => __('Start free'), 'featured' => true, 'soon' => false],
                            ['name' => __('Team'), 'price' => '$99', 'per' => __('per month'), 'blurb' => __('3 coach seats and 50 active clients.'), 'features' => [__('Everything in Coach'), __('Owners and admins see every client'), __('Shared exercise library and templates'), __('Reassign clients between coaches'), __('An inbox for each coach')], 'cta' => __('Start free'), 'featured' => false, 'soon' => false],
                        ] as $plan)
                            <div @class(['flex flex-col rounded-2xl p-7', 'bg-ink text-white ring-4 ring-lime' => $plan['featured'], 'border border-zinc-200' => ! $plan['featured']])>
                                <div class="flex items-center justify-between">
                                    <h3 class="font-display text-xl font-bold">{{ $plan['name'] }}</h3>
                                    @if ($plan['featured'])
                                        <span class="rounded-full bg-lime px-2.5 py-0.5 text-xs font-bold text-ink">{{ __('Most coaches') }}</span>
                                    @elseif ($plan['soon'])
                                        <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-bold text-zinc-600">{{ __('Coming soon') }}</span>
                                    @endif
                                </div>
                                <p class="mt-5"><span class="font-display text-5xl font-extrabold tracking-tight">{{ $plan['price'] }}</span> <span @class(['text-sm', 'text-zinc-300' => $plan['featured'], 'text-zinc-500' => ! $plan['featured']])>{{ $plan['per'] }}</span></p>
                                <p @class(['mt-2 text-[15px] leading-relaxed', 'text-zinc-300' => $plan['featured'], 'text-zinc-600' => ! $plan['featured']])>{{ $plan['blurb'] }}</p>
                                <ul class="mt-6 flex flex-1 flex-col gap-3 text-[15px]">
                                    @foreach ($plan['features'] as $feature)
                                        <li class="flex gap-3"><span @class(['font-extrabold', 'text-lime' => $plan['featured'], 'text-brand' => ! $plan['featured']]) aria-hidden="true">✓</span>{{ $feature }}</li>
                                    @endforeach
                                </ul>
                                @if ($plan['soon'])
                                    <span class="mt-8 inline-flex h-12 items-center justify-center rounded-xl border border-zinc-200 font-semibold text-zinc-500">{{ $plan['cta'] }}</span>
                                @else
                                    <a href="{{ route('register') }}" @class(['mt-8 inline-flex h-12 items-center justify-center rounded-xl font-bold', 'bg-lime text-ink hover:bg-lime/90' => $plan['featured'], 'bg-ink text-white hover:bg-ink/90' => ! $plan['featured']])>{{ $plan['cta'] }}</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-6 text-center text-sm text-zinc-500">{{ __('Prices in USD. Spotter is in early access: create an account free today, and paid plans begin at public launch.') }}</p>

                    {{-- Comparison --}}
                    <div class="mt-20">
                        <h3 class="text-center font-display text-2xl font-extrabold tracking-tight">{{ __('What a coach with 21 clients pays') }}</h3>
                        <div class="mx-auto mt-8 max-w-4xl overflow-x-auto rounded-2xl border border-zinc-200">
                            <table class="w-full min-w-[40rem] text-left text-[15px]">
                                <caption class="sr-only">{{ __('Monthly cost comparison at 21 active clients') }}</caption>
                                <thead class="bg-zinc-50 text-xs uppercase tracking-wider text-zinc-500">
                                    <tr>
                                        <th scope="col" class="px-5 py-3 font-semibold"></th>
                                        <th scope="col" class="px-5 py-3 font-semibold text-ink">Spotter</th>
                                        <th scope="col" class="px-5 py-3 font-semibold">TrueCoach</th>
                                        <th scope="col" class="px-5 py-3 font-semibold">ABC Trainerize</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200">
                                    <tr>
                                        <th scope="row" class="px-5 py-4 font-semibold">{{ __('Plan for 21 clients') }}</th>
                                        <td class="bg-lime/20 px-5 py-4"><strong class="font-display text-lg">$48</strong> <span class="text-zinc-600">{{ __('/mo ($39 + 6 × $1.50)') }}</span></td>
                                        <td class="px-5 py-4"><strong>$164.98</strong> <span class="text-zinc-600">{{ __('/mo (Pro; Standard stops at 20)') }}</span></td>
                                        <td class="px-5 py-4 text-zinc-600">{{ __('Pro plan, priced by client tier') }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="px-5 py-4 font-semibold">{{ __('AI help') }}</th>
                                        <td class="bg-lime/20 px-5 py-4">{{ __('Included') }}</td>
                                        <td class="px-5 py-4 text-zinc-600">{{ __('None advertised') }}</td>
                                        <td class="px-5 py-4 text-zinc-600">{{ __('Workout builder in beta; AI coaching sold as an add-on') }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="px-5 py-4 font-semibold">{{ __('Taking payments') }}</th>
                                        <td class="bg-lime/20 px-5 py-4">{{ __('0% platform fee') }} <span class="text-zinc-500">({{ __('coming soon') }})</span></td>
                                        <td class="px-5 py-4 text-zinc-600">{{ __('5% of every payment, on top of Stripe') }}</td>
                                        <td class="px-5 py-4 text-zinc-600">{{ __('$10/mo add-on') }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="px-5 py-4 font-semibold">{{ __('Your brand on the client app') }}</th>
                                        <td class="bg-lime/20 px-5 py-4">{{ __('Included (name and colour)') }}</td>
                                        <td class="px-5 py-4 text-zinc-600">{{ __('Custom colours') }}</td>
                                        <td class="px-5 py-4 text-zinc-600">{{ __('Branded app $169 + $99/yr Apple account') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mx-auto mt-4 max-w-4xl text-sm text-zinc-500">{{ __('Competitor prices as published on their websites in :date, in USD, and may have changed since. TrueCoach and ABC Trainerize are trademarks of their owners; Spotter isn’t affiliated with either.', ['date' => $pricesAsOf]) }}</p>
                    </div>
                </div>
            </section>

            {{-- FAQ --}}
            <section id="faq" class="scroll-mt-20 bg-zinc-50">
                <div class="mx-auto max-w-3xl px-4 py-20 sm:px-6 lg:py-28">
                    <h2 class="text-center font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ __('Questions coaches ask') }}</h2>
                    <div class="mt-12 divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white">
                        @foreach ([
                            [__('Do I have to schedule every session?'), __('No. Build the program once with training days on set weekdays, and Spotter puts every session for every week on your client’s calendar. Edit the program and every upcoming session follows.')],
                            [__('How does progression work?'), __('Each exercise can add load, add reps or hold. Each time your client finishes a session, Spotter compares what they logged with the plan and re-plans the coming weeks: targets hit means the next step, misses mean it holds.')],
                            [__('Does Spotter track nutrition?'), __('Yes: weight, calories and macros, one entry per day, with targets you set. Clients can also upload exports from MacroFactor, MyFitnessPal or Lose It!. It’s an upload, not a live sync, because those apps don’t offer an open connection.')],
                            [__('Can my whole gym use Spotter?'), __('Yes. Invite your coaches to your team. Each coach sees their own clients; owners and admins see everyone and can reassign clients. The library and templates are shared.')],
                            [__('Can my clients message me?'), __('Yes. Each client has a chat with you in their app, with photos and read receipts. If a message sits unread for 10 minutes, Spotter emails you, so you don’t have to keep the app open. Client messages also land in your inbox with a reply started.')],
                            [__('Does Spotter message my clients for me?'), __('No. It starts replies for you to finish. Every reply and program change waits for you to approve, edit or dismiss it.')],
                            [__('Do my clients need to download an app?'), __('No. Spotter opens in any phone browser, and clients can add it to their home screen so it feels like an app.')],
                            [__('Can I bring my programs from TrueCoach or Trainerize?'), __('Yes. Upload your client CSV and your program files (PDF or text). Spotter rebuilds each program and asks you to confirm any exercise names it isn’t sure about. Past workout logs usually don’t come across, because those platforms don’t export them.')],
                            [__('Can some clients use French and others English?'), __('Yes. Language is set per client. Their app, their invite and the replies drafted for them all follow it.')],
                            [__('Can clients pay me through Spotter?'), __('Not yet. Payments through your own Stripe account, with no Spotter fee on top, are coming soon. Until then, keep billing the way you do today.')],
                            [__('What does early access mean?'), __('Spotter works today and we’re adding to it every week. Accounts are free during early access, and paid plans start at public launch.')],
                        ] as [$q, $a])
                            <details class="group px-6 py-5">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-display text-lg font-bold">
                                    {{ $q }}
                                    <span class="text-2xl font-normal text-zinc-400 transition group-open:rotate-45" aria-hidden="true">+</span>
                                </summary>
                                <p class="mt-3 leading-relaxed text-zinc-600">{{ $a }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Final CTA --}}
            <section class="bg-ink text-white">
                <div class="mx-auto max-w-4xl px-4 py-20 text-center sm:px-6">
                    <h2 class="font-display text-3xl font-extrabold tracking-tight sm:text-5xl">{{ __('Less spreadsheet. More coaching.') }}</h2>
                    <p class="mx-auto mt-5 max-w-xl text-lg text-zinc-300">{{ __('Bring your clients and programs over as part of setup, and build next week from real logs.') }}</p>
                    <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
                        <a href="{{ route('register') }}" class="inline-flex h-12 items-center justify-center rounded-xl bg-lime px-6 font-bold text-ink hover:bg-lime/90">{{ __('Create your coach account') }}</a>
                        @if (\App\Support\Demo::enabled())
                            <a href="{{ route('demo') }}" class="inline-flex h-12 items-center justify-center rounded-xl border border-white/20 px-6 font-semibold hover:bg-white/10">{{ __('Explore the demo gym') }}</a>
                        @endif
                        <a href="{{ route('login') }}" class="inline-flex h-12 items-center justify-center rounded-xl border border-white/20 px-6 font-semibold hover:bg-white/10">{{ __('I’m a client, log me in') }}</a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-white/10 bg-ink text-zinc-400">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 py-8 text-sm sm:flex-row sm:px-6">
                <span>© {{ now()->year }} Spotter</span>
                <nav aria-label="{{ __('Footer') }}" class="flex gap-6">
                    <a href="#pricing" class="hover:text-white">{{ __('Pricing') }}</a>
                    <a href="#faq" class="hover:text-white">{{ __('FAQ') }}</a>
                    @if (\App\Support\Demo::enabled())
                        <a href="{{ route('demo') }}" class="hover:text-white">{{ __('Demo') }}</a>
                    @endif
                    <a href="{{ route('login') }}" class="hover:text-white">{{ __('Log in') }}</a>
                </nav>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
