<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Exercise;
use App\Models\Import;
use App\Models\Program;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Http\UploadedFile;
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
     * Text of an uploaded program: plain text as-is, PDFs read by Claude when configured.
     */
    public function programText(UploadedFile $file): ?string
    {
        $extension = Str::lower($file->getClientOriginalExtension());

        if ($extension === 'pdf' || $file->getMimeType() === 'application/pdf') {
            return $this->assistant->extractProgramText($file->get());
        }

        $text = $file->get();

        return is_string($text) && mb_check_encoding($text, 'UTF-8') ? $text : null;
    }

    /**
     * Turn exported program text into days of exercise rows.
     *
     * Handles lines like "Back Squat 4x5 75kg rpe 7-8", "BSS 3 x 8 16kg DBs", "Plank 3x30s".
     *
     * @return array<int, array{title: string, rows: array<int, array{raw: string, sets: int, reps: string|null, load: string|null, rpe: string|null}>}>
     */
    public function parseProgram(string $text): array
    {
        $days = [];
        $current = null;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(day|jour|session|workout|séance|seance)\b/iu', $line)) {
                $days[] = ['title' => Str::limit($line, 80, ''), 'rows' => []];
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
                $days[] = ['title' => 'Day A', 'rows' => []];
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
     * Read uploaded program files, one per client (named after the client), into the import.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function addPrograms(Import $import, array $files): void
    {
        $coach = $import->coach;
        $clients = $coach->clients()->get(['id', 'name', 'email']);
        $programs = $import->programs ?? [];

        foreach ($files as $file) {
            $base = Str::of($file->getClientOriginalName())->beforeLast('.')->replace(['_', '-'], ' ')->squish()->lower()->toString();
            $client = $clients->first(fn (User $c) => Str::lower($c->name) === $base || Str::lower($c->email) === $base || Str::contains($base, Str::lower($c->name)));

            if (! $client) {
                $programs['unmatched:'.$base] = ['client_name' => $file->getClientOriginalName(), 'days' => [], 'unreadable' => true, 'reason' => 'no_client'];

                continue;
            }

            $text = $this->programText($file);
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
            $coach = $import->coach;
            $created = 0;
            $startsOn = today()->startOfWeek()->addWeek();

            foreach ($import->programs ?? [] as $email => $program) {
                $client = $coach->clients()->where('email', $email)->first();

                if (! $client || ($program['unreadable'] ?? false)) {
                    continue;
                }

                $spotterProgram = Program::create([
                    'coach_id' => $coach->id,
                    'client_id' => $client->id,
                    'title' => __('Imported from :source', ['source' => $import->sourceLabel()]),
                    'starts_on' => $startsOn,
                    'planned_weeks' => 6,
                ]);

                foreach ($program['days'] as $index => $day) {
                    $workout = Workout::create([
                        'coach_id' => $coach->id,
                        'client_id' => $client->id,
                        'program_id' => $spotterProgram->id,
                        'week' => 1,
                        'day' => $index + 1,
                        'title' => $day['title'],
                        'scheduled_on' => $startsOn->addDays($index * intdiv(7, max(count($program['days']), 1))),
                    ]);

                    foreach ($day['rows'] as $position => $row) {
                        $exerciseId = $import->matches[$row['raw']]['exercise_id'] ?? null;

                        if (! $exerciseId) {
                            continue;
                        }

                        $workout->items()->create([
                            'exercise_id' => $exerciseId,
                            'position' => $position,
                            'sets' => $row['sets'],
                            'reps' => $row['reps'],
                            'load' => $row['load'],
                            'target_rpe' => $row['rpe'],
                            'progression' => filled($row['load']) && $row['load'] !== 'BW' ? 'load' : 'none',
                        ]);
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
}
