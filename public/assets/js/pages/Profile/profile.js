// assets/js/pages/Profile/profile.js

import { registerPageActions } from '../Core/pageActions.js';
import { alert } from '../../components/modals.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let profile_container = null;
let unregister = null;

export async function render(container) {
    profile_container = container;
    const html = await fetch('html/Profile/profile.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    await loadProfile();

    unregister = registerPageActions({

        'profile.save': async ({ el }) => {
            const firstName = profile_container.querySelector('#profileFirstName')?.value?.trim() ?? '';
            const lastName = profile_container.querySelector('#profileLastName')?.value?.trim() ?? '';
            const email = profile_container.querySelector('#profileEmail')?.value?.trim() ?? '';

            if (!firstName || !lastName || !email) {
                await alert({ title: 'Error', alertText: 'Please fill in all fields.' });
                return;
            }

            try {
                if (el) el.disabled = true;
                const res = await apiFetch('/api/users/me', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ first_name: firstName, last_name: lastName, email })
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to update profile.' });
                } else {
                    await alert({ title: 'Success', alertText: 'Profile updated successfully.' });
                }
            } catch (err) {
                console.error('Profile save failed:', err);
                await alert({ title: 'Error', alertText: 'An unexpected error occurred.' });
            } finally {
                if (el) el.disabled = false;
            }
        },

        'profile.changePassword': async ({ el }) => {
            const currentPassword = profile_container.querySelector('#profileCurrentPassword')?.value ?? '';
            const newPassword = profile_container.querySelector('#profileNewPassword')?.value ?? '';
            const confirmPassword = profile_container.querySelector('#profileConfirmPassword')?.value ?? '';

            if (!currentPassword || !newPassword || !confirmPassword) {
                await alert({ title: 'Error', alertText: 'Please fill in all password fields.' });
                return;
            }

            if (newPassword.length < 8) {
                await alert({ title: 'Error', alertText: 'New password must be at least 8 characters.' });
                return;
            }

            if (newPassword !== confirmPassword) {
                await alert({ title: 'Error', alertText: 'New password and confirmation do not match.' });
                return;
            }

            try {
                if (el) el.disabled = true;
                const res = await apiFetch('/api/users/me/password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ current_password: currentPassword, new_password: newPassword })
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to change password.' });
                } else {
                    // Clear password fields on success
                    profile_container.querySelector('#profileCurrentPassword').value = '';
                    profile_container.querySelector('#profileNewPassword').value = '';
                    profile_container.querySelector('#profileConfirmPassword').value = '';
                    await alert({ title: 'Success', alertText: 'Password updated successfully.' });
                }
            } catch (err) {
                console.error('Password change failed:', err);
                await alert({ title: 'Error', alertText: 'An unexpected error occurred.' });
            } finally {
                if (el) el.disabled = false;
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    profile_container = null;
}

async function loadProfile() {
    try {
        const res = await apiFetch('/api/auth/me');
        const data = await res.json();
        if (!data.success) return;

        const user = data.data ?? {};

        const set = (id, value) => {
            const el = profile_container?.querySelector(`#${id}`);
            if (el && value != null) el.value = value;
        };

        set('profileUsername', user.username);
        set('profileFirstName', user.first_name);
        set('profileLastName', user.last_name);
        set('profileEmail', user.email);
    } catch (err) {
        console.error('Failed to load profile:', err);
    }
}
