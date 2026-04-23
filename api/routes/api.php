<?php

use App\Modules\Core\Http\Controllers\ProjectController;
use App\Modules\Tasks\Http\Controllers\BoardController;
use App\Modules\Tasks\Http\Controllers\LabelController;
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
            'user' => $request->attributes->get('user'),
            'claims' => $request->attributes->get('supabase_user'),
        ]);
    });

    Route::apiResource('projects', ProjectController::class);

    // Tasks / Board
    Route::get('projects/{project}/columns', [BoardController::class, 'index']);
    Route::post('projects/{project}/columns', [BoardController::class, 'storeColumn']);
    Route::patch('columns/{column}', [BoardController::class, 'updateColumn']);
    Route::delete('columns/{column}', [BoardController::class, 'destroyColumn']);

    Route::post('columns/{column}/cards', [BoardController::class, 'storeCard']);
    Route::get('cards/{card}', [BoardController::class, 'showCard']);
    Route::patch('cards/{card}', [BoardController::class, 'updateCard']);
    Route::delete('cards/{card}', [BoardController::class, 'destroyCard']);

    Route::post('projects/{project}/board/move', [BoardController::class, 'moveCard']);

    // Dependencies
    Route::post('cards/{card}/dependencies', [BoardController::class, 'addDependency']);
    Route::delete('cards/{card}/dependencies/{dep}', [BoardController::class, 'removeDependency']);

    // Labels
    Route::get('projects/{project}/labels', [LabelController::class, 'index']);
    Route::post('projects/{project}/labels', [LabelController::class, 'store']);
    Route::patch('labels/{label}', [LabelController::class, 'update']);
    Route::delete('labels/{label}', [LabelController::class, 'destroy']);
    Route::post('cards/{card}/labels', [LabelController::class, 'attachToCard']);
    Route::delete('cards/{card}/labels/{label}', [LabelController::class, 'detachFromCard']);
});
