// JavaScript Document
import { registerPageActions } from '../pages/Core/pageActions.js';
import { logout } from '../Auth/auth.js';
import { hideLastWindowWarning } from '../SessionWatcher.js';
import { MODAL_SCOPE, openModal } from './modals.js';

let unregister;

export function mount() {
    unregister = registerPageActions({        
        'closeSessionWarning': async () => await hideLastWindowWarning(),
		'open.reAuth.modal': async () => await openModal({
			scope: MODAL_SCOPE.GLOBAL,
			template: 'html/Auth/reAuthModal.html',
			controller: '../pages/Auth/reAuth.modal.js',
			data: {},
			options: { backdrop: 'static', keyboard: true, focus: true }
		})
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}
