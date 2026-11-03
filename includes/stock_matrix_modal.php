<?php
/**
 * OminiFlow POS - Size & Colour Stock Matrix Modal Component
 * Reusable modal for viewing, distributing, and adjusting product inventory by size & colour.
 */
declare(strict_types=1);
?>
<!-- Stock Matrix Modal -->
<div class="modal-overlay" id="productStockMatrixModal" style="z-index: 1050; display: none; align-items: center; justify-content: center; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(2px);">
    <div class="modal-box" style="max-width: 960px; width: 95%; max-height: 90vh; display: flex; flex-direction: column; background: #ffffff; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 8px 10px -6px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; overflow: hidden;">
        
        <!-- Modal Header -->
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 22px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 8px; background: #eff6ff; color: #2563eb; font-size: 18px; font-weight: 800;">⊞</span>
                <div>
                    <h3 class="modal-title" style="margin: 0; font-size: 16.5px; font-weight: 700; color: #0f172a;" id="matrixModalTitle">Product Stock Matrix</h3>
                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                        <span id="matrixModalProdName" style="font-weight: 600; color: #334155;">Product Name</span> · 
                        SKU: <span id="matrixModalProdSku" style="font-family: monospace; font-weight: 700; color: #2563eb;">—</span>
                    </div>
                </div>
            </div>
            <button type="button" class="modal-close-btn" id="closeStockMatrixModal" style="background: none; border: none; font-size: 24px; color: #94a3b8; cursor: pointer; line-height: 1; padding: 4px 8px; border-radius: 6px;">&times;</button>
        </div>

        <!-- Modal Body (Scrollable) -->
        <div class="modal-body" style="padding: 18px 22px; overflow-y: auto; flex: 1;">
            
            <!-- Loading Spinner State -->
            <div id="matrixModalLoading" style="text-align: center; padding: 40px 20px;">
                <div style="display: inline-block; width: 34px; height: 34px; border: 3px solid #e2e8f0; border-top-color: #2563eb; border-radius: 50%; animation: matrixSpin 0.8s linear infinite;"></div>
                <div style="margin-top: 12px; font-size: 13.5px; font-weight: 600; color: #475569;">Loading product stock matrix...</div>
            </div>

            <!-- Error State -->
            <div id="matrixModalError" style="display: none; padding: 14px 18px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-size: 13.5px; margin-bottom: 16px;"></div>

            <!-- Matrix Content Container -->
            <div id="matrixModalContent" style="display: none;">
                
                <!-- Sizes Toolbar -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                        <div style="font-size: 12.5px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 6px;">
                            <span>Active Sizes:</span>
                            <div id="matrixActiveSizesChips" style="display: inline-flex; flex-wrap: wrap; gap: 6px;"></div>
                        </div>
                        <div style="display: inline-flex; align-items: center; gap: 6px;">
                            <input type="text" id="matrixNewSizeInput" placeholder="New size (e.g. 3XL)" style="width: 120px; height: 28px; font-size: 12px; padding: 2px 8px; border: 1px solid #cbd5e1; border-radius: 5px;">
                            <button type="button" class="btn-secondary" id="matrixAddSizeBtn" style="padding: 2px 10px; font-size: 12px; height: 28px; font-weight: 600;">+ Add</button>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11.5px; color: #64748b;">
                        <span style="font-weight: 600;">Quick Presets:</span>
                        <button type="button" class="preset-size-pill" data-preset="S,M,L,XL,XXL">S – XXL</button>
                        <button type="button" class="preset-size-pill" data-preset="XS,S,M,L,XL,XXL,3XL">XS – 3XL</button>
                        <button type="button" class="preset-size-pill" data-preset="FREE SIZE">Free Size</button>
                        <button type="button" class="preset-size-pill" data-preset="28,30,32,34,36,38,40">28 – 40</button>
                    </div>
                </div>

                <!-- Matrix Table Wrap -->
                <div class="table-wrap" style="overflow-x: auto; margin-bottom: 12px; border: 1px solid #cbd5e1; border-radius: 8px;">
                    <table class="matrix-table" id="stockMatrixMainTable" style="margin: 0; width: 100%;">
                        <thead id="stockMatrixHead"></thead>
                        <tbody id="stockMatrixBody"></tbody>
                        <tfoot id="stockMatrixFoot"></tfoot>
                    </table>
                </div>

                <!-- Bulk Fill & Subtotal Bar (Exact match to Screenshot 2) -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; padding-top: 4px;">
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" id="matrixAddColourBtn" style="padding: 6px 14px; font-weight: 600; font-size: 12.5px; border: 1px solid #cbd5e1; border-radius: 6px; background: #ffffff; cursor: pointer;">+ Add Colour</button>
                        <div style="display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                            <span style="font-size: 11.5px; color: #475569; font-weight: 600;">Fill all rows:</span>
                            <input type="number" step="0.01" min="0" placeholder="Cost (₹)" id="matrixBulkCost" class="form-control" style="width: 80px; height: 28px; font-size: 12px; padding: 2px 6px; text-align: right; border: 1px solid #cbd5e1; border-radius: 4px;">
                            <input type="number" step="0.01" min="0" placeholder="Selling (₹)" id="matrixBulkPrice" class="form-control" style="width: 80px; height: 28px; font-size: 12px; padding: 2px 6px; text-align: right; border: 1px solid #cbd5e1; border-radius: 4px;">
                            <input type="number" step="1" min="0" placeholder="Qty" id="matrixBulkQty" class="form-control" style="width: 60px; height: 28px; font-size: 12px; padding: 2px 6px; text-align: center; border: 1px solid #cbd5e1; border-radius: 4px;">
                            <button type="button" class="btn-secondary" id="matrixApplyBulkBtn" style="padding: 2px 10px; font-size: 11.5px; height: 28px; font-weight: 600; border: 1px solid #cbd5e1; border-radius: 4px; background: #ffffff; cursor: pointer;">Apply</button>
                        </div>
                    </div>
                    <!-- Subtotal element with identical styling to screenshot 2 -->
                    <div style="font-size: 13px; font-weight: 700; color: #334155; background: #f8fafc; border: 1px solid #cbd5e1; padding: 6px 14px; border-radius: 6px;">
                        Product Subtotal: <span id="matrixTotalQtyDisplay" style="color: #2563eb;">0</span> pcs · ₹<span id="matrixTotalCostDisplay" style="color: #16a34a;">0.00</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- Modal Footer -->
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 22px; border-top: 1px solid #e2e8f0; background: #f8fafc;">
            <div style="font-size: 12px; color: #64748b;">
                Changes here will update size stocks & synchronize total inventory.
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn-secondary" id="cancelStockMatrixModal" style="padding: 7px 16px; font-weight: 600; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #ffffff; cursor: pointer;">Cancel</button>
                <button type="button" class="header-btn" id="saveStockMatrixModalBtn" style="padding: 7px 20px; font-weight: 700; font-size: 13px; border: 0; border-radius: 6px; background: #2563eb; color: #ffffff; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <span id="saveMatrixBtnSpinner" style="display: none; width: 14px; height: 14px; border: 2px solid #ffffff; border-top-color: transparent; border-radius: 50%; animation: matrixSpin 0.8s linear infinite;"></span>
                    <span id="saveMatrixBtnText">Save Stock Matrix</span>
                </button>
            </div>
        </div>

    </div>
