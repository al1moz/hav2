<?php
declare(strict_types=1);

namespace Conso;

/**
 * Calcul de l'énergie entre deux mesures successives d'une même grandeur.
 *
 * gauge (puissance moyenne en W) : la valeur reçue à t1 couvre l'intervalle ]t0, t1].
 *   Au-delà de maxGap secondes sans mesure, l'énergie de l'intervalle est inconnue (0).
 * counter (index) : la différence d'index est juste même à travers un trou de plusieurs
 *   jours ; elle est répartie uniformément sur les heures du trou. Un index qui baisse
 *   (compteur remplacé) ne produit rien.
 */
final class Energy
{
    /** @return array<int,float> début d'heure UTC => Wh */
    public static function gauge(int $t0, int $t1, float $value, float $factor, int $maxGap): array
    {
        $dt = $t1 - $t0;
        if ($dt <= 0 || $dt > $maxGap || $value <= 0) {
            return [];
        }
        return self::spread($t0, $t1, $value * $factor * $dt / 3600);
    }

    /** @return array<int,float> début d'heure UTC => Wh */
    public static function counter(int $t0, float $v0, int $t1, float $v1, float $factor, int $maxGap): array
    {
        $dt = $t1 - $t0;
        $delta = $v1 - $v0;
        if ($dt <= 0 || $dt > $maxGap || $delta <= 0) {
            return [];
        }
        return self::spread($t0, $t1, $delta * $factor);
    }

    /**
     * Un nouvel index est-il cohérent avec le précédent ? Il ne doit ni baisser ni monter
     * plus vite que la puissance maximale possible (36 kW par défaut). Les erreurs de
     * lecture du Linky de l'ancienne base (index x1000, index tronqué) sont ainsi écartées.
     * Après un trou plus long que $maxGap, toute valeur positive est acceptée comme nouveau départ.
     */
    public static function counterPlausible(int $t0, float $v0, int $t1, float $v1, float $factor, float $maxPowerW, int $maxGap): bool
    {
        $dt = $t1 - $t0;
        if ($dt > $maxGap) {
            return $v1 >= 0;
        }
        $deltaWh = ($v1 - $v0) * $factor;
        return $dt > 0 && $deltaWh >= 0 && $deltaWh <= $maxPowerW * max($dt, 300) / 3600;
    }

    /** Répartit $amount proportionnellement au temps passé dans chaque heure de ]t0, t1]. @return array<int,float> */
    public static function spread(int $t0, int $t1, float $amount): array
    {
        $out = [];
        $total = $t1 - $t0;
        if ($total <= 0) {
            return $out;
        }
        $cursor = $t0;
        while ($cursor < $t1) {
            $hour = Time::hourStart($cursor);
            $end = min($hour + 3600, $t1);
            $out[$hour] = ($out[$hour] ?? 0.0) + $amount * ($end - $cursor) / $total;
            $cursor = $end;
        }
        return $out;
    }
}
