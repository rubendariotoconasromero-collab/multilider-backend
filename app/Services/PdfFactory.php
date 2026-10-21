<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use RuntimeException;

class PdfFactory
{
    public static function make(array $config = []): Mpdf
    {
        $directory = storage_path('app/mpdf-tmp');
        File::ensureDirectoryExists($directory, 0770);

        if (!is_writable($directory)) {
            throw new RuntimeException('La carpeta temporal de PDF no permite escritura.');
        }

        return new Mpdf(array_merge($config, ['tempDir' => $directory]));
    }
}
