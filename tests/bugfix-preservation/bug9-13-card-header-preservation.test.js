import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 9+13 Preservation — Reference Pages and Button Functionality
 *
 * Observe that Businesses and Users pages already follow the card-header pattern.
 * Write property: for all pages already following the pattern, layout is unchanged.
 * Verify all `data-action` attributes are preserved across all pages.
 *
 * This test must PASS on unfixed code — it confirms baseline behavior to preserve.
 *
 * **Validates: Requirements 3.10, 3.11, 3.12, 3.13, 3.14, 3.25, 3.26, 3.27**
 */

describe('Bug 9+13 Preservation — Reference pages follow card-header pattern', () => {

  // Reference pages that ALREADY follow the card-header pattern
  const referencePages = [
    {
      file: 'public/html/Tenant/businesses.html',
      name: 'Businesses',
      expectedAction: 'businesses.create',
      expectedTitle: 'Manage Businesses',
    },
    {
      file: 'public/html/Users/users.html',
      name: 'Users',
      expectedAction: 'users.create',
      expectedTitle: 'Manage Users',
    },
  ];

  for (const { file, name, expectedAction, expectedTitle } of referencePages) {
    const html = fs.readFileSync(path.resolve(file), 'utf-8');

    it(`${name} page has standalone <h1> heading above the card`, () => {
      // The <h1> should be standalone, not inside a flex wrapper with buttons
      const h1Match = html.match(/<h1[^>]*class="[^"]*h3[^"]*"[^>]*>/);
      expect(h1Match, `${name} should have an <h1> with h3 class`).not.toBeNull();

      // The <h1> should NOT be inside a d-flex wrapper with buttons
      const h1InFlex = /<div[^>]*class="[^"]*d-flex[^"]*"[^>]*>[\s\S]*?<h1/m.test(html);
      expect(h1InFlex, `${name} <h1> should NOT be inside a d-flex wrapper`).toBe(false);
    });

    it(`${name} page has card-header with d-flex layout`, () => {
      const hasCardHeader = /card-header[^"]*py-3[^"]*d-flex[^"]*flex-row[^"]*align-items-center[^"]*justify-content-between/.test(html);
      expect(hasCardHeader, `${name} should have card-header with d-flex layout`).toBe(true);
    });

    it(`${name} page has card title inside card-header`, () => {
      expect(html).toContain(expectedTitle);
    });

    it(`${name} page has action button with btn-icon-split pattern inside card-header`, () => {
      const hasBtnIconSplit = /btn-icon-split/.test(html);
      expect(hasBtnIconSplit, `${name} should use btn-icon-split pattern`).toBe(true);
    });

    it(`${name} page has correct data-action attribute: ${expectedAction}`, () => {
      const hasAction = html.includes(`data-action="${expectedAction}"`);
      expect(hasAction, `${name} should have data-action="${expectedAction}"`).toBe(true);
    });

    it(`${name} page button has <span class="text"> and <span class="icon"> children`, () => {
      const hasTextSpan = /<span\s+class="text">/.test(html);
      const hasIconSpan = /<span\s+class="icon">/.test(html);
      expect(hasTextSpan, `${name} button should have <span class="text">`).toBe(true);
      expect(hasIconSpan, `${name} button should have <span class="icon">`).toBe(true);
    });
  }

  // Verify data-action attributes exist on all pages that have action buttons
  const allPagesWithActions = [
    { file: 'public/html/Tenant/businesses.html', actions: ['businesses.create'] },
    { file: 'public/html/Users/users.html', actions: ['users.create'] },
    { file: 'public/html/Finance/accounts.html', actions: ['accounts.create'] },
    { file: 'public/html/Finance/exchangeRates.html', actions: ['exchange-rates.create'] },
  ];

  for (const { file, actions } of allPagesWithActions) {
    const pageName = path.basename(file, '.html');
    const html = fs.readFileSync(path.resolve(file), 'utf-8');

    for (const action of actions) {
      it(`${pageName} preserves data-action="${action}"`, () => {
        expect(html).toContain(`data-action="${action}"`);
      });
    }
  }

  it('property: for all reference pages, the card-header pattern is present', () => {
    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: referencePages.length - 1 }),
        (index) => {
          const { file } = referencePages[index];
          const html = fs.readFileSync(path.resolve(file), 'utf-8');

          // Must have card-header with d-flex
          const hasCardHeaderDflex = /card-header[\s\S]*?d-flex/.test(html);
          expect(hasCardHeaderDflex).toBe(true);

          // Must have btn-icon-split
          expect(/btn-icon-split/.test(html)).toBe(true);

          // Must have data-action
          expect(/data-action=/.test(html)).toBe(true);
        }
      ),
      { numRuns: referencePages.length }
    );
  });

  it('property: for all pages with data-action attributes, the JS handler files exist', () => {
    const jsHandlerFiles = [
      'public/assets/js/pages/Tenant/businesses.js',
      'public/assets/js/pages/Users/users.js',
      'public/assets/js/pages/Finance/accounts.js',
      'public/assets/js/pages/Finance/exchangeRates.js',
    ];

    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: jsHandlerFiles.length - 1 }),
        (index) => {
          const jsFile = jsHandlerFiles[index];
          expect(fs.existsSync(path.resolve(jsFile)), `${jsFile} should exist`).toBe(true);

          const source = fs.readFileSync(path.resolve(jsFile), 'utf-8');
          // Each JS file should register page actions
          expect(/registerPageActions/.test(source)).toBe(true);
        }
      ),
      { numRuns: jsHandlerFiles.length }
    );
  });
});
