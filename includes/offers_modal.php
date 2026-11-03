<?php
/**
 * Promotions & Offers Management Modal Component
 * Matches SRS & Design Layout for Ash Collective Retail POS
 */

declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
$offersCsrfToken = csrf_token();
?>
<div id="promotionsOffersModal" class="pos-offers-modal-backdrop" style="display: none;">
    <div class="pos-offers-modal-card" role="dialog" aria-modal="true" aria-labelledby="offersModalTitle">
        <!-- Modal Header -->
        <div class="pos-offers-modal-header">
            <div style="display: flex; align-items: center; gap: 14px;">
                <h3 class="pos-offers-modal-title" id="offersModalTitle">Manage Offers</h3>
                <div class="pos-offers-tabs">
                    <button type="button" class="pos-offers-tab active" id="tabCreateOfferBtn">+ Create Offer</button>
                    <button type="button" class="pos-offers-tab" id="tabViewOffersBtn">Active Offers (<span id="offersActiveBadgeCount">0</span>)</button>
                </div>
            </div>
            <button type="button" class="pos-offers-close-btn" id="closeOffersModalBtn" aria-label="Close modal">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- CREATE OFFER VIEW -->
        <div id="offersCreateView" class="pos-offers-modal-body">
            <!-- Top Form Fields (Offer Name, Percentage, Start Date, End Date) -->
            <div class="pos-offers-form-grid">
                <div class="pos-offers-col">
                    <div class="pos-offers-field">
                        <label for="offerInputName">Offer Name</label>
                        <input type="text" id="offerInputName" placeholder="Enter Offer Name" autocomplete="off">
                    </div>
                    <div class="pos-offers-field">
                        <label for="offerInputPercent">Offer Percentage</label>
                        <div class="pos-offers-input-group">
                            <input type="number" id="offerInputPercent" placeholder="Enter Offer Percentage" min="1" max="100" step="0.5">
                            <span class="pos-offers-input-addon">%</span>
                        </div>
                    </div>
                </div>

                <div class="pos-offers-col">
                    <div class="pos-offers-field">
                        <label for="offerInputStartDate">Start Date</label>
                        <div class="pos-offers-date-wrap" id="offerStartDateWrap">
                            <input type="date" id="offerInputStartDate" class="pos-offers-date-input" value="<?= date('Y-m-d') ?>">
                            <span class="pos-offers-date-display" id="offerStartDateDisplay"><?= date('d M Y') ?></span>
                            <svg class="pos-offers-cal-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="pos-offers-field">
                        <label for="offerInputEndDate">End Date</label>
                        <div class="pos-offers-date-wrap" id="offerEndDateWrap">
                            <input type="date" id="offerInputEndDate" class="pos-offers-date-input" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                            <span class="pos-offers-date-display" id="offerEndDateDisplay"><?= date('d M Y', strtotime('+30 days')) ?></span>
                            <svg class="pos-offers-cal-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Custom Calendar Picker Dropdown (Matches Screenshot) -->
            <div id="posOffersCalendarPopup" class="pos-offers-custom-calendar" style="display: none;">
                <div class="pos-cal-header-selected" id="posCalHeaderDate"><?= date('d M Y') ?></div>
                <div class="pos-cal-nav-row">
                    <div class="pos-cal-nav-group">
                        <button type="button" class="pos-cal-nav-btn" id="posCalPrevMonth" aria-label="Previous Month">&lt;</button>
                        <span id="posCalMonthLabel" class="pos-cal-label"><?= date('F') ?></span>
                        <button type="button" class="pos-cal-nav-btn" id="posCalNextMonth" aria-label="Next Month">&gt;</button>
                    </div>
                    <div class="pos-cal-nav-group">
                        <button type="button" class="pos-cal-nav-btn" id="posCalPrevYear" aria-label="Previous Year">&lt;</button>
                        <span id="posCalYearLabel" class="pos-cal-label"><?= date('Y') ?></span>
                        <button type="button" class="pos-cal-nav-btn" id="posCalNextYear" aria-label="Next Year">&gt;</button>
                    </div>
                </div>
                <div class="pos-cal-weekdays">
                    <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                </div>
                <div class="pos-cal-days-grid" id="posCalDaysGrid"></div>
                <div class="pos-cal-footer">
                    <button type="button" class="pos-cal-btn-cancel" id="posCalCancelBtn">CANCEL</button>
                    <button type="button" class="pos-cal-btn-ok" id="posCalOkBtn">OK</button>
                </div>
            </div>

            <!-- Items Selection Bar -->
            <div class="pos-offers-items-header">
                <div class="pos-offers-items-heading">
                    Select items you want to apply offer for
                    <span id="offerSelectedCountPill" class="pos-offers-selected-pill" style="display: none;">0 selected</span>
                </div>
                <div class="pos-offers-items-tools">
                    <div class="pos-offers-search-wrap">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #94a3b8;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text" id="offerSearchItemsInput" placeholder="Search any item">
                    </div>
                    <select id="offerFilterCategorySelect" class="pos-offers-cat-select">
                        <option value="all">Search Categories</option>
                    </select>
                </div>
            </div>

            <!-- Products Table -->
            <div class="pos-offers-table-wrap">
                <table class="pos-offers-table">
                    <thead>
                        <tr>
                            <th style="width: 44px; text-align: center;">
                                <input type="checkbox" id="offerMasterCheck" title="Select all items">
                            </th>
                            <th>Item Name <span style="font-size: 10px; color: #94a3b8;">↕</span></th>
                            <th>Item Code</th>
                            <th>Stock QTY <span style="font-size: 10px; color: #94a3b8;">↕</span></th>
                            <th>MRP</th>
                            <th>Selling Price</th>
                            <th>Discounted Price after tax</th>
                        </tr>
                    </thead>
                    <tbody id="offerProductsTableBody">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 36px; color: #94a3b8;">
                                Loading items...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Bottom Rule Banner & Action Buttons -->
            <div class="pos-offers-footer">
                <div class="pos-offers-rule-note">
                    <div class="pos-offers-rule-title">One item, one active offer</div>
                    <div class="pos-offers-rule-desc">An item can have only one active offer at a time. New offers automatically replace older ones.</div>
                </div>
                <div class="pos-offers-actions">
                    <button type="button" class="pos-offers-btn-cancel" id="cancelOfferBtn">Cancel</button>
                    <button type="button" class="pos-offers-btn-save" id="saveOfferBtn">Save</button>
                </div>
            </div>
        </div>

        <!-- ACTIVE OFFERS LIST VIEW -->
        <div id="offersListView" class="pos-offers-modal-body" style="display: none;">
            <div style="padding: 18px 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">Active Product Offers</h4>
                        <p style="margin: 2px 0 0; font-size: 12px; color: #64748b;">Manage running promotional discounts applied to specific items.</p>
                    </div>
                    <button type="button" class="pos-offers-btn-save" id="switchBackToCreateBtn" style="padding: 6px 14px; font-size: 12px;">+ Create Another Offer</button>
                </div>

                <div class="pos-offers-table-wrap" style="max-height: 400px;">
                    <table class="pos-offers-table">
                        <thead>
                            <tr>
                                <th>Offer Name</th>
                                <th>Discount</th>
                                <th>Items Attached</th>
                                <th>Validity Period</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="activeOffersTableBody">
                            <tr><td colspan="6" style="text-align: center; padding: 28px; color: #94a3b8;">No offers found.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.pos-offers-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}

