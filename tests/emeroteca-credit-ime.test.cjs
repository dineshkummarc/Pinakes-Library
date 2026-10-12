const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function picker() {
  let ready, instance;
  const added = [];
  const input = { value: '出版社', setAttribute() {} };
  const wrapper = { querySelector: selector => selector.includes('cloned') ? input : selector.includes('inner') ? { style: { setProperty() {} } } : null };
  const select = { selectedOptions: [], closest: () => wrapper, addEventListener() {} };
  const root = {
    dataset: { periodicalCredit: 'publisher' },
    querySelector: selector => selector === 'select' ? select : selector === '[data-credit-config]' ? { textContent: JSON.stringify({ fits: true }) } : { textContent: 'Publisher', hidden: true },
  };
  class Choices {
    constructor() { instance = this; }
    getValue() { return []; }
    _onEnterKey() { throw new Error('Composing Enter must not reach Choices either'); }
    setChoices(rows) { added.push(...rows); }
    clearInput() { input.value = ''; }
    hideDropdown() {}
  }
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../storage/plugins/emeroteca/assets/js/emeroteca-credits.js'), 'utf8'), {
    Choices, console, document: { addEventListener: (_, fn) => { ready = fn; }, querySelectorAll: () => [root] },
  });
  ready();
  return { enter: event => instance._onEnterKey({ preventDefault() {}, ...event }), added };
}
for (const event of [{ isComposing: true }, { keyCode: 229 }]) {
  test(`composing Enter does not create a publisher: ${JSON.stringify(event)}`, () => {
    const p = picker(); p.enter(event); assert.equal(p.added.length, 0);
  });
}
test('confirmed Enter still creates a publisher credit', () => {
  const p = picker(); p.enter({ isComposing: false, keyCode: 13 });
  assert.equal(p.added.length, 1);
  assert.equal(p.added[0].value, 'new:出版社');
});
