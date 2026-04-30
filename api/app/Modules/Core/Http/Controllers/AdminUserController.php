<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $projectCounts = DB::table('project_members')
            ->select('user_id', DB::raw('count(*) as count'))
            ->groupBy('user_id')
            ->pluck('count', 'user_id');

        $users = User::query()
            ->orderBy('email')
            ->get(['id', 'email', 'name', 'role', 'created_at'])
            ->map(function (User $u) use ($projectCounts) {
                return [
                    'id' => $u->id,
                    'email' => $u->email,
                    'name' => $u->name,
                    'role' => $u->role,
                    'created_at' => $u->created_at?->toIso8601String(),
                    'projects_count' => (int) ($projectCounts[$u->id] ?? 0),
                ];
            });

        return response()->json($users);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', 'in:member,admin'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $currentUserId = $request->attributes->get('supabase_user_id');
        if (
            isset($data['role'])
            && $data['role'] !== 'admin'
            && $user->id === $currentUserId
        ) {
            return response()->json([
                'error' => 'Tu ne peux pas te retirer le rôle admin toi-même.',
            ], 422);
        }

        $user->update($data);

        return response()->json($user->only(['id', 'email', 'name', 'role']));
    }
}
