<?php

declare(strict_types=1);

return [
    'max_mb' => (int) env('UPLOAD_MAX_MB', 5),
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf', 'txt'],
    'mime_types' => [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg', 'image/jpg'],
        'png' => ['image/png'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
    ],
];
