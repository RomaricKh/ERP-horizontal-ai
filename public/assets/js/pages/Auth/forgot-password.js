export async function render(container) {
    const html = await fetch('html/Auth/forgotPassword.html').then(r => r.text());
    container.innerHTML = html;

    const form = container.querySelector('#forgotPasswordForm');
    if (form) {
        form.addEventListener('submit', handleForgotPassword);
    }
}

async function handleForgotPassword(e) {
    e.preventDefault();

    const email = document.querySelector('#resetEmail')?.value?.trim();
    const messageDiv = document.querySelector('#forgotPasswordMessage');
    const submitBtn = document.querySelector('#sendResetBtn');

    if (!email) {
        showMessage(messageDiv, 'Please enter your email address.', 'danger');
        return;
    }

    // Disable button during request
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';
    }

    try {
        const res = await fetch('/ERP-horizontal-ai/public/api/auth/forgot-password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });

        const data = await res.json();

        if (data.success) {
            showMessage(messageDiv, 'If an account with that email exists, a reset link has been sent. Check your inbox.', 'success');
            // Clear the form
            document.querySelector('#resetEmail').value = '';
        } else {
            showMessage(messageDiv, data.message || 'Something went wrong. Please try again.', 'danger');
        }
    } catch (err) {
        console.error('Forgot password request failed:', err);
        showMessage(messageDiv, 'An error occurred. Please try again later.', 'danger');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send Reset Link';
        }
    }
}

function showMessage(el, text, type) {
    if (!el) return;
    el.style.display = 'block';
    el.className = `mt-3 text-center alert alert-${type}`;
    el.textContent = text;
}
