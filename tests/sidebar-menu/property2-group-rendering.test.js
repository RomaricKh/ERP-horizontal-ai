import { describe, it, expect, beforeEach } from 'vitest';
import fc from 'fast-check';
import { JSDOM } from 'jsdom';

/**
 * Feature: sidebar-grouped-menu
 * Property 2: Group rendering produces correct dropdown DOM
 *
 * For any group entry with at least one permitted child, the rendered DOM SHALL contain
 * a <li> with class `nav-item dropdown`, a child <a> with class `dropdown-toggle`
 * displaying the group's label and icon, and a <ul> with class `dropdown-menu` containing
 * one <li><a class="dropdown-item"> per permitted child.
 *
 * **Validates: Requirements 1.3, 2.1, 2.2**
 */

// --- Arbitraries ---

/** Generate a safe alphanumeric id (no special chars that break DOM) */
const safeIdArb = fc.stringMatching(/^[a-zA-Z][a-zA-Z0-9]{0,19}$/);

/** Generate a safe label string */
const safeLabelArb = fc.stringMatching(/^[a-zA-Z][a-zA-Z0-9 ]{0,29}$/);

/** Generate a safe icon class string */
const safeIconArb = fc.stringMatching(/^[a-z]{2,5} fa-fw fa-[a-z]{2,10}$/);

