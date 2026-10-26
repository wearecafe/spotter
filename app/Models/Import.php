<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A coach bringing clients and programs over from another platform.
 *
 * @property int $id
 * @property int $coach_id
 * @property string $source
 * @property string $status
 * @property array<string, array{client_name: string, days: array<int, array{title: string, rows: array<int, array<string, string|null>>}>, unreadable?: bool}>|null $programs
 * @property array<string, array{exercise_id: int|null, suggestion: string|null, confidence: string, used: int, resolved: bool}>|null $matches
 * @property array<string, int>|null $stats
 * @property CarbonInterface|null $finished_at
 */
#[Fillable(['coach_id', 'source', 'status', 'programs', 'matches', 'stats', 'finished_at'])]
class Import extends Model
{
    public const SOURCES = [
        'truecoach' => 'TrueCoach',
        'trainerize' => 'ABC Trainerize',
        'sheets' => 'Google Sheets or Excel',
        'fresh' => 'Starting fresh',
    ];

    protected function casts(): array
    {
        return [
            'programs' => 'array',
            'matches' => 'array',
            'stats' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public function unresolvedCount(): int
    {
        return collect($this->matches ?? [])->where('resolved', false)->count();
    }
}
