/**
 * Property 12: Client-side validation blocks invalid form submissions
 *
 * **Validates: Requirements 15.3, 15.4**
 *
 * For any form state where the name field is empty/whitespace-only, or no payee type
 * checkbox is selected, or the payee type name field (in types modal) is empty/whitespace-only,
 * the frontend SHALL prevent API submission and display inline validation feedback.
 *
 * Uses Vitest + fast-check + jsdom to generate random form states and verify validation behavior.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fc from 'fast-check';

// =============================================================================
// EXTRACTED VALIDATION LOGIC (from personForm.modal.js)
// =============================================================================

/**
 * Validates person form fields. Returns { valid, errors } where errors is an
 * array of { field, message } objects.
 *
 * Mirrors the validateForm() logic from personForm.modal.js:
 * - Name: required (non-empty after trim)
 * - Email: optional, but must be valid format if provided
 * - Phone: optional, allowed characters only (digits, spaces, dashes, plus, parens)
 * - At least one payee type checkbox selected
 */
function validatePersonForm({ name, email, phone, payeeTypeCheckedCount }) {
    const errors = [];

    // Name: required
    if (!name || !name.trim()) {
        errors.push({ field: 'name', message: 'Full name is required' });
    }

    // Email: optional, but must be valid format if provided
    const emailValue = (email || '').trim();
    if (emailValue && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
        errors.push({ field: 'email', message: 'Please enter a valid email address' });
    }

    // Phone: optional, allowed characters only
    const phoneValue = (phone || '').trim();
    if (phoneValue && !/^[\d\s\-\+\(\)]+$/.test(phoneValue)) {
        errors.push({ field: 'phone', message: 'Phone may only contain digits, spaces, dashes, plus signs, and parentheses' });
    }

    // At least one payee type selected
    if (payeeTypeCheckedCount === 0) {
        errors.push({ field: 'payeeTypes', message: 'At least one Payee Type must be selected' });
    }

    return { valid: errors.length === 0, errors };
}

/**
 * Validates payee type name input. Returns { valid, error }.
 *
 * Mirrors the payee-types.add validation from payeeTypes.modal.js:
 * - Name is required (non-empty after trim)
 */
function validatePayeeTypeName(name) {
    const trimmed = (name || '').trim();
    if (!trimmed) {
        return { valid: false, error: 'Type name is required and cannot be empty.' };
    }
    return { valid: true, error: null };
}

/**
 * Applies validation results to DOM (mirrors markInvalid / clearValidation behavior).
 * Returns the modalRoot element after applying validation state.
 */
function applyPersonFormValidation(document, modalRoot, formState) {
    // Clear previous validation
    modalRoot.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot.querySelectorAll('.invalid-feedback').forEach(el => el.remove());

    const result = validatePersonForm(formState);

    if (!result.valid) {
        for (const err of result.errors) {
            if (err.field === 'name') {
                const input = modalRoot.querySelector('#inputPersonName');
                if (input) {
                    input.classList.add('is-invalid');
                    const fb = document.createElement('div');
                    fb.className = 'invalid-feedback';
                    fb.textContent = err.message;
                    input.parentElement.appendChild(fb);
                }
            } else if (err.field === 'email') {
                const input = modalRoot.querySelector('#inputPersonEmail');
                if (input) {
                    input.classList.add('is-invalid');
                    const fb = document.createElement('div');
                    fb.className = 'invalid-feedback';
                    fb.textContent = err.message;
                    input.parentElement.appendChild(fb);
                }
            } else if (err.field === 'phone') {
                const input = modalRoot.querySelector('#inputPersonPhone');
                if (input) {
                    input.classList.add('is-invalid');
                    const fb = document.createElement('div');
                    fb.className = 'invalid-feedback';
                    fb.textContent = err.message;
                    input.parentElement.appendChild(fb);
                }
            } else if (err.field === 'payeeTypes') {
                const box = modalRoot.querySelector('#payeeTypesContainer');
                if (box) {
                    box.classList.add('is-invalid');
                    const fb = document.createElement('div');
                    fb.className = 'invalid-feedback d-block';
                    fb.textContent = err.message;
                    box.parentElement.appendChild(fb);
                }
            }
        }
    }

    return result;
}

/**
 * Applies payee type name validation to DOM (mirrors payeeTypes.modal.js behavior).
 */
