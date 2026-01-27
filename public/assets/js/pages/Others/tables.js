// JavaScript Document
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
	const html = await fetch(`html/tables.html`).then(r => r.text());
    container.innerHTML = html;
	
	const datatablesDesignScript = document.createElement('script');
	datatablesDesignScript.src = `assets/js/templates/datatables.design.js`;
	container.appendChild(datatablesDesignScript);
}