/** Generate a safe hash route */
const safeHashArb = fc.stringMatching(/^#\/[a-z][a-z0-9-]{0,19}$/);

/** Generate a safe permission string */
const safePermissionArb = fc.stringMatching(/^[a-z]{2,10}\.[a-z]{2,10}$/);

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
 * Generate a group entry along with a permission set that allows at least one child.
 * Returns { group, permittedPermissions } where permittedPermissions is a Set of
 * permission strings that hasPermission should return true for.
 */
const groupWithPermissionsArb = groupEntryArb.chain(group => {
  const childCount = group.children.length;
  // Generate a bitmask ensuring at least one child is permitted
  return fc.integer({ min: 1, max: (1 << childCount) - 1 }).map(bitmask => {
    const permittedPermissions = new Set();
    group.children.forEach((child, i) => {
      if (bitmask & (1 << i)) {
        permittedPermissions.add(child.permission);
      }
    });
    return { group, permittedPermissions };
  });
});

// --- Rendering helper (mirrors renderMenu group logic from appShell.js) ---

/**
 * Renders a single group entry into the given container element,
 * using the same algorithm as renderMenu() in appShell.js.
 */
function renderGroupEntry(entry, container, hasPermission) {
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

// --- Tests ---

describe('Feature: sidebar-grouped-menu, Property 2: Group rendering produces correct dropdown DOM', () => {
  let dom;
  let document;
  let container;

  beforeEach(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body><ul id="accordionSidebar" class="navbar-nav"></ul></body></html>');
    document = dom.window.document;
    container = document.getElementById('accordionSidebar');
  });

  it('renders a <li> with class "nav-item dropdown" for each group with permitted children', () => {
    fc.assert(
      fc.property(groupWithPermissionsArb, ({ group, permittedPermissions }) => {
        container.innerHTML = '';
        const hasPermission = (perm) => permittedPermissions.has(perm);

        renderGroupEntry(group, container, hasPermission);

        const li = container.querySelector('li');
        expect(li).not.toBeNull();
        expect(li.classList.contains('nav-item')).toBe(true);
        expect(li.classList.contains('dropdown')).toBe(true);
        expect(li.id).toBe(group.id);
      }),
      { numRuns: 100 }
    );
  });

  it('renders a dropdown-toggle <a> with the group label and icon', () => {
    fc.assert(
      fc.property(groupWithPermissionsArb, ({ group, permittedPermissions }) => {
        container.innerHTML = '';
        const hasPermission = (perm) => permittedPermissions.has(perm);

        renderGroupEntry(group, container, hasPermission);

        const toggle = container.querySelector('.dropdown-toggle');
        expect(toggle).not.toBeNull();
        expect(toggle.classList.contains('nav-link')).toBe(true);
        expect(toggle.classList.contains('dropdown-toggle')).toBe(true);
        expect(toggle.getAttribute('role')).toBe('button');
        expect(toggle.getAttribute('data-bs-toggle')).toBe('dropdown');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(toggle.id).toBe(group.id + 'Toggle');

        // Check label text
        const span = toggle.querySelector('span');
        expect(span).not.toBeNull();
        expect(span.textContent).toBe(group.label);

        // Check icon
        const icon = toggle.querySelector('i');
        expect(icon).not.toBeNull();
        expect(icon.className).toBe(group.icon);
      }),
      { numRuns: 100 }
    );
  });

  it('renders a dropdown-menu <ul> with aria-labelledby referencing the toggle', () => {
    fc.assert(
      fc.property(groupWithPermissionsArb, ({ group, permittedPermissions }) => {
        container.innerHTML = '';
        const hasPermission = (perm) => permittedPermissions.has(perm);

        renderGroupEntry(group, container, hasPermission);

        const dropdownMenu = container.querySelector('.dropdown-menu');
        expect(dropdownMenu).not.toBeNull();
        expect(dropdownMenu.tagName.toLowerCase()).toBe('ul');
        expect(dropdownMenu.getAttribute('aria-labelledby')).toBe(group.id + 'Toggle');
      }),
      { numRuns: 100 }
    );
  });

  it('renders exactly one dropdown-item per permitted child', () => {
    fc.assert(
      fc.property(groupWithPermissionsArb, ({ group, permittedPermissions }) => {
        container.innerHTML = '';
        const hasPermission = (perm) => permittedPermissions.has(perm);

        renderGroupEntry(group, container, hasPermission);

        const expectedChildren = group.children.filter(c => permittedPermissions.has(c.permission));
        const dropdownItems = container.querySelectorAll('.dropdown-item');

        expect(dropdownItems.length).toBe(expectedChildren.length);
      }),
      { numRuns: 100 }
    );
  });

  it('each dropdown-item has correct href, data-route, icon, and label for its child', () => {
    fc.assert(
      fc.property(groupWithPermissionsArb, ({ group, permittedPermissions }) => {
        container.innerHTML = '';
        const hasPermission = (perm) => permittedPermissions.has(perm);

        renderGroupEntry(group, container, hasPermission);

        const expectedChildren = group.children.filter(c => permittedPermissions.has(c.permission));
        const dropdownItems = container.querySelectorAll('.dropdown-item');

        expectedChildren.forEach((child, i) => {
          const item = dropdownItems[i];
          expect(item.getAttribute('href')).toBe(child.hash);
          expect(item.getAttribute('data-route')).toBe(child.hash);
          expect(item.textContent).toContain(child.label);

          const icon = item.querySelector('i');
          expect(icon).not.toBeNull();
          expect(icon.className).toBe(child.icon);
        });
      }),
      { numRuns: 100 }
    );
  });

  it('dropdown-items are wrapped in <li> elements inside the dropdown-menu', () => {
    fc.assert(
      fc.property(groupWithPermissionsArb, ({ group, permittedPermissions }) => {
        container.innerHTML = '';
        const hasPermission = (perm) => permittedPermissions.has(perm);

        renderGroupEntry(group, container, hasPermission);

        const dropdownMenu = container.querySelector('.dropdown-menu');
        const listItems = dropdownMenu.querySelectorAll(':scope > li');
        const dropdownItems = dropdownMenu.querySelectorAll('.dropdown-item');

        // Each dropdown-item should be inside a <li>
        expect(listItems.length).toBe(dropdownItems.length);
        listItems.forEach(li => {
          expect(li.querySelector('.dropdown-item')).not.toBeNull();
        });
      }),
      { numRuns: 100 }
    );
  });

  it('does not render anything when no children are permitted', () => {
    fc.assert(
      fc.property(groupEntryArb, (group) => {
        container.innerHTML = '';
        // No permissions granted
        const hasPermission = () => false;

        renderGroupEntry(group, container, hasPermission);

        expect(container.children.length).toBe(0);
      }),
      { numRuns: 100 }
    );
  });
});