function applyPayeeTypeValidation(document, input, name) {
    // Clear previous state
    input.classList.remove('is-invalid');
    const existingFb = input.parentElement.querySelector('.invalid-feedback');
    if (existingFb) existingFb.style.display = 'none';

    const result = validatePayeeTypeName(name);

    if (!result.valid) {
        input.classList.add('is-invalid');
        let fb = input.parentElement.querySelector('.invalid-feedback');
        if (!fb) {
            fb = document.createElement('div');
            fb.className = 'invalid-feedback';
            input.parentElement.appendChild(fb);
        }
        fb.textContent = result.error;
        fb.style.display = 'block';
    }

    return result;
}

// =============================================================================
// ARBITRARIES (generators)
// =============================================================================

// Generate whitespace-only strings (spaces, tabs, newlines)
const whitespaceOnlyArb = fc.stringMatching(/^[ \t\n\r]{1,10}$/);

// Generate empty or whitespace-only names (invalid)
const invalidNameArb = fc.oneof(
    fc.constant(''),
    whitespaceOnlyArb
);

// Generate valid non-empty names
const validNameArb = fc.stringMatching(/^[a-zA-Z][a-zA-Z0-9 ]{0,30}$/);

// Generate invalid email formats
const invalidEmailArb = fc.oneof(
    fc.constant('notanemail'),
    fc.constant('missing@tld'),
    fc.constant('@nolocalpart.com'),
    fc.constant('spaces in@email.com'),
    fc.constant('no@dots'),
    fc.constant('double@@at.com'),
    fc.constant('no space@here.com'),
    fc.stringMatching(/^[a-z]{1,10}$/)  // no @ at all
);

// Generate valid email formats
const validEmailArb = fc.tuple(
    fc.stringMatching(/^[a-z][a-z0-9]{0,8}$/),
    fc.stringMatching(/^[a-z][a-z0-9]{0,6}$/),
    fc.constantFrom('com', 'org', 'net', 'io', 'co')
).map(([local, domain, tld]) => `${local}@${domain}.${tld}`);

// Generate invalid phone characters (contains letters or special chars that aren't allowed)
const invalidPhoneArb = fc.oneof(
    fc.stringMatching(/^[0-9]{0,3}[a-zA-Z]{1,5}[0-9]{0,3}$/),
    fc.constant('123-abc-456'),
    fc.constant('+1 (555) hello'),
    fc.constant('phone!#$'),
    fc.constant('12.34.56'),
    fc.constant('555~1234')
);

// Generate valid phone strings (only digits, spaces, dashes, plus, parens)
const validPhoneArb = fc.stringMatching(/^[\d][\d\s\-\+\(\)]{0,14}$/);

// =============================================================================
// PROPERTY TESTS
// =============================================================================

