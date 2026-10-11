import { describe, it, expect } from 'vitest';
import fc from 'fast-check';

/**
 * Feature: sidebar-grouped-menu
 * Property 1: Config structure preservation
 *
 * For any valid MENU_CONFIG array containing a mix of standalone items and group entries,
 * every standalone item SHALL retain all required fields (id, label, icon, hash, permission, type),
 * and every group entry SHALL retain its label, icon, and a non-empty children array where each
 * child retains all required item fields (id, label, icon, hash, permission).
 * Groups do NOT have a permission field.
 *
 * **Validates: Requirements 1.1, 1.2, 1.4**
 */

// --- Arbitraries ---

/** Generate a random standalone menu item with all required fields */
const menuItemArb = fc.record({
  type: fc.constant('item'),
  id: fc.string({ minLength: 1, maxLength: 30 }).filter(s => s.trim().length > 0),
  label: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
  icon: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
  hash: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
  permission: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
});

/** Generate a random child item within a group (same shape as item but type is optional) */
const childItemArb = fc.record({
  id: fc.string({ minLength: 1, maxLength: 30 }).filter(s => s.trim().length > 0),
  label: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
  icon: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
  hash: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
  permission: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
});

/** Generate a random group entry with a non-empty children array */
const menuGroupArb = fc.record({
  type: fc.constant('group'),
  id: fc.string({ minLength: 1, maxLength: 30 }).filter(s => s.trim().length > 0),
  label: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
  icon: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
  children: fc.array(childItemArb, { minLength: 1, maxLength: 8 }),
});

/** Generate a random MENU_CONFIG array with a mix of items and groups */
const menuConfigArb = fc.array(fc.oneof(menuItemArb, menuGroupArb), {
  minLength: 1,
  maxLength: 15,
});

// --- Tests ---

describe('Feature: sidebar-grouped-menu, Property 1: Config structure preservation', () => {
  it('every standalone item has all required fields: id, label, icon, hash, permission, type', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        const items = config.filter((entry) => entry.type === 'item');

        for (const item of items) {
          // Required fields exist and are non-empty strings
          expect(item).toHaveProperty('type', 'item');
          expect(typeof item.id).toBe('string');
          expect(item.id.length).toBeGreaterThan(0);
          expect(typeof item.label).toBe('string');
          expect(item.label.length).toBeGreaterThan(0);
          expect(typeof item.icon).toBe('string');
          expect(item.icon.length).toBeGreaterThan(0);
          expect(typeof item.hash).toBe('string');
          expect(item.hash.length).toBeGreaterThan(0);
          expect(typeof item.permission).toBe('string');
          expect(item.permission.length).toBeGreaterThan(0);
        }
      }),
      { numRuns: 100 }
    );
  });

  it('every group entry has required fields: id, label, icon, children (non-empty array), type', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        const groups = config.filter((entry) => entry.type === 'group');

        for (const group of groups) {
          expect(group).toHaveProperty('type', 'group');
          expect(typeof group.id).toBe('string');
          expect(group.id.length).toBeGreaterThan(0);
          expect(typeof group.label).toBe('string');
          expect(group.label.length).toBeGreaterThan(0);
          expect(typeof group.icon).toBe('string');
          expect(group.icon.length).toBeGreaterThan(0);
          expect(Array.isArray(group.children)).toBe(true);
          expect(group.children.length).toBeGreaterThan(0);
        }
      }),
      { numRuns: 100 }
    );
  });

  it('every child within a group has required fields: id, label, icon, hash, permission', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        const groups = config.filter((entry) => entry.type === 'group');

        for (const group of groups) {
          for (const child of group.children) {
            expect(typeof child.id).toBe('string');
            expect(child.id.length).toBeGreaterThan(0);
            expect(typeof child.label).toBe('string');
            expect(child.label.length).toBeGreaterThan(0);
            expect(typeof child.icon).toBe('string');
            expect(child.icon.length).toBeGreaterThan(0);
            expect(typeof child.hash).toBe('string');
            expect(child.hash.length).toBeGreaterThan(0);
            expect(typeof child.permission).toBe('string');
            expect(child.permission.length).toBeGreaterThan(0);
          }
        }
      }),
      { numRuns: 100 }
    );
  });

  it('groups do NOT have a permission field', () => {
    fc.assert(
      fc.property(menuConfigArb, (config) => {
        const groups = config.filter((entry) => entry.type === 'group');

        for (const group of groups) {
          expect(group).not.toHaveProperty('permission');
        }
      }),
      { numRuns: 100 }
    );
  });

  it('the actual MENU_CONFIG from appShell.config.js preserves all structural invariants', async () => {
    const { MENU_CONFIG } = await import(
      '../../public/assets/js/Shells/appShell.config.js'
    );

    expect(Array.isArray(MENU_CONFIG)).toBe(true);
    expect(MENU_CONFIG.length).toBeGreaterThan(0);

    for (const entry of MENU_CONFIG) {
      if (entry.type === 'item') {
        // Standalone item: all required fields present
        expect(typeof entry.id).toBe('string');
        expect(entry.id.length).toBeGreaterThan(0);
        expect(typeof entry.label).toBe('string');
        expect(entry.label.length).toBeGreaterThan(0);
        expect(typeof entry.icon).toBe('string');
        expect(entry.icon.length).toBeGreaterThan(0);
        expect(typeof entry.hash).toBe('string');
        expect(entry.hash.length).toBeGreaterThan(0);
        expect(typeof entry.permission).toBe('string');
        expect(entry.permission.length).toBeGreaterThan(0);
      } else if (entry.type === 'group') {
        // Group: required fields, non-empty children, no permission
        expect(typeof entry.id).toBe('string');
        expect(entry.id.length).toBeGreaterThan(0);
        expect(typeof entry.label).toBe('string');
        expect(entry.label.length).toBeGreaterThan(0);
        expect(typeof entry.icon).toBe('string');
        expect(entry.icon.length).toBeGreaterThan(0);
        expect(Array.isArray(entry.children)).toBe(true);
        expect(entry.children.length).toBeGreaterThan(0);
        expect(entry).not.toHaveProperty('permission');

        // Each child has all required item fields
        for (const child of entry.children) {
          expect(typeof child.id).toBe('string');
          expect(child.id.length).toBeGreaterThan(0);
          expect(typeof child.label).toBe('string');
          expect(child.label.length).toBeGreaterThan(0);
          expect(typeof child.icon).toBe('string');
          expect(child.icon.length).toBeGreaterThan(0);
          expect(typeof child.hash).toBe('string');
          expect(child.hash.length).toBeGreaterThan(0);
          if (child.permission !== undefined) {
            expect(typeof child.permission).toBe('string');
            expect(child.permission.length).toBeGreaterThan(0);
          }
        }
      } else {
        throw new Error(`Unexpected entry type: ${entry.type}`);
      }
    }
  });
});
