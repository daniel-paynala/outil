<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Liste paginée. Filtre `?read=unread|all`.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $filter = $request->query('read', 'all'); // unread | all
        $perPage = min((int) $request->query('per_page', 30), 100);

        $query = Notification::with(['actor:id,email,name,avatar_path', 'project:id,name,slug,color'])
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $this->userId($request))
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $userId = $this->userId($request);
        $notif = Notification::where('user_id', $userId)->findOrFail($id);
        if ($notif->read_at === null) {
            $notif->update(['read_at' => now()]);
        }

        return response()->json($notif);
    }

    /**
     * POST /api/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $this->userId($request))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['marked' => $count]);
    }

    /**
     * DELETE /api/notifications/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $userId = $this->userId($request);
        Notification::where('user_id', $userId)->where('id', $id)->delete();

        return response()->json(null, 204);
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }
}
