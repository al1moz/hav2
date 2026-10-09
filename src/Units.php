<?php
declare(strict_types=1);

namespace Conso;

/** Conversions d'unités acceptées à la réception (ex. l'add-on envoie des Wh, la mesure est en kWh). */
final class Units
{
    private const TO_BASE = [
        'Wh' => ['Wh', 1.0], 'kWh' => ['Wh', 1000.0],
        'W' => ['W', 1.0], 'kW' => ['W', 1000.0],
        'VA' => ['VA', 1.0], 'kVA' => ['VA', 1000.0],
        'L' => ['L', 1.0], 'm3' => ['L', 1000.0], 'm³' => ['L', 1000.0],
    ];

    /** Facteur multiplicatif de $from vers $to, ou null si les unités ne sont pas compatibles. */
    public static function factor(string $from, string $to): ?float
    {
        if ($from === $to) {
            return 1.0;
        }
        if (!isset(self::TO_BASE[$from], self::TO_BASE[$to]) || self::TO_BASE[$from][0] !== self::TO_BASE[$to][0]) {
            return null;
        }
        return self::TO_BASE[$from][1] / self::TO_BASE[$to][1];
    }

    /** Facteur d'énergie par défaut d'une nouvelle mesure (voir la table metric). */
    public static function energyFactor(string $kind, string $unit): ?float
    {
        if ($kind === 'gauge') {
            return ['W' => 1.0, 'kW' => 1000.0][$unit] ?? null;
        }
        return ['Wh' => 1.0, 'kWh' => 1000.0][$unit] ?? null;
    }
}
