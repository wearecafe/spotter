<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\ProgramImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read uploaded program files in the background (PDFs go through Claude and can take a while).
 * Files are stored on the media disk so any worker can reach them, and deleted once read.
 */
class ProcessImportFiles implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /**
     * @param  array<int, array{name: string, path: string}>  $files
     */
    public function __construct(public Import $import, public array $files) {}

    /**
     * Store uploads on the media disk and queue them for reading.
     *
     * @param  array<int, UploadedFile>  $uploads
     */
    public static function start(Import $import, array $uploads): void
    {
        $disk = config('filesystems.media_disk');

        $files = collect($uploads)->map(fn (UploadedFile $file) => [
            'name' => $file->getClientOriginalName(),
            'path' => $file->store('imports/'.$import->id, $disk),
        ])->all();

        $import->update(['status' => 'processing']);

        static::dispatch($import, $files);
    }

    public function handle(ProgramImporter $importer): void
    {
        $disk = Storage::disk(config('filesystems.media_disk'));

        $contents = collect($this->files)
            ->map(fn (array $file) => ['name' => $file['name'], 'contents' => (string) $disk->get($file['path'])])
            ->all();

        $importer->addPrograms($this->import->fresh(), $contents);

        $this->import->update(['status' => 'review']);
        $this->cleanUp();
    }

    public function failed(?Throwable $e): void
    {
        $this->import->update(['status' => 'review']);
        $this->cleanUp();
    }

    protected function cleanUp(): void
    {
        Storage::disk(config('filesystems.media_disk'))->delete(collect($this->files)->pluck('path')->all());
    }
}
