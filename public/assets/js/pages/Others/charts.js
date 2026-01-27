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
	const html = await fetch(`html/charts.html`).then(r => r.text());
    container.innerHTML = html;
	const script1 = document.createElement('script');
	script1.src = `assets/js/templates/demo/chart-area-demo.js`;
	container.appendChild(script1);
	const script2 = document.createElement('script');
	script2.src = `assets/js/templates/demo/chart-pie-demo.js`;
	container.appendChild(script2);
	const script3 = document.createElement('script');
	script3.src = `assets/js/templates/demo/chart-bar-demo.js`;
	container.appendChild(script3);
}