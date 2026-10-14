<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * OpenAPI fragment for the Mobile API 1.7.0 staff work on plugin content
 * (tag `staff-plugins`): desiderata and archives. Only mounted areas are
 * documented; every path has a row in tests/mobile-api-idempotency.spec.js.
 */
final class StaffPluginsOpenApi
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
        $doc['tags'][] = ['name' => 'staff-plugins', 'description' => 'Staff work on plugin content: desiderata (donation proposals) and archives. 404 feature_disabled while the plugin is inactive.'];
        foreach (self::schemas() as $name => $schema) {
            $doc['components']['schemas'][$name] = $schema;
        }
        foreach (self::paths() as $area => $paths) {
            if (!StaffAreas::implemented($area)) {
                continue;
            }
            foreach ($paths as $path => $operations) {
                $doc['paths'][$path] = $operations;
            }
        }

        return $doc;
    }

    /** @return array<string, array<string, mixed>> */
    private static function paths(): array
    {
        $t = 'staff-plugins';
        $disabled = ['404' => O::err('`not_found`, or `feature_disabled` while the plugin is inactive.')];
        $offerId = ['parameters' => [O::path('id', 'Offer id')]];
        $offer = O::ok('The offer as it is now.', O::ref('StaffOffer'));
        $unitId = ['parameters' => [O::path('id', 'Archival unit id')]];
        $detail = O::ok('Unit detail.', O::ref('StaffArchiveDetail'));
        $authorityId = ['parameters' => [O::path('id', 'Authority record id')]];
        $authority = O::ok('Authority record.', O::ref('StaffAuthority'));

        return [
            'desiderata' => [
                '/staff/desiderata/offers' => ['get' => O::op($t, 'staffDesiderataOffers', 'Donation proposals', [
                    '200' => O::ok('Page of proposals.', O::listOf('StaffOffer')),
                ] + $disabled + O::invalid(), ['parameters' => array_merge([
                    O::query('status', 'string', 'pending|accepted|received|rejected|all (default pending).'),
                    O::query('q', 'string', 'Title, author, donor, ISBN.'),
                ], O::paging())])],
                '/staff/desiderata/offers/{id}' => ['delete' => O::op($t, 'staffDeleteOffer', 'Delete a proposal (erases the donor data; any state)', [
                    '200' => O::ok('Deleted.', O::object(['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']])),
                ] + $disabled, $offerId)],
                '/staff/desiderata/offers/{id}/accept' => ['post' => O::op($t, 'staffAcceptOffer', 'Accept a proposal', ['200' => $offer] + $disabled + O::conflict('`offer_closed`: already received or rejected.'), $offerId)],
                '/staff/desiderata/offers/{id}/reject' => ['post' => O::op($t, 'staffRejectOffer', 'Reject a proposal', ['200' => $offer] + $disabled + O::conflict('`offer_closed`.'), $offerId)],
                '/staff/desiderata/offers/{id}/receive' => ['post' => O::op($t, 'staffReceiveOffer', 'Register the donated copy', [
                    '200' => O::ok('Received: copy_id is the new copy.', O::ref('StaffOffer')),
                ] + $disabled + O::invalid() + O::conflict('`offer_closed`.'), $offerId + [
                    'requestBody' => O::inlineBody(['received_book_id' => ['type' => ['integer', 'null'], 'description' => 'Required when the proposal is not linked to a catalogue record.']]),
                ])],
                '/staff/desiderata/wanted' => ['get' => O::op($t, 'staffDesiderataWanted', 'Requested titles with their open proposals', [
                    '200' => O::ok('Same shape as /desiderata + open_offers.', ['type' => 'array', 'items' => ['allOf' => [O::ref('WantedBook'), O::object(['open_offers' => ['type' => 'integer']])]]]),
                ] + $disabled, ['parameters' => array_merge([O::query('q', 'string', 'Search.')], O::paging())])],
                '/staff/desiderata/wanted/{bookId}/receive' => ['post' => O::op($t, 'staffReceiveWanted', 'Register a copy handed over at the desk without a proposal', [
                    '201' => O::ok('Received.', O::object(['book_id' => ['type' => 'integer'], 'copy_id' => O::nullable('integer'), 'offer_id' => O::nullable('integer')])),
                ] + $disabled + O::conflict('`conflict`, meta.reason = open_offers (receive from the proposal) or not_wanted.'), ['parameters' => [O::path('bookId', 'Book id')]])],
            ],
            'archives' => [
                '/staff/archives/lookups' => ['get' => O::op($t, 'staffArchiveLookups', 'Value lists of the archive forms (ETag)', [
                    '200' => O::ok('Lookups.', O::object([
                        'levels' => O::listOf('Option'), 'specific_materials' => O::listOf('Option'), 'color_modes' => O::listOf('Option'),
                        'authority_types' => O::listOf('Option'), 'authority_roles' => O::listOf('Option'),
                    ])),
                    '304' => ['description' => 'Not modified (If-None-Match).'],
                ] + $disabled)],
                '/staff/archives' => [
                    'get' => O::op($t, 'staffArchives', 'Archival units, unpublished included', [
                        '200' => O::ok('Same shape and filters as /archives + published.'),
                    ] + $disabled + O::invalid(), ['parameters' => [O::query('published', 'string', 'all|1|0 (default all).'), O::query('q', 'string', 'Search.'),
                        O::query('level', 'string', 'fonds|series|file|item'), O::query('parent_id', 'integer', 'Children of a unit.'), O::query('cursor', 'string', 'Page cursor.'), O::query('limit', 'integer', '1..50')]]),
                    'post' => O::op($t, 'staffCreateArchive', 'Create an archival unit', [
                        '201' => $detail,
                    ] + $disabled + O::invalid() + O::conflict('`duplicate`: institution_code + reference_code taken, meta.existing = {id, reference_code}.'), ['requestBody' => O::body('ArchiveUnitWrite')]),
                ],
                '/staff/archives/{id}' => [
                    'get' => O::op($t, 'staffArchive', 'Archival unit detail with every field, files and authorities', ['200' => $detail] + $disabled, $unitId),
                    'put' => O::op($t, 'staffUpdateArchive', 'Update (absent keys keep their value); published toggles the public visibility', [
                        '200' => $detail,
                    ] + $disabled + O::invalid() + O::conflict('`duplicate`.'), $unitId + ['requestBody' => O::body('ArchiveUnitWrite')]),
                    'delete' => O::op($t, 'staffDeleteArchive', 'Soft-delete an archival unit (frees its reference code)', [
                        '200' => O::ok('Deleted.', O::object(['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']])),
                    ] + $disabled, $unitId),
                ],
                '/staff/archives/{id}/cover' => [
                    'post' => O::op($t, 'staffArchiveCover', 'Replace the cover image (JPEG/PNG/WebP, max 5 MB)', [
                        '200' => $detail, '422' => O::err('`upload_rejected`.'),
                    ] + $disabled, $unitId + ['requestBody' => O::multipart(['cover' => ['type' => 'string', 'format' => 'binary']], ['cover'])]),
                    'delete' => O::op($t, 'staffArchiveRemoveCover', 'Remove the cover image (idempotent)', ['200' => $detail] + $disabled, $unitId),
                ],
                '/staff/archives/{id}/documents' => ['post' => O::op($t, 'staffArchiveDocument', 'Attach a document (PDF, ePub, audio, video, TIFF/JPEG/PNG; max 200 MB)', [
                    '201' => $detail, '422' => O::err('`upload_rejected`: format not allowed or too large.'),
                ] + $disabled, $unitId + ['requestBody' => O::multipart(['document' => ['type' => 'string', 'format' => 'binary']], ['document'])])],
                '/staff/archives/{id}/documents/{fileId}' => ['delete' => O::op($t, 'staffArchiveRemoveDocument', 'Remove a document (fileId 0 = the legacy single document)', ['200' => $detail] + $disabled, [
                    'parameters' => [O::path('id', 'Archival unit id'), O::path('fileId', 'File id (0 = legacy document)')],
                ])],
                '/staff/archives/{id}/authorities' => ['post' => O::op($t, 'staffArchiveAttachAuthority', 'Link an authority with a role (idempotent)', [
                    '200' => $detail,
                ] + $disabled + O::invalid(), $unitId + ['requestBody' => O::inlineBody(['authority_id' => ['type' => 'integer'], 'role' => ['type' => 'string', 'enum' => self::authorityRoles()]], ['authority_id', 'role'])])],
                '/staff/archives/{id}/authorities/{authorityId}' => ['delete' => O::op($t, 'staffArchiveDetachAuthority', 'Unlink an authority (every role)', ['200' => $detail] + $disabled, [
                    'parameters' => [O::path('id', 'Archival unit id'), O::path('authorityId', 'Authority id')],
                ])],
                '/staff/archives/authorities' => [
                    'get' => O::op($t, 'staffAuthorities', 'Authority records', [
                        '200' => O::ok('Page.', ['type' => 'array', 'items' => O::object(['id' => ['type' => 'integer'], 'type' => ['type' => 'string'], 'name' => ['type' => 'string'], 'dates' => O::nullable('string'), 'units_count' => ['type' => 'integer']])]),
                    ] + $disabled + O::invalid(), ['parameters' => array_merge([O::query('q', 'string', 'Name.'), O::query('type', 'string', 'person|corporate|family')], O::paging())]),
                    'post' => O::op($t, 'staffCreateAuthority', 'Create an authority record (ISAAR-CPF)', ['201' => $authority] + $disabled + O::invalid(), ['requestBody' => O::body('AuthorityWrite')]),
                ],
                '/staff/archives/authorities/{id}' => [
                    'get' => O::op($t, 'staffAuthority', 'Authority record with its units and linked authors', ['200' => $authority] + $disabled, $authorityId),
                    'put' => O::op($t, 'staffUpdateAuthority', 'Update (absent keys keep their value)', ['200' => $authority] + $disabled + O::invalid(), $authorityId + ['requestBody' => O::body('AuthorityWrite')]),
                    'delete' => O::op($t, 'staffDeleteAuthority', 'Soft-delete an authority record', [
                        '200' => O::ok('Deleted.', O::object(['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']])),
                    ] + $disabled, $authorityId),
                ],
            ],
        ];
    }

    /** @return list<string> the Archives plugin's roles (bundled file; loaded on demand). */
    private static function authorityRoles(): array
    {
        if (!class_exists(\App\Plugins\Archives\ArchivesPlugin::class, false)) {
            $file = dirname(__DIR__, 3) . '/archives/ArchivesPlugin.php';
            if (!is_file($file)) {
                return [];
            }
            require_once $file;
        }

        return array_keys(\App\Plugins\Archives\ArchivesPlugin::AUTHORITY_ROLES);
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $s = ['type' => 'string'];
        $ns = O::nullable('string');
        $i = ['type' => 'integer'];
        $ni = O::nullable('integer');
        $ref = static fn (): array => ['oneOf' => [['type' => 'null'], O::object(['id' => ['type' => 'integer'], 'title' => ['type' => 'string']])]];
        $unit = [];
        foreach (\App\Plugins\MobileApi\Controllers\StaffArchivesController::UNIT_KEYS as $key) {
            $unit[$key] = match ($key) {
                'date_start', 'date_end', 'parent_id' => $ni,
                'published' => ['type' => 'boolean', 'description' => 'false hides the unit from the public /archives (default true on create).'],
                default => $ns,
            };
        }
        $authority = [];
        foreach (\App\Plugins\MobileApi\Controllers\StaffArchivesController::AUTHORITY_KEYS as $key) {
            $authority[$key] = $key === 'type' ? ['type' => 'string', 'enum' => ['person', 'corporate', 'family']] : $ns;
        }

        return [
            'Option' => O::object(['value' => $s, 'label' => $s]),
            'StaffOffer' => O::object([
                'id' => $i, 'status' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'received', 'rejected']],
                'donor_name' => $ns, 'donor_email' => $ns, 'title' => $s, 'author' => $ns, 'publisher' => $ns, 'isbn' => $ns, 'notes' => $ns,
                'book' => $ref(), 'received_book' => $ref(), 'copy_id' => $ni, 'source' => ['type' => 'string', 'enum' => ['web', 'mobile']],
                'created_at' => $s, 'received_at' => $ns,
            ]),
            'ArchiveUnitWrite' => ['type' => 'object', 'description' => 'reference_code, level and constructed_title are required on POST; institution_code defaults to PINAKES, language_codes to ita, specific_material to text.', 'properties' => $unit],
            'AuthorityWrite' => ['type' => 'object', 'description' => 'type and authorised_form are required.', 'properties' => $authority],
            'StaffArchiveDetail' => O::object([
                'id' => $i, 'title' => $s, 'reference_code' => $s, 'level' => $s, 'published' => ['type' => 'boolean'],
                'fields' => ['type' => 'object', 'description' => 'Every non-empty column of the unit.'],
                'files' => ['type' => 'array', 'items' => O::object(['id' => $i, 'url' => $ns, 'mime' => $s, 'label' => $s])],
                'authorities' => ['type' => 'array', 'items' => O::object(['id' => $i, 'type' => $s, 'name' => $s, 'role' => $s])],
            ]),
            'StaffAuthority' => ['type' => 'object', 'description' => 'Every column of the authority record, plus units [{id,title,role}] and authors [{id,name}].', 'properties' => [
                'id' => $i, 'type' => $s, 'authorised_form' => $s,
                'units' => ['type' => 'array', 'items' => O::object(['id' => $i, 'title' => $s, 'role' => $s])],
                'authors' => ['type' => 'array', 'items' => O::object(['id' => $i, 'name' => $s])],
            ]],
        ];
    }
}