.pos-offers-modal-card {
    background: #ffffff;
    width: 100%;
    max-width: 900px;
    max-height: 92vh;
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    font-family: inherit;
    animation: posOffersFadeIn 0.18s ease-out;
}

@keyframes posOffersFadeIn {
    from { opacity: 0; transform: translateY(-8px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.pos-offers-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 24px;
    border-bottom: 1px solid #f1f5f9;
}

.pos-offers-modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
}

.pos-offers-tabs {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
}

.pos-offers-tab {
    border: none;
    background: transparent;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.pos-offers-tab.active {
    background: #ffffff;
    color: #4f46e5;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.pos-offers-close-btn {
    border: none;
    background: transparent;
    color: #94a3b8;
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: color 0.15s;
}
.pos-offers-close-btn:hover {
    color: #1e293b;
    background: #f8fafc;
}

.pos-offers-modal-body {
    padding: 20px 24px 16px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.pos-offers-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

.pos-offers-col {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.pos-offers-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.pos-offers-field label {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
}

.pos-offers-field input[type="text"] {
    width: 100%;
    padding: 8px 12px;
    font-size: 13px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    outline: none;
    box-sizing: border-box;
    transition: border-color 0.15s;
}
.pos-offers-field input[type="text"]:focus {
    border-color: #6366f1;
}

.pos-offers-input-group {
    display: flex;
    width: 100%;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
    transition: border-color 0.15s;
    box-sizing: border-box;
}
.pos-offers-input-group:focus-within {
    border-color: #6366f1;
}
.pos-offers-input-group input {
    flex: 1;
    border: none;
    padding: 8px 12px;
    font-size: 13px;
    outline: none;
    width: 100%;
}
.pos-offers-input-addon {
    background: #f8fafc;
    border-left: 1px solid #e2e8f0;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    display: flex;
    align-items: center;
}

.pos-offers-date-wrap {
    position: relative;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    background: #ffffff;
    box-sizing: border-box;
}
.pos-offers-date-wrap:focus-within {
    border-color: #6366f1;
}
.pos-offers-date-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
}
.pos-offers-date-display {
    font-size: 13px;
    color: #1e293b;
    font-weight: 500;
}
.pos-offers-cal-icon {
    color: #64748b;
    pointer-events: none;
}

.pos-offers-items-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 4px;
    flex-wrap: wrap;
}

.pos-offers-items-heading {
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pos-offers-selected-pill {
    background: #e0e7ff;
    color: #4338ca;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 9999px;
}

.pos-offers-items-tools {
    display: flex;
    align-items: center;
    gap: 10px;
}

.pos-offers-search-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 10px;
    background: #ffffff;
    width: 180px;
}
.pos-offers-search-wrap input {
    border: none;
    outline: none;
    font-size: 12px;
    width: 100%;
}

.pos-offers-cat-select {
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 12px;
    color: #334155;
    background: #ffffff;
    outline: none;
    cursor: pointer;
}

.pos-offers-table-wrap {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow-y: auto;
    max-height: 280px;
    background: #ffffff;
}

.pos-offers-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    text-align: left;
}

.pos-offers-table thead th {
    position: sticky;
    top: 0;
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    z-index: 2;
    white-space: nowrap;
}

.pos-offers-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.12s;
}
.pos-offers-table tbody tr:hover {
    background: #f8fafc;
}
.pos-offers-table tbody tr.row-selected {
    background: #f5f3ff;
}

