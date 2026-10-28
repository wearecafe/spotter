<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Exercise;
use App\Models\Import;
use App\Models\Program;
use App\Models\ProgramDay;
use App\Models\User;
use App\Models\Workout;
use App\Support\Demo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Brings a coach's clients and programs over from TrueCoach, Trainerize or spreadsheets.
 *
 * What the other platforms let a coach export (as of 2026): a client CSV with names and
 * contact details, and programs only as documents (TrueCoach emailed/printed workouts,
 * Trainerize PDFs). So clients import exactly; programs are read from text or PDFs and
 * every exercise name is matched against the library for the coach to confirm.
 */
class ProgramImporter
{
    /** Shorthand coaches use in exported programs. */
    public const ABBREVIATIONS = [
        'bss' => 'bulgarian split squat',
        'rdl' => 'romanian deadlift',
        'sldl' => 'stiff leg deadlift',
        'ohp' => 'overhead press',
        'db' => 'dumbbell',
        'dbs' => 'dumbbell',
        'kb' => 'kettlebell',
        'bb' => 'barbell',
        'bw' => 'bodyweight',
        'sa' => 'single arm',
        'pu' => 'pull up',
        'pullup' => 'pull up',
        'pushup' => 'push up',
        'chinup' => 'chin up',
        'ext' => 'extension',
        'lat pd' => 'lat pulldown',
        'pd' => 'pulldown',
        'hip thruster' => 'hip thrust',
        'squats' => 'squat',
        'lunges' => 'lunge',
    ];

    public function __construct(protected CoachAssistant $assistant) {}

