# Implementation Plan: Sidebar Grouped Menu

## Overview

Restructure the ERP horizontal navbar menu from a flat list of 13 items into logically grouped Bootstrap 5 dropdown menus. This involves updating the config data structure, the rendering logic, the active-route highlighting, and adding custom CSS for dropdown styling on the primary gradient navbar.

## Tasks

- [x] 1. Restructure MENU_CONFIG to support grouped entries
  - [x] 1.1 Update `public/assets/js/Shells/appShell.config.js` to use the new config format
    - Change Dashboard to `type: 'item'` with all existing fields preserved
    - Create `type: 'group'` entry for "Core Data" (`id: 'coreDataGroup'`, `icon: 'fas fa-fw fa-database'`) with children: Businesses, Users, People, Items
    - Create `type: 'group'` entry for "Operations" (`id: 'operationsGroup'`, `icon: 'fas fa-fw fa-cogs'`) with children: Purchase Orders, Invoices, Expenses, Salaries
    - Create `type: 'group'` entry for "Finance" (`id: 'financeGroup'`, `icon: 'fas fa-fw fa-chart-line'`) with children: Accounts, Exchange Rates, Payments, Collections
    - Each child item retains its original `id`, `label`, `icon`, `hash`, and `permission` fields
    - Groups have no `permission` field — visibility is derived from children
    - _Requirements: 1.1, 1.2, 1.4_

- [x] 2. Update `renderMenu()` to handle groups and standalone items
  - [x] 2.1 Modify `renderMenu()` in `public/assets/js/Shells/appShell.js` to support both entry types
    - For `type: 'item'`: render as before — `<li class="nav-item">` with `data-route`, containing a `<a class="nav-link">`
    - For `type: 'group'`: filter children through `hasPermission()`, skip group if zero children are visible
    - For visible groups: render `<li class="nav-item dropdown" id="groupId">` containing:
      - `<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" id="groupIdToggle">` with group icon and label
      - `<ul class="dropdown-menu" aria-labelledby="groupIdToggle">` with one `<li><a class="dropdown-item" href="childHash" data-route="childHash">` per permitted child, each showing child icon and label
    - Skip entries with unrecognized `type` and log a console warning
    - _Requirements: 1.3, 2.1, 2.2, 3.1, 3.2, 4.1, 4.2, 4.3, 9.3, 9.4_

  - [x] 2.2 Write property test: Config structure preservation (Property 1)
    - **Property 1: Config structure preservation**
    - Generate random MENU_CONFIG arrays with mixes of items and groups, verify all required fields are preserved
    - **Validates: Requirements 1.1, 1.2, 1.4**

  - [x] 2.3 Write property test: Group rendering produces correct dropdown DOM (Property 2)
    - **Property 2: Group rendering produces correct dropdown DOM**
    - Generate random group entries with random children, mock `hasPermission` to allow at least one child, verify DOM contains `nav-item dropdown`, `dropdown-toggle`, `dropdown-menu`, and correct `dropdown-item` elements
    - **Validates: Requirements 1.3, 2.1, 2.2**

  - [x] 2.4 Write property test: Render order matches config order (Property 3)
    - **Property 3: Render order matches config order**
    - Generate random MENU_CONFIG arrays, render, verify DOM child order matches config entry order
    - **Validates: Requirements 3.1, 3.2**

  - [x] 2.5 Write property test: Permission gating filters items and hides empty groups (Property 4)
    - **Property 4: Permission gating filters items and hides empty groups**
    - Generate random configs and random permission sets, verify only permitted items and groups with at least one permitted child appear in the DOM
    - **Validates: Requirements 4.1, 4.2, 4.3**

- [x] 3. Checkpoint — Verify config and rendering
  - Ensure the menu renders correctly with grouped dropdowns and standalone items. Ensure all tests pass, ask the user if questions arise.

- [x] 4. Update `setActiveMenu()` to handle dropdown items
  - [x] 4.1 Rewrite `setActiveMenu()` in `public/assets/js/Shells/appShell.js` to support nested dropdown items
    - Clear `active` class from all `.nav-item`, `.dropdown-item`, and `.dropdown-toggle` elements within `#accordionSidebar`
    - Check standalone items first: query `#accordionSidebar > .nav-item:not(.dropdown)[data-route="hash"]` — if found, add `active` and return
    - Check dropdown items: query `#accordionSidebar .dropdown-item[data-route="hash"]` — if found, add `active` to the dropdown-item, its parent `li.nav-item.dropdown`, and the parent's `.dropdown-toggle` anchor
    - If no match found, no element gets `active` (correct for routes without menu entries)
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

  - [x] 4.2 Write property test: Active route highlights correct item and parent group (Property 5)
    - **Property 5: Active route highlights correct item and parent group**
    - Generate random rendered menus and random hashes, verify exactly one element with `active` class matches the hash, and if it's a dropdown-item, the parent toggle also has `active`
    - **Validates: Requirements 5.1, 5.2, 5.3, 5.4**

- [x] 5. Add dropdown CSS styling for the primary gradient navbar
  - [x] 5.1 Add custom CSS rules to `public/assets/css/main.css`
    - `#appMenu .dropdown-menu`: background `#4e73df`, no border, `0.375rem` border-radius, box-shadow matching existing navbar shadow
    - `#appMenu .dropdown-item`: white text (`rgba(255,255,255,0.85)`), `0.5rem 1rem` padding, `font-weight: 500`
    - `#appMenu .dropdown-item:hover` and `:focus`: full white text, `rgba(255,255,255,0.15)` background
    - `#appMenu .dropdown-item.active`: full white text, `rgba(255,255,255,0.25)` background
    - `#appMenu .dropdown-toggle.active`: full white text, `rgba(255,255,255,0.15)` background, `0.375rem` border-radius
    - _Requirements: 2.1, 5.4_

- [x] 6. Final checkpoint — Full integration verification
  - Ensure all menu groups render with correct Bootstrap 5 dropdown behavior, active route highlighting works for both standalone and grouped items, and dropdown styling matches the primary gradient navbar. Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- This is a vanilla JS project — property tests would require setting up a test runner (e.g., Vitest + fast-check) if opted in
- Bootstrap 5 handles all dropdown open/close behavior via `data-bs-toggle="dropdown"` — no custom JS event handlers needed
- ARIA attributes (`aria-expanded`, `aria-labelledby`) are built into the dropdown HTML structure in task 2.1
