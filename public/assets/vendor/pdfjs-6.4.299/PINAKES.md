# PDF.js in Pinakes

Mozilla PDF.js 6.4.299, prebuilt legacy distribution for wider browser support.
Source: https://github.com/mozilla/pdf.js/releases/tag/v6.4.299
Archive: pdfjs-6.4.299-legacy-dist.zip
SHA-256: c894bce53b2f4c3e13141e088c89cc051a1f7118ba4a2c02ec058798ae1303d1
License: Apache-2.0 (included LICENSE).

Bundled locally, with no CDN request. Source maps and sample PDFs are omitted.
`web/viewer.html` adds the Pinakes title and `pinakes-viewer.css` skin.
The viewer CSP restricts connections to this origin, blobs and data URLs.
`.htaccess` supplies JavaScript and WebAssembly MIME types for Apache/LiteSpeed.
The engine, worker, viewer JavaScript and translations are unmodified.

The embedding plugin sets viewerCssTheme=1 and disables stored preferences,
PDF scripting, model downloads and annotation editing before initialization. Same-origin PDFs
use this viewer; remote PDFs retain the browser reader to preserve access
without bypassing PDF.js origin checks or introducing a proxy.

When updating, replace the versioned directory from the official release,
verify its archive digest, preserve the skin, and update the URL in
storage/plugins/digital-library/views/frontend-pdf-lightbox.php.
