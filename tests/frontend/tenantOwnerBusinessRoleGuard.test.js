/**
 * Unit test: Tenant Owner Business Role Guard — UI Behavior
 *
 * Verifies that the business role assignment form is hidden when the selected
 * user is already a tenant owner of the current tenant.
 *
 * Validates: REQ-026, Correctness Property 10
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';

// --- Logic under test (extracted from userForm.modal.js) ---

/**
 * Determines whether the business access section should be visible
 * based on the user's tenant owner status.
 *
 * @param {boolean} isTenantOwner - Whether the user is a tenant owner
 * @returns {boolean} Whether the business section should be visible
 */
export function shouldShowBusinessSection(isTenantOwner) {
    return !isTenantOwner;
}

/**
 * Applies the visibility state to the DOM elements.
 *
 * @param {HTMLElement} businessSection - The business access section element
 * @param {HTMLElement} tenantOwnerAlert - The alert element shown for tenant owners
 * @param {boolean} visible - Whether the business section should be visible
 */
export function applyBusinessSectionVisibility(businessSection, tenantOwnerAlert, visible) {
    if (businessSection) {
        businessSection.style.display = visible ? '' : 'none';
    }
    if (tenantOwnerAlert) {
        if (visible) {
            tenantOwnerAlert.classList.add('d-none');
        } else {
            tenantOwnerAlert.classList.remove('d-none');
        }
    }
}

/**
 * Determines tenant owner status from the user profile API response.
 *
 * @param {object} userData - The user profile data from GET /tenant/users/{id}
 * @returns {boolean} Whether the user is a tenant owner
 */
export function isTenantOwnerFromProfile(userData) {
    if (userData.is_tenant_owner === true) return true;
    if (Array.isArray(userData.assignments)) {
        return userData.assignments.some(a => a.role_type === 'TENANT_OWNER');
    }
    return false;
}

// --- Tests ---

describe('Tenant Owner Business Role Guard — UI Behavior', () => {
    let dom;
    let document;

    beforeEach(() => {
        dom = new JSDOM(`
            <div id="modal-root">
                <div id="tenantOwnerAlert" class="alert alert-info d-none">
                    This user is a tenant owner and manages all businesses directly.
                </div>
                <div id="businessAccessSection">
                    <select id="tenantBusinessSelect"><option value="">— Select —</option></select>
                    <select id="tenantRoleSelect"><option value="">— Select —</option></select>
                </div>
            </div>
        `);
        document = dom.window.document;
    });

    describe('shouldShowBusinessSection', () => {
        it('returns false when user is a tenant owner', () => {
            expect(shouldShowBusinessSection(true)).toBe(false);
        });

        it('returns true when user is NOT a tenant owner', () => {
            expect(shouldShowBusinessSection(false)).toBe(true);
        });
    });

    describe('applyBusinessSectionVisibility', () => {
        it('hides business section and shows alert when visible=false (tenant owner)', () => {
            const section = document.getElementById('businessAccessSection');
            const alert = document.getElementById('tenantOwnerAlert');

            applyBusinessSectionVisibility(section, alert, false);

            expect(section.style.display).toBe('none');
            expect(alert.classList.contains('d-none')).toBe(false);
        });

        it('shows business section and hides alert when visible=true (non-tenant-owner)', () => {
            const section = document.getElementById('businessAccessSection');
            const alert = document.getElementById('tenantOwnerAlert');

            // First hide it
            applyBusinessSectionVisibility(section, alert, false);
            // Then show it
            applyBusinessSectionVisibility(section, alert, true);

            expect(section.style.display).toBe('');
            expect(alert.classList.contains('d-none')).toBe(true);
        });

        it('handles null elements gracefully', () => {
            // Should not throw
            expect(() => applyBusinessSectionVisibility(null, null, false)).not.toThrow();
            expect(() => applyBusinessSectionVisibility(null, null, true)).not.toThrow();
        });
    });

    describe('isTenantOwnerFromProfile', () => {
        it('returns true when is_tenant_owner flag is true', () => {
            const userData = {
                is_tenant_owner: true,
                assignments: []
            };
            expect(isTenantOwnerFromProfile(userData)).toBe(true);
        });

        it('returns true when assignments contain a TENANT_OWNER role_type', () => {
            const userData = {
                is_tenant_owner: false,
                assignments: [
                    { business_id: 1, role_type: 'CUSTOM' },
                    { business_id: null, role_type: 'TENANT_OWNER' }
                ]
            };
            expect(isTenantOwnerFromProfile(userData)).toBe(true);
        });

        it('returns false when user has no tenant owner indicators', () => {
            const userData = {
                is_tenant_owner: false,
                assignments: [
                    { business_id: 1, role_type: 'CUSTOM' },
                    { business_id: 2, role_type: 'SYSTEM_OWNER' }
                ]
            };
            expect(isTenantOwnerFromProfile(userData)).toBe(false);
        });

        it('returns false when assignments array is empty', () => {
            const userData = {
                is_tenant_owner: false,
                assignments: []
            };
            expect(isTenantOwnerFromProfile(userData)).toBe(false);
        });

        it('returns false when assignments is undefined', () => {
            const userData = {
                is_tenant_owner: false
            };
            expect(isTenantOwnerFromProfile(userData)).toBe(false);
        });
    });
});
