<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        DB::select('select 1');

        return response()->json(['status' => 'ok']);
    });
});
require __DIR__.'/api_v1.php';
