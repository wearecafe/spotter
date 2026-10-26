<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\FormCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private client media (form-check videos, progress photos) to the client and their coach only.
 */
class MediaController extends Controller
{
    public function formCheck(Request $request, FormCheck $formCheck): StreamedResponse
    {
        $this->authorizeFor($request, $formCheck->client_id, $formCheck->client->coach_id);

        return $this->stream($formCheck->video_path);
    }

    public function checkInPhoto(Request $request, CheckIn $checkIn, int $index): StreamedResponse
    {
        $this->authorizeFor($request, $checkIn->client_id, $checkIn->client->coach_id);

        $path = $checkIn->photos[$index] ?? abort(404);

        return $this->stream($path);
    }

    protected function authorizeFor(Request $request, int $clientId, ?int $coachId): void
    {
        abort_unless(in_array($request->user()->id, [$clientId, $coachId], true), 403);
    }

    protected function stream(string $path): StreamedResponse
    {
        $disk = Storage::disk(config('filesystems.media_disk', config('filesystems.default')));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
