<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\FormCheck;
use App\Models\Message;
use App\Models\User;
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
        $this->authorizeFor($request, $formCheck->client);

        return $this->stream($formCheck->video_path);
    }

    public function checkInPhoto(Request $request, CheckIn $checkIn, int $index): Response
    {
        $this->authorizeFor($request, $checkIn->client);

        $path = $checkIn->photos[$index] ?? abort(404);

        return $this->stream($path);
    }

    public function messagePhoto(Request $request, Message $message, int $index): Response
    {
        $this->authorizeFor($request, $message->client);

        $path = $message->photos[$index] ?? abort(404);

        return $this->stream($path);
    }

    protected function authorizeFor(Request $request, User $client): void
    {
        abort_unless($request->user()->id === $client->id || $request->user()->managesClient($client), 403);
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
