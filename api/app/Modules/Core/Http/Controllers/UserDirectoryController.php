<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Annuaire de l'équipe, accessible à tout membre authentifié.
 *
 * Distinct d'`AdminUserController`, qui est derrière le middleware `admin` et
 * sert à créer, modifier et supprimer des comptes. Ici, seule la lecture, et
 * seulement les champs nécessaires pour désigner quelqu'un : ni rôle, ni
 * métadonnées, ni préférences de notification.
 *
 * Sans cet endpoint, ouvrir une discussion avec un collègue est impossible
 * pour un non-admin : rien ne permet de découvrir les autres comptes.
 */
class UserDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.str_replace('%', '\%', $search).'%';
                $query->where(fn ($q) => $q->where('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term));
            })
            ->orderByRaw('coalesce(name, email)')
            ->get(['id', 'email', 'name', 'avatar_path']);

        return response()->json($users);
    }
}
