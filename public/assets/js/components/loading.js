let loadingCount = 0;
let loadingTimeout = null;
const MIN_DELAY = 150;

function getOverlay() {
    return document.getElementById('loadingOverlay');
}

export function showLoading() {
    loadingCount++;

    if (loadingCount === 1) {
        if (loadingTimeout) clearTimeout(loadingTimeout);

        loadingTimeout = setTimeout(() => {
            const overlay = getOverlay();
            if (overlay) overlay.style.display = 'flex';
        }, MIN_DELAY);
    }
}

export function hideLoading() {
    if (loadingCount === 0) return;

    loadingCount--;

    if (loadingCount === 0) {
        if (loadingTimeout) clearTimeout(loadingTimeout);

        const overlay = getOverlay();
        if (overlay) overlay.style.display = 'none';
    }
}

/* Emergency reset (logout, auth failure, fatal error) */
export function resetLoading() {
    loadingCount = 0;
    if (loadingTimeout) clearTimeout(loadingTimeout);

    const overlay = getOverlay();
    if (overlay) overlay.style.display = 'none';
}
