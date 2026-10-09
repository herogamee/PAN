/* PAN Orders: expand the selected order without navigation or another API call. */
(() => {
  'use strict';
  function initOrderRows() {
    const table = document.getElementById('panOrdersTable');
    if (!table) return;
    function toggle(row) {
      const button = row.querySelector('button.order-expand-button');
      if (!button) return;
      const id = button.getAttribute('aria-controls');
      const detail = document.getElementById(id);
      if (!detail) return;
      const open = button.getAttribute('aria-expanded') !== 'true';
      detail.hidden = !open;
      button.setAttribute('aria-expanded', String(open));
      row.classList.toggle('is-expanded', open);
    }
    table.addEventListener('click', event => {
      const node = event.target;
      if (!node || typeof node.closest !== 'function') return;
      const row = node.closest('tr[data-order-master]');
      if (!row || !table.contains(row)) return;
      const interactive = node.closest('a,button,input,select,textarea,label');
      if (interactive && !interactive.matches('button.order-expand-button')) return;
      toggle(row);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOrderRows);
  } else initOrderRows();
})();
