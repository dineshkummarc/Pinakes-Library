<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * Small builders shared by the Mobile API 1.7.0 OpenAPI fragments
 * (StaffCirculationOpenApi, StaffContentOpenApi, StaffPluginsOpenApi), so each
 * path stays one readable line of intent.
 */
final class OpenApiParts
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function ok(string $description, ?array $data = null): array
    {
        return [
            'description' => $description,
            'content'     => ['application/json' => ['schema' => $data === null
                ? ['$ref' => '#/components/schemas/Envelope']
                : ['allOf' => [['$ref' => '#/components/schemas/Envelope']], 'properties' => ['data' => $data]]]],
        ];
    }

    /** @return array<string, mixed> */
    public static function err(string $description): array
    {
        return [
            'description' => $description,
            'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
        ];
    }

    /** @return array<string, string> */
    public static function ref(string $schema): array
    {
        return ['$ref' => '#/components/schemas/' . $schema];
    }

    /** @return array<string, mixed> */
    public static function listOf(string $schema): array
    {
        return ['type' => 'array', 'items' => self::ref($schema)];
    }

    /** @return array<string, mixed> */
    public static function file(string $mime, string $description): array
    {
        return ['description' => $description, 'content' => [$mime => ['schema' => ['type' => 'string', 'format' => 'binary']]]];
    }

    /** @return array<string, mixed> */
    public static function path(string $name, string $what): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $what, 'schema' => ['type' => 'integer', 'minimum' => 0]];
    }

    /** @return array<string, mixed> */
    public static function query(string $name, string $type, string $description, bool $required = false): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => $required, 'description' => $description, 'schema' => ['type' => $type]];
    }

    /** @return array<string, mixed> */
    public static function body(string $schema): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => self::ref($schema)]]];
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    public static function inlineBody(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return ['required' => $required !== [], 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    public static function multipart(array $properties, array $required): array
    {
        return ['required' => true, 'content' => ['multipart/form-data' => ['schema' => [
            'type' => 'object', 'required' => $required, 'properties' => $properties,
        ]]]];
    }

    /**
     * @param array<int|string, mixed> $responses
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function op(string $tag, string $id, string $summary, array $responses, array $extra = []): array
    {
        $common = [
            '401' => ['$ref' => '#/components/responses/Unauthorized'],
            '403' => ['$ref' => '#/components/responses/Forbidden'],
            '429' => ['$ref' => '#/components/responses/TooManyRequests'],
            '500' => ['$ref' => '#/components/responses/InternalError'],
        ];

        return array_merge([
            'tags'        => [$tag],
            'summary'     => $summary,
            'operationId' => $id,
            'security'    => [['bearerAuth' => []]],
            'responses'   => $responses + $common,
        ], $extra);
    }

    /** @return array<int|string, mixed> */
    public static function notFound(): array
    {
        return ['404' => ['$ref' => '#/components/responses/NotFound']];
    }

    /** @return array<int|string, mixed> */
    public static function invalid(): array
    {
        return ['422' => ['$ref' => '#/components/responses/UnprocessableEntity']];
    }

    /** @return array<int|string, mixed> */
    public static function conflict(string $codes): array
    {
        return ['409' => self::err($codes)];
    }

    /** @return list<array<string, mixed>> */
    public static function paging(): array
    {
        return [
            self::query('cursor', 'string', 'Opaque cursor from meta.next_cursor.'),
            self::query('limit', 'integer', '1..100, default 30.'),
        ];
    }

    /** Nullable scalar schema. @return array<string, mixed> */
    public static function nullable(string $type, ?string $format = null): array
    {
        $schema = ['type' => [$type, 'null']];
        if ($format !== null) {
            $schema['format'] = $format;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    public static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties];
    }
}