.pos-offers-table tbody td {
    padding: 10px 12px;
    color: #334155;
    vertical-align: middle;
}

.pos-offers-item-name {
    font-weight: 600;
    color: #0f172a;
    font-size: 13px;
    line-height: 1.25;
}

.pos-offers-cat-badge {
    display: inline-block;
    background: #f1f5f9;
    color: #475569;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    margin-top: 3px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.pos-offers-code {
    color: #64748b;
    font-family: inherit;
    font-size: 12px;
}

.pos-offers-stock {
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
}

.pos-offers-mrp, .pos-offers-price {
    font-weight: 500;
    color: #334155;
    white-space: nowrap;
}

.pos-offers-discounted {
    font-weight: 700;
    color: #16a34a;
    white-space: nowrap;
}

.pos-offers-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 24px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
    border-bottom-left-radius: 12px;
    border-bottom-right-radius: 12px;
}

.pos-offers-rule-note {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.pos-offers-rule-title {
    font-size: 12px;
    font-weight: 700;
    color: #1e293b;
}

.pos-offers-rule-desc {
    font-size: 11px;
    color: #64748b;
}

.pos-offers-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.pos-offers-btn-cancel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #475569;
    padding: 8px 18px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
}
.pos-offers-btn-cancel:hover {
    background: #f1f5f9;
    color: #1e293b;
}

.pos-offers-btn-save {
    background: #4f46e5;
    border: 1px solid #4f46e5;
    color: #ffffff;
    padding: 8px 22px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
}
.pos-offers-btn-save:hover {
    background: #4338ca;
}
.pos-offers-btn-save:disabled {
    background: #a5b4fc;
    border-color: #a5b4fc;
    cursor: not-allowed;
}

