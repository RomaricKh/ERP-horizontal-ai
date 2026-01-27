// JavaScript Document
import { setToken, getUser, getToken, broadcastTokenUpdate } from './auth.js';

let isSubmitting = false;

function showError(msg) {
  const el = document.getElementById('reauthError');
  el.textContent = msg;
  el.classList.remove('hidden');
}

function hideError() {
  document.getElementById('reauthError').classList.add('hidden');
}

export async function reAuth() {
  if (isSubmitting) return;

  isSubmitting = true;

  try {
	const password = document.getElementById('reauthPassword').value;
	const token = getToken();
	const savedBusinessId = localStorage.getItem('business_id');

	const res = await fetch('/api/auth/reauth', {
		method: 'POST',
		credentials: 'include',
		headers: { 
				'Authorization': `Bearer ${token}`,
				'X-Business-Id': savedBusinessId,
				'Content-Type': 'application/json' },
		body: JSON.stringify({ password })
	});

	const data = await res.json();

	if (!res.ok || !data.success) {
		throw new Error(data.message || 'Reauthentication failed');
	}

	setToken(data.data.token);
	broadcastTokenUpdate(data.data.token);

	isSubmitting = false;

  } catch (err) {
	  showError(err.message);
	  isSubmitting = false;
	  document.getElementById('reauthSubmit').disabled = false;
  }
}
