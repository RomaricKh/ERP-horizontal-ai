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
