// JavaScript Document
import { registerPageActions } from '../Core/pageActions.js';

let unregister;

export async function mount() {
    //unregister = registerPageActions();
}

export function unmount() {
    if (unregister) {
        unregister();
        unregister = null;
    }
}

export async function render(container) {
	const html = await fetch(`html/blank.html`).then(r => r.text());
    container.innerHTML = html;
}
