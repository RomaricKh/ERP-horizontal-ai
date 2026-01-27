// JavaScript Document

import { getDefaultAuthRoute } from '../../router/router.config.js';
import { registerPageActions } from '../Core/pageActions.js';

let unregister;

export async function mount() {
   // unregister = registerPageActions();
}

export function unmount() {
    if (unregister) {
        unregister();
        unregister = null;
    }
}

export async function render(container) {
	const html = await fetch(`html/404.html`).then(r => r.text());
    container.innerHTML = html;
	
	document.getElementById('goToDefault').href = getDefaultAuthRoute();
}
