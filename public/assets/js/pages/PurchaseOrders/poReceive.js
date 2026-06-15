// assets/js/pages/PurchaseOrders/poReceive.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { confirm, alert } from '../../components/modals.js';

/**
 * Receive a Purchase Order, optionally prompting for batch/expiry info
 * if the PO contains expiry-tracked items.
 *
 * @param {number} poId - The purchase order ID
 * @param {Function} onSuccess - Callback invoked after successful receive
 */
export async function receivePOWithBatchInfo(poId, onSuccess) {
    // Confirm the receive action
    const confirmed = await confirm({
        title: 'Receive Purchase Order',
        message: 'Mark this purchase order as received? Stock levels will be updated.'
    });
    if (!confirmed) return;

    try {
        // Attempt to mark as RECEIVED
        const res = await apiFetch(`/api/purchase-orders/${poId}/status`, {
            method: 'POST',
            body: JSON.stringify({ status: 'RECEIVED' })
        });

        const data = await res.json();

        if (!res.ok || !data.success) {
            await alert({
                title: 'Error',
                alertText: data.message || 'Failed to receive purchase order'
            });
            return;
        }

        if (typeof onSuccess === 'function') {
            await onSuccess();
        }
    } catch (err) {
        console.error('Receive PO failed:', err);
        await alert({
            title: 'Error',
            alertText: 'An unexpected error occurred while receiving the purchase order.'
        });
    }
}
