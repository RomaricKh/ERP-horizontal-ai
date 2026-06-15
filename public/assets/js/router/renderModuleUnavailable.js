// assets/js/router/renderModuleUnavailable.js

/**
 * Renders a "Module not available" message in the main content area.
 * Called when the user navigates directly to a route whose module is inactive.
 * The URL is preserved (no redirect).
 */
export function renderModuleUnavailable() {
    const target = document.getElementById('content');
    if (!target) return;

    target.innerHTML = `
        <div class="container-fluid">
            <div class="d-flex flex-column align-items-center justify-content-center" style="min-height: 60vh;">
                <div class="text-center">
                    <i class="fas fa-ban fa-3x text-gray-300 mb-3"></i>
                    <h4 class="text-gray-600">Module not available</h4>
                    <p class="text-gray-500 mb-0">
                        This module is not active for your business.
                        Please contact your administrator if you need access.
                    </p>
                </div>
            </div>
        </div>
    `;
}
