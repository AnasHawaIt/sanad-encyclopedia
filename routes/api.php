<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        DB::select('select 1');

        return response()->json(['status' => 'ok']);
    });
});
require __DIR__.'/api_v1.php';
