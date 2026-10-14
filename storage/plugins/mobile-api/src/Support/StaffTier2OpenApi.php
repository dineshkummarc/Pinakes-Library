<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * OpenAPI fragment for the Mobile API 1.7.0 Tier 2 routes mounted by this
 * plugin: FRBR works (frbr-lrm) and digital attachments (digital-library).
 * The periodicals desk and the book club staff bridge document themselves
 * through the `mobile_api.openapi` filter of their own plugins.
 */
final class StaffTier2OpenApi
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
        $t = 'staff-plugins';
        $disabled = ['404' => O::err('`not_found`, or `feature_disabled` while the plugin is inactive.')];
        $workId = ['parameters' => [O::path('id', 'Work id')]];
        $work = O::ok('Work with editions and expressions.', O::ref('StaffWork'));
        $expression = O::ok('Expression.', O::ref('StaffExpression'));
        $workBody = [];
        foreach (array_keys(\App\Plugins\MobileApi\Controllers\StaffWorksController::WORK_FIELDS) as $key) {
            $workBody[$key] = in_array($key, ['main_author_id', 'created_from', 'created_to'], true) ? O::nullable('integer') : O::nullable('string');
        }
        $expressionBody = [];
        foreach (array_keys(\App\Plugins\MobileApi\Controllers\StaffWorksController::EXPRESSION_FIELDS) as $key) {
            $expressionBody[$key] = in_array($key, ['year', 'translator_id', 'curator_id', 'reviser_id'], true) ? O::nullable('integer') : O::nullable('string');
        }
        $expressionBody['type'] = ['type' => 'string', 'enum' => \App\Plugins\MobileApi\Controllers\StaffWorksController::EXPRESSION_TYPES];

        $doc['components']['schemas']['StaffWork'] = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'slug' => ['type' => 'string'],
            'main_author_name' => O::nullable('string'), 'editions_count' => O::nullable('integer')] + $workBody + [
            'editions' => ['type' => 'array', 'items' => O::object(['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'year' => O::nullable('integer'), 'isbn13' => O::nullable('string'), 'expression_id' => O::nullable('integer')])],
            'expressions' => O::listOf('StaffExpression'),
        ]];
        $doc['components']['schemas']['StaffExpression'] = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'work_id' => ['type' => 'integer']] + $expressionBody];

        $paths = [
            'frbr' => [
                '/staff/works' => [
                    'get' => O::op($t, 'staffWorks', 'Search works (uniform / original title); without q the first ones alphabetically', [
                        '200' => O::ok('Works (no editions/expressions in the list).', O::listOf('StaffWork')),
                    ] + $disabled, ['parameters' => [O::query('q', 'string', 'Title.'), O::query('limit', 'integer', '1..100, default 30.')]]),
                    'post' => O::op($t, 'staffCreateWork', 'Create a work', ['201' => $work] + $disabled + O::invalid(), [
                        'requestBody' => O::inlineBody($workBody, ['uniform_title']),
                    ]),
                ],
                '/staff/works/{id}' => [
                    'get' => O::op($t, 'staffWork', 'A work with its editions and expressions', ['200' => $work] + $disabled, $workId),
                    'put' => O::op($t, 'staffUpdateWork', 'Update a work (absent keys keep their value)', ['200' => $work] + $disabled + O::invalid(), $workId + ['requestBody' => O::inlineBody($workBody)]),
                    'delete' => O::op($t, 'staffDeleteWork', 'Soft-delete a work (its editions are detached)', [
                        '200' => O::ok('Deleted.', O::object(['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']])),
                    ] + $disabled, $workId),
                ],
                '/staff/works/{id}/expressions' => [
                    'get' => O::op($t, 'staffWorkExpressions', 'Expressions of a work', ['200' => O::ok('Expressions.', O::listOf('StaffExpression'))] + $disabled, $workId),
                    'post' => O::op($t, 'staffCreateExpression', 'Add an expression to a work', ['201' => $expression] + $disabled + O::invalid(), $workId + ['requestBody' => O::inlineBody($expressionBody)]),
                ],
                '/staff/works/expressions/{id}' => [
                    'put' => O::op($t, 'staffUpdateExpression', 'Update an expression (absent keys keep their value)', ['200' => $expression] + $disabled + O::invalid(), [
                        'parameters' => [O::path('id', 'Expression id')], 'requestBody' => O::inlineBody($expressionBody),
                    ]),
                    'delete' => O::op($t, 'staffDeleteExpression', 'Soft-delete an expression', [
                        '200' => O::ok('Deleted.', O::object(['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']])),
                    ] + $disabled, ['parameters' => [O::path('id', 'Expression id')]]),
                ],
                '/staff/books/{id}/work' => ['post' => O::op($t, 'staffAttachWork', 'Attach a title to a work (null detaches), optionally naming the expression', [
                    '200' => O::ok('Link.', O::object(['book_id' => ['type' => 'integer'], 'work' => ['oneOf' => [['type' => 'null'], O::object(['id' => ['type' => 'integer'], 'uniform_title' => ['type' => 'string']])]], 'expression_id' => O::nullable('integer')])),
                ] + $disabled + O::invalid(), [
                    'parameters' => [O::path('id', 'Book id')],
                    'requestBody' => O::inlineBody(['work_id' => O::nullable('integer'), 'expression_id' => O::nullable('integer')], ['work_id']),
                ])],
            ],
            'digital_library' => [
                '/staff/books/{id}/digital' => ['post' => O::op($t, 'staffDigitalUpload', 'Upload an e-book (PDF/ePub ≤ 100 MB) or audio book (≤ 500 MB); save the URL with PUT /staff/books/{id}', [
                    '201' => O::ok('Stored file.', O::object(['type' => ['type' => 'string'], 'file_url' => O::nullable('string'), 'audio_url' => O::nullable('string')])),
                    '422' => O::err('`upload_rejected` (format/content refused, message = server text) or `validation_error`.'),
                ] + $disabled, [
                    'parameters' => [O::path('id', 'Book id')],
                    'requestBody' => O::multipart(['file' => ['type' => 'string', 'format' => 'binary'], 'digital_type' => ['type' => 'string', 'enum' => ['ebook', 'audio']]], ['file', 'digital_type']),
                ])],
            ],
        ];
        foreach ($paths as $area => $areaPaths) {
            if (!StaffAreas::implemented($area)) {
                continue;
            }
            foreach ($areaPaths as $path => $operations) {
                $doc['paths'][$path] = $operations;
            }
        }

        return $doc;
    }
}
