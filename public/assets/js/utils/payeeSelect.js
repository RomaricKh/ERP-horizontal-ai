import { apiFetch } from '../Auth/apiFetch.js';

// Cache the payee→people mapping for use by other modules
let payeeToPeopleMap = new Map();

/**
 * Get the people_id for a given payee_id.
 * @param {string|number} payeeId
 * @returns {string|null}
 */
export function getPeopleIdForPayee(payeeId) {
    return payeeToPeopleMap.get(String(payeeId)) || null;
}

/**
 * Fetches people from the API and populates a <select> element
 * with entries that have a payee_id.
 *
 * @param {HTMLSelectElement} selectEl - The select element to populate.
 * @param {Object} [options] - Optional filtering options.
 * @param {string} [options.typeFilter] - If provided, only show payees whose
 *        payee_types string contains this value (case-insensitive).
 *        E.g., "Customer", "Supplier", "Employee".
 * @returns {Promise<void>}
 */
export async function populatePayeeSelect(selectEl, options = {}) {
    const res = await apiFetch('/api/people');
    const json = await res.json();

    const payees = (json.data?.people || []).filter(p => p.payee_id != null);

    // Build the payee→people mapping
    payeeToPeopleMap = new Map();
    for (const p of payees) {
        payeeToPeopleMap.set(String(p.payee_id), String(p.id));
    }

    // Filter by payee type if specified
    const filtered = options.typeFilter
        ? payees.filter(p => (p.payee_types || '').toLowerCase().includes(options.typeFilter.toLowerCase()))
        : payees;

    // Clear existing options
    selectEl.innerHTML = '';

    // Blank default option
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = '';
    selectEl.appendChild(blank);

    // Populate payee options
    for (const payee of filtered) {
        const option = document.createElement('option');
        option.value = payee.payee_id;
        option.textContent = payee.name;
        option.dataset.peopleId = payee.id; // people.id for customer-item matching
        selectEl.appendChild(option);
    }
}
