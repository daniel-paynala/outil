<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    /**
     * GET /api/users/search?q=...&exclude_project={id}
     * Tout user authentifié peut rechercher (pour ajouter à un projet).
     * Renvoie au max 10 résultats avec uniquement id/email/name.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $excludeProject = $request->query('exclude_project');

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $query = User::query()
            ->where(function ($w) use ($q) {
                $w->where('email', 'ilike', "%{$q}%")
                    ->orWhere('name', 'ilike', "%{$q}%");
            })
            ->orderBy('email');

        if ($excludeProject) {
            $query->whereNotIn('id', function ($sub) use ($excludeProject) {
                $sub->select('user_id')
                    ->from('project_members')
                    ->where('project_id', $excludeProject);
            });
        }

        $users = $query->limit(10)->get(['id', 'email', 'name']);

        return response()->json($users);
    }
}
