// Central registry for page-scoped action handlers.
// Handlers are expected to be registered on page mount
// and unregistered on page unmount.

const pageActionRegistry = new Map();

/**
 * Registers action handlers for the current page.
 *
 * @param {Object<string, Function>} actions
 *        Map of action name → handler function.
 *        Handler receives { el, event }.
 *
 * @returns {Function} cleanup function to unregister handlers
 */
export function registerPageActions(actions) {
    if (!actions || typeof actions !== 'object') {
        throw new Error('registerPageActions expects an object of action handlers');
    }

    for (const [action, handler] of Object.entries(actions)) {
        if (typeof handler !== 'function') {
            throw new Error(`Handler for action "${action}" must be a function`);
        }

        if (pageActionRegistry.has(action)) {
            console.warn(
                `[pageActions] Action "${action}" is being overridden. ` +
                `Ensure previous page was unmounted correctly.`
            );
        }

        pageActionRegistry.set(action, handler);
    }

    // Return explicit cleanup function (page unmount hook)
    return function unregisterPageActions() {
        for (const action of Object.keys(actions)) {
            pageActionRegistry.delete(action);
        }
    };
}

/**
 * Dispatches a page action if registered.
 *
 * @param {Object} params
 * @param {string} params.action  Action name
 * @param {HTMLElement} params.el Triggering element
 * @param {Event} params.event    Original DOM event
 *
 * @returns {Promise<boolean>} true if handled, false otherwise
 */
export async function handlePageAction({ action, el, event }) {
    const handler = pageActionRegistry.get(action);
    if (!handler) return false;

    try {
        await handler({ el, event });
        return true;
    } catch (err) {
        console.error(`[pageActions] Error handling action "${action}"`, err);
        throw err;
    }
}
