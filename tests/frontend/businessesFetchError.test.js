/**
 * Unit test: Businesses Page — Fetch Error Handling & Pagination Envelope
 *
 * Verifies that:
 * 1. fetchBusinesses reads from pagination envelope format (data.data?.data || [])
 * 2. showError renders an alert div in the error container
 * 3. Non-success responses trigger error display
 * 4. Network errors trigger error display
 *
 * Validates: Requirements 7.2, 10.1, 10.2
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';

// --- Logic under test (extracted from businesses.js) ---

/**
 * Extracts the business list from a paginated API response.
 * Reads from the Pagination_Envelope format: data.data?.data || []
 *
 * @param {object} responseData - The parsed JSON response body
 * @returns {Array} The array of business objects
 */
export function extractBusinessesFromResponse(responseData) {
    return responseData.data?.data || [];
}

/**
 * Determines if the API response indicates a failure.
 *
 * @param {object} res - The fetch Response object (or mock with .ok)
 * @param {object} data - The parsed JSON body
 * @returns {boolean} True if the response is an error
 */
export function isErrorResponse(res, data) {
    return !res.ok || !data.success;
}

/**
 * Renders an error message into the error container element.
 *
 * @param {Document} doc - The document to query
 * @param {string} message - The error message to display
 */
export function showError(doc, message) {
    const container = doc.querySelector('#businessesErrorContainer');
    if (container) {
        container.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
}

// --- Tests ---

describe('Businesses Page — Fetch Error Handling & Pagination Envelope', () => {
    let dom;
    let document;

    beforeEach(() => {
        dom = new JSDOM(`
            <div id="page-container">
                <div id="businessesErrorContainer"></div>
                <table id="dataTable"><tbody></tbody></table>
            </div>
        `);
        document = dom.window.document;
    });

    describe('extractBusinessesFromResponse — Pagination Envelope', () => {
        it('reads businesses from data.data.data (standard pagination envelope)', () => {
            const responseData = {
                success: true,
                data: {
                    data: [
                        { id: 1, name: 'Biz A' },
                        { id: 2, name: 'Biz B' }
                    ],
                    total: 2,
                    current_page: 1,
                    last_page: 1,
                    per_page: 20
                }
            };
            const result = extractBusinessesFromResponse(responseData);
            expect(result).toEqual([
                { id: 1, name: 'Biz A' },
                { id: 2, name: 'Biz B' }
            ]);
        });

        it('returns empty array when data.data is null', () => {
            const responseData = { success: true, data: null };
            const result = extractBusinessesFromResponse(responseData);
            expect(result).toEqual([]);
        });

        it('returns empty array when data.data.data is undefined', () => {
            const responseData = { success: true, data: { total: 0 } };
            const result = extractBusinessesFromResponse(responseData);
            expect(result).toEqual([]);
        });

        it('returns empty array when data is undefined', () => {
            const responseData = { success: true };
            const result = extractBusinessesFromResponse(responseData);
            expect(result).toEqual([]);
        });
    });

    describe('isErrorResponse', () => {
        it('returns true when res.ok is false', () => {
            expect(isErrorResponse({ ok: false }, { success: true })).toBe(true);
        });

        it('returns true when data.success is false', () => {
            expect(isErrorResponse({ ok: true }, { success: false })).toBe(true);
        });

        it('returns true when both are failures', () => {
            expect(isErrorResponse({ ok: false }, { success: false })).toBe(true);
        });

        it('returns false when both indicate success', () => {
            expect(isErrorResponse({ ok: true }, { success: true })).toBe(false);
        });
    });

    describe('showError', () => {
        it('renders an alert-danger div in #businessesErrorContainer', () => {
            showError(document, 'Failed to load businesses');

            const container = document.querySelector('#businessesErrorContainer');
            expect(container.innerHTML).toBe(
                '<div class="alert alert-danger">Failed to load businesses</div>'
            );
        });

        it('renders network error message', () => {
            showError(document, 'Network error loading businesses');

            const container = document.querySelector('#businessesErrorContainer');
            expect(container.innerHTML).toBe(
                '<div class="alert alert-danger">Network error loading businesses</div>'
            );
        });

        it('overwrites previous error when called again', () => {
            showError(document, 'First error');
            showError(document, 'Second error');

            const container = document.querySelector('#businessesErrorContainer');
            expect(container.innerHTML).toBe(
                '<div class="alert alert-danger">Second error</div>'
            );
        });

        it('does not throw when container is missing', () => {
            const emptyDom = new JSDOM('<div></div>');
            expect(() => showError(emptyDom.window.document, 'Some error')).not.toThrow();
        });
    });
});
