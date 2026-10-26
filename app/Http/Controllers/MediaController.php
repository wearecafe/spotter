<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\FormCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves private client media (form-check videos, progress photos) to the client and their coach only.
 */
class MediaController extends Controller
{
    public function formCheck(Request $request, FormCheck $formCheck): Response
    {
        $this->authorizeFor($request, $formCheck->client_id, $formCheck->client->coach_id);

        return $this->stream($formCheck->video_path);
    }

    public function checkInPhoto(Request $request, CheckIn $checkIn, int $index): Response
    {
        $this->authorizeFor($request, $checkIn->client_id, $checkIn->client->coach_id);

        $path = $checkIn->photos[$index] ?? abort(404);

        return $this->stream($path);
    }

    protected function authorizeFor(Request $request, int $clientId, ?int $coachId): void
    {
        abort_unless(in_array($request->user()->id, [$clientId, $coachId], true), 403);
    }

    protected function stream(string $path): Response
    {
        $diskName = config('filesystems.media_disk', config('filesystems.default'));
        $disk = Storage::disk($diskName);

        abort_unless($disk->exists($path), 404);

        // Object storage: hand the browser a short-lived signed URL (supports video seeking).
        if (config("filesystems.disks.$diskName.driver") === 's3') {
            return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(30)));
        }

        // Local disk: BinaryFileResponse handles byte ranges, which iOS Safari needs to play video.
        return response()->file($disk->path($path), ['Cache-Control' => 'private, max-age=3600']);
    }
}
