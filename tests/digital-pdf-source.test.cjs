const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// Execute the shipped script with a minimal DOM. The asserted result is the
// iframe navigation, not a duplicate of the URL-validation implementation.
function open(file, reader = '/assets/vendor/pdfjs-6.4.299/web/viewer.html') {
  const handlers = {};
  const frameAttrs = { 'data-src': file, 'data-reader': reader };
  const classes = { add() {}, remove() {} };
  const frame = {
    getAttribute: key => frameAttrs[key],
    setAttribute: (key, value) => { frameAttrs[key] = value; },
    removeAttribute: key => { delete frameAttrs[key]; },
  };
  const viewer = {
    open: false, matches: () => true, querySelector: () => frame,
    showModal() { this.open = true; }, addEventListener() {},
  };
  const toggle = { getAttribute: () => 'reader', setAttribute() {} };
  function HTMLDialogElement() {}
  HTMLDialogElement.prototype.showModal = () => {};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../storage/plugins/digital-library/assets/js/pdf-reader.js'), 'utf8'), {
    HTMLDialogElement, URL, URLSearchParams,
    window: { HTMLDialogElement, location: { origin: 'https://library.example' } },
    document: {
      baseURI: 'https://library.example/book/1',
      querySelectorAll: () => [], getElementById: () => viewer,
      addEventListener: (type, handler) => { handlers[type] = handler; },
      documentElement: { classList: classes }, body: { classList: classes, appendChild() {} },
    },
  });
  handlers.click({ type: 'click', preventDefault() {}, target: { closest: selector => selector === '[data-digital-pdf-toggle]' ? toggle : null } });
  return { opened: viewer.open, src: frameAttrs.src };
}

for (const url of ['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'file:///tmp/private.pdf', 'blob:https://library.example/id', 'https://[invalid']) {
  test(`reject unsafe or malformed PDF URL ${url}`, () => {
    assert.deepEqual(open(url), { opened: false, src: undefined });
  });
}
for (const reader of ['javascript:alert(1)', 'https://evil.example/viewer.html', '//evil.example/viewer.html']) {
  test(`reject off-origin or executable viewer ${reader}`, () => {
    assert.deepEqual(open('/uploads/book.pdf', reader), { opened: false, src: undefined });
  });
}
test('same-origin PDF uses the bundled reader and preserves zoom', () => {
  const result = open('/uploads/book.pdf#zoom=140');
  assert.equal(result.opened, true);
  const src = new URL(result.src);
  assert.equal(src.origin, 'https://library.example');
  assert.equal(src.pathname, '/assets/vendor/pdfjs-6.4.299/web/viewer.html');
  assert.equal(src.searchParams.get('file'), 'https://library.example/uploads/book.pdf');
  assert.equal(src.hash, '#zoom=140');
});
test('external HTTPS PDF remains native without a proxy or CORS bypass', () => {
  assert.deepEqual(open('https://archive.example/book.pdf'), { opened: true, src: 'https://archive.example/book.pdf#view=FitH&navpanes=0' });
});
test('PDF links with query parameters remain a single encoded file parameter', () => {
  const src = new URL(open('/uploads/book.pdf?a=1&b=2').src);
  assert.equal(src.searchParams.get('file'), 'https://library.example/uploads/book.pdf?a=1&b=2');
  assert.equal(src.searchParams.size, 1);
});
