import { setToken } from '../../Auth/auth.js';

export async function render(container) {
	const html = await fetch(`html/Auth/forgotPassword.html`).then(r => r.text());
    container.innerHTML = html;

    document
        .querySelector(".user")
        .addEventListener('submit', handleForgotPassword);
}

async function handleForgotPassword(){
	
}