<?php

return [
    'preview_bytes' => (int) env('MAIL_PREVIEW_BYTES', 10 * 1024 * 1024),
    'download_bytes' => (int) env('MAIL_DOWNLOAD_BYTES', 50 * 1024 * 1024),
    'rows' => 100,
    'columns' => 30,
    'zip_bytes' => 50 * 1024 * 1024,
    'zip_entries' => 2000,
    'output_bytes' => 500000,
];
