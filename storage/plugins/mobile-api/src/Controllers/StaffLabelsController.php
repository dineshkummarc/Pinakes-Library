<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Controllers\LibriController;
use App\Controllers\SettingsController;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\HttpCache;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Label PDFs and label settings for the staff app (Mobile API 1.7.0).
 *
 * The PDFs are produced by the same LibriController actions as the admin
 * book page (TCPDF, Code128/EAN barcodes, the stored label format); the
 * settings are written through SettingsController::updateLabels().
 */
final class StaffLabelsController
{
    private const TOGGLES = ['show_app_name', 'show_title', 'show_subtitle', 'show_author_publisher', 'show_dewey'];

    public function __construct(private mysqli $db)
    {
    }

    public function bookLabel(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            if (!$this->bookExists($bookId)) {
                return $this->notFound($response, __('Libro non trovato.'));
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new LibriController())->generateLabelPDF(
                Delegation::request('GET', '/api/libri/' . $bookId . '/etichetta-pdf'),
                new SlimResponse(),
                $db,
                $bookId
            ));

            return $this->pdf($response, $outcome->response, $outcome->body);
        } catch (\Slim\Exception\HttpNotFoundException $e) {
            return $this->notFound($response, __('Libro non trovato.'));
        } catch (\Throwable $e) {
            return $this->fail($response, 'book label', $e);
        }
    }

    public function copyLabels(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        $copyRaw = StaffPaging::query($request, 'copy_id');
        $copyId = $copyRaw === '' ? 0 : StaffPaging::id($copyRaw);
        if ($copyRaw !== '' && $copyId <= 0) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'copy_id']);
        }
        try {
            if (!$this->bookExists($bookId)) {
                return $this->notFound($response, __('Libro non trovato.'));
            }
            if ($copyId > 0 && $this->count('SELECT COUNT(*) FROM copie WHERE id = ? AND libro_id = ?', [$copyId, $bookId]) === 0) {
                return $this->notFound($response, __('Copia non trovata.'));
            }
            $db = $this->db;
            $query = $copyId > 0 ? ['copy_id' => (string) $copyId] : [];
            $outcome = Delegation::run(static fn (): ResponseInterface => (new LibriController())->generateCopyLabelsPDF(
                Delegation::request('GET', '/admin/books/' . $bookId . '/copy-labels-pdf', [], $query),
                new SlimResponse(),
                $db,
                $bookId
            ));
            if ($outcome->isRedirect()) {
                return ResponseEnvelope::error(
                    $response,
                    'no_copies',
                    $outcome->flash('error_message') ?? __('Nessuna copia disponibile per la stampa delle etichette.'),
                    409
                );
            }

            return $this->pdf($response, $outcome->response, $outcome->body);
        } catch (\Slim\Exception\HttpNotFoundException $e) {
            return $this->notFound($response, __('Libro non trovato.'));
        } catch (\Throwable $e) {
            return $this->fail($response, 'copy labels', $e);
        }
    }

    public function settings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return HttpCache::envelope($request, $response, 'staff-label-settings', $this->current());
        } catch (\Throwable $e) {
            return $this->fail($response, 'label settings', $e);
        }
    }

    public function updateSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        try {
            $settings = $this->current();
            foreach (['width', 'height'] as $key) {
                if (!array_key_exists($key, $json)) {
                    continue;
                }
                if (!is_int($json[$key]) || $json[$key] < 10 || $json[$key] > 100) {
                    return ResponseEnvelope::error($response, 'validation_error', __('Dimensioni etichetta non valide. Devono essere comprese tra 10mm e 100mm.'), 422, ['field' => $key]);
                }
                $settings[$key] = $json[$key];
            }
            if (array_key_exists('padding', $json)) {
                $padding = $json['padding'];
                if (!(is_int($padding) || is_float($padding)) || $padding < 0 || $padding > 30) {
                    return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'padding']);
                }
                $settings['padding'] = (float) $padding;
            }
            foreach (self::TOGGLES as $key) {
                if (array_key_exists($key, $json)) {
                    if (!is_bool($json[$key])) {
                        return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => $key]);
                    }
                    $settings[$key] = $json[$key];
                }
            }

            // The web form posts checkboxes: a toggle is ON when its key is present.
            $form = [
                'label_format'  => $settings['width'] . 'x' . $settings['height'],
                'label_padding' => (string) $settings['padding'],
            ];
            foreach (self::TOGGLES as $key) {
                if ($settings[$key] === true) {
                    $form[$key] = '1';
                }
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new SettingsController())->updateLabels(
                Delegation::request('POST', '/admin/settings/labels', $form),
                new SlimResponse(),
                $db
            ));
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return ResponseEnvelope::error($response, 'validation_error', $error, 422, ['field' => 'width']);
            }

            return ResponseEnvelope::success($response, $this->current());
        } catch (\Throwable $e) {
            return $this->fail($response, 'label settings update', $e);
        }
    }

    /**
     * The stored label settings, normalised like LibriController::resolveLabelSettings().
     *
     * @return array{width:int, height:int, padding:float, show_app_name:bool, show_title:bool, show_subtitle:bool, show_author_publisher:bool, show_dewey:bool}
     */
    private function current(): array
    {
        $repo = new \App\Models\SettingsRepository($this->db);
        $width = (int) ($repo->get('label', 'width', (string) \App\Support\ConfigStore::get('label.width', 25)) ?? 25);
        $height = (int) ($repo->get('label', 'height', (string) \App\Support\ConfigStore::get('label.height', 38)) ?? 38);
        if ($width < 10 || $width > 100) {
            $width = 25;
        }
        if ($height < 10 || $height > 100) {
            $height = 38;
        }
        $padding = max(0.0, min(30.0, (float) ($repo->get('label', 'padding', '0') ?? '0')));
        $toggle = static fn (string $key): bool => ($repo->get('label', $key, '1') ?? '1') === '1';

        return [
            'width'                 => $width,
            'height'                => $height,
            'padding'               => $padding,
            'show_app_name'         => $toggle('show_app_name'),
            'show_title'            => $toggle('show_title'),
            'show_subtitle'         => $toggle('show_subtitle'),
            'show_author_publisher' => $toggle('show_author_publisher'),
            'show_dewey'            => $toggle('show_dewey'),
        ];
    }

    private function pdf(ResponseInterface $response, ResponseInterface $core, string $body): ResponseInterface
    {
        if ($core->getStatusCode() !== 200 || !str_starts_with($body, '%PDF-')) {
            throw new \RuntimeException('label PDF not produced (HTTP ' . $core->getStatusCode() . ')');
        }
        $response->getBody()->write($body);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', $core->getHeaderLine('Content-Disposition') ?: 'inline')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function bookExists(int $bookId): bool
    {
        return $this->count('SELECT COUNT(*) FROM libri WHERE id = ? AND deleted_at IS NULL', [$bookId]) > 0;
    }

    /** @param list<int> $params */
    private function count(string $sql, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed');
        }
        $stmt->bind_param(str_repeat('i', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();

        return (int) ($row[0] ?? 0);
    }

    private function notFound(ResponseInterface $response, string $message): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', $message, 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
