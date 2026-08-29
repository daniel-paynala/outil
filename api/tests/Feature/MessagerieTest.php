<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Messagerie\Models\Conversation;
use App\Modules\Messagerie\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Messagerie — les règles qui ne se voient pas à l'écran.
 *
 * Trois d'entre elles sont des pièges connus, et chacune produirait un dégât
 * durable et silencieux si elle cédait : une conversation directe dupliquée
 * disperse l'historique sans que personne ne s'en aperçoive tout de suite, une
 * fuite d'appartenance expose des échanges privés, et un message modifiable par
 * un tiers est un problème dont on ne se remet pas.
 */
class MessagerieTest extends TestCase
{
    use RefreshDatabase;

    public function test_creer_un_groupe_ajoute_son_createur_comme_proprietaire(): void
    {
        [$moi, $entetes] = $this->authenticate();
        [$autre, $_] = $this->authenticate();

        $reponse = $this->postJson('/api/conversations', [
            'name' => 'Équipe produit',
            'is_group' => true,
            'member_ids' => [$autre->id],
        ], $entetes)->assertCreated();

        $conversation = Conversation::find($reponse->json('id'));

        $this->assertSame('owner', $conversation->members()
            ->where('user_id', $moi->id)->value('role'));
        $this->assertSame('member', $conversation->members()
            ->where('user_id', $autre->id)->value('role'));
    }

    public function test_un_groupe_sans_nom_est_refuse(): void
    {
        [$_, $entetes] = $this->authenticate();
        [$autre, $__] = $this->authenticate();

        $this->postJson('/api/conversations', [
            'is_group' => true,
            'member_ids' => [$autre->id],
        ], $entetes)->assertStatus(422);
    }

    public function test_une_discussion_directe_est_rouverte_et_jamais_dupliquee(): void
    {
        // Le piège : sans déduplication, chaque ouverture depuis l'annuaire
        // créerait un fil neuf. Rien ne l'indiquerait — on verrait seulement,
        // des semaines plus tard, des messages éparpillés entre plusieurs
        // conversations portant le même nom.
        [$_, $entetes] = $this->authenticate();
        [$autre, $__] = $this->authenticate();

        $premiere = $this->postJson('/api/conversations', [
            'is_group' => false,
            'member_ids' => [$autre->id],
        ], $entetes)->assertCreated()->json('id');

        $seconde = $this->postJson('/api/conversations', [
            'is_group' => false,
            'member_ids' => [$autre->id],
        ], $entetes)->assertOk()->json('id');

        $this->assertSame($premiere, $seconde);
        $this->assertSame(1, Conversation::where('is_group', false)->count());
    }

    public function test_la_deduplication_vaut_aussi_dans_l_autre_sens(): void
    {
        // La conversation appartient aux deux : celui qui n'a pas ouvert le fil
        // doit retomber dessus, pas en créer un second.
        [$moi, $mesEntetes] = $this->authenticate();
        [$autre, $__] = $this->authenticate();
        $sesEntetes = $this->tokenHeaderFor($autre);

        $premiere = $this->postJson('/api/conversations', [
            'is_group' => false,
            'member_ids' => [$autre->id],
        ], $mesEntetes)->json('id');

        $seconde = $this->postJson('/api/conversations', [
            'is_group' => false,
            'member_ids' => [$moi->id],
        ], $sesEntetes)->json('id');

        $this->assertSame($premiere, $seconde);
    }

    public function test_une_discretion_directe_a_trois_est_refusee(): void
    {
        [$_, $entetes] = $this->authenticate();
        [$a, $__] = $this->authenticate();
        [$b, $___] = $this->authenticate();

        $this->postJson('/api/conversations', [
            'is_group' => false,
            'member_ids' => [$a->id, $b->id],
        ], $entetes)->assertStatus(422);
    }

    public function test_un_non_membre_ne_peut_ni_lire_ni_ecrire(): void
    {
        [$_, $entetes] = $this->authenticate();
        [$membre, $__] = $this->authenticate();
        [$intrus, $___] = $this->authenticate();

        $conversation = $this->postJson('/api/conversations', [
            'name' => 'Salaires',
            'is_group' => true,
            'member_ids' => [$membre->id],
        ], $entetes)->json('id');

        $entetesIntrus = $this->tokenHeaderFor($intrus);

        $this->getJson("/api/conversations/{$conversation}/messages", $entetesIntrus)
            ->assertForbidden();
        $this->postJson("/api/conversations/{$conversation}/messages",
            ['body' => 'coucou'], $entetesIntrus)->assertForbidden();
        $this->getJson("/api/conversations/{$conversation}", $entetesIntrus)
            ->assertForbidden();
    }

