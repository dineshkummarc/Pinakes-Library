<?php
/** @var string $viewerId @var string $pdfHref @var string $pdfLabel */
$escapedViewerId = \App\Support\HtmlHelper::e($viewerId);
$escapedPdfHref = \App\Support\HtmlHelper::e($pdfHref);
$escapedPdfLabel = \App\Support\HtmlHelper::e($pdfLabel);
$escapedReaderHref = \App\Support\HtmlHelper::e(url('/assets/vendor/pdfjs-6.4.299/web/viewer.html'));
?>
<dialog class="digital-pdf-lightbox" id="<?= $escapedViewerId ?>" aria-labelledby="<?= $escapedViewerId ?>-title">
    <header class="digital-pdf-lightbox__toolbar">
        <h2 id="<?= $escapedViewerId ?>-title"><?= $escapedPdfLabel ?></h2>
        <a href="<?= $escapedPdfHref ?>" target="_blank" rel="noopener noreferrer" class="digital-pdf-lightbox__action"><?= __('Apri in una nuova scheda') ?></a>
        <button type="button" class="digital-pdf-lightbox__action" data-digital-pdf-close autofocus><?= __('Chiudi') ?></button>
    </header>
    <iframe class="digital-pdf-lightbox__frame" data-src="<?= $escapedPdfHref ?>" data-reader="<?= $escapedReaderHref ?>" title="<?= $escapedPdfLabel ?>"></iframe>
</dialog>
