<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Files\Services\SupabaseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AvatarController extends Controller
{
    public function __construct(private readonly SupabaseStorage $storage) {}

    /**
     * POST /api/me/avatar
     * Upload une nouvelle photo de profil. Remplace l'éventuelle existante.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        if (! $user) {
            abort(401);
        }

        $request->validate([
            'file' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $path = "users/{$user->id}/".Str::uuid().".{$ext}";
        $mime = $file->getMimeType() ?: 'image/jpeg';

        // Upload sur le bucket public 'avatars'
        $this->storage->upload(
            path: $path,
            contents: file_get_contents($file->getRealPath()),
            mime: $mime,
            bucket: SupabaseStorage::AVATAR_BUCKET,
            public: true,
        );

        // Supprime l'ancienne (best effort)
        if ($user->avatar_path) {
            try {
                $this->storage->delete($user->avatar_path, SupabaseStorage::AVATAR_BUCKET);
            } catch (\Throwable) {
                // ignore
            }
        }

        $user->update(['avatar_path' => $path]);

        return response()->json($user->fresh());
    }

    /**
     * DELETE /api/me/avatar
     * Retire la photo de profil.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        if (! $user) {
            abort(401);
        }

        if ($user->avatar_path) {
            try {
                $this->storage->delete($user->avatar_path, SupabaseStorage::AVATAR_BUCKET);
            } catch (\Throwable) {
                // ignore
            }
        }

        $user->update(['avatar_path' => null]);

        return response()->json($user->fresh());
    }
}
