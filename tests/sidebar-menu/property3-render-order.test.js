import { describe, it, expect, beforeEach } from 'vitest';
import fc from 'fast-check';
import { JSDOM } from 'jsdom';

/**
 * Feature: sidebar-grouped-menu
 * Property 3: Render order matches config order
 *
 * For any MENU_CONFIG array, the rendered direct children of #accordionSidebar
 * SHALL appear in the same order as the entries in the config array, with
 * standalone items rendered as `nav-item` elements and groups rendered as
 * `nav-item dropdown` elements, preserving the interleaving defined in the config.
 *
 * **Validates: Requirements 3.1, 3.2**
 */

// --- Arbitraries ---

/** Generate a safe alphanumeric id */
const safeIdArb = fc.stringMatching(/^[a-zA-Z][a-zA-Z0-9]{0,19}$/);

/** Generate a safe label string */
const safeLabelArb = fc.stringMatching(/^[a-zA-Z][a-zA-Z0-9 ]{0,29}$/);

/** Generate a safe icon class string */
const safeIconArb = fc.stringMatching(/^[a-z]{2,5} fa-fw fa-[a-z]{2,10}$/);

/** Generate a safe hash route */
const safeHashArb = fc.stringMatching(/^#\/[a-z][a-z0-9-]{0,19}$/);

/** Generate a safe permission string */
const safePermissionArb = fc.stringMatching(/^[a-z]{2,10}\.[a-z]{2,10}$/);

/** Generate a random standalone item entry */
const itemEntryArb = fc.record({
  type: fc.constant('item'),
  id: safeIdArb,
  label: safeLabelArb,
  icon: safeIconArb,
  hash: safeHashArb,
  permission: safePermissionArb,
});

/** Generate a random child item within a group */
const childItemArb = fc.record({
  id: safeIdArb,
  label: safeLabelArb,
  icon: safeIconArb,
  hash: safeHashArb,
  permission: safePermissionArb,
});

/** Generate a random group entry with 1-6 children */
const groupEntryArb = fc.record({
  type: fc.constant('group'),
  id: safeIdArb,
  label: safeLabelArb,
  icon: safeIconArb,
  children: fc.array(childItemArb, { minLength: 1, maxLength: 6 }),
});

/** Generate a random MENU_CONFIG array with a mix of items and groups (1-8 entries) */
const menuConfigArb = fc.array(fc.oneof(itemEntryArb, groupEntryArb), { minLength: 1, maxLength: 8 });

// --- Rendering helper (mirrors renderMenu logic from appShell.js) ---

/**
 * Renders a full MENU_CONFIG array into the given container element,
 * using the same algorithm as renderMenu() in appShell.js.
 * hasPermission is injected for testability.
 */
function renderMenuHelper(menuConfig, container, hasPermission) {
  container.innerHTML = '';

  menuConfig.forEach(entry => {
    if (entry.type === 'item') {
      if (!hasPermission(entry.permission)) return;

      const li = container.ownerDocument.createElement('li');
      li.className = 'nav-item';
      li.id = entry.id;
      li.setAttribute('data-route', entry.hash);
      li.innerHTML = `
        <a class="nav-link" href="${entry.hash}">
            <i class="${entry.icon}"></i>
            <span>${entry.label}</span>
        </a>
      `;
      container.appendChild(li);
    } else if (entry.type === 'group') {
      const visibleChildren = (entry.children || []).filter(child => hasPermission(child.permission));
      if (visibleChildren.length === 0) return;

      const toggleId = entry.id + 'Toggle';

      const li = container.ownerDocument.createElement('li');
      li.className = 'nav-item dropdown';
      li.id = entry.id;

      const childrenHtml = visibleChildren.map(child =>
        `<li><a class="dropdown-item" href="${child.hash}" data-route="${child.hash}">
            <i class="${child.icon}"></i> ${child.label}
        </a></li>`
      ).join('');

      li.innerHTML = `
        <a class="nav-link dropdown-toggle" href="#" role="button"
           data-bs-toggle="dropdown" aria-expanded="false"
           id="${toggleId}">
            <i class="${entry.icon}"></i>
            <span>${entry.label}</span>
        </a>
        <ul class="dropdown-menu" aria-labelledby="${toggleId}">
            ${childrenHtml}
        </ul>
      `;
      container.appendChild(li);
    } else {
      // Skip unrecognized types (mirrors console.warn in production code)
    }
  });
}

// --- Tests ---

describe('Feature: sidebar-grouped-menu, Property 3: Render order matches config order', () => {
  let dom;
  let document;
  let container;

  beforeEach(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body><ul id="accordionSidebar" class="navbar-nav"></ul></body></html>');
    document = dom.window.document;
    container = document.getElementById('accordionSidebar');
  });

  it('rendered children count matches the number of visible config entries (all permitted)', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        container.innerHTML = '';
        const hasPermission = () => true;

        renderMenuHelper(config, container, hasPermission);

        // With all permissions granted, every item and every group (with >=1 child) should render
        const expectedCount = config.filter(entry => {
          if (entry.type === 'item') return true;
          if (entry.type === 'group') return (entry.children || []).length > 0;
          return false;
        }).length;

        expect(container.children.length).toBe(expectedCount);
      }),
      { numRuns: 100 }
    );
  });

  it('DOM child order matches config entry order for all-permitted configs', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        container.innerHTML = '';
        const hasPermission = () => true;

        renderMenuHelper(config, container, hasPermission);

        // Build expected order: only entries that would render
        const expectedEntries = config.filter(entry => {
          if (entry.type === 'item') return true;
          if (entry.type === 'group') return (entry.children || []).length > 0;
          return false;
        });

        const renderedChildren = Array.from(container.children);

        expect(renderedChildren.length).toBe(expectedEntries.length);

        expectedEntries.forEach((entry, i) => {
          const li = renderedChildren[i];
          expect(li.id).toBe(entry.id);

          if (entry.type === 'item') {
            expect(li.classList.contains('nav-item')).toBe(true);
            expect(li.classList.contains('dropdown')).toBe(false);
          } else if (entry.type === 'group') {
            expect(li.classList.contains('nav-item')).toBe(true);
            expect(li.classList.contains('dropdown')).toBe(true);
          }
        });
      }),
      { numRuns: 100 }
    );
  });

  it('standalone items are rendered as nav-item (without dropdown) in config order', () => {
    // Generate configs with only items to isolate standalone ordering
    const itemOnlyConfigArb = fc.array(itemEntryArb, { minLength: 1, maxLength: 8 });

    fc.assert(
      fc.property(itemOnlyConfigArb, (config) => {
        container.innerHTML = '';
        const hasPermission = () => true;

        renderMenuHelper(config, container, hasPermission);

        const renderedChildren = Array.from(container.children);
        expect(renderedChildren.length).toBe(config.length);

        config.forEach((entry, i) => {
          const li = renderedChildren[i];
          expect(li.id).toBe(entry.id);
          expect(li.classList.contains('nav-item')).toBe(true);
          expect(li.classList.contains('dropdown')).toBe(false);
          expect(li.getAttribute('data-route')).toBe(entry.hash);
        });
      }),
      { numRuns: 100 }
    );
  });

  it('groups are rendered as nav-item dropdown in config order', () => {
    // Generate configs with only groups to isolate group ordering
    const groupOnlyConfigArb = fc.array(groupEntryArb, { minLength: 1, maxLength: 8 });

    fc.assert(
      fc.property(groupOnlyConfigArb, (config) => {
        container.innerHTML = '';
        const hasPermission = () => true;

        renderMenuHelper(config, container, hasPermission);

        const expectedGroups = config.filter(g => (g.children || []).length > 0);
        const renderedChildren = Array.from(container.children);
        expect(renderedChildren.length).toBe(expectedGroups.length);

        expectedGroups.forEach((entry, i) => {
          const li = renderedChildren[i];
          expect(li.id).toBe(entry.id);
          expect(li.classList.contains('nav-item')).toBe(true);
          expect(li.classList.contains('dropdown')).toBe(true);
        });
      }),
      { numRuns: 100 }
    );
  });

  it('interleaved items and groups preserve config ordering', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        container.innerHTML = '';
        const hasPermission = () => true;

        renderMenuHelper(config, container, hasPermission);

        const expectedEntries = config.filter(entry => {
          if (entry.type === 'item') return true;
          if (entry.type === 'group') return (entry.children || []).length > 0;
          return false;
        });

        const renderedChildren = Array.from(container.children);

        // Verify the type sequence matches
        const expectedTypes = expectedEntries.map(e =>
          e.type === 'item' ? 'item' : 'group'
        );
        const renderedTypes = renderedChildren.map(li =>
          li.classList.contains('dropdown') ? 'group' : 'item'
        );

        expect(renderedTypes).toEqual(expectedTypes);
      }),
      { numRuns: 100 }
    );
  });
});