    /**
     * Read a client export. Accepts "Name" or "First Name"/"Last Name", "Email", optional "Language".
     *
     * @return Collection<int, array{name: string, email: string, locale: string}>
     */
    public function parseClientsCsv(string $csv): Collection
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($csv)) ?: [], fn ($l) => trim($l) !== ''));

        if (count($lines) < 2) {
            return collect();
        }

        $header = array_map(fn ($h) => Str::of($h)->lower()->replaceMatches('/[^a-z]/', '')->toString(), str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF")));
        $col = fn (array $names) => collect($names)->map(fn ($n) => array_search($n, $header, true))->first(fn ($i) => $i !== false);

        $email = $col(['email', 'emailaddress', 'clientemail']);
        $name = $col(['name', 'fullname', 'clientname']);
        $first = $col(['firstname', 'first']);
        $last = $col(['lastname', 'last']);
        $language = $col(['language', 'locale', 'lang', 'langue']);

        if ($email === null) {
            return collect();
        }

        return collect(array_slice($lines, 1))
            ->map(fn ($line) => str_getcsv($line))
            ->map(function (array $row) use ($email, $name, $first, $last, $language) {
                $fullName = $name !== null
                    ? trim($row[$name] ?? '')
                    : trim(($first !== null ? ($row[$first] ?? '') : '').' '.($last !== null ? ($row[$last] ?? '') : ''));
                $lang = Str::lower(trim($language !== null ? ($row[$language] ?? '') : ''));

                return [
                    'name' => $fullName,
                    'email' => Str::lower(trim($row[$email] ?? '')),
                    'locale' => in_array($lang, ['fr', 'french', 'français', 'francais'], true) ? 'fr' : 'en',
                ];
            })
            ->filter(fn ($c) => filter_var($c['email'], FILTER_VALIDATE_EMAIL) && $c['name'] !== '')
            ->unique('email')
            ->values();
    }

    /**
     * Create (or find) client accounts for an imported list. Returns how many were new.
     *
     * @param  Collection<int, array{name: string, email: string, locale: string}>  $clients
     */
    public function createClients(User $coach, Collection $clients): int
    {
        $created = 0;

        foreach ($clients as $row) {
            $existing = User::firstWhere('email', $row['email']);

            if ($existing) {
                continue; // Never take over an account that already exists.
            }

            User::create([
                'name' => $row['name'],
                'email' => $row['email'],
                'role' => Role::Client,
                'coach_id' => $coach->id,
                'locale' => $row['locale'],
                'invitation_token' => Str::random(48),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Text of a program file: plain text as-is, PDFs read by Claude when configured
     * (and allowed: the demo gym never sends visitors' uploads to Claude).
     */
    public function programText(string $contents, string $filename, bool $mayUseAi = true): ?string
    {
        if (Str::lower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf' || str_starts_with($contents, '%PDF')) {
            return $mayUseAi ? $this->assistant->extractProgramText($contents) : null;
        }

        return mb_check_encoding($contents, 'UTF-8') ? $contents : null;
    }

    /**
     * Turn exported program text into days of exercise rows.
     *
     * Handles lines like "Back Squat 4x5 75kg rpe 7-8", "BSS 3 x 8 16kg DBs", "Plank 3x30s".
     *
     * @return array<int, array{title: string, week: int, rows: array<int, array{raw: string, sets: int, reps: string|null, load: string|null, rpe: string|null}>}>
     */
    public function parseProgram(string $text): array
    {
        $days = [];
        $current = null;
        $week = 1;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

            if ($line === '') {
                continue;
            }

            // "Week 2" or "Week 2 - Day 1 Lower" (programs exported across several weeks).
            if (preg_match('/^(?:week|wk|semaine)\s*(\d{1,2})\b\s*[-:–,]?\s*(.*)$/iu', $line, $w)) {
                $week = max(1, min((int) $w[1], 52));
                $current = null;
                $line = trim($w[2]);

                if ($line === '') {
                    continue;
                }
            }

            if (preg_match('/^(day|jour|session|workout|séance|seance)\b/iu', $line)) {
                $days[] = ['title' => Str::limit($line, 80, ''), 'week' => $week, 'rows' => []];
                $current = array_key_last($days);

                continue;
            }

            $pattern = '/^(?<name>.+?)\s*[-:–]?\s+(?<sets>\d{1,2})\s*[x×]\s*(?<reps>\d+(?:\s*-\s*\d+)?(?:\s*(?:sec|s|m|reps?)\b)?)'
                .'(?:\s+(?:@\s*)?(?<load>(?:\d+\s*[x×]\s*)?\d+(?:[.,]\d+)?\s*(?:kgs?|lbs?)?(?:\s*dbs?|\s*kbs?)?|bw|bodyweight))?'
                .'(?:.*?\brpe\s*(?<rpe>\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?))?/iu';

            if (! preg_match($pattern, $line, $m)) {
                continue;
            }

            if ($current === null) {
                $days[] = ['title' => 'Day A', 'week' => $week, 'rows' => []];
                $current = array_key_last($days);
            }

            $days[$current]['rows'][] = [
                'raw' => trim($m['name'], " \t-:–•*."),
                'sets' => min((int) $m['sets'], 20),
                'reps' => preg_replace('/\s+|reps?$/i', '', $m['reps']) ?: null,
                'load' => filled($m['load'] ?? null) ? $this->tidyLoad($m['load']) : null,
                'rpe' => filled($m['rpe'] ?? null) ? str_replace(' ', '', $m['rpe']) : null,
            ];
        }

        return array_values(array_filter($days, fn ($d) => $d['rows'] !== []));
    }

    protected function tidyLoad(string $load): string
    {
        $load = preg_replace('/(\d)\s*(kgs?|lbs?)/i', '$1 $2', trim($load)) ?? $load;
        $load = preg_replace('/\bkgs\b/i', 'kg', $load) ?? $load;

        return strtoupper($load) === 'BW' || strtolower($load) === 'bodyweight' ? 'BW' : $load;
    }

    /**
     * Find the closest library exercise for a name as written in an export.
     *
     * @return array{exercise_id: int|null, suggestion: string|null, confidence: string}
     */
    public function match(string $raw, User $coach): array
    {
        $needle = $this->normalize($raw);
        $best = null;
        $bestScore = 0.0;

        foreach (Exercise::query()->availableTo($coach)->get(['id', 'name']) as $exercise) {
            $candidate = $this->normalize($exercise->name);

            if ($candidate === $needle) {
                return ['exercise_id' => $exercise->id, 'suggestion' => $exercise->name, 'confidence' => 'high'];
            }

            similar_text($needle, $candidate, $percent);
            $tokens = array_unique(explode(' ', $needle));
            $candidateTokens = array_unique(explode(' ', $candidate));
            $overlap = count(array_intersect($tokens, $candidateTokens)) / max(count(array_unique([...$tokens, ...$candidateTokens])), 1) * 100;
            $score = max($percent, $overlap);

            if ($score > $bestScore) {
                [$best, $bestScore] = [$exercise, $score];
            }
        }

        $confidence = match (true) {
            $bestScore >= 85 => 'high',
            $bestScore >= 55 => 'medium',
            default => 'none',
        };

        return [
            'exercise_id' => $confidence === 'none' ? null : $best?->id,
            'suggestion' => $confidence === 'none' ? null : $best?->name,
            'confidence' => $confidence,
        ];
    }

    public function normalize(string $name): string
    {
        $name = Str::of($name)->lower()->ascii()
            ->replace(['1/2', 'half kneeling', 'half-kneeling', 'half kneel', '½'], 'half kneeling')
            ->replaceMatches('/[^a-z0-9 ]/', ' ')
            ->squish()
            ->toString();

        foreach (self::ABBREVIATIONS as $short => $long) {
            $name = preg_replace('/\b'.preg_quote($short, '/').'\b/', $long, $name) ?? $name;
        }

        // Drop words that describe the export, not the movement.
        $name = preg_replace('/\b(the|with|and|reps?|sets?)\b/', ' ', $name) ?? $name;

        return Str::squish($name);
    }

    /**
     * Read program files (one per client, named after the client) into the import.
     *
     * @param  array<int, array{name: string, contents: string}>  $files
     */
    public function addPrograms(Import $import, array $files): void
    {
        $coach = $import->coach;
        $clients = $coach->clients()->get(['id', 'name', 'email']);
        $programs = $import->programs ?? [];

        foreach ($files as $file) {
            $client = $this->clientForFile($file['name'], $clients);

            if (! $client) {
                $base = Str::of($file['name'])->beforeLast('.')->squish()->lower()->toString();
                $programs['unmatched:'.$base] = ['client_name' => $file['name'], 'days' => [], 'unreadable' => true, 'reason' => 'no_client'];

                continue;
            }

            $text = $this->programText($file['contents'], $file['name'], Demo::mayUseAi($coach->team_id));
            $days = $text ? $this->parseProgram($text) : [];

            $programs[$client->email] = $days === []
                ? ['client_name' => $client->name, 'days' => [], 'unreadable' => true, 'reason' => $text === null ? 'unreadable' : 'no_exercises']
                : ['client_name' => $client->name, 'days' => $days];
        }

        $import->programs = $programs;
        $import->matches = $this->buildMatches($import);
        $import->save();
    }

    /**
     * Match a file like "Maya Chen.pdf" or "maya_chen - week 6.txt" to exactly one client.
     * Whole-word matches only, so "Sam Leeson.txt" never lands on "Sam Lee"; ambiguous files are left for the coach.
     *
     * @param  Collection<int, User>  $clients
     */
    public function clientForFile(string $filename, Collection $clients): ?User
    {
        $base = Str::of($filename)->beforeLast('.')->replace(['_', '.'], ' ')->squish()->lower()->toString();

        $exact = $clients->first(fn (User $c) => Str::lower($c->name) === $base || Str::lower($c->email) === $base);

        if ($exact) {
            return $exact;
        }

        $candidates = $clients->filter(fn (User $c) => str_contains(Str::lower($c->name), ' ')
            && preg_match('/(^|[^\pL])'.preg_quote(Str::lower($c->name), '/').'($|[^\pL])/u', $base));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @return array<string, array{exercise_id: int|null, suggestion: string|null, confidence: string, used: int, resolved: bool}>
     */
    protected function buildMatches(Import $import): array
    {
        $existing = $import->matches ?? [];
        $usage = [];

        foreach ($import->programs ?? [] as $program) {
            foreach ($program['days'] as $day) {
                foreach ($day['rows'] as $row) {
                    $usage[$row['raw']][] = $program['client_name'];
                }
            }
        }

        $matches = [];

        foreach ($usage as $raw => $clients) {
            $matches[$raw] = $existing[$raw] ?? [...$this->match($raw, $import->coach), 'resolved' => false];
            $matches[$raw]['used'] = count(array_unique($clients));

            if (! isset($existing[$raw]) && $matches[$raw]['confidence'] === 'high') {
                $matches[$raw]['resolved'] = true;
            }
        }

        uasort($matches, fn ($a, $b) => [$a['resolved'], $b['used']] <=> [$b['resolved'], $a['used']]);

        return $matches;
    }

    /**
     * Build each client's imported program as week 1 of a Spotter program.
     */
    public function finish(Import $import): int
    {
        return DB::transaction(function () use ($import) {
            // Lock and re-check so a double-click can't build every program twice.
            $import = Import::query()->lockForUpdate()->findOrFail($import->id);

            if ($import->status === 'finished') {
                return 0;
            }

            $coach = $import->coach;
            $created = 0;
            $startsOn = today()->startOfWeek()->addWeek();

            foreach ($import->programs ?? [] as $email => $program) {
                $client = $coach->clients()->where('email', $email)->first();

                if (! $client || ($program['unreadable'] ?? false)) {
                    continue;
                }

                $weeks = collect($program['days'])->groupBy(fn ($day) => $day['week'] ?? 1)->sortKeys();

                $spotterProgram = Program::create([
                    'coach_id' => $client->coach_id ?? $coach->id,
                    'client_id' => $client->id,
                    'title' => __('Imported from :source', ['source' => $import->sourceLabel()]),
                    'starts_on' => $startsOn,
                    'planned_weeks' => max(6, (int) $weeks->keys()->max()),
                ]);

                // The first imported week becomes the program blueprint, repeated every week…
                $firstWeek = $weeks->first()->values()->take(7);
                $weekdays = ProgramScheduler::DEFAULT_WEEKDAYS[max(1, $firstWeek->count())];
                $blueprintDays = $firstWeek->map(fn ($day, $index) => $this->createBlueprintDay($import, $spotterProgram, $index, $weekdays[$index], $day));

                app(ProgramScheduler::class)->sync($spotterProgram);

                // …and any later weeks in the export keep exactly what the old platform had.
                foreach ($weeks->slice(1) as $week => $days) {
                    foreach ($days->values() as $index => $day) {
                        $this->overrideSession($import, $spotterProgram, (int) $week, $index, $blueprintDays->get($index), $day);
                    }

                    // A later week with fewer days than the first: the extra days are skipped that week.
                    foreach ($blueprintDays->slice($days->count()) as $extraDay) {
                        $session = Workout::query()->where('program_day_id', $extraDay->id)->where('week', (int) $week)->first();
                        $session ? app(ProgramScheduler::class)->skip($session) : null;
                    }
                }

                $created++;
            }

            $import->update([
                'status' => 'finished',
                'finished_at' => now(),
                'stats' => ['programs' => $created] + ($import->stats ?? []),
            ]);

            return $created;
        });
    }

    /**
     * @param  array{title: string, rows: array<int, array<string, mixed>>}  $day
     */
    protected function createBlueprintDay(Import $import, Program $program, int $index, int $weekday, array $day): ProgramDay
    {
        $blueprint = $program->days()->create(['position' => $index + 1, 'title' => $day['title'], 'weekday' => $weekday]);

        foreach ($this->importedRows($import, $day) as $position => $row) {
            $blueprint->exercises()->create($row + ['position' => $position]);
        }

        return $blueprint;
    }

    /**
     * Replace one scheduled session with exactly what the export had for that week.
     *
     * @param  array{title: string, rows: array<int, array<string, mixed>>}  $day
     */
    protected function overrideSession(Import $import, Program $program, int $week, int $index, ?ProgramDay $blueprintDay, array $day): void
    {
        $session = $blueprintDay
            ? Workout::query()->where('program_day_id', $blueprintDay->id)->where('week', $week)->first()
            : null;

        $session ??= Workout::create([
            'coach_id' => $program->coach_id,
            'client_id' => $program->client_id,
            'program_id' => $program->id,
            'week' => $week,
            'day' => $index + 1,
            'scheduled_on' => $program->dateFor($week, ProgramScheduler::DEFAULT_WEEKDAYS[7][min($index, 6)]),
            'title' => $day['title'],
        ]);

        $session->forceFill(['title' => $day['title'], 'customized_at' => now()])->save();
        $session->items()->delete();

        $links = $blueprintDay?->exercises()->pluck('id', 'exercise_id') ?? collect();

        foreach ($this->importedRows($import, $day) as $position => $row) {
            $session->items()->create($row + ['position' => $position, 'program_exercise_id' => $links->get($row['exercise_id'])]);
        }
    }

    /**
     * Rows of an imported day whose exercise name was matched, as prescriptions.
     *
     * @param  array{rows: array<int, array<string, mixed>>}  $day
     * @return array<int, array<string, mixed>>
     */
    protected function importedRows(Import $import, array $day): array
    {
        return collect($day['rows'])
            ->map(fn ($row) => [
                'exercise_id' => $import->matches[$row['raw']]['exercise_id'] ?? null,
                'sets' => $row['sets'],
                'reps' => $row['reps'],
                'load' => $row['load'],
                'target_rpe' => $row['rpe'],
                'progression' => filled($row['load']) && $row['load'] !== 'BW' ? 'load' : 'none',
            ])
            ->filter(fn ($row) => $row['exercise_id'] !== null)
            ->values()
            ->all();
    }
}
