<?php

use App\Modules\Activity\Http\Controllers\ActivityController;
use App\Modules\Adr\Http\Controllers\DecisionController;
use App\Modules\Core\Http\Controllers\ProjectController;
use App\Modules\Docs\Http\Controllers\DocController;
use App\Modules\Files\Http\Controllers\ProjectFileController;
use App\Modules\Tasks\Http\Controllers\BoardController;
use App\Modules\Tasks\Http\Controllers\CommentController;
use App\Modules\Tasks\Http\Controllers\LabelController;
use App\Modules\Tasks\Http\Controllers\MyTasksController;
use App\Modules\Time\Http\Controllers\TimeController;
use App\Modules\Vault\Http\Controllers\VaultController;
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

    // Card comments
    Route::get('cards/{card}/comments', [CommentController::class, 'index']);
    Route::post('cards/{card}/comments', [CommentController::class, 'store']);
    Route::patch('comments/{comment}', [CommentController::class, 'update']);
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

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

    // Documentation (markdown pages with light revisions)
    Route::get('projects/{project}/docs', [DocController::class, 'index']);
    Route::post('projects/{project}/docs', [DocController::class, 'store']);
    Route::get('docs/{page}', [DocController::class, 'show']);
    Route::patch('docs/{page}', [DocController::class, 'update']);
    Route::delete('docs/{page}', [DocController::class, 'destroy']);
    Route::get('docs/{page}/revisions', [DocController::class, 'revisions']);
    Route::get('docs/revisions/{revision}', [DocController::class, 'showRevision']);
    Route::post('docs/{page}/restore/{revision}', [DocController::class, 'restoreRevision']);

    // Vault (encrypted secrets + audit log)
    Route::get('projects/{project}/vault', [VaultController::class, 'index']);
    Route::post('projects/{project}/vault', [VaultController::class, 'store']);
    Route::get('vault/{entry}', [VaultController::class, 'show']);
    Route::patch('vault/{entry}', [VaultController::class, 'update']);
    Route::delete('vault/{entry}', [VaultController::class, 'destroy']);
    Route::get('vault/{entry}/reveal', [VaultController::class, 'reveal']);
    Route::get('vault/{entry}/log', [VaultController::class, 'accessLog']);

    // Time tracking
    Route::get('time/running', [TimeController::class, 'running']);
    Route::get('projects/{project}/time', [TimeController::class, 'index']);
    Route::post('projects/{project}/time', [TimeController::class, 'store']);
    Route::post('projects/{project}/time/start', [TimeController::class, 'start']);
    Route::post('time/{entry}/stop', [TimeController::class, 'stop']);
    Route::patch('time/{entry}', [TimeController::class, 'update']);
    Route::delete('time/{entry}', [TimeController::class, 'destroy']);

    // Decisions (ADR)
    Route::get('projects/{project}/decisions', [DecisionController::class, 'index']);
    Route::post('projects/{project}/decisions', [DecisionController::class, 'store']);
    Route::get('decisions/{decision}', [DecisionController::class, 'show']);
    Route::patch('decisions/{decision}', [DecisionController::class, 'update']);
    Route::delete('decisions/{decision}', [DecisionController::class, 'destroy']);

    // Activity log
    Route::get('projects/{project}/activity', [ActivityController::class, 'index']);
});
