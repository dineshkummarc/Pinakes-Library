<script>
(function () {
    // Leave ordinary PDF links intact when the browser cannot display a modal.
    if (!window.HTMLDialogElement || typeof HTMLDialogElement.prototype.showModal !== 'function') return;
    document.querySelectorAll('[data-digital-pdf-toggle]').forEach(function (toggle) {
        toggle.setAttribute('role', 'button');
        toggle.setAttribute('aria-haspopup', 'dialog');
        toggle.setAttribute('aria-expanded', 'false');
    });
    if (window.digitalPdfLightboxBound) return;
    window.digitalPdfLightboxBound = true;
    // PDF.js dispatches this before initialization; scope the configuration to
    // our frames and keep its origin validation and document loading intact.
    document.addEventListener('webviewerloaded', function (event) {
        var source = event.detail && event.detail.source;
        var frame = Array.from(document.querySelectorAll('.digital-pdf-lightbox__frame')).find(function (candidate) {
            return candidate.contentWindow === source;
        });
        if (!frame || !source.PDFViewerApplicationOptions) return;
        var options = source.PDFViewerApplicationOptions;
        options.set('disablePreferences', true);
        options.set('viewerCssTheme', 1);
        options.set('defaultZoomValue', 'auto');
        options.set('sidebarViewOnLoad', 0);
        options.set('enableScripting', false);
        options.set('enableAltTextModelDownload', false);
        options.set('enableGuessAltText', false);
        options.set('annotationEditorMode', -1);
        source.document.addEventListener('keydown', function (keyEvent) {
            if (keyEvent.key !== 'Escape') return;
            var findbar = source.document.getElementById('findbar');
            var searchWasOpen = findbar && !findbar.classList.contains('hidden');
            // Let the reader close its search/menu first, if it consumes Esc.
            queueMicrotask(function () {
                if (searchWasOpen || keyEvent.defaultPrevented || source.document.querySelector('dialog[open]')) return;
                var dialog = frame.closest('dialog');
                if (dialog && dialog.open) dialog.close();
            });
        });
    });
    function openViewer(toggle, event) {
        var viewer = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!viewer || !viewer.matches('dialog.digital-pdf-lightbox')) return;
        if (event.type === 'click' && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)) return;
        event.preventDefault();
        if (viewer.open) return;
        // The top layer escapes clipping; moving to body also avoids inherited
        // layout rules and keeps the toolbar independent of the book's columns.
        document.body.appendChild(viewer);
        viewer.showModal();
        document.documentElement.classList.add('digital-pdf-open');
        document.body.classList.add('digital-pdf-open');
        toggle.setAttribute('aria-expanded', 'true');
        var frame = viewer.querySelector('iframe');
        var src = frame.getAttribute('data-src');
        var file = new URL(src, document.baseURI);
        if (file.origin === window.location.origin && frame.getAttribute('data-reader')) {
            var hash = new URLSearchParams(file.hash.slice(1));
            if (!hash.has('zoom')) hash.set('zoom', 'auto');
            file.hash = '';
            src = frame.getAttribute('data-reader') + '?file=' + encodeURIComponent(file.href) + '#' + hash.toString();
        } else {
            // Off-origin attachments remain readable even when their host does
            // not permit JavaScript fetches; no CORS/origin bypass or proxy.
            src += src.indexOf('#') === -1 ? '#view=FitH&navpanes=0' : '';
        }
        frame.setAttribute('src', src);
        viewer.addEventListener('close', function () {
            frame.removeAttribute('src');
            toggle.setAttribute('aria-expanded', 'false');
            if (!document.querySelector('dialog.digital-pdf-lightbox[open]')) {
                document.documentElement.classList.remove('digital-pdf-open');
                document.body.classList.remove('digital-pdf-open');
            }
            if (toggle.isConnected) toggle.focus({ preventScroll: true });
        }, { once: true });
    }
    document.addEventListener('click', function (event) {
        var close = event.target.closest && event.target.closest('[data-digital-pdf-close]');
        if (close) {
            var dialog = close.closest('dialog.digital-pdf-lightbox');
            if (dialog) dialog.close();
            return;
        }
        var toggle = event.target.closest && event.target.closest('[data-digital-pdf-toggle]');
        if (toggle) openViewer(toggle, event);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== ' ' && event.key !== 'Spacebar') return;
        var toggle = event.target.closest && event.target.closest('[data-digital-pdf-toggle]');
        if (toggle) openViewer(toggle, event);
    });
})();
</script>
