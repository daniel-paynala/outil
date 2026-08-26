<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Préférences de notification de l'utilisateur courant.
 *
 * Une catégorie par type d'événement, chacune coupable indépendamment. Le
 * réglage vaut pour le push **et** l'in-app : couper « messages » et
 * continuer à voir une pastille rouge serait incompréhensible.
 *
 * Les préférences email existantes (`notify_*_email`) sont laissées de côté :
 * elles appartiennent au module de notifications déjà en production, qui
 * n'est pas dans ce dépôt.
 */
class PreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->user($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (User::NOTIFICATION_PREFERENCES as $key) {
            $rules[$key] = ['sometimes', 'boolean'];
        }

        $data = $request->validate($rules);
        $user = $this->user($request);
        $user->update($data);

        return response()->json($this->payload($user->fresh()));
    }

    /** @return array<string, bool> */
    private function payload(User $user): array
    {
        $out = [];
        foreach (User::NOTIFICATION_PREFERENCES as $key) {
            // Défaut à `true` : une préférence absente en base — colonne
            // ajoutée après la création du compte — vaut « activée ».
            $out[$key] = (bool) ($user->{$key} ?? true);
        }

        return $out;
    }

    private function user(Request $request): User
    {
        return $request->attributes->get('user')
            ?? abort(401, 'Missing user');
    }
}
