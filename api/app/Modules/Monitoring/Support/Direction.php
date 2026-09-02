<?php

namespace App\Modules\Monitoring\Support;

/**
 * Dans quel sens une sonde se dégrade.
 *
 * ## Pourquoi les deux existent
 *
 * Presque toutes les sondes comptent une chose qui ne devrait pas arriver :
 * des time-outs, des refus, des logs manquants. Plus le nombre monte, plus
 * ça va mal. C'est le sens croissant, et c'était le seul.
 *
 * Mais certaines mesurent la santé elle-même — des paiements réussis, un
 * volume de transactions. Là, tout s'inverse : le danger est en bas. Une sonde
 * de paiements réussis réglée en croissant préviendrait quand la journée est
 * bonne, et se tairait quand la production tombe à zéro. Exactement le
 * contraire de ce qu'on lui demande.
 *
 * ## Ce que « le plus grave » veut dire de chaque côté
 *
 * En croissant, le palier le plus grave est le plus haut franchi : 100 est
 * pire que 20. En décroissant, c'est le plus bas : tomber sous 20 est pire que
 * tomber sous 100. Toute la logique de signalement en découle — c'est pourquoi
 * la colonne s'appelle `severest_tier` et non `highest_tier`.
 */
enum Direction: string
{
    /** Le danger est en haut. Un compte d'erreurs qui grimpe. */
    case Croissant = 'croissant';

    /** Le danger est en bas. Une mesure de santé qui s'effondre. */
    case Decroissant = 'decroissant';

    /** Le franchissement se lit-il comme un plancher plutôt qu'un plafond ? */
    public function isFloor(): bool
    {
        return $this === self::Decroissant;
    }

    /**
     * Le mot à employer devant un nombre, dans une notification.
     *
     * « palier 10 » décrit un seuil qu'on dépasse ; « plancher 50 » un seuil
     * sous lequel on tombe. Employer le même mot pour les deux ferait lire
     * « palier 50 » à quelqu'un qui vient de voir sa production s'effondrer, et
     * il comprendrait l'inverse.
     */
    public function label(): string
    {
        return $this->isFloor() ? 'plancher' : 'palier';
    }
}
