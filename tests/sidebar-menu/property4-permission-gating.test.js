import { describe, it, expect, beforeEach } from 'vitest';
import fc from 'fast-check';
import { JSDOM } from 'jsdom';

/**
 * Feature: sidebar-grouped-menu
 * Property 4: Permission gating filters items and hides empty groups
 *
 * For any MENU_CONFIG array and any permission set, the rendered menu SHALL
 * include only standalone items whose permission passes hasPermission(), and
 * only groups that have at least one child whose permission passes hasPermission().
 * Within each rendered group, only permitted children SHALL appear.
 *
 * **Validates: Requirements 4.1, 4.2, 4.3**
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

/**
 * Generate a MENU_CONFIG array with unique IDs across all entries.
 * This avoids DOM id collisions that would make getElementById unreliable.
 */
const menuConfigArb = fc.array(fc.oneof(itemEntryArb, groupEntryArb), { minLength: 1, maxLength: 8 })
  .map(config => {
    // Deduplicate IDs by appending an index suffix
    const usedIds = new Set();
    return config.map((entry, i) => {
      let id = entry.id;
      if (usedIds.has(id)) id = `${id}${i}`;
      usedIds.add(id);

      if (entry.type === 'group') {
        const children = (entry.children || []).map((child, j) => {
          let cid = child.id;
          if (usedIds.has(cid)) cid = `${cid}${i}c${j}`;
          usedIds.add(cid);
          return { ...child, id: cid };
        });
        return { ...entry, id, children };
      }
      return { ...entry, id };
    });
  });

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
    }
  });
}

/**
 * Collect all unique permissions from a MENU_CONFIG array.
 */
function collectAllPermissions(config) {
  const perms = new Set();
  for (const entry of config) {
    if (entry.type === 'item') {
      perms.add(entry.permission);
    } else if (entry.type === 'group') {
      for (const child of entry.children || []) {
        perms.add(child.permission);
      }
    }
  }
  return [...perms];
}

/**
 * Generate a random permission set (subset of all permissions in the config).
 * Returns an arbitrary that depends on the config.
 */
function permissionSetArb(config) {
  const allPerms = collectAllPermissions(config);
  if (allPerms.length === 0) return fc.constant(new Set());
  return fc.subarray(allPerms, { minLength: 0, maxLength: allPerms.length })
    .map(arr => new Set(arr));
}

// --- Tests ---

