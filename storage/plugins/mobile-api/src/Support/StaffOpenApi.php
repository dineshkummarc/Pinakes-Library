<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * OpenAPI fragment for the Mobile API 1.6.0 additions: the staff content area
 * (/staff/*).
 *
 * Kept out of OpenApiController to keep that file readable; merged into the
 * document before the `mobile_api.openapi` filter runs. Every path here has a
 * row in tests/mobile-api-idempotency.spec.js (its guard fails otherwise).
 */
final class StaffOpenApi
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function extend(array $doc): array
    {
        $doc['tags'][] = ['name' => 'staff', 'description' => 'Content management for admin and staff accounts (403 forbidden for other roles).'];

        $features = &$doc['components']['schemas']['HealthPayload']['properties']['features']['properties'];
        $features['staff_catalog'] = ['type' => 'boolean', 'description' => 'The /staff/* routes exist (each call still checks the role).'];
        unset($features);

        foreach (self::schemas() as $name => $schema) {
            $doc['components']['schemas'][$name] = $schema;
        }
        foreach (self::paths() as $path => $operations) {
            $doc['paths'][$path] = $operations;
        }

        return $doc;
    }

    /**
     * @return array<string, mixed>
     */
    private static function paths(): array
    {
        $ok = static fn (string $description, ?array $data = null): array => [
            'description' => $description,
            'content'     => ['application/json' => ['schema' => $data === null
                ? ['$ref' => '#/components/schemas/Envelope']
                : ['allOf' => [['$ref' => '#/components/schemas/Envelope']], 'properties' => ['data' => $data]]]],
        ];
        $ref = static fn (string $schema): array => ['$ref' => '#/components/schemas/' . $schema];
        $list = static fn (string $schema): array => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $schema]];
        $err = static fn (string $description): array => [
            'description' => $description,
            'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
        ];
        $common = [
            '401' => ['$ref' => '#/components/responses/Unauthorized'],
            '403' => ['$ref' => '#/components/responses/Forbidden'],
            '429' => ['$ref' => '#/components/responses/TooManyRequests'],
            '500' => ['$ref' => '#/components/responses/InternalError'],
        ];
        $notFound = ['404' => ['$ref' => '#/components/responses/NotFound']];
        $invalid = ['422' => ['$ref' => '#/components/responses/UnprocessableEntity']];
        $idParam = static fn (string $what): array => [
            'name' => 'id', 'in' => 'path', 'required' => true, 'description' => $what . ' id',
            'schema' => ['type' => 'integer', 'minimum' => 1],
        ];
        $query = static fn (string $name, string $type, string $description): array => [
            'name' => $name, 'in' => 'query', 'required' => false, 'description' => $description, 'schema' => ['type' => $type],
        ];
        $body = static fn (string $schema): array => [
            'required' => true,
            'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
        ];
        $op = static function (string $tag, string $id, string $summary, array $responses, array $extra = []) use ($common): array {
            return array_merge([
                'tags'        => [$tag],
                'summary'     => $summary,
                'operationId' => $id,
                'security'    => [['bearerAuth' => []]],
                'responses'   => $responses + $common,
            ], $extra);
        };
        $duplicate = ['409' => $err('`duplicate`: meta.existing describes the record that already exists.')];

        return [
            '/staff/lookups' => ['get' => $op('staff', 'staffLookups', 'Value lists for the staff book form (ETag)', [
                '200' => $ok('Lookups.', $ref('StaffLookups')),
                '304' => ['description' => 'Not modified (If-None-Match).'],
            ])],
            '/staff/isbn/{isbn}' => ['get' => $op('staff', 'staffIsbnLookup', 'Look up an ISBN-10, ISBN-13 or EAN-13 through every scraping source', [
                '200' => $ok('Lookup result; found=false when no source knows the code.', $ref('IsbnLookup')),
                '503' => $err('`scrape_unavailable`: no scraping source is installed or reachable.'),
            ] + $invalid, ['parameters' => [[
                'name' => 'isbn', 'in' => 'path', 'required' => true, 'description' => 'Scanned or typed code; dashes and spaces are ignored.',
                'schema' => ['type' => 'string'],
            ]], 'description' => 'Rate limited to 30 requests per minute. `existing` is the non-deleted title that already carries the code, if any.'])],
            '/staff/books' => [
                'get' => $op('staff', 'staffBooks', 'Search every non-deleted title, wanted ones included (ETag)', [
                    '200' => $ok('Page of titles; meta = {count, limit, next_cursor, has_more}.', $list('StaffBookRow')),
                    '304' => ['description' => 'Not modified (If-None-Match).'],
                ], ['parameters' => [
                    $query('q', 'string', 'Free-text query.'),
                    $query('cursor', 'string', 'Opaque cursor from meta.next_cursor.'),
                    $query('limit', 'integer', '1..50, default 20.'),
                    $query('sort', 'string', 'relevance|newest|oldest|title_asc|title_desc|author_asc|author_desc'),
                ], 'description' => 'Same filters and paging as /catalog/search.']),
                'post' => $op('staff', 'staffCreateBook', 'Create a title (and its copies) through the admin book pipeline', [
                    '201' => $ok('Created.', $ref('StaffBook')),
                    '503' => $err('`lock_error`: identifier lock unavailable, retry.'),
                ] + $duplicate + $invalid, ['requestBody' => $body('StaffBookWrite')]),
            ],
            '/staff/books/{id}' => [
                'get' => $op('staff', 'staffBook', 'Edit model of a title (ETag)', [
                    '200' => $ok('Title.', $ref('StaffBook')),
                    '304' => ['description' => 'Not modified (If-None-Match).'],
                ] + $notFound, ['parameters' => [$idParam('Book')]]),
                'put' => $op('staff', 'staffUpdateBook', 'Partial update: absent keys keep their value, null clears', [
                    '200' => $ok('Updated.', $ref('StaffBook')),
                ] + $notFound + $duplicate + $invalid, ['parameters' => [$idParam('Book')], 'requestBody' => $body('StaffBookWrite')]),
                'delete' => $op('staff', 'staffDeleteBook', 'Soft-delete a title', [
                    '200' => $ok('Deleted.', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']]]),
                    '409' => $err('`conflict` with meta.reason = active_circulation (active loans or reservations).'),
                ] + $notFound, ['parameters' => [$idParam('Book')]]),
            ],
            '/staff/books/{id}/copies' => ['post' => $op('staff', 'staffAddCopies', 'Add physical copies', [
                '201' => $ok('Copies created.', $ref('AddCopiesResult')),
            ] + $notFound + $invalid, ['parameters' => [$idParam('Book')], 'requestBody' => $body('AddCopiesRequest')])],
            '/staff/books/{id}/cover' => ['post' => $op('staff', 'staffBookCover', 'Replace the cover: multipart field `cover` (JPEG/PNG, max 2 MB) or JSON {cover_url}', [
                '200' => $ok('Cover stored.', ['type' => 'object', 'properties' => ['cover_url' => ['type' => 'string', 'format' => 'uri']]]),
                '502' => $err('`cover_download_failed`: the remote image could not be fetched.'),
            ] + $notFound + $invalid, [
                'parameters'  => [$idParam('Book')],
                'requestBody' => ['required' => true, 'content' => [
                    'multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => ['cover' => ['type' => 'string', 'format' => 'binary']]]],
                    'application/json'    => ['schema' => ['type' => 'object', 'required' => ['cover_url'], 'properties' => ['cover_url' => ['type' => 'string']]]],
                ]],
            ])],
            '/staff/authors' => [
                'get' => $op('staff', 'staffAuthors', 'Search authors', [
                    '200' => $ok('Authors.', $list('StaffAuthorRow')),
                ], ['parameters' => [$query('q', 'string', 'Words matched against name and pseudonym.'), $query('limit', 'integer', '1..50, default 20.')]]),
                'post' => $op('staff', 'staffCreateAuthor', 'Create an author', [
                    '201' => $ok('Created.', $ref('StaffAuthor')),
                ] + $duplicate + $invalid, ['requestBody' => $body('StaffAuthorWrite')]),
            ],
            '/staff/authors/{id}' => [
                'get' => $op('staff', 'staffAuthor', 'Author detail', ['200' => $ok('Author.', $ref('StaffAuthor'))] + $notFound, ['parameters' => [$idParam('Author')]]),
                'put' => $op('staff', 'staffUpdateAuthor', 'Update an author (absent keys unchanged)', [
                    '200' => $ok('Updated.', $ref('StaffAuthor')),
                ] + $notFound + $invalid, ['parameters' => [$idParam('Author')], 'requestBody' => $body('StaffAuthorWrite')]),
            ],
            '/staff/publishers' => [
                'get' => $op('staff', 'staffPublishers', 'Search publishers', [
                    '200' => $ok('Publishers.', ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]]]),
                ], ['parameters' => [$query('q', 'string', 'Words matched against the name.'), $query('limit', 'integer', '1..50, default 20.')]]),
                'post' => $op('staff', 'staffCreatePublisher', 'Create a publisher', [
                    '201' => $ok('Created.', $ref('StaffPublisher')),
                ] + $duplicate + $invalid, ['requestBody' => $body('StaffPublisherWrite')]),
            ],
            '/staff/publishers/{id}' => [
                'get' => $op('staff', 'staffPublisher', 'Publisher detail', ['200' => $ok('Publisher.', $ref('StaffPublisher'))] + $notFound, ['parameters' => [$idParam('Publisher')]]),
                'put' => $op('staff', 'staffUpdatePublisher', 'Update a publisher (absent keys unchanged)', [
                    '200' => $ok('Updated.', $ref('StaffPublisher')),
                ] + $notFound + $invalid, ['parameters' => [$idParam('Publisher')], 'requestBody' => $body('StaffPublisherWrite')]),
            ],
            '/staff/genres' => [
                'get' => $op('staff', 'staffGenres', 'Genre tree (ETag)', [
                    '200' => $ok('Tree.', $list('GenreNodeStaff')),
                    '304' => ['description' => 'Not modified (If-None-Match).'],
                ]),
                'post' => $op('staff', 'staffCreateGenre', 'Find-or-create a genre under an optional parent', [
                    '201' => $ok('Genre (created=false when it already existed).', $ref('GenreCreated')),
                ] + $invalid, ['requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'required' => ['name'],
                    'properties' => ['name' => ['type' => 'string', 'maxLength' => 100], 'parent_id' => ['type' => ['integer', 'null']]],
                ]]]]]),
            ],
            '/staff/shelves' => [
                'get' => $op('staff', 'staffShelves', 'Bookcases with their levels', ['200' => $ok('Shelves.', $list('Shelf'))]),
                'post' => $op('staff', 'staffCreateShelf', 'Create a bookcase', [
                    '201' => $ok('Created.', $ref('Shelf')),
                ] + $duplicate + $invalid, ['requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'required' => ['code'],
                    'properties' => ['code' => ['type' => 'string', 'maxLength' => 20], 'name' => ['type' => ['string', 'null']], 'order' => ['type' => ['integer', 'null']]],
                ]]]]]),
            ],
            '/staff/shelves/{id}/levels' => ['post' => $op('staff', 'staffCreateLevel', 'Add a level to a bookcase', [
                '201' => $ok('Created.', $ref('ShelfLevel')),
            ] + $notFound + $duplicate + $invalid, ['parameters' => [$idParam('Shelf')], 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'required' => ['number'],
                'properties' => ['number' => ['type' => 'integer', 'minimum' => 1], 'description' => ['type' => ['string', 'null']], 'order' => ['type' => ['integer', 'null']]],
            ]]]]])],
            '/staff/shelves/next-position' => ['get' => $op('staff', 'staffNextPosition', 'Next free position on a level', [
                '200' => $ok('Position.', $ref('NextPosition')),
            ] + $invalid, ['parameters' => [
                $query('shelf_id', 'integer', 'Bookcase id.'),
                $query('level_id', 'integer', 'Level id (must belong to the bookcase).'),
                $query('book_id', 'integer', 'Optional: keep this title\'s current slot.'),
            ]])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemas(): array
    {
        $s = ['type' => ['string', 'null']];
        $i = ['type' => ['integer', 'null']];
        $idName = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]];
        $option = ['type' => 'object', 'properties' => ['value' => ['type' => 'string'], 'label' => ['type' => 'string']]];
        $ids = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]];
        $names = ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 255]];

        return [
            'StaffLookups' => ['type' => 'object', 'properties' => [
                'languages'         => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'label' => ['type' => 'string']]]],
                'media_types'       => ['type' => 'array', 'items' => $option],
                'formats'           => ['type' => 'array', 'items' => $option, 'description' => 'Values already used in the catalogue (free text).'],
                'acquisition_types' => ['type' => 'array', 'items' => $option, 'description' => 'Suggestions; the field is free text.'],
                'contributor_roles' => ['type' => 'array', 'items' => $option],
                'copy_states'       => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'value' => ['type' => 'string'], 'label' => ['type' => 'string'],
                    'creatable' => ['type' => 'boolean', 'description' => 'Allowed as status of a new copy.'],
                ]]],
            ]],
            'IsbnLookup' => ['type' => 'object', 'properties' => [
                'identifier' => ['type' => 'object', 'properties' => [
                    'input' => ['type' => 'string'], 'isbn10' => $s, 'isbn13' => $s, 'ean' => $s,
                    'kind' => ['type' => 'string', 'enum' => ['isbn', 'ean']],
                ]],
                'found'    => ['type' => 'boolean'],
                'existing' => ['oneOf' => [['type' => 'null'], ['$ref' => '#/components/schemas/ExistingBook']]],
                'preview'  => ['oneOf' => [['type' => 'null'], ['$ref' => '#/components/schemas/IsbnPreview']]],
                'sources_checked' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
            'ExistingBook' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'isbn10' => $s, 'isbn13' => $s, 'ean' => $s,
                'copies_total' => ['type' => 'integer'], 'location' => $s,
            ]],
            'IsbnPreview' => ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string'], 'subtitle' => $s, 'authors' => ['type' => 'array', 'items' => ['type' => 'string']],
                'publisher' => $s, 'year' => $i, 'pub_date' => $s, 'pages' => $i, 'language' => $s, 'description' => $s,
                'cover_url' => $s, 'isbn10' => $s, 'isbn13' => $s, 'ean' => $s, 'series' => $s, 'edition' => $s, 'place' => $s,
                'keywords' => $s, 'price' => $s, 'format' => $s, 'media_type' => $s, 'dewey' => $s, 'translator' => $s,
                'illustrator' => $s, 'author_bio' => $s, 'source' => $s,
                'editors' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
            'StaffBookRow' => ['allOf' => [['$ref' => '#/components/schemas/BookSummary']], 'type' => 'object', 'properties' => [
                'is_wanted' => ['type' => 'boolean'], 'updated_at' => $s,
            ]],
            'StaffBook' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'subtitle' => $s,
                'isbn10' => $s, 'isbn13' => $s, 'ean' => $s, 'issn' => $s, 'year' => $i, 'pub_date' => $s, 'edition' => $s,
                'place' => $s, 'pages' => $i, 'language' => $s, 'format' => $s, 'media_type' => $s, 'description' => $s,
                'keywords' => $s, 'notes' => $s, 'series' => $s, 'series_number' => $s, 'dewey' => $s,
                'price' => ['type' => ['number', 'null']], 'acquisition_date' => $s, 'acquisition_type' => $s, 'inventory_base' => $s,
                'copies_total' => ['type' => 'integer'], 'copies_available' => ['type' => 'integer'],
                'cover_url' => $s, 'cover_is_local' => ['type' => 'boolean'],
                'genre' => ['oneOf' => [['type' => 'null'], $idName]], 'subgenre' => ['oneOf' => [['type' => 'null'], $idName]],
                'authors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'name' => ['type' => 'string'],
                    'role' => ['type' => 'string', 'enum' => ['principale', 'co-autore', 'traduttore', 'illustratore', 'curatore', 'colorista']],
                ]]],
                'publishers' => ['type' => 'array', 'items' => $idName, 'description' => 'Ordered; the first is the primary publisher.'],
                'location' => ['oneOf' => [['type' => 'null'], ['type' => 'object', 'properties' => [
                    'shelf_id' => $i, 'shelf_code' => $s, 'level_id' => $i, 'level_number' => $i, 'position' => $i, 'label' => $s,
                ]]]],
                'copies' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'inventory_number' => ['type' => 'string'], 'status' => ['type' => 'string'], 'notes' => $s,
                ]]],
                'is_wanted' => ['type' => 'boolean'], 'created_at' => $s, 'updated_at' => $s,
            ]],
            'StaffBookWrite' => ['type' => 'object', 'description' => 'title is required on POST. On PUT absent keys keep their value and null clears it; `copies` is create-only (use /copies afterwards).', 'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 255], 'subtitle' => $s, 'isbn10' => $s, 'isbn13' => $s, 'ean' => $s, 'issn' => $s,
                'year' => $i, 'pub_date' => $s, 'edition' => $s, 'place' => $s, 'pages' => $i, 'language' => $s, 'format' => $s,
                'media_type' => ['type' => ['string', 'null'], 'enum' => ['libro', 'disco', 'audiolibro', 'dvd', 'altro', null]],
                'description' => $s, 'keywords' => $s, 'notes' => $s, 'series' => $s, 'series_number' => $s, 'dewey' => $s,
                'price' => ['type' => ['number', 'null']], 'acquisition_date' => ['type' => ['string', 'null'], 'format' => 'date'],
                'acquisition_type' => $s, 'inventory_base' => $s,
                'copies' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'genre_id' => $i, 'subgenre_id' => $i, 'genre_new' => $s, 'subgenre_new' => $s,
                'author_ids' => $ids, 'authors_new' => $names,
                'contributors' => ['type' => 'object', 'properties' => [
                    'translator_ids' => $ids, 'translators_new' => $names, 'illustrator_ids' => $ids, 'illustrators_new' => $names,
                    'editor_ids' => $ids, 'editors_new' => $names, 'colorist_ids' => $ids, 'colorists_new' => $names,
                ]],
                'publisher_ids' => $ids, 'publishers_new' => $names,
                'shelf_id' => $i, 'level_id' => $i, 'position' => ['type' => ['integer', 'null'], 'description' => '0 or absent = next free position.'],
                'cover_url' => ['type' => ['string', 'null'], 'description' => 'http(s) URL (downloaded) or a /uploads/copertine/ path.'],
                'remove_cover' => ['type' => 'boolean'],
                'scraped' => ['type' => 'object', 'properties' => ['author_bio' => $s]],
            ]],
            'AddCopiesRequest' => ['type' => 'object', 'required' => ['quantity'], 'properties' => [
                'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'status' => ['type' => 'string', 'enum' => ['disponibile', 'manutenzione', 'in_restauro', 'perso', 'danneggiato', 'in_trasferimento']],
                'notes' => $s,
            ]],
            'AddCopiesResult' => ['type' => 'object', 'properties' => [
                'copies_total' => ['type' => 'integer'], 'copies_available' => ['type' => 'integer'],
                'created' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'inventory_number' => ['type' => 'string']]]],
            ]],
            'StaffAuthorRow' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'pseudonym' => $s, 'label' => ['type' => 'string'], 'born' => $s, 'died' => $s,
            ]],
            'StaffAuthor' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'pseudonym' => $s, 'born' => $s, 'died' => $s,
                'nationality' => $s, 'biography' => $s, 'website' => $s, 'photo_url' => $s, 'gnd_id' => $s,
            ]],
            'StaffAuthorWrite' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 255], 'pseudonym' => $s,
                'born' => ['type' => ['string', 'null'], 'format' => 'date'], 'died' => ['type' => ['string', 'null'], 'format' => 'date'],
                'nationality' => $s, 'biography' => $s, 'website' => $s, 'gnd_id' => $s,
            ]],
            'StaffPublisher' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'website' => $s, 'address' => $s, 'phone' => $s, 'email' => $s,
            ]],
            'StaffPublisherWrite' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 255], 'website' => $s, 'address' => $s, 'phone' => $s, 'email' => $s,
            ]],
            'GenreNodeStaff' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'parent_id' => $i,
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GenreNodeStaff']],
            ]],
            'GenreCreated' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'parent_id' => $i, 'created' => ['type' => 'boolean'],
            ]],
            'ShelfLevel' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'number' => ['type' => 'integer'], 'description' => $s, 'order' => ['type' => 'integer'],
            ]],
            'Shelf' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'name' => $s, 'order' => ['type' => 'integer'],
                'levels' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ShelfLevel']],
            ]],
            'NextPosition' => ['type' => 'object', 'properties' => [
                'position' => ['type' => 'integer'], 'label' => ['type' => 'string'], 'level_number' => ['type' => 'integer'], 'shelf_code' => ['type' => 'string'],
            ]],
        ];
    }
}
