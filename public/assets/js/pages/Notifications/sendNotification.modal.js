// assets/js/pages/Notifications/sendNotification.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    // Load users for recipient selector
    await loadRecipients();

    // Wire send button
    const sendBtn = modalRoot.querySelector('#sendNotifBtn');
    sendBtn?.addEventListener('click', handleSend);
}

export function unmount() {
    modalRoot = null;
}

async function loadRecipients() {
    try {
        const res = await apiFetch('/api/users');
        const json = await res.json();
        if (!res.ok || !json.success) return;

        const users = json.data?.users ?? [];
        const select = modalRoot.querySelector('#notifRecipient');
        if (!select) return;

        users.forEach(user => {
            const opt = document.createElement('option');
            opt.value = user.id;
            opt.textContent = user.name || user.email || `User #${user.id}`;
            select.appendChild(opt);
        });
    } catch (err) {
        console.warn('[SendNotification] Failed to load recipients:', err);
    }
}

async function handleSend() {
    clearValidation();

    const recipientId = modalRoot.querySelector('#notifRecipient')?.value;
    const title = modalRoot.querySelector('#notifTitle')?.value?.trim();
    const body = modalRoot.querySelector('#notifBody')?.value?.trim();

    let valid = true;

    if (!recipientId) {
        markInvalid(modalRoot.querySelector('#notifRecipient'), 'Please select a recipient');
        valid = false;
    }
    if (!title) {
        markInvalid(modalRoot.querySelector('#notifTitle'), 'Title is required');
        valid = false;
    }
    if (!body) {
        markInvalid(modalRoot.querySelector('#notifBody'), 'Message is required');
        valid = false;
    }

    if (!valid) return;

    const sendBtn = modalRoot.querySelector('#sendNotifBtn');
    sendBtn.disabled = true;

    try {
        const res = await apiFetch('/api/notifications/send', {
            method: 'POST',
            body: JSON.stringify({
                recipient_id: parseInt(recipientId, 10),
                title,
                body
            })
        });
        const json = await res.json();

        if (!res.ok || !json.success) {
            showFormError(json.message || 'Failed to send notification');
            return;
        }

        // Close modal on success
        bootstrap.Modal.getInstance(modalRoot).hide();
        document.dispatchEvent(new CustomEvent('notifications.updated'));
    } catch (err) {
        console.error('[SendNotification] Send failed:', err);
        showFormError('An unexpected error occurred');
    } finally {
        sendBtn.disabled = false;
    }
}

function clearValidation() {
    modalRoot.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
    modalRoot.querySelector('.modal-footer .form-error')?.remove();
}

function markInvalid(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    let fb = input.parentElement.querySelector('.invalid-feedback');
    if (!fb) {
        fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        input.parentElement.appendChild(fb);
    }
    fb.textContent = message;
}

function showFormError(message) {
    const footer = modalRoot.querySelector('.modal-footer');
    let el = footer?.querySelector('.form-error');
    if (!el) {
        el = document.createElement('div');
        el.className = 'form-error text-danger me-auto';
        footer?.prepend(el);
    }
    el.textContent = message;
}
