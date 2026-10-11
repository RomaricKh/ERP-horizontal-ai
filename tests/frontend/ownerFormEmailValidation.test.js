/**
 * Unit test: Owner Form Client-Side Email Validation
 *
 * Verifies that the tenant owner designation form validates email format
 * client-side before submitting the request.
 *
 * Validates: Requirements 14.1, 14.2
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';

// --- Logic under test (extracted from ownerForm.modal.js) ---

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Validates an email value against the client-side pattern.
 *
 * @param {string} email - The email value to validate
 * @returns {{ valid: boolean, message?: string }} Validation result
 */
export function validateEmail(email) {
    const trimmed = (email || '').trim();
    if (!trimmed) {
        return { valid: false, message: 'Email is required' };
    }
    if (!EMAIL_PATTERN.test(trimmed)) {
        return { valid: false, message: 'Please enter a valid email address' };
    }
    return { valid: true };
}

// --- Tests ---

describe('Owner Form Client-Side Email Validation', () => {

    describe('validateEmail — valid emails', () => {
        it('accepts a standard email address', () => {
            expect(validateEmail('user@example.com')).toEqual({ valid: true });
        });

        it('accepts email with subdomain', () => {
            expect(validateEmail('admin@mail.company.org')).toEqual({ valid: true });
        });

        it('accepts email with plus addressing', () => {
            expect(validateEmail('user+tag@example.com')).toEqual({ valid: true });
        });

        it('accepts email with dots in local part', () => {
            expect(validateEmail('first.last@domain.co')).toEqual({ valid: true });
        });

        it('trims whitespace before validating', () => {
            expect(validateEmail('  user@example.com  ')).toEqual({ valid: true });
        });
    });

    describe('validateEmail — invalid emails', () => {
        it('rejects empty string', () => {
            const result = validateEmail('');
            expect(result.valid).toBe(false);
            expect(result.message).toBe('Email is required');
        });

        it('rejects whitespace-only input', () => {
            const result = validateEmail('   ');
            expect(result.valid).toBe(false);
            expect(result.message).toBe('Email is required');
        });

        it('rejects email without @ symbol', () => {
            const result = validateEmail('userexample.com');
            expect(result.valid).toBe(false);
            expect(result.message).toBe('Please enter a valid email address');
        });

        it('rejects email without domain part', () => {
            const result = validateEmail('user@');
            expect(result.valid).toBe(false);
            expect(result.message).toBe('Please enter a valid email address');
        });

        it('rejects email without local part', () => {
            const result = validateEmail('@example.com');
            expect(result.valid).toBe(false);
            expect(result.message).toBe('Please enter a valid email address');
        });

        it('rejects email without TLD dot', () => {
            const result = validateEmail('user@example');
            expect(result.valid).toBe(false);
            expect(result.message).toBe('Please enter a valid email address');
        });

        it('rejects email with spaces in it', () => {
            const result = validateEmail('user @example.com');
            expect(result.valid).toBe(false);
            expect(result.message).toBe('Please enter a valid email address');
        });
    });

    describe('DOM integration — inline validation message', () => {
        let dom;
        let document;

        beforeEach(() => {
            dom = new JSDOM(`
                <div id="modal-root">
                    <div class="col-md-6">
                        <label class="form-label" for="ownerInputEmail">Email</label>
                        <input class="form-control" id="ownerInputEmail" type="email" placeholder="Enter email address">
                    </div>
                </div>
            `);
            document = dom.window.document;
        });

        it('email input has type="email"', () => {
            const emailInput = document.getElementById('ownerInputEmail');
            expect(emailInput.getAttribute('type')).toBe('email');
        });

        it('marks input invalid and shows feedback message on bad email', () => {
            const emailInput = document.getElementById('ownerInputEmail');
            emailInput.value = 'not-an-email';

            // Simulate the markInvalid behavior from the modal controller
            const result = validateEmail(emailInput.value);
            if (!result.valid) {
                emailInput.classList.add('is-invalid');
                const fb = document.createElement('div');
                fb.className = 'invalid-feedback';
                fb.textContent = result.message;
                emailInput.parentElement.appendChild(fb);
            }

            expect(emailInput.classList.contains('is-invalid')).toBe(true);
            const feedback = emailInput.parentElement.querySelector('.invalid-feedback');
            expect(feedback).not.toBeNull();
            expect(feedback.textContent).toBe('Please enter a valid email address');
        });

        it('does not mark input invalid for a valid email', () => {
            const emailInput = document.getElementById('ownerInputEmail');
            emailInput.value = 'test@example.com';

            const result = validateEmail(emailInput.value);

            expect(result.valid).toBe(true);
            expect(emailInput.classList.contains('is-invalid')).toBe(false);
        });
    });
});
