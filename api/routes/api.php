<?php

use App\Modules\Core\Http\Controllers\ProjectController;
use App\Modules\Files\Http\Controllers\ProjectFileController;
use App\Modules\Tasks\Http\Controllers\BoardController;
use App\Modules\Tasks\Http\Controllers\LabelController;
use App\Modules\Tasks\Http\Controllers\MyTasksController;
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

    Route::get('me/tasks', [MyTasksController::class, 'index']);

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

    // Files (Supabase Storage)
    Route::get('projects/{project}/files', [ProjectFileController::class, 'index']);
    Route::post('projects/{project}/files', [ProjectFileController::class, 'store']);
    Route::get('files/{file}', [ProjectFileController::class, 'show']);
    Route::delete('files/{file}', [ProjectFileController::class, 'destroy']);
});
