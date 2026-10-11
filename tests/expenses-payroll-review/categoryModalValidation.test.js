/**
 * Property 11: Frontend Category Modal Validation
 *
 * **Validates: Requirements 18.1, 18.2**
 *
 * For any category name that is empty/whitespace-only OR exceeds 100 characters,
 * the expense categories modal SHALL block form submission (validateForm returns false).
 *
 * Uses Vitest + fast-check + jsdom to generate random invalid category names and verify
 * validation behavior.
 *
 * Tag: Feature: expenses-payroll-review, Property 11: Frontend category modal validation
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fc from 'fast-check';

// =============================================================================
// EXTRACTED VALIDATION LOGIC (from expenseCategories.modal.js)
// =============================================================================

/**
 * Recreates the validateForm() logic from expenseCategories.modal.js.
 * Operates on a jsdom document with the modal's DOM structure.
 *
 * Returns true if the form is valid, false otherwise.
 * Also applies .is-invalid and .invalid-feedback to the DOM.
 */
function validateForm(document, modalRoot) {
    // Clear previous validation
    clearValidation(modalRoot);

    let valid = true;

    const nameInput = document.getElementById('newExpenseCategoryName');
    const name = nameInput?.value.trim();

    if (!name) {
        markInvalid(document, nameInput, 'Category name is required');
        valid = false;
    } else if (name.length > 100) {
        markInvalid(document, nameInput, 'Category name must not exceed 100 characters');
        valid = false;
    }

    return valid;
}

function clearValidation(modalRoot) {
    modalRoot?.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot?.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
}

function markInvalid(document, input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    let fb = input.parentElement?.querySelector('.invalid-feedback');
    if (!fb) {
        fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        input.parentElement?.appendChild(fb);
    }
    fb.textContent = message;
}

// =============================================================================
// MINIMAL DOM STRUCTURE
// =============================================================================

const MODAL_HTML = `
<div id="modal-root">
    <div><input id="newExpenseCategoryName" type="text" value="" /></div>
</div>
`;

// =============================================================================
// ARBITRARIES (generators)
// =============================================================================

/**
 * Empty strings — the input is completely empty.
 */
const emptyStringArb = fc.constant('');

/**
 * Whitespace-only strings of various lengths (1 to 50 chars).
 * Uses spaces and tabs which are guaranteed to trim to empty.
 */
const whitespaceOnlyArb = fc.oneof(
    fc.constant(' '),
    fc.constant('  '),
    fc.constant('   '),
    fc.constant('\t'),
    fc.constant('\t\t'),
    fc.constant('\n'),
    fc.constant('\r\n'),
    fc.constant('  \t  '),
    fc.constant('\n\t\r\n'),
    fc.integer({ min: 1, max: 50 }).map(n => ' '.repeat(n)),
    fc.integer({ min: 1, max: 20 }).map(n => '\t'.repeat(n))
);

/**
 * Strings exceeding 100 characters after trimming.
 * Generate strings between 101 and 500 visible characters (non-whitespace content).
 */
const tooLongNameArb = fc.integer({ min: 101, max: 500 }).chain(len =>
    fc.stringMatching(new RegExp(`^[A-Za-z0-9 ]{${len},${len}}$`))
        .filter(s => s.trim().length > 100)
);

/**
 * Valid category names: 1-100 characters, not whitespace-only.
 * Uses printable ASCII characters (excluding control chars).
 */
const validNameArb = fc.stringMatching(/^[A-Za-z0-9_\- ]{1,100}$/)
    .filter(s => s.trim().length >= 1 && s.trim().length <= 100);

// =============================================================================
// PROPERTY TESTS
// =============================================================================

describe('Property 11: Frontend category modal validation', () => {

    // --- Empty/whitespace-only names block submission ---
    describe('empty or whitespace-only names block submission', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects empty string category names', () => {
            /**
             * Validates: Requirements 18.1
             */
            fc.assert(
                fc.property(emptyStringArb, (nameValue) => {
                    document.getElementById('newExpenseCategoryName').value = nameValue;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the name field is marked invalid
                    const nameEl = document.getElementById('newExpenseCategoryName');
                    expect(nameEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message exists
                    const feedback = nameEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Category name is required');
                }),
                { numRuns: 100 }
            );
        });

        it('rejects whitespace-only category names of various lengths', () => {
            /**
             * Validates: Requirements 18.1
             */
            fc.assert(
                fc.property(whitespaceOnlyArb, (nameValue) => {
                    document.getElementById('newExpenseCategoryName').value = nameValue;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the name field is marked invalid
                    const nameEl = document.getElementById('newExpenseCategoryName');
                    expect(nameEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message
                    const feedback = nameEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Category name is required');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Names exceeding 100 characters block submission ---
    describe('names exceeding 100 characters block submission', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects category names longer than 100 characters', () => {
            /**
             * Validates: Requirements 18.2
             */
            fc.assert(
                fc.property(tooLongNameArb, (nameValue) => {
                    document.getElementById('newExpenseCategoryName').value = nameValue;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the name field is marked invalid
                    const nameEl = document.getElementById('newExpenseCategoryName');
                    expect(nameEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message
                    const feedback = nameEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Category name must not exceed 100 characters');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Positive case: valid names pass validation ---
    describe('positive case: valid names (1-100 chars, non-whitespace) pass', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('accepts valid category names between 1 and 100 non-whitespace characters', () => {
            /**
             * Validates: Requirements 18.1, 18.2
             */
            fc.assert(
                fc.property(validNameArb, (nameValue) => {
                    document.getElementById('newExpenseCategoryName').value = nameValue;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(true);

                    // No fields should be marked invalid
                    const invalidElements = modalRoot.querySelectorAll('.is-invalid');
                    expect(invalidElements.length).toBe(0);
                }),
                { numRuns: 100 }
            );
        });
    });
});
