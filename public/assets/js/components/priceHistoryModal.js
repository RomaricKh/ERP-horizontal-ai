// assets/js/components/priceHistoryModal.js
// Utility to open the price history modal from within other modals (e.g. item form)

import { MODAL_SCOPE, openModal } from './modals.js';

/**
 * Open the Price History modal for a given item.
 * Can be called from within other modals (item form, etc.)
 *
 * @param {number} itemId - The item ID to show price history for
 * @param {string} [itemName] - Optional item name for display
 */
export async function openPriceHistoryModal(itemId, itemName = '') {
    await openModal({
        scope: MODAL_SCOPE.GLOBAL,
        template: 'html/Items/priceHistory.html',
        controller: '../pages/Items/priceHistory.modal.js',
        data: { item_id: itemId, item_name: itemName },
        options: { backdrop: true, keyboard: true, focus: true }
    });
}
