<?php

use Illuminate\Support\Facades\Route;

// Versioned API routes: each version lives in its own file so a future /api/v2
// can be added without touching or breaking the existing /api/v1 contract.
Route::prefix('v1')->name('v1.')->group(base_path('routes/api_v1.php'));
