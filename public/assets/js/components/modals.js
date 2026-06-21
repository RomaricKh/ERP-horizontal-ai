// JavaScript Document

const openModals = new WeakMap();
const modalContainers = new Set(); 
const modalStack = []; // Track order of opened modals

// Global listener for Escape key to close only the topmost modal
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modalStack.length > 0) {
        // Find topmost modal that is actually shown
        const topmost = modalStack[modalStack.length - 1];
        if (topmost && topmost.modal) {
            e.preventDefault();
            e.stopPropagation();
            topmost.modal.hide();
        }
    }
}, true); // Use capture phase to intercept before any other listeners

export const MODAL_SCOPE = {
    GLOBAL: 'GLOBAL',
    PAGE: 'PAGE',
    STACKED: 'STACKED'
};

export async function openModal({ scope = MODAL_SCOPE.PAGE, template, controller, data, options = {} }) {
	
	let container;
	if (scope === MODAL_SCOPE.GLOBAL) {
		container = document.getElementById('global-modal-layer');
	} else if (scope === MODAL_SCOPE.PAGE) {
		container = document.getElementById('content');
	} else if (scope === MODAL_SCOPE.STACKED) {
		if (modalStack.length > 0) {
			container = modalStack[modalStack.length - 1].modalRoot.parentElement;
		} else {
			container = document.getElementById('content');
		}
	}
	
	if (!container) {
		throw new Error('openModal: container is required');
	}

	const modalRoot = document.createElement('div');
	modalRoot.classList.add('modal', 'fade');
	modalRoot.tabIndex = -1;
	modalRoot.setAttribute('role', 'dialog');

    // To ensure proper stacking when multiple modals share the same container,
    // we dynamically adjust the z-index of the new modal.
    const baseZIndex = 1055;
    modalRoot.style.zIndex = (baseZIndex + (modalStack.length * 10)).toString();

	const html = await fetch(template).then(r => r.text());
	modalRoot.innerHTML = html;
	container.appendChild(modalRoot);

	const module = await import(controller);
	module.mount?.({ data, modalRoot });

	const modal = new bootstrap.Modal(modalRoot, {
		backdrop: options.backdrop ?? true,
		keyboard: false, // We handle Escape key globally for proper stacking
		focus: options.focus ?? true
	});

	const cleanup = () => {
		modalRoot.removeEventListener('hidden.bs.modal', cleanup);
		module.unmount?.();
		modalRoot.remove();
		
		const stackIdx = modalStack.findIndex(m => m.modalRoot === modalRoot);
		if (stackIdx > -1) modalStack.splice(stackIdx, 1);
	};

	modalRoot.addEventListener('hidden.bs.modal', cleanup, { once: true });

	modalStack.push({ modal, modalRoot });

	modal.show();

    // Adjust backdrop to properly overlay and scope to the container
    setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 0) {
            const latestBackdrop = backdrops[backdrops.length - 1];
            
            // Move backdrop into the specific container so it doesn't sit on the global body
            if (latestBackdrop.parentNode !== container) {
                container.appendChild(latestBackdrop);
            }
            
            // If in-app, constrain the backdrop to the container area only (don't dim the appshell)
            if (scope === MODAL_SCOPE.PAGE || scope === MODAL_SCOPE.STACKED) {
                latestBackdrop.style.position = 'absolute';
                if (window.getComputedStyle(container).position === 'static') {
                    container.style.position = 'relative';
                }
            }

            // Backdrop should sit just behind this modal
            latestBackdrop.style.zIndex = (baseZIndex + ((modalStack.length - 1) * 10) - 5).toString();
        }
    }, 50);

	return modalRoot;
}

export function closeModalsInContainer(container) {
	if (!container) return;
    modalStack.filter(m => m.modalRoot.parentElement === container).forEach(m => m.modal.hide());
}

export function closeAllModals() {
    [...modalStack].reverse().forEach(m => m.modal.hide());
}

	
export function confirm({ title, message, isHtml }) {
  	return new Promise(async (resolve) => {
		await openModal({
		  scope: MODAL_SCOPE.GLOBAL,
		  template: 'html/components/ConfirmationModal.html',
		  controller: '../components/confirmation.modal.js',
		  data: { title, message, isHtml, resolve },
		  options: { backdrop: 'static', keyboard: true, focus: true }
		});
	});
}

export function alert({ title, alertText }) {
  return new Promise(async (resolve) => {
    await openModal({
      scope: MODAL_SCOPE.GLOBAL,
      template: 'html/components/AlertsModal.html',
      controller: '../components/alerts.modal.js',
      data: { title, alertText, resolve },
      options: { backdrop: 'static', keyboard: true, focus: true }
    });
  });
}