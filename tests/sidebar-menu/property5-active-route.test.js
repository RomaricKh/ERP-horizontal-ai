import { describe, it, expect, beforeEach } from 'vitest';
import fc from 'fast-check';
import { JSDOM } from 'jsdom';

/**
 * Feature: sidebar-grouped-menu
 * Property 5: Active route highlights correct item and parent group
 *
 * For any rendered menu and any hash value, calling setActiveMenu(hash) SHALL
 * result in exactly one element with the `active` class matching that hash.
 * If the matching element is a dropdown-item, its parent dropdown's toggle
 * SHALL also have the `active` class. If the matching element is a standalone
 * nav-item, no dropdown toggles SHALL have the `active` class.
 *
 * **Validates: Requirements 5.1, 5.2, 5.3, 5.4**
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
 * Generate a MENU_CONFIG array with unique IDs and unique hashes.
 * Unique hashes are critical for setActiveMenu — duplicate data-route
 * attributes would make the querySelector behavior ambiguous.
 */
const menuConfigArb = fc.array(fc.oneof(itemEntryArb, groupEntryArb), { minLength: 1, maxLength: 8 })
  .map(config => {
    const usedIds = new Set();
    const usedHashes = new Set();
    return config.map((entry, i) => {
      // Deduplicate IDs
      let id = entry.id;
      if (usedIds.has(id)) id = `${id}${i}`;
      usedIds.add(id);

      if (entry.type === 'group') {
        const children = (entry.children || []).map((child, j) => {
          let cid = child.id;
          if (usedIds.has(cid)) cid = `${cid}${i}c${j}`;
          usedIds.add(cid);

          // Deduplicate hashes
          let chash = child.hash;
          if (usedHashes.has(chash)) chash = `${chash}${i}${j}`;
          usedHashes.add(chash);

          return { ...child, id: cid, hash: chash };
        });
        return { ...entry, id, children };
      }

      // Deduplicate hashes for standalone items
      let hash = entry.hash;
      if (usedHashes.has(hash)) hash = `${hash}${i}`;
      usedHashes.add(hash);

      return { ...entry, id, hash };
    });
  });

// --- Rendering helper (mirrors renderMenu logic from appShell.js) ---

/**
 * Renders a full MENU_CONFIG array into the given container element,
 * using the same algorithm as renderMenu() in appShell.js.
 * All permissions are granted (hasPermission always returns true).
 */
