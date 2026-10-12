<?php
/** Fullscreen reader for the legacy single-PDF attachment. */
$fileUrl = (string) ($book['file_url'] ?? '');
$urlPath = (string) (parse_url($fileUrl, PHP_URL_PATH) ?? '');
if ($fileUrl === '' || strtolower(pathinfo($urlPath, PATHINFO_EXTENSION)) !== 'pdf') return;
$viewerId = 'pdf-viewer-container';
$pdfHref = url($fileUrl);
$pdfLabel = (string) ($book['titolo'] ?? 'PDF');
include __DIR__ . '/frontend-pdf-lightbox.php';
include __DIR__ . '/frontend-pdf-script.php';
