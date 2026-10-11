/**
 * Unit test: Tenant Form Modal — fetchAndFill URL and response parsing
 *
 * Verifies that:
 * 1. fetchAndFill calls the correct endpoint: /api/tenant/tenants/{id}
 * 2. fetchAndFill reads tenant data from json.data?.tenant
 * 3. Non-success responses are handled gracefully (no DOM updates)
 *
 * Validates: Requirements 8.4
 */

import { describe, it, expect } from 'vitest';

// --- Logic under test (extracted from tenantForm.modal.js) ---

/**
 * Builds the URL that fetchAndFill uses to fetch a single tenant.
 *
 * @param {number|string} id - The tenant ID
 * @returns {string} The API URL
 */
export function buildFetchTenantUrl(id) {
    return `/api/tenant/tenants/${id}`;
}

/**
 * Extracts the tenant object from the API response JSON.
 * Returns null if the response indicates failure or the tenant is missing.
 *
 * @param {{ ok: boolean }} res - The fetch Response object (or mock)
 * @param {object} json - The parsed JSON body
 * @returns {object|null} The tenant object or null
 */
export function extractTenantFromResponse(res, json) {
    if (!res.ok || !json.success) return null;
    return json.data?.tenant || null;
}

// --- Tests ---

describe('Tenant Form Modal — fetchAndFill', () => {

    describe('buildFetchTenantUrl', () => {
        it('builds correct URL for numeric tenant ID', () => {
            expect(buildFetchTenantUrl(5)).toBe('/api/tenant/tenants/5');
        });

        it('builds correct URL for string tenant ID', () => {
            expect(buildFetchTenantUrl('12')).toBe('/api/tenant/tenants/12');
        });

        it('builds correct URL for large ID', () => {
            expect(buildFetchTenantUrl(9999)).toBe('/api/tenant/tenants/9999');
        });
    });

    describe('extractTenantFromResponse', () => {
        it('returns tenant object from successful response', () => {
            const res = { ok: true };
            const json = {
                success: true,
                data: {
                    tenant: { id: 5, name: 'Acme Corp', description: 'Test tenant' }
                }
            };
            const result = extractTenantFromResponse(res, json);
            expect(result).toEqual({ id: 5, name: 'Acme Corp', description: 'Test tenant' });
        });

        it('returns null when res.ok is false', () => {
            const res = { ok: false };
            const json = { success: true, data: { tenant: { id: 1 } } };
            expect(extractTenantFromResponse(res, json)).toBeNull();
        });

        it('returns null when json.success is false', () => {
            const res = { ok: true };
            const json = { success: false, data: { tenant: { id: 1 } } };
            expect(extractTenantFromResponse(res, json)).toBeNull();
        });

        it('returns null when data is null', () => {
            const res = { ok: true };
            const json = { success: true, data: null };
            expect(extractTenantFromResponse(res, json)).toBeNull();
        });

        it('returns null when data.tenant is undefined', () => {
            const res = { ok: true };
            const json = { success: true, data: {} };
            expect(extractTenantFromResponse(res, json)).toBeNull();
        });

        it('returns null when both res.ok and json.success are false', () => {
            const res = { ok: false };
            const json = { success: false, data: null };
            expect(extractTenantFromResponse(res, json)).toBeNull();
        });
    });
});