/* Custom Calendar Picker Popup (Matches screenshot 04e4bf7c) */
.pos-offers-custom-calendar {
    position: absolute;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.25), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    width: 280px;
    padding: 14px;
    z-index: 100000;
    box-sizing: border-box;
    font-family: inherit;
}
.pos-cal-header-selected {
    text-align: center;
    font-size: 14px;
    font-weight: 700;
    color: #4f46e5;
    margin-bottom: 12px;
}
.pos-cal-nav-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.pos-cal-nav-group {
    display: flex;
    align-items: center;
    gap: 4px;
}
.pos-cal-nav-btn {
    border: 1px solid #e2e8f0;
    background: #ffffff;
    border-radius: 4px;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    cursor: pointer;
    color: #475569;
}
.pos-cal-nav-btn:hover {
    background: #f1f5f9;
}
.pos-cal-label {
    font-size: 12px;
    font-weight: 600;
    color: #1e293b;
    min-width: 65px;
    text-align: center;
}
.pos-cal-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    text-align: center;
    font-size: 11px;
    color: #94a3b8;
    margin-bottom: 6px;
    font-weight: 600;
}
.pos-cal-days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    margin-bottom: 12px;
}
.pos-cal-day-cell {
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    border-radius: 6px;
    cursor: pointer;
    color: #334155;
    transition: background 0.12s;
}
.pos-cal-day-cell:hover {
    background: #eef2ff;
}
.pos-cal-day-cell.other-month {
    color: #cbd5e1;
}
.pos-cal-day-cell.selected {
    background: #4f46e5;
    color: #ffffff;
    font-weight: 700;
}
.pos-cal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding-top: 8px;
    border-top: 1px solid #f1f5f9;
}
.pos-cal-btn-cancel, .pos-cal-btn-ok {
    border: none;
    background: transparent;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
}
.pos-cal-btn-cancel {
    color: #64748b;
}
.pos-cal-btn-cancel:hover {
    color: #1e293b;
}
.pos-cal-btn-ok {
    color: #4f46e5;
}
.pos-cal-btn-ok:hover {
    color: #4338ca;
}

/* Header Offers Button */
.pos-offers-header-btn {
    position: relative;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    height: 38px;
    padding: 0 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 13px;
    font-weight: 600;
    color: #4338ca;
}
.pos-offers-header-btn:hover {
    background: #eef2ff;
    border-color: #c7d2fe;
}
</style>

