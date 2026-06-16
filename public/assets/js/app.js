// assets/js/app.js

import { initRouter } from './router/router.js';
import { renderComponent } from './components/componentManager.js';

document.addEventListener('DOMContentLoaded', async () => {
	await renderComponent(document.getElementById("appShell"), 
						  'LoadingOverlay',
						  'html/components/LoadingOverlay.html', 
						  './loading.js');
    await initRouter();
});

function generateUUID() {
  if (window.crypto?.randomUUID) {
    return crypto.randomUUID();
  }

  // RFC4122 v4 fallback
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}

const TAB_ID = generateUUID();



// expose read-only
window.APP = {
  TAB_ID,
  isActiveTab: () => localStorage.getItem('active_tab') === TAB_ID
};
// track active tab
function markActive() {
  localStorage.setItem('active_tab', TAB_ID);
}

// When tab becomes visible
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') {
    markActive();
    window.dispatchEvent(new CustomEvent('tab:activated'));
  } else {
    window.dispatchEvent(new CustomEvent('tab:deactivated'));
  }
});

// When window gains / loses focus
window.addEventListener('focus', () => {
  markActive();
  window.dispatchEvent(new CustomEvent('tab:activated'));
});

window.addEventListener('blur', () => {
  window.dispatchEvent(new CustomEvent('tab:deactivated'));
});

// Initial claim
markActive();

// ─── Global: Fix dropdown overflow in DataTables ─────────────────────────
// When a dropdown inside a table opens, reposition it as fixed to prevent
// clipping by overflow:hidden/auto on parent containers.
document.addEventListener('shown.bs.dropdown', (e) => {
    const menu = e.target.nextElementSibling || e.target.parentElement.querySelector('.dropdown-menu');
    if (!menu) return;

    // Only fix dropdowns inside tables
    const table = e.target.closest('.table-responsive, .dataTables_wrapper');
    if (!table) return;

    const btnRect = e.target.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = `${btnRect.bottom + 2}px`;
    menu.style.left = 'auto';
    menu.style.right = `${window.innerWidth - btnRect.right}px`;
    menu.style.transform = 'none';
    menu.style.zIndex = '1080';

    // If dropdown would go below viewport, open upward
    const menuHeight = menu.offsetHeight;
    if (btnRect.bottom + menuHeight + 10 > window.innerHeight) {
        menu.style.top = `${btnRect.top - menuHeight - 2}px`;
    }
});

document.addEventListener('hidden.bs.dropdown', (e) => {
    const menu = e.target.nextElementSibling || e.target.parentElement.querySelector('.dropdown-menu');
    if (!menu) return;
    // Reset styles
    menu.style.position = '';
    menu.style.top = '';
    menu.style.left = '';
    menu.style.right = '';
    menu.style.transform = '';
    menu.style.zIndex = '';
});
