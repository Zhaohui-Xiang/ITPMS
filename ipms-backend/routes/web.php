<?php

use Illuminate\Support\Facades\Route;

// SPA entry - all non-API routes return the Vue SPA
Route::get('/{any}', function () {
    return file_get_contents(public_path('index.html'));
})->where('any', '^(?!api|sanctum).*$');

