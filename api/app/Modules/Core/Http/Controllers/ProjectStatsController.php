<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Activity\Models\ActivityLog;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/projects/{project}/stats
 * Renvoie un résumé pour la page Aperçu : compteurs tâches, échéances,
 * temps cumulé, dernière activité.
 */
class ProjectStatsController extends Controller
{
    public function show(Request $request, Project $project): JsonResponse
    {
        $userId = $request->attributes->get('supabase_user_id') ?? abort(401);
        if (! $project->hasMember($userId)) {
            abort(403);
        }

        // Tâches
        $cardsBase = Card::where('project_id', $project->id)->whereNull('deleted_at');
        $totalCards = (clone $cardsBase)->count();
        $openCards = (clone $cardsBase)->whereNull('completed_at')->count();
        $doneCards = $totalCards - $openCards;

        $now = now();
        $overdueCards = (clone $cardsBase)
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->where('due_date', '<', $now)
            ->count();

        $upcomingCards = (clone $cardsBase)
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$now, $now->copy()->addDays(7)])
            ->count();

        // Tâches par priorité (open seulement)
        $byPriority = (clone $cardsBase)
            ->whereNull('completed_at')
            ->whereNotNull('priority')
            ->select('priority', DB::raw('count(*) as count'))
            ->groupBy('priority')
            ->pluck('count', 'priority');

        // Tâches par assignee (open seulement, top 5)
        $byAssignee = (clone $cardsBase)
            ->whereNull('completed_at')
            ->whereNotNull('assigned_to')
            ->select('assigned_to', DB::raw('count(*) as count'))
            ->groupBy('assigned_to')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(function ($r) {
                $u = User::find($r->assigned_to);

                return [
                    'user_id' => $r->assigned_to,
                    'email' => $u?->email,
                    'name' => $u?->name,
                    'avatar_url' => $u?->avatar_url,
                    'count' => (int) $r->count,
                ];
            });

        // Prochaines échéances (5 prochaines, ouvertes)
        $upcomingDeadlines = (clone $cardsBase)
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->where('due_date', '>=', $now->copy()->subDay())
            ->orderBy('due_date')
            ->limit(5)
            ->get(['id', 'title', 'due_date', 'priority', 'assigned_to'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'due_date' => $c->due_date?->toIso8601String(),
                'priority' => $c->priority,
                'is_overdue' => $c->due_date && $c->due_date->isPast(),
            ]);

        // Activité récente (10 dernières)
        $recentActivity = ActivityLog::where('project_id', $project->id)
            ->with('actor:id,email,name,avatar_path')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Temps cumulé (colonne `seconds` dans time_entries)
        $totalSeconds = 0;
        if (\Schema::hasTable('time_entries')) {
            $totalSeconds = (int) DB::table('time_entries')
                ->where('project_id', $project->id)
                ->sum('seconds');
        }

        // Compteurs annexes
        $documentsCount = \Schema::hasTable('project_files')
            ? DB::table('project_files')
                ->where('project_id', $project->id)
                ->count()
            : 0;

        $docsCount = \Schema::hasTable('doc_pages')
            ? DB::table('doc_pages')
                ->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->count()
            : 0;

        $decisionsCount = \Schema::hasTable('decisions')
            ? DB::table('decisions')
                ->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->count()
            : 0;

        $vaultCount = \Schema::hasTable('vault_entries')
            ? DB::table('vault_entries')
                ->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->count()
            : 0;

        $membersCount = $project->projectMembers()->count();

        return response()->json([
            'cards' => [
                'total' => $totalCards,
                'open' => $openCards,
                'done' => $doneCards,
                'overdue' => $overdueCards,
                'upcoming_7d' => $upcomingCards,
                'by_priority' => $byPriority,
                'by_assignee' => $byAssignee,
            ],
            'upcoming_deadlines' => $upcomingDeadlines,
            'recent_activity' => $recentActivity,
            'time_seconds' => $totalSeconds,
            'counts' => [
                'members' => $membersCount,
                'documents' => $documentsCount,
                'docs' => $docsCount,
                'decisions' => $decisionsCount,
                'vault_entries' => $vaultCount,
            ],
        ]);
    }
}
