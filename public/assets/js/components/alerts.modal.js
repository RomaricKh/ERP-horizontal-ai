// JavaScript Document

import { MODAL_SCOPE } from './modals.js';

let modalRoot;
let unregister;

export function mount({ modalRoot: root, data }) {
	modalRoot = root;
	document.getElementById('alert.text').innerHTML = data.alertText;
}

export function unmount() {
    unregister?.();
    unregister = null;
}
