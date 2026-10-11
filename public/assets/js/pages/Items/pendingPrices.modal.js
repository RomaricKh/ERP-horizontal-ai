import { apiFetch } from '../../Auth/apiFetch.js';
import { alert, confirm } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';

let modalRoot;
let dt = null;

export async function mount({ modalRoot: root }) {
    modalRoot = root;
    
    await loadPendingPrices();
    
    if (!dt) {
        setupTable();
    }
    // Always setup events, they use event delegation so it's safe to run once.
    setupEvents();
    
    // Reset selections
    $(modalRoot).find('#selectAllPendingPrices').prop('checked', false);
    updateButtonsState();
}

export function unmount() {
    if (dt) {
        dt.destroy();
        dt = null;
    }
}

function setupTable() {
    const tableEl = $(modalRoot).find('#pendingPricesTable');
    dt = createDataTable(tableEl, {
        columnDefs: [
            { targets: '_all', className: 'text-nowrap align-middle' },
            { targets: 0, orderable: false, searchable: false }
        ],
        scrollY: '50vh',
        columns: [
            {
                data: 'id',
                orderable: false,
                className: 'text-center align-middle',
                render: function(data) {
                    return `
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input pending-price-cb" id="cb_pp_${data}" value="${data}">
                            <label class="custom-control-label" for="cb_pp_${data}"></label>
                        </div>
                    `;
                }
            },
            {
                data: null,
                render: (data) => {
                    let name = escHtml(data.item_name || '');
                    let sku = data.sku ? ` <span class="badge bg-secondary">${escHtml(data.sku)}</span>` : '';
                    return `${name}${sku}`;
                }
            },
            { 
                data: 'current_sale_price',
                className: 'text-end',
                render: (data) => parseFloat(data || 0).toFixed(4)
            },
            { 
                data: 'pending_sale_price',
                className: 'text-end fw-bold text-success',
                render: (data) => parseFloat(data || 0).toFixed(4)
            },
            {
                data: null,
                render: (data) => {
                    if (data.source_type === 'PURCHASE_ORDER') {
                        return `<span class="badge bg-info">PO #${escHtml(data.po_number || data.source_id)}</span>`;
                    } else if (data.source_type === 'MANUAL') {
                        return `<span class="badge bg-secondary">Manual Edit</span>`;
                    }
                    return data.source_type;
                }
            },
            { 
                data: 'created_at',
                render: (data) => data ? data.substring(0, 16) : ''
            }
        ],
        order: [[5, 'desc']],
        language: { emptyTable: 'No pending price updates.' },
        pageLength: 25
    });
}

async function loadPendingPrices() {
    try {
        const res = await apiFetch('/api/items/pending-prices');
        const json = await res.json();
        
        if (!dt) {
            setupTable();
        }

        dt.clear();
        if (json.success && json.data && json.data.pending_prices) {
            dt.rows.add(json.data.pending_prices);
        }
        dt.columns.adjust().draw();
        
    } catch (err) {
        console.error('Failed to load pending prices:', err);
    }
}

function setupEvents() {
    const $root = $(modalRoot);
    
    // Prevent double binding by unbinding first
    $root.find('#selectAllPendingPrices').off('change');
    $root.find('#pendingPricesTable').off('change', '.pending-price-cb');
    $root.find('#btnApplyPendingPrices').off('click');
    $root.find('#btnCancelPendingPrices').off('click');
    
    $root.find('#selectAllPendingPrices').on('change', function() {
        const isChecked = $(this).prop('checked');
        $root.find('.pending-price-cb').prop('checked', isChecked);
        updateButtonsState();
    });

    $root.find('#pendingPricesTable').on('change', '.pending-price-cb', function() {
        const total = $root.find('.pending-price-cb').length;
        const checked = $root.find('.pending-price-cb:checked').length;
        $root.find('#selectAllPendingPrices').prop('checked', total === checked && total > 0);
        updateButtonsState();
    });

    $root.find('#btnApplyPendingPrices').on('click', applySelected);
    $root.find('#btnCancelPendingPrices').on('click', cancelSelected);
}

function updateButtonsState() {
    const checkedCount = $(modalRoot).find('.pending-price-cb:checked').length;
    $(modalRoot).find('#btnApplyPendingPrices, #btnCancelPendingPrices').prop('disabled', checkedCount === 0);
}

function getSelectedIds() {
    return $(modalRoot).find('.pending-price-cb:checked').map(function() {
        return parseInt($(this).val());
    }).get();
}

async function applySelected() {
    const ids = getSelectedIds();
    if (ids.length === 0) return;

    const isConfirmed = await confirm({
        title: 'Apply Pending Prices',
        message: `Are you sure you want to apply ${ids.length} pending sale price(s)?`
    });
    if (!isConfirmed) return;

    try {
        const $btn = $(modalRoot).find('#btnApplyPendingPrices');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Applying...');
        
        const res = await apiFetch('/api/items/pending-prices/apply', { 
            method: 'POST',
            body: JSON.stringify({ ids })
        });
        const json = await res.json();
        
        if (res.ok && json.success) {
            // Trigger an event so the main items table can reload
            document.dispatchEvent(new CustomEvent('items.updated'));
        } else {
            await alert({ title: 'Error', alertText: json.message || 'Prices applied with errors' });
        }
    } catch (e) {
        await alert({ title: 'Error', alertText: e.message });
    } finally {
        $(modalRoot).find('#btnApplyPendingPrices').html('<i class="fas fa-fw fa-check"></i> Apply Selected');
        await loadPendingPrices();
        $(modalRoot).find('#selectAllPendingPrices').prop('checked', false);
        updateButtonsState();
    }
}

async function cancelSelected() {
    const ids = getSelectedIds();
    if (ids.length === 0) return;

    const isConfirmed = await confirm({
        title: 'Discard Pending Prices',
        message: `Are you sure you want to discard ${ids.length} pending sale price(s)?`
    });
    if (!isConfirmed) return;

    try {
        const $btn = $(modalRoot).find('#btnCancelPendingPrices');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Discarding...');
        
        const res = await apiFetch('/api/items/pending-prices/cancel', { 
            method: 'POST',
            body: JSON.stringify({ ids })
        });
        const json = await res.json();
        
        if (!res.ok || !json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Prices discarded with errors' });
        }
    } catch (e) {
        await alert({ title: 'Error', alertText: e.message });
    } finally {
        $(modalRoot).find('#btnCancelPendingPrices').html('<i class="fas fa-fw fa-times"></i> Discard Selected');
        await loadPendingPrices();
        $(modalRoot).find('#selectAllPendingPrices').prop('checked', false);
        updateButtonsState();
    }
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