</div>

<style>
@keyframes matrixSpin {
    to { transform: rotate(360deg); }
}
.matrix-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    background: #ffffff;
}
.matrix-table th {
    background: #f1f5f9;
    color: #334155;
    font-weight: 700;
    text-align: center;
    padding: 9px 8px;
    border: 1px solid #cbd5e1;
    white-space: nowrap;
}
.matrix-table td {
    padding: 6px 8px;
    border: 1px solid #cbd5e1;
    vertical-align: middle;
    text-align: center;
    background: #ffffff;
}
.matrix-size-th {
    background: #eff6ff !important;
    color: #1e40af !important;
    min-width: 65px;
}
.matrix-cell-inp {
    width: 60px;
    height: 32px;
    text-align: center;
    font-weight: 700;
    font-size: 14px;
    padding: 2px 4px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    color: #0f172a;
    transition: all 0.15s ease;
}
.matrix-cell-inp:focus {
    border-color: #2563eb;
    outline: none;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
    background: #eff6ff;
}
.matrix-cell-inp::-webkit-outer-spin-button,
.matrix-cell-inp::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.matrix-cell-inp[type=number] {
    -moz-appearance: textfield;
}
.preset-size-pill {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}
.preset-size-pill:hover {
    background: #dbeafe;
    border-color: #93c5fd;
}
.matrix-active-size-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
    font-size: 11.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
}
.matrix-active-size-chip button {
    background: none;
    border: none;
    color: #3b82f6;
    cursor: pointer;
    font-size: 13px;
    line-height: 1;
    padding: 0 2px;
    font-weight: 700;
}
.matrix-active-size-chip button:hover {
    color: #ef4444;
}
.open-stock-matrix-badge {
    cursor: pointer !important;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    user-select: none;
}
.open-stock-matrix-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
    filter: brightness(0.97);
}
</style>

