<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing in the coach's inbox. The summary, draft reply and suggestion are drafts:
 * nothing reaches the client until the coach approves it.
 *
 * @property int $id
 * @property int $coach_id
 * @property int $client_id
 * @property string $type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int $priority
 * @property string $headline
 * @property string|null $summary
 * @property string|null $draft_reply
 * @property array{workout_exercise_id: int, field: string, from: string|null, to: string, reason: string, applied?: bool}|null $suggestion
 * @property string|null $drafted_by
 * @property string $status
 * @property CarbonInterface|null $snoozed_until
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface $created_at
 */
#[Fillable(['coach_id', 'client_id', 'type', 'subject_type', 'subject_id', 'priority', 'headline', 'summary', 'draft_reply', 'suggestion', 'drafted_by', 'status', 'snoozed_until', 'resolved_at'])]
class InboxItem extends Model
{
    /** Base priority per type; higher is more urgent. */
    public const PRIORITY = [
        'check_in' => 70,
        'form_check' => 65,
        'missed' => 80,
        'feedback' => 55,
        'message' => 60,
        'pr' => 30,
    ];

    public const LABELS = [
        'check_in' => 'Check-in',
        'form_check' => 'Form check',
        'missed' => 'Missed sessions',
        'feedback' => 'Workout feedback',
        'message' => 'Message',
        'pr' => 'Personal record',
    ];

    protected function casts(): array
    {
        return [
            'suggestion' => 'array',
            'snoozed_until' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** @return BelongsTo<User, $this> */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function label(): string
    {
        return __(self::LABELS[$this->type] ?? ucfirst($this->type));
    }

    /**
     * Items the coach should see now: open, or snoozed until a time that has passed.
     *
     * @param  Builder<InboxItem>  $query
     */
    public function scopeActionable(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('status', 'open')
            ->orWhere(fn (Builder $q) => $q->where('status', 'snoozed')->where('snoozed_until', '<=', now())));
    }

    /**
     * Most urgent first; older items of equal priority rise.
     *
     * @param  Builder<InboxItem>  $query
     */
    public function scopeRanked(Builder $query): void
    {
        $query->orderByDesc('priority')->orderBy('created_at');
    }
}
