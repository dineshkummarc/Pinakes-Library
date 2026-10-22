<?php
declare(strict_types=1);

namespace App\Support;

final class ContentSanitizer
{
    /**
     * Normalizza URL esterni noti per rispettare HTTPS e la CSP.
     */
    public static function normalizeExternalAssets(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $content = strtr($content, [
            'http://fonts.googleapis.com' => 'https://fonts.googleapis.com',
            'http://fonts.gstatic.com' => 'https://fonts.gstatic.com',
            '//fonts.googleapis.com' => 'https://fonts.googleapis.com',
            '//fonts.gstatic.com' => 'https://fonts.gstatic.com',
        ]);

        // Rimuove completamente qualsiasi inclusione esterna ai Google Fonts.
        $patterns = [
            '#<link[^>]+fonts\.googleapis\.com[^>]*>#i',
            '#<link[^>]+fonts\.gstatic\.com[^>]*>#i',
            '#@import\s+url\((?:"|\'|)(?:https?:)?//fonts\.googleapis\.com[^)]*\)\s*;?#i',
            '#https?://fonts\.googleapis\.com/[^\"\'\s>]+#i',
            '#https?://fonts\.gstatic\.com/[^\"\'\s>]+#i',
        ];

        return preg_replace($patterns, '', $content) ?? $content;
    }

    /**
     * Sanitizza CSS destinato a un blocco inline <style>…</style>.
     *
     * Il contenuto di un <style> è "raw text" per il parser HTML: l'unico
     * modo per uscirne ed arrivare all'esecuzione di script è il tag di
     * chiusura `</style` (ASCII case-insensitive). Un CSS valido non contiene
     * mai quella sequenza né `<script`, quindi neutralizzarle chiude il
     * vettore di breakout→XSS senza toccare alcun foglio di stile reale.
     * Applicata SIA in salvataggio (i valori stored restano puliti) SIA in
     * rendering (protegge i valori già presenti nel DB o iniettati altrove).
     */
    public static function sanitizeCustomCss(string $css): string
    {
        if ($css === '') {
            return $css;
        }

        // Prima la normalizzazione degli asset esterni (Google Fonts → HTTPS).
        $css = self::normalizeExternalAssets($css);

        // Rimuove ogni apertura/chiusura di <style>/<script>: sequenze che
        // non compaiono mai in CSS legittimo ma che consentirebbero di
        // rompere il contesto raw-text ed eseguire JavaScript. Come difesa
        // aggiuntiva annulla anche i marcatori di commento HTML.
        // Si ripete finché il testo non cambia più: una sola passata lascia
        // ricomporre un tag da pezzi annidati (`</sty<style>le>` → `</style>`,
        // `<scr<!---->ipt>` → `<script>`).
        do {
            $previous = $css;
            $css = preg_replace('#<\s*/?\s*(?:style|script)\b[^>]*>?#i', '', $css) ?? $css;
            $css = str_ireplace(['<!--', '-->', '<![cdata[', ']]>'], '', $css);
        } while ($css !== $previous);

        return $css;
    }

    /**
     * Estrae i meta tag di verifica della proprietà del sito (Google Search
     * Console, Bing, Yandex, Pinterest, Facebook…) dal testo che
     * l'amministratore incolla così come lo fornisce il servizio, es.
     * `<meta name="google-site-verification" content="abc123" />`.
     *
     * Del markup incollato si tengono solo name e content, poi il tag viene
     * ricostruito dal layout: nessun altro attributo o tag arriva nell'<head>.
     * Il name deve essere di verifica (contiene "verif" o "validate", come
     * `msvalidate.01` di Bing e `p:domain_verify` di Pinterest), così il campo
     * non può diventare un modo per iniettare `robots`/`description` e
     * deindicizzare il sito; il content è un token senza spazi né virgolette.
     * Applicata sia in salvataggio sia in rendering.
     *
     * @return list<array{name: string, content: string}>
     */
    public static function parseVerificationMetaTags(string $input): array
    {
        // A tag commented out (an old code kept "just in case") is not live.
        $input = (string) preg_replace('/<!--.*?(?:-->|$)/s', '', $input);
        if ($input === '' || !preg_match_all('#<meta\b([^>]*)>#i', $input, $tags)) {
            return [];
        }

        $result = [];
        foreach ($tags[1] as $attributes) {
            preg_match_all('#([a-z:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $attributes, $pairs, PREG_SET_ORDER);
            $map = [];
            foreach ($pairs as $pair) {
                $map[strtolower($pair[1])] = trim($pair[3] ?? $pair[2]);
            }

            $name = $map['name'] ?? '';
            $content = $map['content'] ?? '';
            if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/i', $name)
                || !preg_match('/verif|validate/i', $name)
                || !preg_match('#^[A-Za-z0-9._:=+/-]{1,256}$#', $content)) {
                continue;
            }

            $key = strtolower($name) . "\0" . $content;
            $result[$key] = ['name' => $name, 'content' => $content];
        }

        return array_values($result);
    }

    /**
     * Forma canonica da salvare: un meta tag ricostruito per riga.
     *
     * @param list<array{name: string, content: string}> $tags
     */
    public static function formatVerificationMetaTags(array $tags): string
    {
        $lines = [];
        foreach ($tags as $tag) {
            $lines[] = '<meta name="' . $tag['name'] . '" content="' . $tag['content'] . '">';
        }

        return implode("\n", $lines);
    }
}