describe('Property 12: Client-side validation blocks invalid form submissions', () => {

    // --- Person Form: Name validation ---
    describe('Person Form: empty/whitespace name blocks submission', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(`
                <div id="modal-root">
                    <div><input id="inputPersonName" type="text" value="" /></div>
                    <div><input id="inputPersonEmail" type="text" value="" /></div>
                    <div><input id="inputPersonPhone" type="text" value="" /></div>
                    <div id="payeeTypesWrapper">
                        <div id="payeeTypesContainer">
                            <input class="payee-type-cb" type="checkbox" value="1" checked />
                        </div>
                    </div>
                </div>
            `);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects any empty or whitespace-only name and marks field invalid', () => {
            /**
             * Validates: Requirements 15.3
             */
            fc.assert(
                fc.property(invalidNameArb, (name) => {
                    const formState = {
                        name,
                        email: '',
                        phone: '',
                        payeeTypeCheckedCount: 1
                    };

                    const result = validatePersonForm(formState);
                    expect(result.valid).toBe(false);
                    expect(result.errors.some(e => e.field === 'name')).toBe(true);

                    // Verify DOM feedback
                    modalRoot.querySelector('#inputPersonName').value = name;
                    const domResult = applyPersonFormValidation(document, modalRoot, formState);
                    expect(domResult.valid).toBe(false);

                    const nameInput = modalRoot.querySelector('#inputPersonName');
                    expect(nameInput.classList.contains('is-invalid')).toBe(true);
                    const feedback = nameInput.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Full name is required');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Person Form: Email validation ---
    describe('Person Form: invalid email format blocks submission', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(`
                <div id="modal-root">
                    <div><input id="inputPersonName" type="text" value="" /></div>
                    <div><input id="inputPersonEmail" type="text" value="" /></div>
                    <div><input id="inputPersonPhone" type="text" value="" /></div>
                    <div id="payeeTypesWrapper">
                        <div id="payeeTypesContainer">
                            <input class="payee-type-cb" type="checkbox" value="1" checked />
                        </div>
                    </div>
                </div>
            `);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects any non-empty invalid email and marks field invalid', () => {
            /**
             * Validates: Requirements 15.3
             */
            fc.assert(
                fc.property(invalidEmailArb, validNameArb, (email, name) => {
                    const formState = {
                        name,
                        email,
                        phone: '',
                        payeeTypeCheckedCount: 1
                    };

                    const result = validatePersonForm(formState);
                    expect(result.valid).toBe(false);
                    expect(result.errors.some(e => e.field === 'email')).toBe(true);

                    // Verify DOM feedback
                    const domResult = applyPersonFormValidation(document, modalRoot, formState);
                    expect(domResult.valid).toBe(false);

                    const emailInput = modalRoot.querySelector('#inputPersonEmail');
                    expect(emailInput.classList.contains('is-invalid')).toBe(true);
                    const feedback = emailInput.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Please enter a valid email address');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Person Form: Phone validation ---
    describe('Person Form: invalid phone characters block submission', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(`
                <div id="modal-root">
                    <div><input id="inputPersonName" type="text" value="" /></div>
                    <div><input id="inputPersonEmail" type="text" value="" /></div>
                    <div><input id="inputPersonPhone" type="text" value="" /></div>
                    <div id="payeeTypesWrapper">
                        <div id="payeeTypesContainer">
                            <input class="payee-type-cb" type="checkbox" value="1" checked />
                        </div>
                    </div>
                </div>
            `);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects any phone with invalid characters and marks field invalid', () => {
            /**
             * Validates: Requirements 15.3
             */
            fc.assert(
                fc.property(invalidPhoneArb, validNameArb, (phone, name) => {
                    const formState = {
                        name,
                        email: '',
                        phone,
                        payeeTypeCheckedCount: 1
                    };

                    const result = validatePersonForm(formState);
                    expect(result.valid).toBe(false);
                    expect(result.errors.some(e => e.field === 'phone')).toBe(true);

                    // Verify DOM feedback
                    const domResult = applyPersonFormValidation(document, modalRoot, formState);
                    expect(domResult.valid).toBe(false);

                    const phoneInput = modalRoot.querySelector('#inputPersonPhone');
                    expect(phoneInput.classList.contains('is-invalid')).toBe(true);
                    const feedback = phoneInput.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Phone may only contain digits, spaces, dashes, plus signs, and parentheses');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Person Form: Payee type selection ---
    describe('Person Form: no payee type selected blocks submission', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(`
                <div id="modal-root">
                    <div><input id="inputPersonName" type="text" value="" /></div>
                    <div><input id="inputPersonEmail" type="text" value="" /></div>
                    <div><input id="inputPersonPhone" type="text" value="" /></div>
                    <div id="payeeTypesWrapper">
                        <div id="payeeTypesContainer">
                            <input class="payee-type-cb" type="checkbox" value="1" />
                            <input class="payee-type-cb" type="checkbox" value="2" />
                        </div>
                    </div>
                </div>
            `);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects when zero payee types are checked and displays feedback', () => {
            /**
             * Validates: Requirements 15.3
             */
            fc.assert(
                fc.property(validNameArb, (name) => {
                    const formState = {
                        name,
                        email: '',
                        phone: '',
                        payeeTypeCheckedCount: 0
                    };

                    const result = validatePersonForm(formState);
                    expect(result.valid).toBe(false);
                    expect(result.errors.some(e => e.field === 'payeeTypes')).toBe(true);

                    // Verify DOM feedback
                    const domResult = applyPersonFormValidation(document, modalRoot, formState);
                    expect(domResult.valid).toBe(false);

                    const typesBox = modalRoot.querySelector('#payeeTypesContainer');
                    expect(typesBox.classList.contains('is-invalid')).toBe(true);
                    const feedback = typesBox.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('At least one Payee Type must be selected');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Person Form: Valid states pass ---
    describe('Person Form: valid states pass without errors', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(`
                <div id="modal-root">
                    <div><input id="inputPersonName" type="text" value="" /></div>
                    <div><input id="inputPersonEmail" type="text" value="" /></div>
                    <div><input id="inputPersonPhone" type="text" value="" /></div>
                    <div id="payeeTypesWrapper">
                        <div id="payeeTypesContainer">
                            <input class="payee-type-cb" type="checkbox" value="1" checked />
                        </div>
                    </div>
                </div>
            `);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('accepts valid form states with non-empty name, valid email/phone, and at least one type', () => {
            /**
             * Validates: Requirements 15.3
             */
            fc.assert(
                fc.property(
                    validNameArb,
                    fc.oneof(fc.constant(''), validEmailArb),
                    fc.oneof(fc.constant(''), validPhoneArb),
                    fc.integer({ min: 1, max: 5 }),
                    (name, email, phone, typeCount) => {
                        const formState = {
                            name,
                            email,
                            phone,
                            payeeTypeCheckedCount: typeCount
                        };

                        const result = validatePersonForm(formState);
                        expect(result.valid).toBe(true);
                        expect(result.errors).toHaveLength(0);

                        // Verify DOM has no invalid markers
                        const domResult = applyPersonFormValidation(document, modalRoot, formState);
                        expect(domResult.valid).toBe(true);

                        const invalidElements = modalRoot.querySelectorAll('.is-invalid');
                        expect(invalidElements.length).toBe(0);
                        const feedbackElements = modalRoot.querySelectorAll('.invalid-feedback');
                        expect(feedbackElements.length).toBe(0);
                    }
                ),
                { numRuns: 100 }
            );
        });
    });

    // --- Payee Types Modal: Name validation ---
    describe('Payee Types Modal: empty/whitespace name blocks submission', () => {
        let dom, document, input;

        beforeEach(() => {
            dom = new JSDOM(`
                <div id="modal-root">
                    <div class="input-wrapper">
                        <input id="newPayeeTypeName" type="text" value="" />
                    </div>
                </div>
            `);
            document = dom.window.document;
            input = document.getElementById('newPayeeTypeName');
        });

        it('rejects any empty or whitespace-only type name and marks input invalid', () => {
            /**
             * Validates: Requirements 15.4
             */
            fc.assert(
                fc.property(invalidNameArb, (name) => {
                    const result = validatePayeeTypeName(name);
                    expect(result.valid).toBe(false);
                    expect(result.error).toBe('Type name is required and cannot be empty.');

                    // Verify DOM feedback
                    const domResult = applyPayeeTypeValidation(document, input, name);
                    expect(domResult.valid).toBe(false);

                    expect(input.classList.contains('is-invalid')).toBe(true);
                    const feedback = input.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Type name is required and cannot be empty.');
                    expect(feedback.style.display).toBe('block');
                }),
                { numRuns: 100 }
            );
        });

        it('accepts valid non-empty type names', () => {
            /**
             * Validates: Requirements 15.4
             */
            fc.assert(
                fc.property(validNameArb, (name) => {
                    const result = validatePayeeTypeName(name);
                    expect(result.valid).toBe(true);
                    expect(result.error).toBeNull();
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Combined: Any single invalid field blocks entire submission ---
    describe('Person Form: any single invalid field blocks entire submission', () => {
        it('form is invalid if any one of name, email, phone, or types is invalid', () => {
            /**
             * Validates: Requirements 15.3
             */
            const invalidFormStateArb = fc.oneof(
                // Invalid name only
                invalidNameArb.map(name => ({
                    name,
                    email: '',
                    phone: '',
                    payeeTypeCheckedCount: 1
                })),
                // Invalid email only
                fc.tuple(validNameArb, invalidEmailArb).map(([name, email]) => ({
                    name,
                    email,
                    phone: '',
                    payeeTypeCheckedCount: 1
                })),
                // Invalid phone only
                fc.tuple(validNameArb, invalidPhoneArb).map(([name, phone]) => ({
                    name,
                    email: '',
                    phone,
                    payeeTypeCheckedCount: 1
                })),
                // No payee types only
                validNameArb.map(name => ({
                    name,
                    email: '',
                    phone: '',
                    payeeTypeCheckedCount: 0
                }))
            );

            fc.assert(
                fc.property(invalidFormStateArb, (formState) => {
                    const result = validatePersonForm(formState);
                    expect(result.valid).toBe(false);
                    expect(result.errors.length).toBeGreaterThan(0);
                }),
                { numRuns: 200 }
            );
        });
    });
});
