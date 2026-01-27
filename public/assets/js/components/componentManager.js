// componentManager.js

const renderedComponents = new Map();

/**
 * Render a component into a container.
 * @param {HTMLElement} container
 * @param {string} key - unique component key
 * @param {string} templatePath - HTML file path
 * @param {string} controllerPath - JS controller module path
 * @param {object} [data]
 */
export async function renderComponent(
    container,
    key,
    templatePath,
    controllerPath,
    data = {}
) {
    if (renderedComponents.has(key)) {
        return renderedComponents.get(key).element;
    }

    // Load HTML
    const html = await fetch(templatePath).then(r => r.text());
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    const element = wrapper.firstElementChild;

    container.appendChild(element);

    // Load controller ONCE
    const module = controllerPath ? await import(controllerPath) : null;
    module?.mount?.({ element, data });

    renderedComponents.set(key, {
        element,
        module
    });

    return element;
}

/**
 * Unrender a component by key.
 */
export function unrenderComponent(key) {
    const entry = renderedComponents.get(key);
    if (!entry) return;

    entry.module?.unmount?.();
    entry.element.remove();
    renderedComponents.delete(key);
}

/**
 * Unrender all rendered components cleanly.
 */
export function unrenderAll() {
    for (const [key, entry] of renderedComponents.entries()) {
        entry.module?.unmount?.();
        entry.element.remove();
    }
    renderedComponents.clear();
}
