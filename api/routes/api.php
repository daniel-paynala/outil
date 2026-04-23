<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]);
});

Route::middleware('supabase.auth')->group(function () {
    Route::get('/me', function (Request $request) {
        return response()->json([
            'id' => $request->attributes->get('supabase_user_id'),
            'claims' => $request->attributes->get('supabase_user'),
        ]);
    });
});
