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
        frame.setAttribute('src', src + (src.indexOf('#') === -1 ? '#view=FitH&navpanes=0' : ''));
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
