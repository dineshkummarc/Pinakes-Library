<?php
declare(strict_types=1);

namespace App\Support;

/** Stable seeded names stay language-independent in storage; custom names stay as entered. */
final class RolePresets
{
    public static function displayName(string $name): string
    {
        return match ($name) {
            'Catalogatore' => __('Catalogatore'),
            'Addetto prestiti' => __('Addetto prestiti'),
            'Archivista' => __('Archivista'),
            default => $name,
        };
    }

    public static function description(string $name): string
    {
        return match ($name) {
            'Catalogatore' => __('Profilo iniziale per gestire libri, autori ed editori, senza gestire prestiti, utenti o file riservati.'),
            'Addetto prestiti' => __('Profilo iniziale per registrare prestiti, restituzioni e prenotazioni, senza modificare il catalogo o le impostazioni.'),
            'Archivista' => __('Profilo iniziale per catalogare e gestire gli allegati digitali, compresa l’apertura dei file riservati.'),
            default => '',
        };
    }

    /** Saving a translated label unchanged must not rename the underlying preset. */
    public static function storedName(string $submitted, string $original): string
    {
        return trim($submitted) === self::displayName($original) ? $original : trim($submitted);
    }
}
