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
 * It answers the stored URL; the app then saves it on the title with
 * PUT /staff/books/{id} (`file_url` / `audio_url`), exactly like the web form.
 */
final class StaffDigitalController
{
    public function __construct(private mysqli $db, private HookManager $hooks)
    {
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
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
                $url = absoluteUrl((string) $outcome->json['uploadURL']);

                return ResponseEnvelope::success($response, [
                    'type'      => $type,
                    'file_url'  => $type === 'ebook' ? $url : null,
                    'audio_url' => $type === 'audio' ? $url : null,
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
}
