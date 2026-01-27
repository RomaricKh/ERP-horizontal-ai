import { login } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';

let unregister;

export async function mount() {
    unregister = registerPageActions({
        'login': () => handleLogin()
    });
}

export function unmount() {
    if (unregister) {
        unregister();
        unregister = null;
    }
}


export async function render(container) {
	const html = await fetch(`html/Auth/login.html`).then(r => r.text());
    container.innerHTML = html;

    document
        .querySelector(".user")
        .addEventListener('submit', handleLogin);
}

async function handleLogin(e){
	e.preventDefault(); // prevent default form submission

	const username = document.getElementById("username").value;
	const password = document.getElementById("password").value;
	const remember = document.getElementById("rememberCheck").checked;

	await login(username, password, remember);
}