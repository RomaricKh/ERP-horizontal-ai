// JavaScript Document
import { registerPageActions } from '../Core/pageActions.js';
import { reAuth } from '../../Auth/reauth.js';
import { hideLastWindowWarning } from '../../SessionWatcher.js';

let modalRoot;
let unregister;

export function mount({ modalRoot: root, data }) {
	modalRoot = root;
    unregister = registerPageActions({
        'reAuth': async () => {
            await reAuth();
			hideLastWindowWarning();
      		bootstrap.Modal.getInstance(modalRoot).hide();
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}