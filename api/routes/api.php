<?php

use App\Modules\Activity\Http\Controllers\ActivityController;
use App\Modules\Adr\Http\Controllers\DecisionController;
use App\Modules\Calls\Http\Controllers\CallController;
use App\Modules\Core\Http\Controllers\AdminUserController;
use App\Modules\Core\Http\Controllers\PreferencesController;
use App\Modules\Core\Http\Controllers\ProjectController;
use App\Modules\Core\Http\Controllers\UserDirectoryController;
use App\Modules\Docs\Http\Controllers\DocController;
use App\Modules\Files\Http\Controllers\ProjectFileController;
use App\Modules\Github\Http\Controllers\GithubController;
use App\Modules\Mail\Http\Controllers\MailController;
use App\Modules\Messagerie\Http\Controllers\ConversationController;
use App\Modules\Messagerie\Http\Controllers\MessageController;
use App\Modules\Monitoring\Http\Controllers\IntegrityController;
use App\Modules\Monitoring\Http\Controllers\PushHealthController;
use App\Modules\Monitoring\Http\Controllers\QueueHealthController;
use App\Modules\Monitoring\Http\Controllers\SearchHealthController;
use App\Modules\Monitoring\Http\Controllers\ServerErrorsController;
use App\Modules\Roadmap\Http\Controllers\RoadmapController;
use App\Modules\Search\Http\Controllers\SearchController;
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

// Sonde de la file d'attente, volontairement hors authentification : elle ne
// divulgue que des compteurs, et doit rester consultable quand c'est
// justement l'authentification qui est en panne.
Route::get('/monitoring/queue', [QueueHealthController::class, 'show']);
Route::get('/monitoring/search', [SearchHealthController::class, 'show']);
Route::get('/monitoring/push', [PushHealthController::class, 'show']);

// Intégrité de l'installation : versions, configuration, schéma, stockage.
// Authentifiée — elle nomme des adresses de services et l'état du disque, qui
// n'ont pas à circuler librement.
Route::middleware('supabase.auth')->get(
    '/monitoring/integrity',
    [IntegrityController::class, 'show'],
);

// Réparations. Réservées aux administrateurs : elles exécutent des commandes
// figées dans le code — rien de ce que l'appelant envoie n'atteint le shell —
// mais elles modifient l'état du serveur.
Route::middleware(['supabase.auth', 'admin'])->group(function () {
    Route::post('/monitoring/search/repair', [SearchHealthController::class, 'repair']);
    Route::post('/monitoring/queue/flush', [QueueHealthController::class, 'flush']);
});

// Erreurs du serveur. Réservée aux administrateurs : même expurgé, un journal
// applicatif reste la chose la plus indiscrète d'une installation.
Route::middleware(['supabase.auth', 'admin'])->get(
    '/monitoring/errors',
    [ServerErrorsController::class, 'show'],
);

Route::middleware('supabase.auth')->group(function () {
    // Appels internes. Le serveur ne fait que sonner : la voix va d'un
    // téléphone à l'autre en direct, la signalisation passe par Supabase.
    Route::post('calls/devices', [CallController::class, 'registerDevice']);
    Route::post('calls/ring', [CallController::class, 'ring']);
    Route::get('calls/turn', [CallController::class, 'turnCredentials']);
    Route::get('calls', [CallController::class, 'history']);
    Route::post('calls', [CallController::class, 'log']);

    // Rattachement d'une boîte Google Workspace. Trois opérations seulement :
    // lire et écrire du courrier se fait de l'appareil à Gmail directement,
    // sans passer par notre infrastructure.
    Route::get('mail/status', [MailController::class, 'status']);
    Route::post('mail/connect', [MailController::class, 'connect']);
    Route::delete('mail/connect', [MailController::class, 'disconnect']);

    Route::get('/me', function (Request $request) {
        return response()->json([
            'id' => $request->attributes->get('supabase_user_id'),
            'user' => $request->attributes->get('user'),
            'claims' => $request->attributes->get('supabase_user'),
        ]);
    });

    Route::apiResource('projects', ProjectController::class);

    // Annuaire de l'équipe — nécessaire pour désigner quelqu'un (discussion,
    // assignation). En lecture seule ; la gestion des comptes reste sous /admin.
    Route::get('users', [UserDirectoryController::class, 'index']);

    // Préférences de notification, par catégorie.
    Route::get('me/preferences', [PreferencesController::class, 'show']);
    Route::patch('me/preferences', [PreferencesController::class, 'update']);

    Route::get('me/tasks', [MyTasksController::class, 'index']);
    Route::get('me/archive', [MyTasksController::class, 'archive']);
    Route::get('search', [SearchController::class, 'index']);

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

    // GitHub (multi-repos per project, pull model)
    Route::get('projects/{project}/github/repos', [GithubController::class, 'index']);
    Route::post('projects/{project}/github/repos', [GithubController::class, 'store']);
    Route::patch('github/repos/{repo}', [GithubController::class, 'update']);
    Route::delete('github/repos/{repo}', [GithubController::class, 'destroy']);
    Route::post('github/repos/{repo}/sync', [GithubController::class, 'sync']);
    Route::get('projects/{project}/github/commits', [GithubController::class, 'commits']);

    // Admin — user management (admin role required)
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('users', [AdminUserController::class, 'index']);
        Route::post('users', [AdminUserController::class, 'store']);
        Route::patch('users/{user}', [AdminUserController::class, 'update']);
        Route::delete('users/{user}', [AdminUserController::class, 'destroy']);
    });

    // Messagerie interne — conversations et messages.
    // Aucune notion de projet obligatoire : une conversation peut être
    // transverse. L'appartenance à la conversation est la seule autorisation.
    Route::get('conversations', [ConversationController::class, 'index']);
    Route::post('conversations', [ConversationController::class, 'store']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
    Route::patch('conversations/{conversation}', [ConversationController::class, 'update']);
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'markRead']);
    Route::post('conversations/{conversation}/members', [ConversationController::class, 'addMembers']);
    Route::delete('conversations/{conversation}/members/{userId}', [ConversationController::class, 'removeMember']);

    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);
    // Bucket privé : l'URL est signée à la demande, jamais stockée.
    Route::get('message-attachments/{attachment}/url', [MessageController::class, 'attachmentUrl']);

    // Roadmap (items + releases, multi-views)
    Route::get('projects/{project}/roadmap', [RoadmapController::class, 'index']);
    Route::post('projects/{project}/roadmap', [RoadmapController::class, 'store']);
    Route::get('roadmap-items/{item}', [RoadmapController::class, 'show']);
    Route::patch('roadmap-items/{item}', [RoadmapController::class, 'update']);
    Route::delete('roadmap-items/{item}', [RoadmapController::class, 'destroy']);
    Route::post('projects/{project}/roadmap/move', [RoadmapController::class, 'move']);
    Route::post('roadmap-items/{item}/cards', [RoadmapController::class, 'attachCard']);
    Route::delete('roadmap-items/{item}/cards/{cardId}', [RoadmapController::class, 'detachCard']);
    // Releases
    Route::post('projects/{project}/releases', [RoadmapController::class, 'storeRelease']);
    Route::patch('releases/{release}', [RoadmapController::class, 'updateRelease']);
    Route::delete('releases/{release}', [RoadmapController::class, 'destroyRelease']);
});
