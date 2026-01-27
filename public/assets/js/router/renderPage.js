// JavaScript Document

let currentPageModule = null;

export async function renderPage(route, shellType) {
	const module = await route.load();

	if (typeof module.render !== 'function') {
		throw new Error(`Route ${route.path} missing render()`);
	}

    // 🔥 UNMOUNT PREVIOUS PAGE
    if (currentPageModule?.unmount) {
        try {
            currentPageModule.unmount();
        } catch (e) {
            console.error('Error during page unmount:', e);
        }
    }

	const target = shellType === 'authenticated'
		? document.getElementById('content')
		: document.getElementById('authViews');

	target.innerHTML = '';
	await module.render(target);

    // 🔥 MOUNT NEW PAGE
    if (module.mount) {
        await module.mount();
    }

    currentPageModule = module;
}