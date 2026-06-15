// JavaScript Document
// confirmation.modal.js
import { registerPageActions } from '../pages/Core/pageActions.js';

let modalRoot;
let unregister;
let resolver;

export function mount({ modalRoot: root, data }) {
  modalRoot = root;

  // Set title & message dynamically
  document.getElementById('modal.title').textContent =
    data.title || 'Confirmation';

  const msgEl = document.getElementById('confirmation.text');
  if (data.isHtml) {
    msgEl.innerHTML = data.message || 'Are you sure?';
  } else {
    msgEl.textContent = data.message || 'Are you sure?';
  }

  resolver = data.resolve;

  unregister = registerPageActions({
    'confirmation.yes': () => close(true),
    'confirmation.no': () => close(false)
  });
}

function close(result) {
  modalRoot.addEventListener(
    'hidden.bs.modal',
    () => {
      // wait one microtask so openModal cleanup finishes
      Promise.resolve().then(() => {
        if (resolver) {
          resolver(result);
          resolver = null;
        }
      });
    },
    { once: true }
  );

  bootstrap.Modal.getInstance(modalRoot).hide();
}


export function unmount() {
  unregister?.();
  unregister = null;
}
