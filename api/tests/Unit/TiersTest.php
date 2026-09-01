<?php

namespace Tests\Unit;

use App\Modules\Monitoring\Support\Tiers;
use PHPUnit\Framework\TestCase;

/**
 * Le franchissement des paliers.
 *
 * C'est la seule partie de la supervision dont une erreur se paie en
 * notifications — trop, ou pas du tout. Les deux sont graves : l'une fait
 * désinstaller l'application, l'autre fait rater l'incident.
 */
class TiersTest extends TestCase
{
    private const PALIERS = [3, 10, 20, 40, 60, 100];

    public function test_sous_le_premier_palier_il_ne_se_passe_rien(): void
    {
        $this->assertNull(Tiers::toRaise(2, self::PALIERS, 0));
    }

    public function test_le_premier_palier_se_signale(): void
    {
        $this->assertSame(3, Tiers::toRaise(3, self::PALIERS, 0));
    }

    public function test_un_palier_deja_signale_ne_se_repete_pas(): void
    {
        // Le compte reste à 12 pendant des heures : rien de nouveau n'arrive,
        // et le redire toutes les douze secondes ferait désinstaller l'app.
        $this->assertNull(Tiers::toRaise(12, self::PALIERS, 10));
    }

    public function test_une_fenetre_glissante_qui_redescend_ne_renotifie_pas(): void
    {
        // Le cas qui a dicté toute la conception : le compte redescend quand de
        // vieux événements sortent de la fenêtre, puis repasse le même palier.
        $this->assertNull(Tiers::toRaise(9, self::PALIERS, 10));
        $this->assertNull(Tiers::toRaise(10, self::PALIERS, 10));
        $this->assertNull(Tiers::toRaise(19, self::PALIERS, 10));
    }

    public function test_le_palier_suivant_se_signale(): void
    {
        $this->assertSame(20, Tiers::toRaise(20, self::PALIERS, 10));
    }

    public function test_un_bond_saute_les_paliers_intermediaires(): void
    {
        // Passer de 3 à 45 d'un coup signale 40, pas 10 puis 20 puis 40. Trois
        // notifications simultanées disent moins qu'une seule qui annonce le
        // bon chiffre.
        $this->assertSame(40, Tiers::toRaise(45, self::PALIERS, 3));
    }

    public function test_au_dela_du_dernier_palier_rien_de_plus(): void
    {
        // À 100 on a déjà tout dit. Continuer à monter ne mérite pas une
        // notification de plus — l'incident est ouvert, il faut l'acquitter.
        $this->assertSame(100, Tiers::toRaise(250, self::PALIERS, 60));
        $this->assertNull(Tiers::toRaise(900, self::PALIERS, 100));
    }

    public function test_un_acquittement_reouvre_tous_les_paliers(): void
    {
        // Après acquittement, l'état repart de zéro et le comptage recommence à
        // la date de l'acquittement. Un nouveau franchissement se signale même
        // s'il est plus bas que le précédent incident.
        $this->assertSame(3, Tiers::toRaise(4, self::PALIERS, 0));
    }

    public function test_des_paliers_desordonnes_ne_trompent_pas(): void
    {
        // Une liste saisie à la main dans l'interface n'arrive pas toujours
        // triée. Le résultat doit être le même.
        $this->assertSame(40, Tiers::reached(45, [100, 3, 40, 10]));
    }

    public function test_sans_palier_rien_ne_se_signale(): void
    {
        // Une sonde qu'on observe sans vouloir être alerté : le graphique se
        // remplit, le téléphone reste muet.
        $this->assertNull(Tiers::toRaise(9999, [], 0));
    }
}