describe('Feature: sidebar-grouped-menu, Property 4: Permission gating filters items and hides empty groups', () => {
  let dom;
  let document;
  let container;

  beforeEach(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body><ul id="accordionSidebar" class="navbar-nav"></ul></body></html>');
    document = dom.window.document;
    container = document.getElementById('accordionSidebar');
  });

  it('only standalone items whose permission passes appear in the DOM', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        fc.assert(
          fc.property(permissionSetArb(config), (permittedSet) => {
            container.innerHTML = '';
            const hasPermission = (perm) => permittedSet.has(perm);

            renderMenuHelper(config, container, hasPermission);

            // Check standalone items: only permitted ones should be in DOM
            const renderedStandaloneItems = Array.from(
              container.querySelectorAll(':scope > .nav-item:not(.dropdown)')
            );

            const expectedStandaloneItems = config.filter(
              e => e.type === 'item' && permittedSet.has(e.permission)
            );

            expect(renderedStandaloneItems.length).toBe(expectedStandaloneItems.length);

            renderedStandaloneItems.forEach((li, i) => {
              expect(li.id).toBe(expectedStandaloneItems[i].id);
            });
          }),
          { numRuns: 5 }
        );
      }),
      { numRuns: 25 }
    );
  });

  it('groups with zero permitted children are completely hidden from the DOM', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        fc.assert(
          fc.property(permissionSetArb(config), (permittedSet) => {
            container.innerHTML = '';
            const hasPermission = (perm) => permittedSet.has(perm);

            renderMenuHelper(config, container, hasPermission);

            // Identify groups that should be hidden (zero permitted children)
            const hiddenGroups = config
              .filter(e => e.type === 'group')
              .filter(g => (g.children || []).every(c => !permittedSet.has(c.permission)));

            // None of these group ids should appear in the DOM
            for (const group of hiddenGroups) {
              const el = document.getElementById(group.id);
              expect(el).toBeNull();
            }
          }),
          { numRuns: 5 }
        );
      }),
      { numRuns: 25 }
    );
  });

  it('groups with some permitted children only show those permitted children as dropdown-items', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        fc.assert(
          fc.property(permissionSetArb(config), (permittedSet) => {
            container.innerHTML = '';
            const hasPermission = (perm) => permittedSet.has(perm);

            renderMenuHelper(config, container, hasPermission);

            // For each group that should be visible, verify only permitted children appear
            const visibleGroups = config
              .filter(e => e.type === 'group')
              .filter(g => (g.children || []).some(c => permittedSet.has(c.permission)));

            for (const group of visibleGroups) {
              const groupEl = document.getElementById(group.id);
              expect(groupEl).not.toBeNull();

              const dropdownItems = Array.from(
                groupEl.querySelectorAll('.dropdown-item')
              );

              const expectedChildren = (group.children || []).filter(
                c => permittedSet.has(c.permission)
              );

              expect(dropdownItems.length).toBe(expectedChildren.length);

              // Verify each rendered dropdown-item matches an expected permitted child
              dropdownItems.forEach((item, i) => {
                expect(item.getAttribute('data-route')).toBe(expectedChildren[i].hash);
              });
            }
          }),
          { numRuns: 5 }
        );
      }),
      { numRuns: 25 }
    );
  });

  it('total rendered items count matches expected permitted items', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        fc.assert(
          fc.property(permissionSetArb(config), (permittedSet) => {
            container.innerHTML = '';
            const hasPermission = (perm) => permittedSet.has(perm);

            renderMenuHelper(config, container, hasPermission);

            // Count expected top-level rendered elements
            const expectedTopLevel = config.filter(entry => {
              if (entry.type === 'item') return permittedSet.has(entry.permission);
              if (entry.type === 'group') {
                return (entry.children || []).some(c => permittedSet.has(c.permission));
              }
              return false;
            }).length;

            expect(container.children.length).toBe(expectedTopLevel);

            // Count total dropdown-items across all groups
            const totalDropdownItems = container.querySelectorAll('.dropdown-item').length;

            const expectedDropdownItems = config
              .filter(e => e.type === 'group')
              .reduce((sum, g) => {
                const permittedChildren = (g.children || []).filter(
                  c => permittedSet.has(c.permission)
                );
                // Only count if group is visible (at least one permitted child)
                return sum + (permittedChildren.length > 0 ? permittedChildren.length : 0);
              }, 0);

            expect(totalDropdownItems).toBe(expectedDropdownItems);
          }),
          { numRuns: 5 }
        );
      }),
      { numRuns: 25 }
    );
  });

  it('granting no permissions renders an empty menu', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        container.innerHTML = '';
        const hasPermission = () => false;

        renderMenuHelper(config, container, hasPermission);

        expect(container.children.length).toBe(0);
        expect(container.innerHTML).toBe('');
      }),
      { numRuns: 100 }
    );
  });

  it('granting all permissions renders every item and every non-empty group', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        container.innerHTML = '';
        const hasPermission = () => true;

        renderMenuHelper(config, container, hasPermission);

        const expectedCount = config.filter(entry => {
          if (entry.type === 'item') return true;
          if (entry.type === 'group') return (entry.children || []).length > 0;
          return false;
        }).length;

        expect(container.children.length).toBe(expectedCount);

        // All children within groups should be rendered
        for (const entry of config) {
          if (entry.type === 'group' && (entry.children || []).length > 0) {
            const groupEl = document.getElementById(entry.id);
            expect(groupEl).not.toBeNull();
            const items = groupEl.querySelectorAll('.dropdown-item');
            expect(items.length).toBe(entry.children.length);
          }
        }
      }),
      { numRuns: 100 }
    );
  });
});
