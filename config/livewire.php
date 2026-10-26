<?php

// Only the settings Spotter changes; everything else uses Livewire's defaults.
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Form-check videos from phones are large. Keep in step with PHP's upload_max_filesize on the server.
        'rules' => ['required', 'file', 'max:102400'], // 100 MB
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 10,
        'cleanup' => true,
    ],
];
