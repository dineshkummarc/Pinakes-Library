/* Ordered, removable chips, matching the author picker used throughout Pinakes. */
document.addEventListener('DOMContentLoaded', function () {
  if (typeof Choices === 'undefined') return;
  document.querySelectorAll('[data-periodical-credit]').forEach(function (root) {
    const publisher = root.dataset.periodicalCredit === 'publisher';
    const config = JSON.parse(root.querySelector('[data-credit-config]').textContent);
    if (!config.fits) return;
    const select = root.querySelector('select');
    const initial = Array.from(select.selectedOptions).map(option => ({value: option.value, label: option.textContent}));
    let choice;
    try {
      choice = new Choices(select, {
        searchEnabled: true, searchChoices: false, removeItemButton: true,
        duplicateItemsAllowed: false, maxItemCount: 20, maxItemText: () => config.limit,
        placeholder: true, placeholderValue: config.placeholder,
        noChoicesText: config.noChoices, itemSelectText: config.select,
        shouldSort: false, searchResultLimit: -1, searchFloor: 1, allowHTML: false
      });
      if ((choice.getValue() || []).length !== initial.length) throw new Error('Credit chips not loaded');
    } catch (error) {
      console.error(error);
      if (choice) choice.destroy();
      return;
    }
    if (!publisher) {
      const fallback = root.querySelector('[data-credit-fallback]');
      fallback.querySelector('input').disabled = true;
      fallback.hidden = true;
      select.name = 'direttori[]';
      const marker = document.createElement('input');
      marker.type = 'hidden'; marker.name = 'direttori_present'; marker.value = '1';
      root.append(marker);
      root.querySelector('[data-credit-picker]').hidden = false;
      root.querySelector('label').htmlFor = select.id;
    }
    root.querySelector('[data-credit-help]').hidden = false;
    const wrapper = select.closest('.choices');
    const input = wrapper.querySelector('.choices__input--cloned');
    input.maxLength = 255;
    wrapper.querySelector('.choices__inner').style.setProperty('flex-wrap', 'wrap', 'important');
    // Give the internal search input the visible label, as well as the select.
    input.setAttribute('aria-label', root.querySelector('label').textContent);
    function directOrder(text) {
      const at = text.indexOf(',');
      return at < 0 ? text : (text.slice(at + 1).trim() + ' ' + text.slice(0, at).trim()).replace(/\s+/g, ' ');
    }
    function matches(label, typed) {
      const candidate = label.trim().toLowerCase();
      const term = typed.trim().toLowerCase();
      return candidate === term || (!publisher && candidate === directOrder(term));
    }
    function add(name) {
      name = name.trim();
      if (!publisher) name = name.replace(/;/g, ',');
      if (!name || name.length > 255 || (choice.getValue() || []).length >= 20) return;
      const existing = (choice.getValue() || []).find(item => matches(String(item.label), name));
      if (!existing) {
        const value = publisher ? 'new:' + name : name;
        choice.setChoices([{value, label:name, selected:true}], 'value', 'label', false);
      }
      choice.clearInput(); choice.hideDropdown();
    }
    // Choices handles Enter before subsequently registered listeners, so wrap it.
    if (typeof choice._onEnterKey === 'function') {
      const original = choice._onEnterKey.bind(choice);
      choice._onEnterKey = function (event, active) {
        const typed = input.value.trim();
        if (!typed) return original(event, active);
        const highlighted = wrapper.querySelector('.choices__list--dropdown .choices__item--selectable.is-highlighted');
        if (highlighted && matches((highlighted.querySelector('.choices__item-text') || highlighted.childNodes[0] || highlighted).textContent, typed)) return original(event, active);
        event.preventDefault(); add(typed);
      };
    }
    let timer; let request; let generation = 0;
    const errorNote = root.querySelector('[data-credit-search-error]');
    select.addEventListener('search', function (event) {
      const term = String(event.detail?.value || '').trim();
      clearTimeout(timer); if (request) request.abort();
      const current = ++generation;
      if (term.length < 2) return;
      timer = setTimeout(async function () {
        request = new AbortController();
        try {
          const response = await fetch(config.searchUrl + '?q=' + encodeURIComponent(term.replace(/,/g, ' ')), {signal: request.signal});
          if (!response.ok) throw new Error('Credit search ' + response.status);
          const rows = await response.json();
          if (current !== generation) return;
          const selected = new Set((choice.getValue(true) || []).map(String));
          const options = rows.slice(0,30).map(row => ({value: publisher ? String(row.id) : row.label, label:row.label})).filter(row => !selected.has(row.value));
          choice.setChoices(options, 'value', 'label', true, false);
          errorNote.hidden = true;
        } catch (error) {
          if (error.name === 'AbortError' || current !== generation) return;
          console.error(error); errorNote.hidden = false;
        }
      }, 300);
    });
  });
});
