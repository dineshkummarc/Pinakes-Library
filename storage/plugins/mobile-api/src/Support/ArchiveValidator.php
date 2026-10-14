<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Validation of the Archives plugin forms for the staff API (Mobile API 1.7.0).
 *
 * The Archives admin actions re-render an HTML form on a validation error, so
 * the API validates first to answer 422 with the field. Rather than copying
 * the rules (which would drift), it runs the Archives plugin's OWN private
 * helpers — extractArchivalUnitPayload(), validateArchivalUnit(),
 * parentWouldCreateCycle(), extractAuthorityPayload(), validateAuthority() —
 * on the very form the delegated action will receive. Both plugins are
 * bundled together; tests/mobile-api-archives-validator.unit.php pins the
 * signatures, so a refactor of the Archives plugin fails there first.
 */
final class ArchiveValidator
{
    public function __construct(private object $plugin)
    {
    }

    /**
     * Field → message of the core validator for an archival-unit form, plus
     * the hierarchy-cycle rules updateAction() adds for an existing unit.
     *
     * @param array<string, string> $form
     * @return array<string, string>
     */
    public function unit(array $form, ?int $excludeId): array
    {
        $values = $this->unitValues($form);
        $method = new \ReflectionMethod($this->plugin, 'validateArchivalUnit');
        $errors = (array) $method->invokeArgs($this->plugin, [&$values, $excludeId]);
        if ($excludeId !== null && !isset($errors['parent_id']) && $values['parent_id'] !== null) {
            if ((int) $values['parent_id'] === $excludeId) {
                $errors['parent_id'] = __("Un'unità archivistica non può essere padre di se stessa.");
            } elseif ((bool) $this->call('parentWouldCreateCycle', [$excludeId, (int) $values['parent_id']])) {
                $errors['parent_id'] = __("Un'unità archivistica non può essere spostata sotto uno dei propri discendenti.");
            }
        }
        unset($errors['_global']);

        return array_map('strval', $errors);
    }

    /**
     * The normalised values the core extracts from this form.
     *
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    public function unitValues(array $form): array
    {
        return (array) $this->call('extractArchivalUnitPayload', [self::request($form)]);
    }

    /**
     * @param array<string, string> $form
     * @return array<string, string>
     */
    public function authority(array $form): array
    {
        $values = (array) $this->call('extractAuthorityPayload', [self::request($form)]);

        return array_map('strval', (array) $this->call('validateAuthority', [$values]));
    }

    /**
     * @param list<mixed> $args
     */
    private function call(string $name, array $args): mixed
    {
        $method = new \ReflectionMethod($this->plugin, $name);

        return $method->invokeArgs($this->plugin, $args);
    }

    /**
     * @param array<string, string> $form
     */
    private static function request(array $form): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/admin/archives')->withParsedBody($form);
    }
}
