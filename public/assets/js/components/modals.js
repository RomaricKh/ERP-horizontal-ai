// JavaScript Document

// JavaScript Document

const openModals = new WeakMap();
const modalContainers = new Set(); 

export const MODAL_SCOPE = {
    GLOBAL: 'GLOBAL',
    PAGE: 'PAGE'
};

export async function openModal({ scope = MODAL_SCOPE.PAGE, template, controller, data, options = {} }) {
	
	const container =
	scope === MODAL_SCOPE.GLOBAL
		? document.getElementById('global-modal-layer')
		: document.getElementById('content');
	
	if (!container) {
		throw new Error('openModal: container is required');
	}
	if (openModals.has(container)) {
		throw new Error('Modal already open in container');
	}

	const modalRoot = document.createElement('div');
	modalRoot.classList.add('modal', 'fade');
	modalRoot.tabIndex = -1;
	modalRoot.setAttribute('role', 'dialog');

	const html = await fetch(template).then(r => r.text());
	modalRoot.innerHTML = html;
	container.appendChild(modalRoot);

	const module = await import(controller);
	module.mount?.({ data, modalRoot });

	const modal = new bootstrap.Modal(modalRoot, {
		backdrop: options.backdrop ?? true,
		keyboard: options.keyboard ?? true,
		focus: options.focus ?? true
	});

	const cleanup = () => {
		modalRoot.removeEventListener('hidden.bs.modal', cleanup);
		module.unmount?.();
		modalRoot.remove();
		openModals.delete(container);
		modalContainers.delete(container);
	};

	modalRoot.addEventListener('hidden.bs.modal', cleanup, { once: true });

	openModals.set(container, { modal, modalRoot, cleanup });
	modalContainers.add(container);

	modal.show();

	return modalRoot;
}

export function closeModalsInContainer(container) {
	if (!container) return;
	const entry = openModals.get(container);
	entry?.modal.hide();
}

export function closeAllModals() {
	for (const container of modalContainers) {
		const entry = openModals.get(container);
		entry?.modal.hide();
	}
}
