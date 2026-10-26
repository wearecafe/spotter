<?php

namespace App\Jobs;

use App\Models\InboxItem;
use App\Services\CoachAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fill in an inbox item's summary, draft reply and suggestion.
 */
class DraftInboxItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public InboxItem $item) {}

    public function handle(CoachAssistant $assistant): void
    {
        $item = $this->item->fresh(['client', 'coach', 'subject']);

        if (! $item || $item->status === 'done' || ! $item->client) {
            return;
        }

        $draft = $assistant->draft($item);

        $item->update([
            'summary' => $draft['summary'],
            'draft_reply' => $draft['reply'],
            'suggestion' => $draft['suggestion'],
            'drafted_by' => $draft['by'],
        ]);
    }
}
