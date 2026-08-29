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

        // La signature n'est pas une préférence de notification : c'est du
        // texte, et elle a ses propres règles. `nullable` compte — vider le
        // champ doit pouvoir signifier « je n'en veux pas », ce qu'une chaîne
        // absente ne dirait pas.
        $rules['mail_signature'] = ['sometimes', 'nullable', 'string', 'max:500'];

        $data = $request->validate($rules);
        $user = $this->user($request);
        $user->update($data);

        return response()->json($this->payload($user->fresh()));
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $out = [];
        foreach (User::NOTIFICATION_PREFERENCES as $key) {
            // Défaut à `true` : une préférence absente en base — colonne
            // ajoutée après la création du compte — vaut « activée ».
            $out[$key] = (bool) ($user->{$key} ?? true);
        }

        // Rendue telle quelle, `null` compris : c'est au client de distinguer
        // « jamais réglée » — où il propose sa mention par défaut — de « vidée
        // exprès », où il n'ajoute rien. Substituer un défaut ici rendrait les
        // deux indiscernables, et la mention impossible à retirer.
        $out['mail_signature'] = $user->mail_signature;

        return $out;
    }

    private function user(Request $request): User
    {
        return $request->attributes->get('user')
            ?? abort(401, 'Missing user');
    }
}