<script>
(function () {
    const modal = document.getElementById('promotionsOffersModal');
    const openBtn = document.getElementById('openHeaderOffersBtn');
    const closeBtn = document.getElementById('closeOffersModalBtn');
    const cancelBtn = document.getElementById('cancelOfferBtn');
    const saveBtn = document.getElementById('saveOfferBtn');
    const csrfToken = <?= json_encode($offersCsrfToken) ?>;

    const tabCreate = document.getElementById('tabCreateOfferBtn');
    const tabView = document.getElementById('tabViewOffersBtn');
    const createView = document.getElementById('offersCreateView');
    const listView = document.getElementById('offersListView');
    const switchBackBtn = document.getElementById('switchBackToCreateBtn');

    const inputName = document.getElementById('offerInputName');
    const inputPercent = document.getElementById('offerInputPercent');
    const inputStartDate = document.getElementById('offerInputStartDate');
    const inputEndDate = document.getElementById('offerInputEndDate');
    const startDisplay = document.getElementById('offerStartDateDisplay');
    const endDisplay = document.getElementById('offerEndDateDisplay');

    const searchInput = document.getElementById('offerSearchItemsInput');
    const catSelect = document.getElementById('offerFilterCategorySelect');
    const masterCheck = document.getElementById('offerMasterCheck');
    const tableBody = document.getElementById('offerProductsTableBody');
    const activeTableBody = document.getElementById('activeOffersTableBody');
    const badgeCount = document.getElementById('offersActiveBadgeCount');
    const selectedCountPill = document.getElementById('offerSelectedCountPill');

    let allItems = [];
    let selectedItemIds = new Set();
    let isSaving = false;

    // Open & Close
    if (openBtn) {
        openBtn.addEventListener('click', function () {
            openModal();
        });
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    function showOfferToast(msg, isError) {
        let toast = document.getElementById('posOffersToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'posOffersToast';
            toast.style.cssText = 'position: fixed; top: 24px; right: 24px; z-index: 1000000; padding: 12px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; box-shadow: 0 10px 20px -3px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 8px; transition: all 0.25s ease; opacity: 0; transform: translateY(-10px); pointer-events: none;';
            document.body.appendChild(toast);
        }
        toast.style.background = isError ? '#fee2e2' : '#dcfce7';
        toast.style.color = isError ? '#b91c1c' : '#15803d';
        toast.style.border = isError ? '1px solid #f87171' : '1px solid #86efac';
        toast.innerHTML = (isError ? '⚠️ ' : '✓ ') + escapeHtml(msg);
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
        }, 3200);
    }

    function openModal() {
        if (!modal) return;
        modal.style.display = 'flex';
        switchTab('create');
        loadEligibleItems();
        loadActiveOffers();
    }

    function closeModal() {
        if (!modal) return;
        modal.style.setProperty('display', 'none', 'important');
        if (calPopup) calPopup.style.setProperty('display', 'none', 'important');
    }

    // Tabs
    if (tabCreate) tabCreate.addEventListener('click', () => switchTab('create'));
    if (tabView) tabView.addEventListener('click', () => switchTab('list'));
    if (switchBackBtn) switchBackBtn.addEventListener('click', () => switchTab('create'));

    function switchTab(tab) {
        if (tab === 'create') {
            if (createView) createView.style.display = 'flex';
            if (listView) listView.style.display = 'none';
            if (tabCreate) tabCreate.classList.add('active');
            if (tabView) tabView.classList.remove('active');
        } else {
            if (createView) createView.style.display = 'none';
            if (listView) listView.style.display = 'block';
            if (tabCreate) tabCreate.classList.remove('active');
            if (tabView) tabView.classList.add('active');
            loadActiveOffers();
        }
    }

    // Date formatting helper
    function formatDateDisplay(ymd) {
        if (!ymd) return '';
        try {
            const parts = ymd.split('-');
            if (parts.length === 3) {
                const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            }
        } catch (e) {}
        return ymd;
    }

    if (inputStartDate) {
        inputStartDate.addEventListener('change', function () {
            if (startDisplay) startDisplay.textContent = formatDateDisplay(this.value);
        });
    }

    if (inputEndDate) {
        inputEndDate.addEventListener('change', function () {
            if (endDisplay) endDisplay.textContent = formatDateDisplay(this.value);
        });
    }

    // Custom Calendar Popup Controller (Matches screenshot 04e4bf7c)
    const calPopup = document.getElementById('posOffersCalendarPopup');
    const startWrap = document.getElementById('offerStartDateWrap');
    const endWrap = document.getElementById('offerEndDateWrap');
    const calHeaderDate = document.getElementById('posCalHeaderDate');
    const calMonthLabel = document.getElementById('posCalMonthLabel');
    const calYearLabel = document.getElementById('posCalYearLabel');
    const calDaysGrid = document.getElementById('posCalDaysGrid');
    const calPrevMonth = document.getElementById('posCalPrevMonth');
    const calNextMonth = document.getElementById('posCalNextMonth');
    const calPrevYear = document.getElementById('posCalPrevYear');
    const calNextYear = document.getElementById('posCalNextYear');
    const calCancelBtn = document.getElementById('posCalCancelBtn');
    const calOkBtn = document.getElementById('posCalOkBtn');

    let currentCalTargetInput = null;
    let currentCalTargetDisplay = null;
    let calViewDate = new Date();
    let calSelectedYmd = '';

    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

    function openCalendar(targetInput, targetDisplay, wrapEl) {
        if (!calPopup) return;
        currentCalTargetInput = targetInput;
        currentCalTargetDisplay = targetDisplay;

        const val = targetInput ? targetInput.value : '';
        if (val) {
            const parts = val.split('-');
            calViewDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            calSelectedYmd = val;
        } else {
            calViewDate = new Date();
            calSelectedYmd = calViewDate.toISOString().slice(0, 10);
        }

        renderCalendar();

        const rect = wrapEl.getBoundingClientRect();
        calPopup.style.display = 'block';
        calPopup.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        calPopup.style.left = Math.max(10, Math.min(window.innerWidth - 300, rect.left + window.scrollX)) + 'px';
    }

    function closeCalendar() {
        if (calPopup) calPopup.style.display = 'none';
        currentCalTargetInput = null;
        currentCalTargetDisplay = null;
    }

    if (startWrap) {
        startWrap.addEventListener('click', function (e) {
            e.stopPropagation();
            openCalendar(inputStartDate, startDisplay, startWrap);
        });
    }

    if (endWrap) {
        endWrap.addEventListener('click', function (e) {
            e.stopPropagation();
            openCalendar(inputEndDate, endDisplay, endWrap);
        });
    }

    if (calCancelBtn) calCancelBtn.addEventListener('click', closeCalendar);
    if (calOkBtn) {
        calOkBtn.addEventListener('click', function () {
            if (currentCalTargetInput && calSelectedYmd) {
                currentCalTargetInput.value = calSelectedYmd;
                if (currentCalTargetDisplay) currentCalTargetDisplay.textContent = formatDateDisplay(calSelectedYmd);
                currentCalTargetInput.dispatchEvent(new Event('change'));
            }
            closeCalendar();
        });
    }

    if (calPrevMonth) {
        calPrevMonth.addEventListener('click', function (e) {
            e.stopPropagation();
            calViewDate.setMonth(calViewDate.getMonth() - 1);
            renderCalendar();
        });
    }
    if (calNextMonth) {
        calNextMonth.addEventListener('click', function (e) {
            e.stopPropagation();
            calViewDate.setMonth(calViewDate.getMonth() + 1);
            renderCalendar();
        });
    }
    if (calPrevYear) {
        calPrevYear.addEventListener('click', function (e) {
            e.stopPropagation();
            calViewDate.setFullYear(calViewDate.getFullYear() - 1);
            renderCalendar();
        });
    }
    if (calNextYear) {
        calNextYear.addEventListener('click', function (e) {
            e.stopPropagation();
            calViewDate.setFullYear(calViewDate.getFullYear() + 1);
            renderCalendar();
        });
    }

    function renderCalendar() {
        const year = calViewDate.getFullYear();
        const month = calViewDate.getMonth();
        if (calMonthLabel) calMonthLabel.textContent = monthNames[month];
        if (calYearLabel) calYearLabel.textContent = String(year);
        if (calHeaderDate) calHeaderDate.textContent = formatDateDisplay(calSelectedYmd);

        if (!calDaysGrid) return;
        calDaysGrid.innerHTML = '';

        const firstDayIdx = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        for (let i = firstDayIdx - 1; i >= 0; i--) {
            const d = daysInPrevMonth - i;
            const cell = document.createElement('div');
            cell.className = 'pos-cal-day-cell other-month';
            cell.textContent = String(d);
            calDaysGrid.appendChild(cell);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const ymd = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            const cell = document.createElement('div');
            cell.className = 'pos-cal-day-cell' + (ymd === calSelectedYmd ? ' selected' : '');
            cell.textContent = String(d);
            cell.addEventListener('click', function (e) {
                e.stopPropagation();
                calSelectedYmd = ymd;
                if (calHeaderDate) calHeaderDate.textContent = formatDateDisplay(ymd);
                calDaysGrid.querySelectorAll('.pos-cal-day-cell').forEach(c => c.classList.remove('selected'));
                cell.classList.add('selected');
            });
            cell.addEventListener('dblclick', function (e) {
                e.stopPropagation();
                calSelectedYmd = ymd;
                if (currentCalTargetInput) {
                    currentCalTargetInput.value = calSelectedYmd;
                    if (currentCalTargetDisplay) currentCalTargetDisplay.textContent = formatDateDisplay(calSelectedYmd);
                    currentCalTargetInput.dispatchEvent(new Event('change'));
                }
                closeCalendar();
            });
            calDaysGrid.appendChild(cell);
        }

        const totalRendered = firstDayIdx + daysInMonth;
        const remaining = (7 - (totalRendered % 7)) % 7;
        for (let d = 1; d <= remaining; d++) {
            const cell = document.createElement('div');
            cell.className = 'pos-cal-day-cell other-month';
            cell.textContent = String(d);
            calDaysGrid.appendChild(cell);
        }
    }

    document.addEventListener('click', function (e) {
        if (calPopup && calPopup.style.display !== 'none') {
            if (!calPopup.contains(e.target) && (!startWrap || !startWrap.contains(e.target)) && (!endWrap || !endWrap.contains(e.target))) {
                closeCalendar();
            }
        }
    });

    // Fetch items from server
    function loadEligibleItems() {
        const url = 'ajax_offers.php?action=get_items';
        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    allItems = data.items || [];
                    populateCategories(data.categories || []);
                    renderTable();
                } else {
                    tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #ef4444; padding: 24px;">Failed to load items.</td></tr>';
                }
            })
            .catch(() => {
                tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #ef4444; padding: 24px;">Network error loading items.</td></tr>';
            });
    }

    function populateCategories(cats) {
        if (!catSelect) return;
        catSelect.innerHTML = '<option value="all">Search Categories</option>';
        cats.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.name;
            opt.textContent = c.name;
            catSelect.appendChild(opt);
        });
    }

    function getFilteredItems() {
        const q = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const cat = catSelect ? catSelect.value : 'all';

        return allItems.filter(item => {
            const matchesCat = (cat === 'all' || item.category_name === cat);
            const matchesQ = !q ||
                (item.name && item.name.toLowerCase().includes(q)) ||
                (item.item_code && item.item_code.toLowerCase().includes(q)) ||
                (item.category_name && item.category_name.toLowerCase().includes(q));
            return matchesCat && matchesQ;
        });
    }

    // Live calculation of discounted price
    function getDiscountPercent() {
        const v = parseFloat(inputPercent ? inputPercent.value : 0);
        return isNaN(v) ? 0 : Math.max(0, Math.min(100, v));
    }

    function renderTable() {
        if (!tableBody) return;
        const filtered = getFilteredItems();
        const discPct = getDiscountPercent();

        if (filtered.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #94a3b8; padding: 28px;">No matching items found.</td></tr>';
            updateSelectedPill();
            return;
        }

        let html = '';
        filtered.forEach(item => {
            const isChecked = selectedItemIds.has(item.id);
            let discPriceText = '-';
            if (discPct > 0) {
                const discounted = item.selling_price * (1 - (discPct / 100));
                discPriceText = '₹ ' + discounted.toFixed(2);
            }

            const currentOfferNotice = item.current_offer ? 
                `<span style="color: #6366f1; font-size: 10px; display: block;">Active: ${escapeHtml(item.current_offer)}</span>` : '';

            html += `
                <tr class="${isChecked ? 'row-selected' : ''}" data-id="${item.id}">
                    <td style="text-align: center;">
                        <input type="checkbox" class="pos-offer-chk" data-id="${item.id}" ${isChecked ? 'checked' : ''}>
                    </td>
                    <td>
                        <div class="pos-offers-item-name">${escapeHtml(item.name)}</div>
                        <span class="pos-offers-cat-badge">${escapeHtml(item.category_name || 'General')}</span>
                        ${currentOfferNotice}
                    </td>
                    <td class="pos-offers-code">${escapeHtml(item.item_code)}</td>
                    <td class="pos-offers-stock">${item.stock_display}</td>
                    <td class="pos-offers-mrp">${item.mrp_formatted}</td>
                    <td class="pos-offers-price">${item.selling_price_formatted}</td>
                    <td class="pos-offers-discounted">${discPriceText}</td>
                </tr>
            `;
        });

        tableBody.innerHTML = html;
        updateSelectedPill();
        syncMasterCheck();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function updateSelectedPill() {
        if (!selectedCountPill) return;
        const count = selectedItemIds.size;
        if (count > 0) {
            selectedCountPill.style.display = 'inline-block';
            selectedCountPill.textContent = count + ' selected';
        } else {
            selectedCountPill.style.display = 'none';
        }
    }

    function syncMasterCheck() {
        if (!masterCheck) return;
        const filtered = getFilteredItems();
        if (filtered.length === 0) {
            masterCheck.checked = false;
            masterCheck.indeterminate = false;
            return;
        }
        let selectedInFiltered = 0;
        filtered.forEach(it => {
            if (selectedItemIds.has(it.id)) selectedInFiltered++;
        });
        if (selectedInFiltered === filtered.length) {
            masterCheck.checked = true;
            masterCheck.indeterminate = false;
        } else if (selectedInFiltered > 0) {
            masterCheck.checked = false;
            masterCheck.indeterminate = true;
        } else {
            masterCheck.checked = false;
            masterCheck.indeterminate = false;
        }
    }

    // Table Checkbox handlers
    if (tableBody) {
        tableBody.addEventListener('change', function (e) {
            if (e.target.classList.contains('pos-offer-chk')) {
                const id = parseInt(e.target.getAttribute('data-id'), 10);
                if (e.target.checked) {
                    selectedItemIds.add(id);
                } else {
                    selectedItemIds.delete(id);
                }
                const tr = e.target.closest('tr');
                if (tr) tr.classList.toggle('row-selected', e.target.checked);
                updateSelectedPill();
                syncMasterCheck();
            }
        });
    }

    if (masterCheck) {
        masterCheck.addEventListener('change', function () {
            const filtered = getFilteredItems();
            const shouldCheck = this.checked;
            filtered.forEach(it => {
                if (shouldCheck) selectedItemIds.add(it.id);
                else selectedItemIds.delete(it.id);
            });
            renderTable();
        });
    }

    // Filter and search live handlers
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            renderTable();
        });
    }

    if (catSelect) {
        catSelect.addEventListener('change', function () {
            renderTable();
        });
    }

    if (inputPercent) {
        inputPercent.addEventListener('input', function () {
            renderTable();
        });
    }

    // Save Offer
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            const name = (inputName ? inputName.value : '').trim();
            const percent = getDiscountPercent();
            const startDate = inputStartDate ? inputStartDate.value : '';
            const endDate = inputEndDate ? inputEndDate.value : '';

            if (!name) {
                alert('Please enter an Offer Name.');
                if (inputName) inputName.focus();
                return;
            }

            if (percent <= 0) {
                alert('Please enter a valid Offer Percentage (e.g. 10%).');
                if (inputPercent) inputPercent.focus();
                return;
            }

            if (selectedItemIds.size === 0) {
                alert('Please select at least one item to apply this offer.');
                return;
            }

            isSaving = true;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            const payload = new FormData();
            payload.append('action', 'save_offer');
            payload.append('csrf_token', csrfToken);
            payload.append('name', name);
            payload.append('discount_percent', String(percent));
            payload.append('start_date', startDate);
            payload.append('end_date', endDate);
            payload.append('product_ids', JSON.stringify(Array.from(selectedItemIds)));

            fetch('ajax_offers.php', { method: 'POST', body: payload })
                .then(res => res.json())
                .then(data => {
                    isSaving = false;
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save';

                    if (data.success) {
                        closeModal();
                        showOfferToast(data.message || 'Offer saved successfully!');
                        // Reset form
                        if (inputName) inputName.value = '';
                        if (inputPercent) inputPercent.value = '';
                        selectedItemIds.clear();
                        renderTable();
                        loadActiveOffers();
                        // If on POS screen, recalculate
                        if (typeof scheduleAutoPromoRecalc === 'function' && typeof summarySubtotalCache !== 'undefined') {
                            scheduleAutoPromoRecalc(summarySubtotalCache);
                        }
                    } else {
                        showOfferToast(data.error || 'Failed to save offer.', true);
                    }
                })
                .catch(() => {
                    isSaving = false;
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save';
                    showOfferToast('Network error while saving offer. Please try again.', true);
                });
        });
    }

    // Load active offers
    function loadActiveOffers() {
        fetch('ajax_offers.php?action=get_offers')
            .then(res => res.json())
            .then(data => {
                if (data.success && activeTableBody) {
                    const list = data.offers || [];
                    if (badgeCount) badgeCount.textContent = String(list.length);
                    const hdrBadge = document.getElementById('hdrOffersBadge');
                    if (hdrBadge) {
                        hdrBadge.textContent = String(list.length);
                        hdrBadge.style.display = list.length > 0 ? 'flex' : 'none';
                    }

                    if (list.length === 0) {
                        activeTableBody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 28px;">No active product offers right now.</td></tr>';
                        return;
                    }

                    let rows = '';
                    list.forEach(o => {
                        const statusColor = o.status === 'active' ? '#16a34a' : '#94a3b8';
                        const dates = (o.start_date ? formatDateDisplay(o.start_date) : '-') + ' to ' + (o.end_date ? formatDateDisplay(o.end_date) : '-');
                        rows += `
                            <tr>
                                <td><strong>${escapeHtml(o.name)}</strong></td>
                                <td><span style="font-weight: 700; color: #4f46e5;">${parseFloat(o.discount_value)}%</span></td>
                                <td>${parseInt(o.items_count, 10)} items</td>
                                <td style="color: #64748b; font-size: 11px;">${dates}</td>
                                <td><span style="display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: ${o.status === 'active' ? '#dcfce7' : '#f1f5f9'}; color: ${statusColor};">${escapeHtml(o.status)}</span></td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn-delete-active-offer" data-id="${o.id}" style="border: none; background: #fee2e2; color: #ef4444; border-radius: 4px; padding: 4px 10px; font-size: 11px; font-weight: 600; cursor: pointer;">Delete</button>
                                </td>
                            </tr>
                        `;
                    });
                    activeTableBody.innerHTML = rows;
                }
            })
            .catch(() => {});
    }

    // Delete active offer handler
    if (activeTableBody) {
        activeTableBody.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-delete-active-offer');
            if (btn) {
                const id = parseInt(btn.getAttribute('data-id'), 10);
                if (confirm('Are you sure you want to delete this promotional offer?')) {
                    const fd = new FormData();
                    fd.append('action', 'delete_offer');
                    fd.append('csrf_token', csrfToken);
                    fd.append('promotion_id', String(id));

                    fetch('ajax_offers.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                loadActiveOffers();
                                loadEligibleItems();
                                if (typeof scheduleAutoPromoRecalc === 'function' && typeof summarySubtotalCache !== 'undefined') {
                                    scheduleAutoPromoRecalc(summarySubtotalCache);
                                }
                            } else {
                                alert(res.error || 'Failed to delete offer.');
                            }
                        })
                        .catch(() => alert('Network error deleting offer.'));
                }
            }
        });
    }

    // Initial fetch of active offers count for badge on page load
    setTimeout(loadActiveOffers, 500);
})();
</script>
