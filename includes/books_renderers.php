<?php
// Dedicated Renderers for Ledgers & Books

function render_tab_customer_ledger(int $bizId, string $dFrom, string $dTo): void {
    $db = get_db();
    $customers = $db->query("SELECT id, name, phone, COALESCE(outstanding_receivable, outstanding_balance, 0.00) AS bal FROM customers WHERE business_id = {$bizId} ORDER BY name ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $selectedCid = !empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : (!empty($customers[0]['id']) ? (int)$customers[0]['id'] : 0);
    $ledger = $selectedCid > 0 ? get_customer_ledger($selectedCid, $dFrom, $dTo, $bizId) : null;
    ?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <label style="font-weight:700;">Select Customer:</label>
            <select class="form-control" style="width:280px;" onchange="location.href='<?= asset('books.php?tab=customer-ledger&customer_id=') ?>'+this.value">
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selectedCid === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?> (<?= e($c['phone'] ?: 'No Phone') ?>) - Bal: ₹<?= number_format((float)$c['bal'], 2) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if ($ledger && !empty($ledger['customer'])): ?>
        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 20px;">
            <div class="kpi-card">
                <div class="kpi-title">Customer Name</div>
                <div class="kpi-value" style="font-size:18px;"><?= e($ledger['customer']['name']) ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Contact Phone</div>
                <div class="kpi-value" style="font-size:16px;"><?= e($ledger['customer']['phone'] ?: '-') ?></div>
            </div>
            <div class="kpi-card" style="border-left:4px solid <?= (float)$ledger['closing_balance'] > 0 ? '#b91c1c' : '#15803d' ?>;">
                <div class="kpi-title">Outstanding Receivable</div>
                <div class="kpi-value" style="color:<?= (float)$ledger['closing_balance'] > 0 ? '#b91c1c' : '#15803d' ?>;">
                    ₹<?= number_format((float)$ledger['closing_balance'], 2) ?>
                </div>
            </div>
        </div>

        <div class="section-card" style="padding:0; overflow:hidden;">
            <table class="books-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th>Branch</th>
                        <th class="num">Invoice Debit (+)</th>
                        <th class="num">Payment Credit (-)</th>
                        <th class="num">Running Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ledger['entries'])): ?>
                        <tr><td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">No transactions for this customer.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ledger['entries'] as $en): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime((string)$en['tx_date'])) ?></td>
                                <td><span style="font-family:monospace; font-weight:700;"><?= e($en['reference']) ?></span></td>
                                <td><?= e($en['description']) ?></td>
                                <td><?= e($en['branch_name'] ?? 'Main Store') ?></td>
                                <td class="num" style="color:#b91c1c;">
                                    <?= (float)$en['debit'] > 0 ? ('₹' . number_format((float)$en['debit'], 2)) : '-' ?>
                                </td>
                                <td class="num" style="color:#15803d;">
                                    <?= (float)$en['credit'] > 0 ? ('₹' . number_format((float)$en['credit'], 2)) : '-' ?>
                                </td>
                                <td class="num" style="font-weight:700;">₹<?= number_format((float)$en['balance'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="section-card" style="padding:40px; text-align:center; color:#94a3b8;">No customer selected or found.</div>
    <?php endif; ?>
<?php
}

function render_tab_supplier_ledger(int $bizId, string $dFrom, string $dTo): void {
    $db = get_db();
    $vendors = $db->query("SELECT id, name, phone, gstin, outstanding_balance FROM vendors WHERE business_id = {$bizId} ORDER BY name ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $selectedVid = !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : (!empty($vendors[0]['id']) ? (int)$vendors[0]['id'] : 0);
    $ledger = $selectedVid > 0 ? get_supplier_ledger($selectedVid, $dFrom, $dTo, $bizId) : null;
    ?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <label style="font-weight:700;">Select Supplier:</label>
            <select class="form-control" style="width:280px;" onchange="location.href='<?= asset('books.php?tab=supplier-ledger&vendor_id=') ?>'+this.value">
                <?php foreach ($vendors as $v): ?>
                    <option value="<?= $v['id'] ?>" <?= $selectedVid === (int)$v['id'] ? 'selected' : '' ?>>
                        <?= e($v['name']) ?> (<?= e($v['gstin'] ?: 'No GSTIN') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if ($ledger && !empty($ledger['vendor'])): ?>
        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 20px;">
            <div class="kpi-card">
                <div class="kpi-title">Supplier Name</div>
                <div class="kpi-value" style="font-size:18px;"><?= e($ledger['vendor']['name']) ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">GSTIN</div>
                <div class="kpi-value" style="font-size:15px; font-family:monospace;"><?= e($ledger['vendor']['gstin'] ?: '-') ?></div>
            </div>
            <div class="kpi-card" style="border-left:4px solid <?= (float)$ledger['closing_balance'] > 0 ? '#b91c1c' : '#15803d' ?>;">
                <div class="kpi-title">Balance Payable (AP)</div>
                <div class="kpi-value" style="color:<?= (float)$ledger['closing_balance'] > 0 ? '#b91c1c' : '#15803d' ?>;">
                    ₹<?= number_format((float)$ledger['closing_balance'], 2) ?>
                </div>
            </div>
        </div>

        <div class="section-card" style="padding:0; overflow:hidden;">
            <table class="books-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th>Branch</th>
                        <th class="num">Bill Credit (+)</th>
                        <th class="num">Payment/Return Debit (-)</th>
                        <th class="num">Balance Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ledger['entries'])): ?>
                        <tr><td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">No transactions for this supplier.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ledger['entries'] as $en): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime((string)$en['tx_date'])) ?></td>
                                <td><span style="font-family:monospace; font-weight:700;"><?= e($en['reference']) ?></span></td>
                                <td><?= e($en['description']) ?></td>
                                <td><?= e($en['branch_name'] ?? 'Head Office') ?></td>
                                <td class="num" style="color:#b91c1c;">
                                    <?= (float)$en['credit'] > 0 ? ('₹' . number_format((float)$en['credit'], 2)) : '-' ?>
                                </td>
                                <td class="num" style="color:#15803d;">
                                    <?= (float)$en['debit'] > 0 ? ('₹' . number_format((float)$en['debit'], 2)) : '-' ?>
                                </td>
                                <td class="num" style="font-weight:700;">₹<?= number_format((float)$en['balance'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php
}

function render_tab_sales_returns(int $bizId, string $dFrom, string $dTo): void {
    $returns = get_sales_return_book($dFrom, $dTo, $bizId);
    ?>
    <div class="section-card" style="padding:0; overflow:hidden;">
        <table class="books-table">
            <thead>
                <tr>
                    <th>Return #</th>
                    <th>Date</th>
                    <th>Original Order</th>
                    <th>Customer</th>
                    <th>Branch</th>
                    <th>Reason</th>
                    <th>Refund Method</th>
                    <th class="num">Refund Amount (₹)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($returns)): ?>
                    <tr><td colspan="9" style="text-align:center; padding:40px; color:#94a3b8;">No sales returns recorded for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($returns as $ret): ?>
                        <tr>
                            <td><span style="font-weight:700; font-family:monospace; color:#2563eb;"><?= e($ret['return_number']) ?></span></td>
                            <td><?= date('d M Y', strtotime((string)$ret['return_date'])) ?></td>
                            <td><span style="font-weight:700;">INV-<?= e($ret['order_number']) ?></span></td>
                            <td><?= e($ret['customer_name']) ?></td>
                            <td><?= e($ret['branch_name'] ?? 'Main Store') ?></td>
                            <td><?= e($ret['reason'] ?: '-') ?></td>
                            <td><span class="books-pill books-pill-blue"><?= strtoupper(e($ret['refund_method'])) ?></span></td>
                            <td class="num" style="font-weight:700; color:#b91c1c;">₹<?= number_format((float)$ret['refund_amount'], 2) ?></td>
                            <td><span class="books-pill books-pill-green"><?= strtoupper(e($ret['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
}

function render_tab_purchase_returns(int $bizId, string $dFrom, string $dTo): void {
    $returns = get_purchase_return_book($dFrom, $dTo, $bizId);
    ?>
    <div class="section-card" style="padding:0; overflow:hidden;">
        <table class="books-table">
            <thead>
                <tr>
                    <th>Return #</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>GSTIN</th>
                    <th>Reason</th>
                    <th>Credit Method</th>
                    <th class="num">Total Amount (₹)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($returns)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:40px; color:#94a3b8;">No purchase returns recorded for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($returns as $pret): ?>
                        <tr>
                            <td><span style="font-weight:700; font-family:monospace;"><?= e($pret['return_number']) ?></span></td>
                            <td><?= date('d M Y', strtotime((string)$pret['return_date'])) ?></td>
                            <td><?= e($pret['vendor_name']) ?></td>
                            <td><span style="font-family:monospace; font-size:11px;"><?= e($pret['vendor_gstin'] ?: '-') ?></span></td>
                            <td><?= e($pret['reason'] ?: '-') ?></td>
                            <td><span class="books-pill books-pill-blue"><?= strtoupper(e($pret['refund_method'])) ?></span></td>
                            <td class="num" style="font-weight:700; color:#15803d;">₹<?= number_format((float)$pret['total_amount'], 2) ?></td>
                            <td><span class="books-pill books-pill-green"><?= strtoupper(e($pret['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
}

function render_tab_general_ledger(int $bizId): void {
    $db = get_db();
    $accounts = $db->query("SELECT * FROM chart_of_accounts WHERE business_id = {$bizId} ORDER BY account_code ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    ?>
    <div class="section-card" style="padding:0; overflow:hidden;">
        <table class="books-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account Name</th>
                    <th>Category</th>
                    <th>Sub-Type</th>
                    <th class="num">Opening (₹)</th>
                    <th class="num">Current Balance (₹)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td><span style="font-family:monospace; font-weight:700; color:#2563eb;"><?= e($a['account_code']) ?></span></td>
                        <td style="font-weight:600;"><?= e($a['account_name']) ?></td>
                        <td><span class="books-pill books-pill-blue"><?= strtoupper(e($a['account_type'])) ?></span></td>
                        <td><span style="font-size:12px; color:#64748b;"><?= e($a['sub_type']) ?></span></td>
                        <td class="num">₹<?= number_format((float)$a['opening_balance'], 2) ?></td>
                        <td class="num" style="font-weight:700; color:#0f172a;">₹<?= number_format((float)$a['current_balance'], 2) ?></td>
                        <td><span class="books-pill books-pill-green"><?= strtoupper(e($a['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
}