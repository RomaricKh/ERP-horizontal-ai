// JavaScript Document
import { registerPageActions } from '../Core/pageActions.js';
import { getToken } from '../../Auth/auth.js';

let modalRoot;
let unregister;

export function mount({ modalRoot: root, data }) {
	modalRoot = root;
	document.getElementById('modal.title').innerHTML = "User delete";
	document.getElementById('confirmation.text').innerHTML = "Are you sure you want to delete the user "+data.username+"?";
    unregister = registerPageActions({
        'confirmation.yes': () => {
            // validate
			alert("user ID " + data.user_id);
            // call API
            
      		bootstrap.Modal.getInstance(modalRoot).hide();
			//modalRoot?.modal.hide();
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}
