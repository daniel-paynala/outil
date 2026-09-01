<?php

namespace App\Modules\Monitoring\Support;

/**
 * Le franchissement des paliers.
 *
 * Extrait du reste pour être vérifiable sans base ni réseau : c'est la seule
 * partie de la supervision dont une erreur se paie en notifications — trop, ou
 * pas du tout.
 */
class Tiers
{
    /**
     * Le palier atteint par [$valeur], ou 0 si aucun.
     *
     * @param  array<int, int>  $paliers  croissants
     */
    public static function reached(int $valeur, array $paliers): int
    {
        $atteint = 0;
        foreach ($paliers as $palier) {
            if ($valeur >= $palier) {
                $atteint = max($atteint, $palier);
            }
        }

        return $atteint;
    }

    /**
     * Faut-il signaler, et à quel palier ?
     *
     * Rend le palier à signaler, ou `null` s'il n'y a rien à dire.
     *
     * ## La règle, et ce qu'elle évite
     *
     * On ne signale qu'un palier **strictement supérieur** au plus haut déjà
     * signalé. C'est ce qui rend une fenêtre glissante utilisable : le compte y
     * redescend quand de vieux événements en sortent, puis repasse le même
     * palier. Sans cette règle, 9 → 10 → 9 → 10 produirait deux notifications
     * pour un seul incident, et une nuit entière en produirait des centaines.
     *
     * On saute aussi les paliers intermédiaires : passer de 3 à 45 d'un coup
     * signale 40, pas 10 puis 20 puis 40. Trois notifications simultanées
     * disent moins qu'une seule qui annonce le bon chiffre.
     *
     * @param  array<int, int>  $paliers
     */
    public static function toRaise(
        int $valeur,
        array $paliers,
        int $plusHautSignale,
    ): ?int {
        $atteint = self::reached($valeur, $paliers);

        return $atteint > $plusHautSignale ? $atteint : null;
    }
}
