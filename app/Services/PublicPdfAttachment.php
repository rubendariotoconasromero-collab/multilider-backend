<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PublicPdfAttachment
{
    public static function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, 'public');
        abort_unless(is_string($path) && $path !== '', 500, 'No se pudo guardar el documento.');

        return Storage::url($path);
    }

    public static function retireAfterCommit(?string $previousUrl, string $directory): void
    {
        $path = ltrim(str_replace('/storage', '', $previousUrl ?? ''), '/');
        if (dirname($path) !== $directory || basename($path) === '') {
            return;
        }

        DB::afterCommit(static function () use ($path): void {
            Storage::disk('public')->delete($path);
        });
    }
}
