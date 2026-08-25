<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Core\Models\ProjectMember;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectMemberController extends Controller
{
    private const ROLES = ['owner', 'member'];

    public function __construct(
        private ActivityLogger $activity,
        private NotificationService $notify,
    ) {}

    /**
     * GET /api/projects/{project}/members
     * Tout membre du projet peut voir la liste.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $members = $project->projectMembers()
            ->with('user:id,email,name,avatar_path')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ProjectMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'email' => $m->user?->email,
                'name' => $m->user?->name,
                'avatar_url' => $m->user?->avatar_url,
                'role' => $m->role,
                'added_at' => $m->created_at?->toIso8601String(),
            ]);

        return response()->json($members);
    }

    /**
     * POST /api/projects/{project}/members
     * Owner uniquement. Body : { user_id, role }
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureOwner($request, $project);

        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'role' => ['required', 'in:'.implode(',', self::ROLES)],
        ]);

        if ($project->projectMembers()->where('user_id', $data['user_id'])->exists()) {
            return response()->json([
                'error' => 'Cet utilisateur est déjà membre du projet.',
            ], 422);
        }

        $member = ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $data['user_id'],
            'role' => $data['role'],
        ]);

        $member->load('user:id,email,name,avatar_path');

        $userId = $this->userId($request);

        $this->activity->log(
            $project->id,
            $userId,
            'project.member.added',
            $member->user,
            $member->user?->email,
            ['role' => $member->role],
        );

        // Notif au nouveau membre
        $this->notify->forUser(
            userId: $member->user_id,
            type: 'project.member.added',
            title: 'Tu as été ajouté à un projet',
            body: $project->name,
            link: "/projects/{$project->id}",
            projectId: $project->id,
            actorId: $userId,
        );

        // Broadcast aux autres membres du projet
        $this->notify->forProjectMembers(
            projectId: $project->id,
            type: 'project.member.joined',
            title: 'Nouveau membre',
            body: ($member->user?->name ?? $member->user?->email ?? 'Un nouveau membre').' a rejoint le projet '.$project->name,
            link: "/projects/{$project->id}/members",
            actorId: $userId,
            exceptUserId: $member->user_id,
        );

        return response()->json([
            'id' => $member->id,
            'user_id' => $member->user_id,
            'email' => $member->user?->email,
            'name' => $member->user?->name,
            'avatar_url' => $member->user?->avatar_url,
            'role' => $member->role,
            'added_at' => $member->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * PATCH /api/projects/{project}/members/{user}
     * Owner uniquement. Body : { role }
     */
    public function update(Request $request, Project $project, User $user): JsonResponse
    {
        $this->ensureOwner($request, $project);

        $data = $request->validate([
            'role' => ['required', 'in:'.implode(',', self::ROLES)],
        ]);

        $member = $project->projectMembers()
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Empêche de retirer le dernier owner
        if ($member->role === 'owner' && $data['role'] !== 'owner') {
            $ownersCount = $project->projectMembers()->where('role', 'owner')->count();
            if ($ownersCount <= 1) {
                return response()->json([
                    'error' => 'Impossible de retirer le dernier owner du projet.',
                ], 422);
            }
        }

        $previousRole = $member->role;
        $member->update(['role' => $data['role']]);

        $this->activity->log(
            $project->id,
            $this->userId($request),
            'project.member.role_changed',
            $user,
            $user->email,
            ['from' => $previousRole, 'to' => $data['role']],
        );

        return response()->json([
            'id' => $member->id,
            'user_id' => $member->user_id,
            'role' => $member->role,
        ]);
    }

    /**
     * DELETE /api/projects/{project}/members/{user}
     * Owner uniquement. Empêche de supprimer le dernier owner.
     */
    public function destroy(Request $request, Project $project, User $user): JsonResponse
    {
        $this->ensureOwner($request, $project);

        $member = $project->projectMembers()
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($member->role === 'owner') {
            $ownersCount = $project->projectMembers()->where('role', 'owner')->count();
            if ($ownersCount <= 1) {
                return response()->json([
                    'error' => 'Impossible de retirer le dernier owner du projet.',
                ], 422);
            }
        }

        $member->delete();

        $this->activity->log(
            $project->id,
            $this->userId($request),
            'project.member.removed',
            $user,
            $user->email,
        );

        return response()->json(null, 204);
    }

    // ── Helpers ──

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }

    private function ensureMember(Request $request, Project $project): void
    {
        if (! $project->hasMember($this->userId($request))) {
            abort(403, 'Not a member of this project');
        }
    }

    private function ensureOwner(Request $request, Project $project): void
    {
        $isOwner = $project->projectMembers()
            ->where('user_id', $this->userId($request))
            ->where('role', 'owner')
            ->exists();

        if (! $isOwner) {
            abort(403, 'Owner role required');
        }
    }
}
