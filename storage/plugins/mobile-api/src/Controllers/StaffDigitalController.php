<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * POST /staff/books/{id}/digital (Mobile API 1.7.0, Tier 2): upload an
 * e-book (PDF/ePub) or an audio book through the digital-library plugin's own
 * upload handler — same size caps, extension and content checks, same
 * storage. The handler expects a web session with a CSRF token: a token is
 * minted inside the isolated delegation, so nothing reaches a web session.
 *
 * Since #480 the file is attached to the title here, as a new edition or track on
 * the library's default visibility ("inherit"), and the answer carries a link
 * signed for the caller: files live outside the web root, and a file no record
 * lists opens only for operators, so an unattached upload could not be opened by
 * the app at all. (PUT /staff/books/{id} never wrote file_url / audio_url.)
 */
final class StaffDigitalController
{
    public function __construct(private mysqli $db, private HookManager $hooks)
    {
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        // The upload writes the title's attachments: like the web form, that takes editing
        // the record (catalog.manage) as well as managing files (digital.manage, the route's).
        if (!\App\Support\Permissions::can('catalog.manage')) {
            return ResponseEnvelope::error($response, 'forbidden', __('Accesso negato.'), 403);
        }
        if (!(new CollectionsController($this->db, $this->hooks))->available('digital-library')) {
            return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile su questa istanza.'), 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $type = is_string($body['digital_type'] ?? null) ? (string) $body['digital_type'] : '';
        if (!in_array($type, ['audio', 'ebook'], true)) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'digital_type']);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return ResponseEnvelope::error($response, 'validation_error', __('Nessun file caricato.'), 422, ['field' => 'file']);
        }
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM libri WHERE id = ? AND deleted_at IS NULL');
            if ($stmt === false) {
                throw new \RuntimeException('book probe prepare failed');
            }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result !== false && $result->fetch_row() !== null;
            $stmt->close();
            if (!$exists) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }
            if (!class_exists('DigitalLibraryPlugin', false)) {
                require_once dirname(__DIR__, 3) . '/digital-library/DigitalLibraryPlugin.php';
            }
            $plugin = new \DigitalLibraryPlugin($this->db, $this->hooks);
            $keys = array_merge(Delegation::SESSION_KEYS, ['csrf_token', 'csrf_token_time']);
            $outcome = Delegation::run(static function () use ($plugin, $type, $file): ResponseInterface {
                $token = \App\Support\Csrf::ensureToken();
                $inner = Delegation::request('POST', '/admin/plugins/digital-library/upload', ['digital_type' => $type])
                    ->withHeader('X-CSRF-Token', $token)
                    ->withUploadedFiles(['file' => $file]);

                return $plugin->handleUploadRequest($inner, new SlimResponse());
            }, $keys);
            if ($outcome->status === 200 && ($outcome->json['success'] ?? false) === true && is_string($outcome->json['uploadURL'] ?? null)) {
                $path = (string) (parse_url((string) $outcome->json['uploadURL'], PHP_URL_PATH) ?: $outcome->json['uploadURL']);
                $attached = $this->attach($bookId, $path, $type, (string) $file->getClientFilename());
                $user = $request->getAttribute(\App\Plugins\MobileApi\Support\AppAuthMiddleware::ATTR_USER);
                $url = absoluteUrl($path);
                if (is_array($user) && (int) ($user['id'] ?? 0) > 0) {
                    $url = \App\Support\DigitalAccess::signedUrl($url, (int) $user['id'], $this->db);
                }

                return ResponseEnvelope::success($response, [
                    'type'      => $type,
                    'file_url'  => $type === 'ebook' ? $url : null,
                    'audio_url' => $type === 'audio' ? $url : null,
                    'attached'  => $attached,
                ], [], 201);
            }
            if ($outcome->status >= 500) {
                throw new \RuntimeException('digital upload answered HTTP ' . $outcome->status);
            }

            return ResponseEnvelope::error(
                $response,
                'upload_rejected',
                is_string($outcome->json['message'] ?? null) ? (string) $outcome->json['message'] : __('Formato file non supportato.'),
                422,
                ['field' => 'file']
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff digital upload failed: ' . $e->getMessage());

            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    /**
     * Add the stored file to the title's attachments, as the web form does when the
     * operator saves after an upload: last in the list, on the library default
     * visibility, with the first edition/track mirrored in file_url / audio_url.
     * A record holding an attachment the list cannot read is left untouched rather
     * than rewritten without it: false, and the file stays unlisted (operators only).
     */
    private function attach(int $bookId, string $path, string $type, string $clientName): bool
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT id, file_url, audio_url, digital_attachments FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            if ($stmt === false) {
                throw new \RuntimeException('book read prepare failed');
            }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $rows = is_array($book) ? \App\Support\DigitalAttachments::fromBook($book, true) : null;
            if ($rows === null || array_filter($rows, static fn (array $row): bool => !empty($row['invalid'])) !== []) {
                $this->db->rollback();
                return false;
            }
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['url'] !== $path));
            $label = trim(pathinfo($clientName, PATHINFO_FILENAME));
            $rows[] = [
                'url' => $path,
                'label' => mb_substr($label !== '' ? $label : basename($path), 0, 255),
                'kind' => $type === 'audio' ? 'audio' : 'ebook',
                'visibility' => 'inherit',
            ];
            $fields = \App\Support\DigitalAttachments::applySubmission([], ['digital_attachments_present' => '1', 'digital_attachments' => $rows]);
            $stmt = $this->db->prepare('UPDATE libri SET digital_attachments = ?, file_url = ?, audio_url = ? WHERE id = ? AND deleted_at IS NULL');
            if ($stmt === false) {
                throw new \RuntimeException('book write prepare failed');
            }
            $stmt->bind_param('sssi', $fields['digital_attachments'], $fields['file_url'], $fields['audio_url'], $bookId);
            $stmt->execute();
            $stmt->close();
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
