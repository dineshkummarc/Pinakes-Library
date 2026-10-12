// Progressive enhancements: every underlying route and plugin action remains
// available without JavaScript. Controls appear only once their handlers exist.
const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();

function initPluginDirectory() {
  const directory = document.querySelector('[data-plugin-directory]');
  if (!directory) return;
  const input = directory.querySelector('#plugin-search');
  const rows = Array.from(directory.querySelectorAll('[data-plugin-row]'));
  const filters = Array.from(directory.querySelectorAll('[data-plugin-filter]'));
  const count = directory.querySelector('[data-plugin-count]');
  const empty = directory.querySelector('[data-plugin-empty]');
  const params = new URLSearchParams(window.location.search);
  let state = params.get('plugin_state') || 'all';
  if (!['all', 'active', 'inactive'].includes(state)) state = 'all';
  input.value = params.get('plugin_q') || '';
  const searchable = new Map(rows.map(row => [row, normalize(row.dataset.pluginSearch || '')]));

  const update = (saveUrl = true) => {
    const terms = normalize(input.value).split(/\s+/).filter(Boolean);
    let visible = 0;
    rows.forEach(row => {
      const matches = (state === 'all' || row.dataset.pluginState === state)
        && terms.every(term => searchable.get(row).includes(term));
      row.hidden = !matches;
      if (matches) visible++;
    });
    filters.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.pluginFilter === state)));
    const values = [visible, rows.length];
    let index = 0;
    count.textContent = count.dataset.countTemplate.replace(/%(\d+\$)?d/g, (_match, position) => String(values[position ? Number.parseInt(position, 10) - 1 : index++]));
    count.hidden = false;
    empty.hidden = visible > 0 || rows.length === 0;
    if (saveUrl) {
      const url = new URL(window.location.href);
      if (input.value.trim()) url.searchParams.set('plugin_q', input.value.trim());
      else url.searchParams.delete('plugin_q');
      if (state !== 'all') url.searchParams.set('plugin_state', state);
      else url.searchParams.delete('plugin_state');
      window.history.replaceState(window.history.state, '', url);
    }
  };
  input.addEventListener('input', () => update());
  filters.forEach(button => button.addEventListener('click', () => { state = button.dataset.pluginFilter; update(); }));
  directory.querySelector('[data-plugin-reset]').addEventListener('click', () => {
    state = 'all'; input.value = ''; update(); input.focus();
  });
  directory.querySelector('[data-plugin-controls]').hidden = false;
  update(false);
}

function initNavigationSearch() {
  const navigation = document.querySelector('#workspace-navigation');
  const control = document.querySelector('[data-navigation-search]');
  if (!navigation || !control) return;
  const input = control.querySelector('input');
  const empty = control.querySelector('[data-navigation-empty]');
  const links = Array.from(navigation.querySelectorAll('a[href]'));
  const originals = new Map(links.map(link => [link, link.hidden]));
  const groups = Array.from(navigation.children).filter(group => group.querySelector('a[href]'));
  const originalGroups = new Map(groups.map(group => [group, group.hidden]));
  const disclosures = Array.from(navigation.querySelectorAll('details'));
  const disclosureStates = new Map();
  let searching = false;
  input.addEventListener('input', () => {
    const terms = normalize(input.value).split(/\s+/).filter(Boolean);
    if (terms.length && !searching) disclosures.forEach(details => disclosureStates.set(details, details.open));
    links.forEach(link => {
      link.hidden = originals.get(link) || !terms.every(term => normalize(link.dataset.navigationKeywords || link.textContent).includes(term));
    });
    disclosures.forEach(details => {
      if (terms.length) details.open = Array.from(details.querySelectorAll('a[href]')).some(link => !link.hidden);
      else if (searching) details.open = disclosureStates.get(details);
    });
    groups.forEach(group => {
      group.hidden = originalGroups.get(group) || (terms.length > 0 && !Array.from(group.querySelectorAll('a[href]')).some(link => !link.hidden));
    });
    searching = terms.length > 0;
    empty.hidden = links.some(link => !link.hidden && link.getClientRects().length);
  });
  control.hidden = false;
}