    public function test_la_liste_ne_montre_que_ses_propres_conversations(): void
    {
        [$_, $entetes] = $this->authenticate();
        [$membre, $__] = $this->authenticate();
        [$etranger, $___] = $this->authenticate();

        $this->postJson('/api/conversations', [
            'name' => 'Direction',
            'is_group' => true,
            'member_ids' => [$membre->id],
        ], $entetes)->assertCreated();

        $this->getJson('/api/conversations', $this->tokenHeaderFor($etranger))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_on_ne_peut_modifier_que_ses_propres_messages(): void
    {
        [$_, $entetes] = $this->authenticate();
        [$autre, $__] = $this->authenticate();

        $conversation = $this->postJson('/api/conversations', [
            'name' => 'Atelier',
            'is_group' => true,
            'member_ids' => [$autre->id],
        ], $entetes)->json('id');

        $message = $this->postJson("/api/conversations/{$conversation}/messages",
            ['body' => 'Texte original'], $entetes)->assertCreated()->json('id');

        // Membre du groupe, mais pas auteur : être présent ne donne pas le
        // droit de réécrire ce que quelqu'un d'autre a dit.
        $this->patchJson("/api/messages/{$message}",
            ['body' => 'Texte falsifié'], $this->tokenHeaderFor($autre))
            ->assertForbidden();

        $this->patchJson("/api/messages/{$message}",
            ['body' => 'Texte corrigé'], $entetes)->assertOk();

        $this->assertDatabaseHas('messages', [
            'id' => $message,
            'body' => 'Texte corrigé',
        ]);
    }

    public function test_une_modification_est_datee(): void
    {
        // Sans `edited_at`, rien ne distinguerait un message réécrit d'un
        // message d'origine — l'app affiche « modifié » à partir de là.
        [$_, $entetes] = $this->authenticate();
        [$autre, $__] = $this->authenticate();

        $conversation = $this->postJson('/api/conversations', [
            'name' => 'Atelier', 'is_group' => true, 'member_ids' => [$autre->id],
        ], $entetes)->json('id');

        $message = $this->postJson("/api/conversations/{$conversation}/messages",
            ['body' => 'Avant'], $entetes)->json('id');

        $this->assertNull(
            Message::find($message)->edited_at,
        );

        $this->patchJson("/api/messages/{$message}", ['body' => 'Après'], $entetes)
            ->assertOk();

        $this->assertNotNull(
            Message::find($message)->fresh()->edited_at,
        );
    }

    public function test_un_message_supprime_reste_en_base(): void
    {
        // Suppression douce : effacer la ligne trouerait la pagination par
        // curseur et casserait les réponses qui citent le message.
        [$_, $entetes] = $this->authenticate();
        [$autre, $__] = $this->authenticate();

        $conversation = $this->postJson('/api/conversations', [
            'name' => 'Atelier', 'is_group' => true, 'member_ids' => [$autre->id],
        ], $entetes)->json('id');

        $message = $this->postJson("/api/conversations/{$conversation}/messages",
            ['body' => 'À retirer'], $entetes)->json('id');

        $this->deleteJson("/api/messages/{$message}", [], $entetes)->assertNoContent();

        $this->assertDatabaseHas('messages', ['id' => $message]);
        $this->assertNotNull(
            Message::withoutGlobalScopes()
                ->find($message)?->deleted_at,
        );
    }

    public function test_un_message_vide_et_sans_piece_jointe_est_refuse(): void
    {
        [$_, $entetes] = $this->authenticate();
        [$autre, $__] = $this->authenticate();

        $conversation = $this->postJson('/api/conversations', [
            'name' => 'Atelier', 'is_group' => true, 'member_ids' => [$autre->id],
        ], $entetes)->json('id');

        $this->postJson("/api/conversations/{$conversation}/messages",
            ['body' => ''], $entetes)->assertStatus(422);
    }

    // ── Mentions dans un groupe ─────────────────────────────────────────

    /**
     * Crée un groupe et rend ses trois protagonistes.
     *
     * @return array{0: User, 1: array<string,string>, 2: User, 3: User, 4: string}
     */
    private function groupeDeTrois(): array
    {
        [$auteur, $entetes] = $this->authenticate();
        [$nomme] = $this->authenticate();
        [$muet] = $this->authenticate();

        // Celui-ci a coupé les notifications de message. C'est le cas qui
        // compte : dans un groupe actif, c'est le réflexe de tout le monde.
        $muet->forceFill(['notify_messages' => false])->save();
        $nomme->forceFill(['notify_messages' => false])->save();

        $id = $this->postJson('/api/conversations', [
            'name' => 'Équipe produit',
            'is_group' => true,
            'member_ids' => [$nomme->id, $muet->id],
        ], $entetes)->assertCreated()->json('id');

        return [$auteur, $entetes, $nomme, $muet, $id];
    }

    public function test_une_mention_passe_malgre_les_notifications_coupees(): void
    {
        // Sans cela, il n'existerait aucun moyen d'appeler quelqu'un dans un
        // groupe qu'il a mis en sourdine — et chacun serait ramené à surveiller
        // le fil en permanence.
        [$auteur, $entetes, $nomme, $muet, $id] = $this->groupeDeTrois();

        config(['onesignal.app_id' => 'app', 'onesignal.rest_key' => 'cle']);
        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        $this->postJson("/api/conversations/{$id}/messages", [
            'body' => "@[Nommé]({$nomme->id}) tu peux regarder ?",
        ], $entetes)->assertCreated();

        Http::assertSent(function ($requete) use ($nomme) {
            $c = $requete->data();

            return $c['include_aliases']['external_id'] === [$nomme->id]
                && $c['data']['type'] === 'message.mentioned'
                && str_contains($c['headings']['en'], 'vous a mentionné');
        });
    }

    public function test_les_autres_gardent_leur_preference(): void
    {
        // La mention ouvre une porte pour la personne nommée, pas pour tout le
        // monde : celui qui a coupé et n'est pas nommé ne reçoit rien.
        [$auteur, $entetes, $nomme, $muet, $id] = $this->groupeDeTrois();

        config(['onesignal.app_id' => 'app', 'onesignal.rest_key' => 'cle']);
        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        $this->postJson("/api/conversations/{$id}/messages", [
            'body' => "@[Nommé]({$nomme->id}) tu peux regarder ?",
        ], $entetes)->assertCreated();

        Http::assertNotSent(function ($requete) use ($muet) {
            $cibles = $requete->data()['include_aliases']['external_id'] ?? [];

            return in_array($muet->id, $cibles, true);
        });
    }

    public function test_le_balisage_ne_part_pas_dans_la_notification(): void
    {
        // « @[Fidèle](8c1f…) tu peux voir ? » sur un écran verrouillé serait
        // illisible.
        [$auteur, $entetes, $nomme, $muet, $id] = $this->groupeDeTrois();

        config(['onesignal.app_id' => 'app', 'onesignal.rest_key' => 'cle']);
        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        $this->postJson("/api/conversations/{$id}/messages", [
            'body' => "@[Nommé]({$nomme->id}) tu peux regarder ?",
        ], $entetes)->assertCreated();

        Http::assertSent(function ($requete) use ($nomme) {
            $corps = $requete->data()['contents']['en'];

            return str_contains($corps, '@Nommé')
                && ! str_contains($corps, $nomme->id);
        });
    }

    public function test_mentionner_un_non_membre_ne_notifie_personne(): void
    {
        // L'identifiant vient du client : le croire sur parole ferait fuiter le
        // contenu d'un fil à quelqu'un qui n'y a pas accès.
        [$auteur, $entetes, $nomme, $muet, $id] = $this->groupeDeTrois();
        [$etranger] = $this->authenticate();

        config(['onesignal.app_id' => 'app', 'onesignal.rest_key' => 'cle']);
        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        $this->postJson("/api/conversations/{$id}/messages", [
            'body' => "@[Intrus]({$etranger->id}) regarde",
        ], $entetes)->assertCreated();

        Http::assertNotSent(function ($requete) use ($etranger) {
            $cibles = $requete->data()['include_aliases']['external_id'] ?? [];

            return in_array($etranger->id, $cibles, true);
        });
    }
}