<script>
(function() {
    let currentMatrixData = null;
    let csrfToken = '<?= csrf_token() ?>';

    const modal = document.getElementById('productStockMatrixModal');
    const closeBtn = document.getElementById('closeStockMatrixModal');
    const cancelBtn = document.getElementById('cancelStockMatrixModal');
    const saveBtn = document.getElementById('saveStockMatrixModalBtn');
    const loadingEl = document.getElementById('matrixModalLoading');
    const errorEl = document.getElementById('matrixModalError');
    const contentEl = document.getElementById('matrixModalContent');
    const activeSizesChips = document.getElementById('matrixActiveSizesChips');
    const newSizeInput = document.getElementById('matrixNewSizeInput');
    const addSizeBtn = document.getElementById('matrixAddSizeBtn');
    const addColourBtn = document.getElementById('matrixAddColourBtn');
    const applyBulkBtn = document.getElementById('matrixApplyBulkBtn');

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function openStockMatrixModal(productId) {
        if (!modal) return;
        modal.style.display = 'flex';
        loadingEl.style.display = 'block';
        errorEl.style.display = 'none';
        contentEl.style.display = 'none';
        saveBtn.disabled = true;

        fetch('<?= asset('products.php') ?>?action=get_stock_matrix&product_id=' + encodeURIComponent(productId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            loadingEl.style.display = 'none';
            if (!data.success) {
                errorEl.textContent = data.error || 'Could not load stock matrix.';
                errorEl.style.display = 'block';
                return;
            }
            currentMatrixData = data;
            renderMatrixModal();
            contentEl.style.display = 'block';
            saveBtn.disabled = false;
        })
        .catch(err => {
            loadingEl.style.display = 'none';
            errorEl.textContent = 'Network error loading matrix. Please try again.';
            errorEl.style.display = 'block';
        });
    }

    function closeStockMatrixModal() {
        if (modal) modal.style.display = 'none';
        currentMatrixData = null;
    }

    if (closeBtn) closeBtn.addEventListener('click', closeStockMatrixModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeStockMatrixModal);
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeStockMatrixModal();
        });
    }

    function renderMatrixModal() {
        if (!currentMatrixData) return;
        const prod = currentMatrixData.product || {};
        document.getElementById('matrixModalProdName').textContent = prod.name || 'Product';
        document.getElementById('matrixModalProdSku').textContent = prod.sku || '—';

        renderActiveSizeChips();
        renderMatrixTable();
        recalculateMatrixTotals();
    }

    function renderActiveSizeChips() {
        if (!activeSizesChips || !currentMatrixData) return;
        activeSizesChips.innerHTML = '';
        currentMatrixData.sizes.forEach(sz => {
            const chip = document.createElement('span');
            chip.className = 'matrix-active-size-chip';
            chip.innerHTML = '<span>' + escapeHtml(sz) + '</span>' + 
                (currentMatrixData.sizes.length > 1 ? '<button type="button" title="Remove ' + escapeHtml(sz) + '">&times;</button>' : '');
            const delBtn = chip.querySelector('button');
            if (delBtn) {
                delBtn.addEventListener('click', function() {
                    removeSize(sz);
                });
            }
            activeSizesChips.appendChild(chip);
        });
    }

    function renderMatrixTable() {
        if (!currentMatrixData) return;
        const tableHead = document.getElementById('stockMatrixHead');
        const tableBody = document.getElementById('stockMatrixBody');
        const tableFoot = document.getElementById('stockMatrixFoot');
        const sizes = currentMatrixData.sizes;
        const rows = currentMatrixData.rows;
        const prod = currentMatrixData.product;

        // 1. Header (exact style match to Screenshot 2)
        let hHtml = '<tr>' +
            '<th style="min-width: 130px; text-align: left;">Colour</th>' +
            '<th style="width: 100px; text-align: center;">Cost (₹)</th>' +
            '<th style="width: 100px; text-align: center;">Selling (₹)</th>';
        sizes.forEach(sz => {
            hHtml += '<th class="matrix-size-th">' + escapeHtml(sz) + '</th>';
        });
        hHtml += '<th style="width: 85px; text-align: center; background: #f1f5f9; color: #334155;">Total Pcs</th>' +
                 '<th style="width: 40px; text-align: center;"></th>' +
                 '</tr>';
        tableHead.innerHTML = hHtml;

        // 2. Body rows
        tableBody.innerHTML = '';
        rows.forEach((row, rIdx) => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-row-idx', rIdx);

            let rHtml = '<td style="text-align: left;">' +
                '<input type="text" class="form-control matrix-colour-inp" value="' + escapeHtml(row.colour || '') + '" placeholder="e.g. Maroon" style="font-weight: 600; width: 100%; height: 32px; font-size: 13px; padding: 2px 8px; border: 1px solid #cbd5e1; border-radius: 5px;">' +
                '</td>' +
                '<td><input type="number" step="0.01" min="0" class="form-control matrix-cost-inp" value="' + escapeHtml(row.cost_price !== undefined ? row.cost_price : prod.cost_price) + '" placeholder="0.00" style="text-align: right; width: 100%; height: 32px; font-size: 12.5px; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 5px;"></td>' +
                '<td><input type="number" step="0.01" min="0" class="form-control matrix-price-inp" value="' + escapeHtml(row.selling_price !== undefined ? row.selling_price : prod.selling_price) + '" placeholder="0.00" style="text-align: right; width: 100%; height: 32px; font-size: 12.5px; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 5px;"></td>';

            sizes.forEach(sz => {
                const q = row.quantities && row.quantities[sz] !== undefined ? row.quantities[sz] : 0;
                rHtml += '<td>' +
                    '<input type="number" min="0" step="1" class="matrix-cell-inp" data-size="' + escapeHtml(sz) + '" value="' + parseInt(q, 10) + '" placeholder="0">' +
                    '</td>';
            });

            rHtml += '<td style="font-weight: 800; font-size: 14px; color: #0f172a;" class="matrix-row-total-pcs">0</td>' +
                     '<td><button type="button" class="matrix-row-del-btn" style="background: none; border: none; color: #dc2626; font-size: 18px; font-weight: 700; cursor: pointer; padding: 2px 6px;" title="Remove colour row">&times;</button></td>';

            tr.innerHTML = rHtml;

            // Input event listeners
            tr.querySelectorAll('.matrix-cell-inp').forEach(inp => {
                inp.addEventListener('input', function() {
                    const sz = this.getAttribute('data-size');
                    const val = Math.max(0, parseInt(this.value, 10) || 0);
                    if (!row.quantities) row.quantities = {};
                    row.quantities[sz] = val;
                    recalculateMatrixTotals();
                });
            });

            tr.querySelector('.matrix-colour-inp').addEventListener('input', function() {
                row.colour = this.value.trim();
            });
            tr.querySelector('.matrix-cost-inp').addEventListener('input', function() {
                row.cost_price = parseFloat(this.value) || 0;
                recalculateMatrixTotals();
            });
            tr.querySelector('.matrix-price-inp').addEventListener('input', function() {
                row.selling_price = parseFloat(this.value) || 0;
                recalculateMatrixTotals();
            });

            const delRowBtn = tr.querySelector('.matrix-row-del-btn');
            if (delRowBtn) {
                delRowBtn.addEventListener('click', function() {
                    if (currentMatrixData.rows.length <= 1) {
                        alert('At least one colour row is required.');
                        return;
                    }
                    currentMatrixData.rows.splice(rIdx, 1);
                    renderMatrixTable();
                    recalculateMatrixTotals();
                });
            }

            tableBody.appendChild(tr);
        });

        // 3. Footer row (matching totals row in Screenshot 2)
        let fHtml = '<tr style="background: #f8fafc; font-weight: 800;">' +
            '<td colspan="3" style="text-align: right; padding-right: 14px; color: #475569; font-size: 13px;">Total</td>';
        sizes.forEach(sz => {
            fHtml += '<td style="color: #1e40af; font-size: 15px; font-weight: 800;" class="matrix-col-total" data-size="' + escapeHtml(sz) + '">0</td>';
        });
        fHtml += '<td style="color: #1e40af; font-size: 15px; font-weight: 800;" id="matrixGrandTotalPcs">0</td>' +
                 '<td></td>' +
                 '</tr>';
        tableFoot.innerHTML = fHtml;
    }

    function recalculateMatrixTotals() {
        if (!currentMatrixData) return;
        const sizes = currentMatrixData.sizes;
        const rows = currentMatrixData.rows;
        const sizeTotals = {};
        sizes.forEach(sz => { sizeTotals[sz] = 0; });

        let grandTotal = 0;
        let totalValuation = 0.0;

        const bodyRows = document.querySelectorAll('#stockMatrixBody tr');
        rows.forEach((row, rIdx) => {
            let rowPcs = 0;
            const price = parseFloat(row.selling_price) || 0;
            sizes.forEach(sz => {
                const q = Math.max(0, parseInt(row.quantities && row.quantities[sz] !== undefined ? row.quantities[sz] : 0, 10));
                rowPcs += q;
                sizeTotals[sz] = (sizeTotals[sz] || 0) + q;
                grandTotal += q;
                totalValuation += q * price;
            });
            row.row_total = rowPcs;

            const tr = bodyRows[rIdx];
            if (tr) {
                const rowTotEl = tr.querySelector('.matrix-row-total-pcs');
                if (rowTotEl) rowTotEl.textContent = rowPcs;
            }
        });

        // Update footer column totals
        sizes.forEach(sz => {
            const colTotEl = document.querySelector('#stockMatrixFoot .matrix-col-total[data-size="' + CSS.escape(sz) + '"]');
            if (colTotEl) colTotEl.textContent = sizeTotals[sz] || 0;
        });

        const grandEl = document.getElementById('matrixGrandTotalPcs');
        if (grandEl) grandEl.textContent = grandTotal;

        // Update bottom subtotal bar
        const subtotalQtyEl = document.getElementById('matrixTotalQtyDisplay');
        const subtotalValEl = document.getElementById('matrixTotalCostDisplay');
        if (subtotalQtyEl) subtotalQtyEl.textContent = grandTotal;
        if (subtotalValEl) subtotalValEl.textContent = totalValuation.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function removeSize(sz) {
        if (!currentMatrixData || currentMatrixData.sizes.length <= 1) return;
        currentMatrixData.sizes = currentMatrixData.sizes.filter(s => s !== sz);
        renderActiveSizeChips();
        renderMatrixTable();
        recalculateMatrixTotals();
    }

    function addSize(sz) {
        if (!currentMatrixData) return;
        const clean = (sz || '').trim();
        if (!clean) return;
        const exists = currentMatrixData.sizes.some(s => s.toLowerCase() === clean.toLowerCase());
        if (!exists) {
            currentMatrixData.sizes.push(clean);
            renderActiveSizeChips();
            renderMatrixTable();
            recalculateMatrixTotals();
        }
    }

    if (addSizeBtn && newSizeInput) {
        addSizeBtn.addEventListener('click', function() {
            addSize(newSizeInput.value);
            newSizeInput.value = '';
            newSizeInput.focus();
        });
        newSizeInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addSize(newSizeInput.value);
                newSizeInput.value = '';
            }
        });
    }

    document.querySelectorAll('.preset-size-pill').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!currentMatrixData) return;
            const p = this.getAttribute('data-preset');
            if (!p) return;
            const arr = p.split(',').map(s => s.trim()).filter(Boolean);
            if (arr.length) {
                currentMatrixData.sizes = arr;
                renderActiveSizeChips();
                renderMatrixTable();
                recalculateMatrixTotals();
            }
        });
    });

    if (addColourBtn) {
        addColourBtn.addEventListener('click', function() {
            if (!currentMatrixData) return;
            const prod = currentMatrixData.product || {};
            const newRow = {
                colour: 'Colour ' + (currentMatrixData.rows.length + 1),
                colour_code: '',
                video_sku: '',
                cost_price: prod.cost_price || 0,
                selling_price: prod.selling_price || 0,
                quantities: {}
            };
            currentMatrixData.sizes.forEach(sz => { newRow.quantities[sz] = 0; });
            currentMatrixData.rows.push(newRow);
            renderMatrixTable();
            recalculateMatrixTotals();
        });
    }

    if (applyBulkBtn) {
        applyBulkBtn.addEventListener('click', function() {
            if (!currentMatrixData) return;
            const costVal = document.getElementById('matrixBulkCost').value.trim();
            const priceVal = document.getElementById('matrixBulkPrice').value.trim();
            const qtyVal = document.getElementById('matrixBulkQty').value.trim();

            if (!costVal && !priceVal && !qtyVal) {
                alert('Please enter a Cost, Selling price, or Quantity to fill.');
                return;
            }

            currentMatrixData.rows.forEach(r => {
                if (costVal !== '') r.cost_price = parseFloat(costVal) || 0;
                if (priceVal !== '') r.selling_price = parseFloat(priceVal) || 0;
                if (qtyVal !== '') {
                    const q = Math.max(0, parseInt(qtyVal, 10) || 0);
                    currentMatrixData.sizes.forEach(sz => {
                        r.quantities[sz] = q;
                    });
                }
            });

            renderMatrixTable();
            recalculateMatrixTotals();
        });
    }

    // Save Stock Matrix
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            if (!currentMatrixData) return;
            const prodId = currentMatrixData.product.id;
            const spinner = document.getElementById('saveMatrixBtnSpinner');
            const btnText = document.getElementById('saveMatrixBtnText');

            saveBtn.disabled = true;
            if (spinner) spinner.style.display = 'inline-block';
            if (btnText) btnText.textContent = 'Saving...';

            const payload = {
                sizes: currentMatrixData.sizes,
                rows: currentMatrixData.rows
            };

            const formData = new FormData();
            formData.append('action', 'save_stock_matrix');
            formData.append('product_id', prodId);
            formData.append('matrix_data', JSON.stringify(payload));
            formData.append('csrf_token', csrfToken);

            fetch('<?= asset('products.php') ?>', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                saveBtn.disabled = false;
                if (spinner) spinner.style.display = 'none';
                if (btnText) btnText.textContent = 'Save Stock Matrix';

                if (!data.success) {
                    alert('Error saving stock matrix: ' + (data.error || 'Unknown error'));
                    return;
                }

                // Update stock badge on the page
                const newStock = parseInt(data.total_stock, 10) || 0;
                const badges = document.querySelectorAll('.open-stock-matrix-badge[data-id="' + prodId + '"]');
                badges.forEach(badge => {
                    badge.setAttribute('data-stock', newStock);
                    if (newStock <= 0) {
                        badge.className = 'badge badge-out-of-stock open-stock-matrix-badge';
                        badge.textContent = 'Out of stock (0)';
                    } else {
                        badge.className = 'badge badge-in-stock open-stock-matrix-badge';
                        badge.textContent = newStock + ' in stock';
                    }
                });

                // Update data-stock on any action buttons
                document.querySelectorAll('[data-id="' + prodId + '"]').forEach(el => {
                    el.setAttribute('data-stock', newStock);
                });

                closeStockMatrixModal();

                // Show notification
                if (typeof showToast === 'function') {
                    showToast('Stock matrix saved! Total stock: ' + newStock + ' units.', 'success');
                } else {
                    const notice = document.createElement('div');
                    notice.style.cssText = 'position:fixed; bottom:24px; right:24px; background:#16a34a; color:#fff; padding:12px 20px; border-radius:8px; font-weight:700; font-size:14px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.2); z-index:9999; animation: matrixFadeIn 0.2s ease;';
                    notice.textContent = 'Stock matrix updated successfully! Total stock: ' + newStock + ' units.';
                    document.body.appendChild(notice);
                    setTimeout(() => { notice.remove(); }, 3500);
                }
            })
            .catch(err => {
                saveBtn.disabled = false;
                if (spinner) spinner.style.display = 'none';
                if (btnText) btnText.textContent = 'Save Stock Matrix';
                alert('Network error saving matrix. Please try again.');
            });
        });
    }

    // Attach click listeners to open matrix
    document.addEventListener('DOMContentLoaded', function() {
        document.body.addEventListener('click', function(e) {
            const trigger = e.target.closest('.open-stock-matrix-badge, .open-stock-matrix-btn');
            if (trigger) {
                e.preventDefault();
                e.stopPropagation();
                const pid = trigger.getAttribute('data-id') || trigger.getAttribute('data-product-id');
                if (pid) {
                    openStockMatrixModal(parseInt(pid, 10));
                }
            }
        });
    });
})();
</script>