function initNavigationMotion() {
  const navigation = document.querySelector('#workspace-navigation');
  if (!navigation) return;
  const links = Array.from(navigation.querySelectorAll('a[href]'));
  const highlight = document.createElement('span');
  highlight.className = 'pk-nav-highlight';
  highlight.setAttribute('aria-hidden', 'true');
  highlight.hidden = true;
  navigation.prepend(highlight);
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const hover = window.matchMedia('(hover: hover) and (pointer: fine)');
  let animation = null;
  let target = null;
  let frame = null;

  const move = (link, animate = true) => {
    if (!link || !link.getClientRects().length) {
      animation?.cancel(); highlight.hidden = true; target = null; return;
    }
    const rect = link.getBoundingClientRect();
    const rail = navigation.getBoundingClientRect();
    const next = `translate(${rect.left - rail.left + navigation.scrollLeft}px, ${rect.top - rail.top + navigation.scrollTop}px)`;
    const previous = highlight.hidden ? next : getComputedStyle(highlight).transform;
    animation?.cancel();
    highlight.style.width = `${rect.width}px`;
    highlight.style.height = `${rect.height}px`;
    highlight.style.transform = next;
    highlight.hidden = false;
    target = link;
    if (animate && !reducedMotion.matches) {
      animation = highlight.animate([{ transform: previous }, { transform: next }], {
        duration: 220, easing: 'cubic-bezier(.16, 1, .3, 1)'
      });
    }
  };
  const restore = (animate = true) => move(navigation.querySelector('[aria-current="page"]'), animate);
  links.forEach(link => link.addEventListener('pointerenter', () => {
    if (hover.matches) move(link);
  }));
  navigation.addEventListener('pointerleave', () => {
    const focused = document.activeElement?.closest('a[href]');
    if (focused && navigation.contains(focused)) move(focused);
    else restore();
  });
  navigation.addEventListener('focusin', event => {
    const link = event.target.closest('a[href]');
    if (link) move(link);
  });
  navigation.addEventListener('focusout', event => {
    if (!navigation.contains(event.relatedTarget)) restore();
  });
  new ResizeObserver(() => move(target, false)).observe(navigation);
  new MutationObserver(records => {
    if (!records.some(record => record.target !== highlight)) return;
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => restore(false));
  }).observe(navigation, { subtree: true, attributes: true, attributeFilter: ['hidden', 'aria-current', 'open'] });
  reducedMotion.addEventListener('change', () => move(target, false));
  navigation.dataset.navigationMotion = '';
  restore(false);
}

function initTableRegions() {
  document.querySelectorAll('.admin-shell main table').forEach(table => {
    if (table.closest('.fc, .dt-container, .dataTables_wrapper')) return;
    const region = table.closest('.overflow-x-auto, .entity-table-wrap, .archive-table-card') || table.parentElement;
    region.classList.add('pk-table-scroll');
  });
  document.querySelectorAll('.admin-shell main .pk-table-scroll').forEach(region => {
    if (!region.querySelector('table')) return;
    // A keyboard user must be able to reach horizontally clipped columns.
    const original = ['tabindex', 'role', 'aria-label'].map(name => [name, region.getAttribute(name)]);
    const update = () => {
      if (region.scrollWidth > region.clientWidth + 1) {
        region.tabIndex = 0;
        region.setAttribute('role', 'region');
        region.setAttribute('aria-label', document.querySelector('main h1')?.textContent.trim() || document.title);
      } else {
        original.forEach(([name, value]) => {
          if (value === null) region.removeAttribute(name);
          else region.setAttribute(name, value);
        });
      }
    };
    new ResizeObserver(update).observe(region);
    update();
  });
}

function initFormActions() {
  if (!document.body.classList.contains('admin-shell') || !('IntersectionObserver' in window)) return;
  const visible = new Set();
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) visible.add(entry.target);
      else visible.delete(entry.target);
    });
    // The floating "back to top" control must not cover a Save action.
    document.body.classList.toggle('pk-form-actions-visible', visible.size > 0);
  });
  document.querySelectorAll('.pk-form-actions').forEach(row => observer.observe(row));
}

export function initInterface() {
  initPluginDirectory();
  initNavigationSearch();
  initNavigationMotion();
  initTableRegions();
  initFormActions();
}