function renderMenuHelper(menuConfig, container) {
  container.innerHTML = '';

  menuConfig.forEach(entry => {
    if (entry.type === 'item') {
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
      const visibleChildren = entry.children || [];
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
 * Test helper that mirrors the setActiveMenu() algorithm from appShell.js.
 * Operates on the provided document rather than the global document.
 */
function setActiveMenuHelper(doc, hash) {
  // Clear all active states
  doc.querySelectorAll('#accordionSidebar .nav-item')
    .forEach(el => el.classList.remove('active'));
  doc.querySelectorAll('#accordionSidebar .dropdown-item')
    .forEach(el => el.classList.remove('active'));
  doc.querySelectorAll('#accordionSidebar .dropdown-toggle')
    .forEach(el => el.classList.remove('active'));

  // Check standalone items first
  const standaloneItem = doc.querySelector(
    `#accordionSidebar > .nav-item:not(.dropdown)[data-route="${hash}"]`
  );
  if (standaloneItem) {
    standaloneItem.classList.add('active');
    return;
  }

  // Check dropdown items
  const dropdownItem = doc.querySelector(
    `#accordionSidebar .dropdown-item[data-route="${hash}"]`
  );
  if (dropdownItem) {
    dropdownItem.classList.add('active');
    const parentDropdown = dropdownItem.closest('.nav-item.dropdown');
    if (parentDropdown) {
      parentDropdown.classList.add('active');
      const toggle = parentDropdown.querySelector('.dropdown-toggle');
      if (toggle) toggle.classList.add('active');
    }
  }
}

/**
 * Collect all hashes from a MENU_CONFIG array.
 */
function collectAllHashes(config) {
  const hashes = [];
  for (const entry of config) {
    if (entry.type === 'item') {
      hashes.push(entry.hash);
    } else if (entry.type === 'group') {
      for (const child of entry.children || []) {
        hashes.push(child.hash);
      }
    }
  }
  return hashes;
}

// --- Tests ---

describe('Feature: sidebar-grouped-menu, Property 5: Active route highlights correct item and parent group', () => {
  let dom;
  let document;
  let container;

  beforeEach(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body><ul id="accordionSidebar" class="navbar-nav"></ul></body></html>');
    document = dom.window.document;
    container = document.getElementById('accordionSidebar');
  });

  it('when hash matches a standalone item, only that nav-item gets active', () => {
    fc.assert(
      fc.property(
        menuConfigArb.filter(cfg => cfg.some(e => e.type === 'item')),
        (config) => {
          // Pick a standalone item hash
          const standaloneItems = config.filter(e => e.type === 'item');
          if (standaloneItems.length === 0) return; // skip if none

          for (const item of standaloneItems) {
            container.innerHTML = '';
            renderMenuHelper(config, container);
            setActiveMenuHelper(document, item.hash);

            // The standalone nav-item with matching data-route should have active
            const activeNavItems = container.querySelectorAll(':scope > .nav-item.active');
            expect(activeNavItems.length).toBe(1);
            expect(activeNavItems[0].getAttribute('data-route')).toBe(item.hash);

            // No dropdown-items should have active
            const activeDropdownItems = container.querySelectorAll('.dropdown-item.active');
            expect(activeDropdownItems.length).toBe(0);

            // No dropdown-toggles should have active
            const activeToggles = container.querySelectorAll('.dropdown-toggle.active');
            expect(activeToggles.length).toBe(0);
          }
        }
      ),
      { numRuns: 100 }
    );
  });

  it('when hash matches a dropdown-item, the item, parent nav-item.dropdown, and toggle all get active', () => {
    fc.assert(
      fc.property(
        menuConfigArb.filter(cfg => cfg.some(e => e.type === 'group' && e.children.length > 0)),
        (config) => {
          // Pick a dropdown child hash
          const groups = config.filter(e => e.type === 'group' && e.children.length > 0);
          if (groups.length === 0) return;

          const group = groups[0];
          const child = group.children[0];

          container.innerHTML = '';
          renderMenuHelper(config, container);
          setActiveMenuHelper(document, child.hash);

          // The dropdown-item with matching data-route should have active
          const activeDropdownItems = container.querySelectorAll('.dropdown-item.active');
          expect(activeDropdownItems.length).toBe(1);
          expect(activeDropdownItems[0].getAttribute('data-route')).toBe(child.hash);

          // The parent nav-item.dropdown should have active
          const parentDropdown = activeDropdownItems[0].closest('.nav-item.dropdown');
          expect(parentDropdown).not.toBeNull();
          expect(parentDropdown.classList.contains('active')).toBe(true);

          // The parent's dropdown-toggle should have active
          const toggle = parentDropdown.querySelector('.dropdown-toggle');
          expect(toggle).not.toBeNull();
          expect(toggle.classList.contains('active')).toBe(true);

          // No standalone nav-items should have active
          const activeStandalones = container.querySelectorAll(
            ':scope > .nav-item:not(.dropdown).active'
          );
          expect(activeStandalones.length).toBe(0);
        }
      ),
      { numRuns: 100 }
    );
  });

  it('when hash matches no item, no elements get active', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        container.innerHTML = '';
        renderMenuHelper(config, container);

        // Use a hash that definitely doesn't exist in the config
        const nonExistentHash = '#/nonexistent-route-xyz-999';
        setActiveMenuHelper(document, nonExistentHash);

        // No nav-items should have active
        const activeNavItems = container.querySelectorAll('.nav-item.active');
        expect(activeNavItems.length).toBe(0);

        // No dropdown-items should have active
        const activeDropdownItems = container.querySelectorAll('.dropdown-item.active');
        expect(activeDropdownItems.length).toBe(0);

        // No dropdown-toggles should have active
        const activeToggles = container.querySelectorAll('.dropdown-toggle.active');
        expect(activeToggles.length).toBe(0);
      }),
      { numRuns: 100 }
    );
  });

  it('calling setActiveMenu with a new hash clears previous active states', () => {
    fc.assert(
      fc.property(
        menuConfigArb.filter(cfg => {
          const allHashes = collectAllHashes(cfg);
          return allHashes.length >= 2;
        }),
        (config) => {
          const allHashes = collectAllHashes(config);
          if (allHashes.length < 2) return;

          container.innerHTML = '';
          renderMenuHelper(config, container);

          // Activate the first hash
          setActiveMenuHelper(document, allHashes[0]);

          // Verify something is active
          const firstActiveCount =
            container.querySelectorAll('.nav-item.active').length +
            container.querySelectorAll('.dropdown-item.active').length;
          expect(firstActiveCount).toBeGreaterThan(0);

          // Activate a different hash
          setActiveMenuHelper(document, allHashes[1]);

          // The first hash's elements should no longer be active (unless both
          // happen to be in the same dropdown group, in which case the parent
          // dropdown stays active but the dropdown-item changes)
          const activeDropdownItems = container.querySelectorAll('.dropdown-item.active');
          const activeStandalones = container.querySelectorAll(
            ':scope > .nav-item:not(.dropdown).active'
          );

          // At most one dropdown-item should be active
          expect(activeDropdownItems.length).toBeLessThanOrEqual(1);
          // At most one standalone should be active
          expect(activeStandalones.length).toBeLessThanOrEqual(1);

          // Exactly one of these should be active (not both)
          const totalActiveTargets = activeDropdownItems.length + activeStandalones.length;
          expect(totalActiveTargets).toBe(1);

          // Verify the active element matches the second hash
          if (activeDropdownItems.length === 1) {
            expect(activeDropdownItems[0].getAttribute('data-route')).toBe(allHashes[1]);
          }
          if (activeStandalones.length === 1) {
            expect(activeStandalones[0].getAttribute('data-route')).toBe(allHashes[1]);
          }
        }
      ),
      { numRuns: 100 }
    );
  });

  it('if a dropdown-item is active, no standalone items have active, and vice versa', () => {
    fc.assert(
      fc.property(
        menuConfigArb.filter(cfg => {
          const hasStandalone = cfg.some(e => e.type === 'item');
          const hasGroup = cfg.some(e => e.type === 'group' && e.children.length > 0);
          return hasStandalone && hasGroup;
        }),
        (config) => {
          const allHashes = collectAllHashes(config);
          if (allHashes.length === 0) return;

          container.innerHTML = '';
          renderMenuHelper(config, container);

          // Test each hash in the config
          for (const hash of allHashes) {
            setActiveMenuHelper(document, hash);

            const activeDropdownItems = container.querySelectorAll('.dropdown-item.active');
            const activeStandalones = container.querySelectorAll(
              ':scope > .nav-item:not(.dropdown).active'
            );

            // Mutual exclusivity: if a dropdown-item is active, no standalone is active
            if (activeDropdownItems.length > 0) {
              expect(activeStandalones.length).toBe(0);
            }
            // And vice versa
            if (activeStandalones.length > 0) {
              expect(activeDropdownItems.length).toBe(0);
            }

            // Exactly one target element should be active
            expect(activeDropdownItems.length + activeStandalones.length).toBe(1);
          }
        }
      ),
      { numRuns: 100 }
    );
  });

  it('exactly one active dropdown-toggle per active dropdown-item, and it belongs to the correct parent', () => {
    fc.assert(
      fc.property(
        menuConfigArb.filter(cfg => cfg.some(e => e.type === 'group' && e.children.length > 0)),
        (config) => {
          const groups = config.filter(e => e.type === 'group' && e.children.length > 0);
          if (groups.length === 0) return;

          container.innerHTML = '';
          renderMenuHelper(config, container);

          // Test each dropdown child hash
          for (const group of groups) {
            for (const child of group.children) {
              setActiveMenuHelper(document, child.hash);

              const activeToggles = container.querySelectorAll('.dropdown-toggle.active');
              expect(activeToggles.length).toBe(1);

              // The active toggle should be inside the correct parent group
              const parentLi = activeToggles[0].closest('.nav-item.dropdown');
              expect(parentLi).not.toBeNull();
              expect(parentLi.id).toBe(group.id);
            }
          }
        }
      ),
      { numRuns: 100 }
    );
  });
});
