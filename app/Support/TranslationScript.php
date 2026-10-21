<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The JavaScript translation table, served as one cacheable script per locale.
 *
 * The layouts used to inline the whole locale file in every page: about 600 KB of
 * HTML on each request (8,000+ strings), and on an Italian install every entry
 * mapped a string to itself. As a script versioned by the file's content, the
 * browser downloads it once per release and reuses it on every page.
 */
final class TranslationScript
{
    /** Route path, without the locale. */
    public const ROUTE_PREFIX = '/i18n/';

    /**
     * URL of the script for a locale, versioned so a new translation file is
     * fetched at once while an unchanged one stays cached for a year.
     *
     * The version hashes the file's content, not its mtime and size: a deploy that
     * keeps timestamps can change a translation without changing the size, and the
     * immutable URL would then serve the old table for a year. xxh3 reads the
     * ~650 KB file in about 0.1 ms.
     */
    public static function url(string $locale): string
    {
        $file = self::file($locale);
        $hash = $file !== null ? hash_file('xxh3', $file) : false;
        $version = $hash !== false ? substr($hash, 0, 12) : '0';
        // No ".js" extension: see the route in app/Routes/web.php.
        return url(self::ROUTE_PREFIX . rawurlencode($locale)) . '?v=' . $version;
    }

    /**
     * Body of the script, or null for a locale with no translation file.
     */
    public static function body(string $locale): ?string
    {
        $file = self::file($locale);
        if ($file === null) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        // The keys are the Italian source strings and __() falls back to the key,
        // so an entry that maps a string to itself only adds weight.
        $translations = array_filter(
            is_array($decoded) ? $decoded : [],
            static fn ($value, $key): bool => $value !== $key,
            ARRAY_FILTER_USE_BOTH
        );
        $json = json_encode(
            (object) $translations,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
        );
        return 'window.i18nTranslations = ' . ($json !== false ? $json : '{}') . ";\n";
    }

    private static function file(string $locale): ?string
    {
        // Only a locale code reaches the filesystem: no path from the URL.
        if (preg_match('/^[a-z]{2}_[A-Z]{2}$/D', $locale) !== 1) {
            return null;
        }
        $file = dirname(__DIR__, 2) . '/locale/' . $locale . '.json';
        return is_file($file) ? $file : null;
    }
}
