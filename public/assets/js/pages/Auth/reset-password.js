export async function render(container) {
    const html = await fetch('html/Auth/resetPassword.html').then(r => r.text());
    container.innerHTML = html;

    const form = container.querySelector('#resetPasswordForm');
    if (form) {
        form.addEventListener('submit', handleResetPassword);
    }

    // Check if token is present in URL
    const token = getTokenFromUrl();
    if (!token) {
        const messageDiv = container.querySelector('#resetPasswordMessage');
        showMessage(messageDiv, 'Invalid or missing reset token. Please request a new reset link.', 'danger');
        const submitBtn = container.querySelector('#resetPasswordBtn');
        if (submitBtn) submitBtn.disabled = true;
    }
}

async function handleResetPassword(e) {
    e.preventDefault();

    const token = getTokenFromUrl();
    const newPassword = document.querySelector('#newPassword')?.value;
    const confirmPassword = document.querySelector('#confirmPassword')?.value;
    const messageDiv = document.querySelector('#resetPasswordMessage');
    const submitBtn = document.querySelector('#resetPasswordBtn');

    if (!token) {
        showMessage(messageDiv, 'Invalid or missing reset token.', 'danger');
        return;
    }

    if (!newPassword || newPassword.length < 6) {
        showMessage(messageDiv, 'Password must be at least 6 characters.', 'danger');
        return;
    }

    if (newPassword !== confirmPassword) {
        showMessage(messageDiv, 'Passwords do not match.', 'danger');
        return;
    }

    // Disable button during request
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Resetting...';
    }

    try {
        const res = await fetch('/ERP-horizontal-ai/public/api/auth/reset-password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token, password: newPassword })
        });

        const data = await res.json();

        if (data.success) {
            showMessage(messageDiv, 'Password reset successfully! Redirecting to login...', 'success');
            // Clear form
            document.querySelector('#newPassword').value = '';
            document.querySelector('#confirmPassword').value = '';
            // Redirect to login after 2 seconds
            setTimeout(() => {
                window.location.hash = '#/login';
            }, 2000);
        } else {
            showMessage(messageDiv, data.message || 'Failed to reset password. The token may be expired or already used.', 'danger');
        }
    } catch (err) {
        console.error('Reset password request failed:', err);
        showMessage(messageDiv, 'An error occurred. Please try again later.', 'danger');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Set New Password';
        }
    }
}

function getTokenFromUrl() {
    // Token comes from URL hash: #/reset-password?token=abc123
    const hash = window.location.hash;
    const queryStart = hash.indexOf('?');
    if (queryStart === -1) return null;

    const params = new URLSearchParams(hash.substring(queryStart));
    return params.get('token') || null;
}

function showMessage(el, text, type) {
    if (!el) return;
    el.style.display = 'block';
    el.className = `mt-3 text-center alert alert-${type}`;
    el.textContent = text;
}
