// JavaScript Document
import { registerPageActions } from '../Core/pageActions.js';
import { getToken } from '../../Auth/auth.js';

let modalRoot;
let unregister;

export function mount({ modalRoot: root, data }) {
	modalRoot = root;
	document.getElementById('modal.title').innerHTML = "Business delete";
	document.getElementById('confirmation.text').innerHTML = "Are you sure you want to delete the business: "+data.name+"?";
    unregister = registerPageActions({
        'confirmation.yes': () => {
            // validate
			alert("business ID " + data.business_id);
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
