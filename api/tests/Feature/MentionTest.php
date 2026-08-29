<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Models\Project;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Column;
use App\Support\Mentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nommer quelqu'un dans un commentaire.
 *
 * ## Ce qui se joue ici
 *
 * Un commentaire sans mention est une bouteille à la mer : personne ne sait
 * qu'il existe tant qu'on n'ouvre pas la carte. Une mention en fait une demande
 * adressée — donc la notification n'est pas un accessoire, c'est la
 * fonctionnalité.
 *
 * Et l'identifiant mentionné vient du client. Il ne peut pas être cru sur
 * parole : notifier quelqu'un à propos d'une carte qu'il ne peut pas ouvrir lui
 * apprendrait son existence et son titre tout en le laissant devant une porte
 * fermée.
 */
class MentionTest extends TestCase
{
    use RefreshDatabase;

    private Project $projet;

    private Card $carte;

    private User $auteur;

    /** @var array<string, string> */
    private array $entetes;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->auteur, $this->entetes] = $this->authenticate();
        $this->projet = $this->projet($this->auteur);

        $colonne = Column::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->projet->id,
            'name' => 'À faire',
            'position' => 0,
        ]);

        $this->carte = Card::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->projet->id,
            'column_id' => $colonne->id,
            'title' => 'Brancher le relais',
            'position' => 0,
            'created_by' => $this->auteur->id,
        ]);
    }

    private function projet(User $proprietaire): Project
    {
        $projet = Project::create([
            'id' => (string) Str::uuid(),
            'name' => 'Assuerpay',
            'slug' => 'assuerpay-'.Str::random(8),
            'created_by' => $proprietaire->id,
        ]);

        $this->rattacher($projet, $proprietaire, 'owner');

        return $projet;
    }

    private function rattacher(Project $projet, User $user, string $role = 'member'): void
    {
        DB::table('project_members')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $projet->id,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function commenter(string $contenu, ?array $entetes = null)
    {
        return $this->postJson(
            "/api/cards/{$this->carte->id}/comments",
            ['content' => $contenu],
            $entetes ?? $this->entetes,
        );
    }

    /** @return array<int, string> destinataires des notifications de mention */
    private function notifies(): array
    {
        return DB::table('notifications')
            ->where('type', 'card.mentioned')
            ->pluck('user_id')
            ->all();
    }

    // ── Le format ───────────────────────────────────────────────────────

    public function test_les_identifiants_sont_extraits_dans_l_ordre(): void
    {
        $ids = Mentions::ids('@[Daniel](a-1) puis @[Fidèle](b-2)');

        $this->assertSame(['a-1', 'b-2'], $ids);
    }

    public function test_une_personne_nommee_deux_fois_ne_compte_qu_une(): void
    {
        // On se nomme puis on se renomme : c'est courant, et cela ne doit pas
        // produire deux notifications.
        $this->assertSame(
            ['a-1'],
            Mentions::ids('@[Daniel](a-1), et donc @[Daniel](a-1) aussi'),
        );
    }

    public function test_le_balisage_disparait_a_la_lecture(): void
    {
        // « @[Daniel](8c1f…) peux-tu voir ça ? » n'est pas ce qu'on veut lire
        // sur un écran verrouillé.
        $this->assertSame(
            '@Daniel peux-tu voir ça ?',
            Mentions::enClair('@[Daniel](8c1f-uuid) peux-tu voir ça ?'),
        );
    }

    public function test_un_arobase_ordinaire_n_est_pas_une_mention(): void
    {
        // Une adresse électronique dans un commentaire est fréquente.
        $this->assertSame([], Mentions::ids('écris à daniel@paynala.com'));
    }

    // ── La notification ─────────────────────────────────────────────────

    public function test_une_mention_notifie_la_personne(): void
    {
        [$fidele] = $this->authenticate();
        $this->rattacher($this->projet, $fidele);

        $this->commenter("@[Fidèle]({$fidele->id}) peux-tu regarder ?")
            ->assertCreated();

        $this->assertSame([$fidele->id], $this->notifies());
    }

    public function test_la_notification_porte_le_texte_lisible(): void
    {
        [$fidele] = $this->authenticate();
        $this->rattacher($this->projet, $fidele);

        $this->commenter("@[Fidèle]({$fidele->id}) peux-tu regarder ?");

        $corps = DB::table('notifications')
            ->where('type', 'card.mentioned')
            ->value('body');

        $this->assertStringContainsString('@Fidèle peux-tu regarder', $corps);
        $this->assertStringNotContainsString($fidele->id, $corps);
    }

    public function test_un_non_membre_n_est_pas_notifie(): void
    {
        // L'identifiant vient du client. Notifier quelqu'un à propos d'une
        // carte qu'il ne peut pas ouvrir lui en apprendrait le titre tout en le
        // laissant devant une porte fermée.
        [$etranger] = $this->authenticate();

        $this->commenter("@[Intrus]({$etranger->id}) regarde ça")
            ->assertCreated();

        $this->assertSame([], $this->notifies());
    }

    public function test_un_identifiant_inventé_ne_casse_rien(): void
    {
        $this->commenter('@[Fantôme](00000000-0000-4000-8000-000000000000) ?')
            ->assertCreated();

        $this->assertSame([], $this->notifies());
    }

    public function test_se_mentionner_soi_meme_ne_notifie_pas(): void
    {
        // Se laisser une note est un usage légitime ; se le faire annoncer ne
        // l'est pas.
        $this->commenter("@[Moi]({$this->auteur->id}) ne pas oublier")
            ->assertCreated();

        $this->assertSame([], $this->notifies());
    }

    public function test_plusieurs_personnes_sont_toutes_notifiees(): void
    {
        [$a] = $this->authenticate();
        [$b] = $this->authenticate();
        $this->rattacher($this->projet, $a);
        $this->rattacher($this->projet, $b);

        $this->commenter("@[A]({$a->id}) et @[B]({$b->id}) — vos avis ?");

        $notifies = $this->notifies();
        sort($notifies);
        $attendus = [$a->id, $b->id];
        sort($attendus);

        $this->assertSame($attendus, $notifies);
    }

    // ── La modification ─────────────────────────────────────────────────

    public function test_corriger_un_commentaire_ne_re_notifie_pas(): void
    {
        [$fidele] = $this->authenticate();
        $this->rattacher($this->projet, $fidele);

        $reponse = $this->commenter("@[Fidèle]({$fidele->id}) regarde");
        $id = $reponse->json('id');

        $this->patchJson("/api/comments/{$id}", [
            'content' => "@[Fidèle]({$fidele->id}) regarde stp",
        ], $this->entetes)->assertOk();

        // Une seule notification, pas deux : corriger une faute de frappe ne
        // doit pas re-sonner chez ceux qui étaient déjà nommés.
        $this->assertCount(1, $this->notifies());
    }

    public function test_ajouter_une_mention_en_modifiant_notifie(): void
    {
        [$fidele] = $this->authenticate();
        $this->rattacher($this->projet, $fidele);

        $id = $this->commenter('rien de spécial')->json('id');

        $this->patchJson("/api/comments/{$id}", [
            'content' => "finalement @[Fidèle]({$fidele->id}) ?",
        ], $this->entetes)->assertOk();

        $this->assertSame([$fidele->id], $this->notifies());
    }

    // ── Le push ─────────────────────────────────────────────────────────

    public function test_une_mention_part_aussi_sur_le_telephone(): void
    {
        // Une notification qui n'existe que dans le centre de l'app n'arrive
        // qu'à qui pense à l'ouvrir. Autant dire jamais, pour une information
        // dont l'intérêt est d'arriver sans qu'on la cherche.
        [$fidele] = $this->authenticate();
        $this->rattacher($this->projet, $fidele);

        config(['onesignal.app_id' => 'app', 'onesignal.rest_key' => 'cle']);
        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        $this->commenter("@[Fidèle]({$fidele->id}) regarde")->assertCreated();

        Http::assertSent(function ($requete) use ($fidele) {
            $c = $requete->data();

            return $c['include_aliases']['external_id'] === [$fidele->id]
                && $c['data']['type'] === 'card.mentioned'
                && str_contains((string) $c['data']['link'], $this->carte->id);
        });
    }

    public function test_le_push_ne_part_pas_a_qui_la_ligne_a_ete_refusee(): void
    {
        // Le déclencheur de préférences abandonne l'insertion en silence. Le
        // push relit donc ce qui a réellement été inséré plutôt que de refaire
        // le raisonnement — deux implémentations de la même règle finiraient
        // par diverger, et c'est la plus permissive qui ferait la loi.
        [$fidele] = $this->authenticate();
        $this->rattacher($this->projet, $fidele);

        config(['onesignal.app_id' => 'app', 'onesignal.rest_key' => 'cle']);
        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        // Aucune ligne créée : l'auteur ne se notifie pas lui-même.
        $this->commenter("@[Moi]({$this->auteur->id}) note")->assertCreated();

        Http::assertNothingSent();
    }
}
