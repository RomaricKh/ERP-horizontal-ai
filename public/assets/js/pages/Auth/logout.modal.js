// JavaScript Document
import { registerPageActions } from '../Core/pageActions.js';
import { logout } from '../../Auth/auth.js';

let modalRoot;
let unregister;

export function mount({ modalRoot: root, data }) {
	modalRoot = root;
    unregister = registerPageActions({
        'logout': async () => {
            await logout();
      		//modalRoot._cleanup();
			bootstrap.Modal.getInstance(modalRoot).hide();
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}
