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
        options.set('spreadModeOnLoad', 0);
        options.set('scrollModeOnLoad', 0);
        options.set('enableScripting', false);
        options.set('enableAltTextModelDownload', false);
        options.set('enableGuessAltText', false);
        options.set('annotationEditorMode', -1);
        source.document.addEventListener('keydown', function (keyEvent) {
            if (keyEvent.key !== 'Escape') return;
            // Esc consistently exits the lightbox, even with focus in the PDF.
            keyEvent.preventDefault();
            var dialog = frame.closest('dialog');
            if (dialog && dialog.open) dialog.close();
        });
    });
    function openViewer(toggle, event) {
        var viewer = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!viewer || !viewer.matches('dialog.digital-pdf-lightbox')) return;
        if (event.type === 'click' && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)) return;
        event.preventDefault();
        if (viewer.open) return;
        var frame = viewer.querySelector('iframe');
        var source;
        try {
            var file = new URL(frame.getAttribute('data-src'), document.baseURI);
            // Validate again at the DOM boundary, even for server-sanitized attachments.
            if (file.protocol !== 'https:' && file.protocol !== 'http:') return;
            source = file;
            if (file.origin === window.location.origin && frame.getAttribute('data-reader')) {
                var reader = new URL(frame.getAttribute('data-reader'), document.baseURI);
                if (reader.origin !== window.location.origin) return;
                var hash = new URLSearchParams(file.hash.slice(1));
                if (!hash.has('zoom')) hash.set('zoom', 'auto');
                file.hash = '';
                reader.searchParams.set('file', file.href);
                reader.hash = hash.toString();
                source = reader;
            } else if (!file.hash) {
                // Off-origin attachments retain native reading without a CORS bypass.
                file.hash = 'view=FitH&navpanes=0';
            }
        } catch (_) { return; }
        // Both branches must produce a canonical HTTP(S) URL before assigning src.
        if (source.protocol !== 'https:' && source.protocol !== 'http:') return;
        // The top layer escapes clipping; moving to body also avoids inherited
        // layout rules and keeps the toolbar independent of the book's columns.
        document.body.appendChild(viewer);
        viewer.showModal();
        document.documentElement.classList.add('digital-pdf-open');
        document.body.classList.add('digital-pdf-open');
        toggle.setAttribute('aria-expanded', 'true');
        frame.setAttribute('src', source.href);
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
