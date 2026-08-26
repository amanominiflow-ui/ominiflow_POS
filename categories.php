<?php
/**
 * OminiFlow POS - Category Management
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/products_db.php';

require_auth();

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));

$flashSuccess = get_flash('success');
$flashError = get_flash('error');

// Handle Category Creation / Edit / Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please try again.');
        redirect(APP_URL . '/categories.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
        $data = [
            'name' => $_POST['name'] ?? '',
            'code' => $_POST['code'] ?? '',
            'description' => $_POST['description'] ?? '',
            'status' => $_POST['status'] ?? 'active',
            'remove_image' => !empty($_POST['remove_image']),
        ];

        $result = save_category($data, $id, null, $_FILES['category_image'] ?? null);
        if ($result['success']) {
            set_flash('success', $id ? 'Category updated successfully!' : 'Category created successfully!');
        } else {
            $msg = implode(' ', $result['errors']);
            set_flash('error', $msg);
        }
        redirect(APP_URL . '/categories.php');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['category_id'] ?? 0);
        $result = delete_category($id);
        if ($result['success']) {
            set_flash('success', 'Category deleted successfully!');
        } else {
            set_flash('error', $result['error'] ?? 'Could not delete category.');
        }
        redirect(APP_URL . '/categories.php');
    }
}

$categories = get_categories($search, $statusFilter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - OminiFlow POS</title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon-16x16.png') ?>">
    <link rel="shortcut icon" href="<?= asset('assets/images/favicon.ico') ?>">

    <link rel="stylesheet" href="<?= asset('assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="app-layout">
        <!-- Sidebar Component -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="app-main">
            <!-- Header Component -->
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <main class="dashboard-content">
                <!-- Page Top Row -->
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title">Category Management</h1>
                        <p class="page-subtitle">Organize and group your POS product catalog efficiently</p>
                    </div>
                    <div class="page-actions">
                        <a href="<?= asset('products.php') ?>" class="btn-secondary">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                            </svg>
                            <span>Back to Products</span>
                        </a>
                        <button type="button" class="header-btn" id="openAddCategoryModal">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Add Category</span>
                        </button>
                    </div>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="saas-alert saas-alert-success">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span><?= e($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="saas-alert saas-alert-danger">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span><?= e($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Tab Navigation between Products and Categories -->
                <div class="tab-row">
                    <a href="<?= asset('products.php') ?>" class="tab-btn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <span>Products Catalog</span>
                    </a>
                    <a href="<?= asset('categories.php') ?>" class="tab-btn active">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <span>Categories (<?= count($categories) ?>)</span>
                    </a>
                    <a href="<?= asset('inventory.php') ?>" class="tab-btn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                        </svg>
                        <span>Inventory & Stock Log</span>
                    </a>
                </div>

                <!-- Filter Bar -->
                <div class="filter-card">
                    <form method="GET" action="<?= asset('categories.php') ?>" class="filter-form">
                        <div class="search-input-wrap">
                            <span class="search-icon">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </span>
                            <input
                                type="text"
                                name="q"
                                value="<?= e($search) ?>"
                                placeholder="Search by category name, code, or description..."
                                class="form-control with-icon"
                            >
                        </div>

                        <select name="status" class="form-control filter-select">
                            <option value="">All Statuses</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>

                        <button type="submit" class="btn-filter-submit">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                            </svg>
                            <span>Filter</span>
                        </button>

                        <?php if ($search !== '' || $statusFilter !== ''): ?>
                            <a href="<?= asset('categories.php') ?>" class="btn-filter-clear">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Categories Table -->
                <div class="section-card">
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead>
                                <tr>
                                    <th>Category Name</th>
                                    <th>Code</th>
                                    <th>Description</th>
                                    <th>Products Count</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categories)): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">🏷️</div>
                                                <div style="font-weight: 700; color: var(--saas-navy-950); margin-bottom: 4px;">No categories found</div>
                                                <div>Create your first category or adjust your search filters.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($categories as $cat): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 12px;">
                                                    <?php if (!empty($cat['image_path'])): ?>
                                                        <img src="<?= asset($cat['image_path']) ?>" alt="" style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover; border: 1px solid #e2e8f0; flex-shrink: 0;">
                                                    <?php else: ?>
                                                        <div style="width: 38px; height: 38px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 15px; color: #64748b; flex-shrink: 0;">🏷️</div>
                                                    <?php endif; ?>
                                                    <div style="font-weight: 700; color: var(--saas-navy-950);"><?= e($cat['name']) ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <code style="background: var(--saas-surface); padding: 2px 6px; border-radius: 4px; font-weight: 600; color: var(--saas-primary);"><?= e($cat['code']) ?></code>
                                            </td>
                                            <td>
                                                <span style="color: var(--saas-slate-500); font-size: 13px;">
                                                    <?= e($cat['description'] ?: '—') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= (int)$cat['product_count'] > 0 ? 'badge-info' : 'badge-secondary' ?>">
                                                    <?= (int) $cat['product_count'] ?> Products
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($cat['status'] === 'active'): ?>
                                                    <span class="badge badge-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color: var(--saas-slate-500); font-size: 12.5px;">
                                                <?= date('M d, Y', strtotime($cat['created_at'])) ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <div class="action-group" style="justify-content: flex-end;">
                                                    <button
                                                        type="button"
                                                        class="btn-action edit edit-category-btn"
                                                        data-id="<?= $cat['id'] ?>"
                                                        data-name="<?= e($cat['name']) ?>"
                                                        data-code="<?= e($cat['code']) ?>"
                                                        data-description="<?= e($cat['description'] ?? '') ?>"
                                                        data-status="<?= e($cat['status']) ?>"
                                                        data-image="<?= e($cat['image_path'] ?? '') ?>"
                                                        data-image-url="<?= !empty($cat['image_path']) ? e(asset($cat['image_path'])) : '' ?>"
                                                        title="Edit Category"
                                                    >
                                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                        </svg>
                                                    </button>

                                                    <form method="POST" action="<?= asset('categories.php') ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete category \'<?= e(addslashes($cat['name'])) ?>\'?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                                        <button type="submit" class="btn-action delete" title="Delete Category">
                                                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Category Modal (Add / Edit) -->
    <div class="modal-overlay" id="categoryModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title" id="categoryModalTitle">Add Category</h3>
                <button type="button" class="modal-close-btn" id="closeCategoryModal">&times;</button>
            </div>
            <form method="POST" action="<?= asset('categories.php') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="category_id" id="modalCategoryId" value="">

                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="modalCategoryName" class="form-label">Category Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="modalCategoryName" name="name" required placeholder="e.g. Beverages, Electronics, Snacks" class="form-control">
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="modalCategoryCode" class="form-label">Category Code</label>
                        <input type="text" id="modalCategoryCode" name="code" placeholder="e.g. BEV, ELEC (Auto-generated if empty)" class="form-control" style="text-transform: uppercase;">
                        <span class="form-hint">Unique short code used in reports and SKU barcodes.</span>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="modalCategoryDescription" class="form-label">Description</label>
                        <textarea id="modalCategoryDescription" name="description" rows="3" placeholder="Optional brief description of this product category..." class="form-control"></textarea>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">Category Image</label>
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <div id="modalImagePreviewBox" style="width: 56px; height: 56px; border-radius: 8px; border: 1.5px dashed #cbd5e1; display: flex; align-items: center; justify-content: center; background: #f8fafc; overflow: hidden; flex-shrink: 0;">
                                <span id="modalImagePlaceholder" style="font-size: 22px;">🏷️</span>
                                <img id="modalImagePreview" src="" alt="" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                            </div>
                            <div style="flex: 1;">
                                <input type="file" id="modalCategoryImage" name="category_image" accept="image/png,image/jpeg,image/webp" class="form-control" style="padding: 6px 10px; font-size: 13px;">
                                <label id="modalRemoveImageWrap" style="display: none; align-items: center; gap: 6px; font-size: 12.5px; color: #ef4444; cursor: pointer; margin-top: 6px;">
                                    <input type="checkbox" name="remove_image" id="modalRemoveImage" value="1">
                                    <span>Remove current image</span>
                                </label>
                            </div>
                        </div>
                        <span class="form-hint">Shown on storefront and POS catalog. PNG, JPG, WEBP (Max 5MB).</span>
                    </div>

                    <div class="form-group">
                        <label for="modalCategoryStatus" class="form-label">Status</label>
                        <select id="modalCategoryStatus" name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelCategoryModal">Cancel</button>
                    <button type="submit" class="header-btn" style="border: 0;">Save Category</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= asset('assets/js/dashboard.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('categoryModal');
            const openAddBtn = document.getElementById('openAddCategoryModal');
            const closeBtn = document.getElementById('closeCategoryModal');
            const cancelBtn = document.getElementById('cancelCategoryModal');
            const modalTitle = document.getElementById('categoryModalTitle');
            const idInput = document.getElementById('modalCategoryId');
            const nameInput = document.getElementById('modalCategoryName');
            const codeInput = document.getElementById('modalCategoryCode');
            const descInput = document.getElementById('modalCategoryDescription');
            const statusInput = document.getElementById('modalCategoryStatus');
            const fileInput = document.getElementById('modalCategoryImage');
            const previewImg = document.getElementById('modalImagePreview');
            const placeholder = document.getElementById('modalImagePlaceholder');
            const removeWrap = document.getElementById('modalRemoveImageWrap');
            const removeCheck = document.getElementById('modalRemoveImage');

            function updateImageDisplay(imageUrl) {
                if (imageUrl) {
                    previewImg.src = imageUrl;
                    previewImg.style.display = 'block';
                    placeholder.style.display = 'none';
                    if (removeWrap) removeWrap.style.display = 'flex';
                } else {
                    previewImg.src = '';
                    previewImg.style.display = 'none';
                    placeholder.style.display = 'block';
                    if (removeWrap) removeWrap.style.display = 'none';
                }
                if (removeCheck) removeCheck.checked = false;
                if (fileInput) fileInput.value = '';
            }

            if (fileInput) {
                fileInput.addEventListener('change', function () {
                    if (this.files && this.files[0]) {
                        const reader = new FileReader();
                        reader.onload = function (e) {
                            previewImg.src = e.target.result;
                            previewImg.style.display = 'block';
                            placeholder.style.display = 'none';
                        };
                        reader.readAsDataURL(this.files[0]);
                        if (removeCheck) removeCheck.checked = false;
                    }
                });
            }

            if (removeCheck) {
                removeCheck.addEventListener('change', function () {
                    if (this.checked) {
                        previewImg.style.display = 'none';
                        placeholder.style.display = 'block';
                        if (fileInput) fileInput.value = '';
                    } else {
                        const currentUrl = idInput.getAttribute('data-current-img');
                        if (currentUrl) {
                            previewImg.src = currentUrl;
                            previewImg.style.display = 'block';
                            placeholder.style.display = 'none';
                        }
                    }
                });
            }

            function openModal(isEdit = false, data = {}) {
                modalTitle.textContent = isEdit ? 'Edit Category' : 'Add Category';
                idInput.value = isEdit ? data.id : '';
                idInput.setAttribute('data-current-img', isEdit && data.imageUrl ? data.imageUrl : '');
                nameInput.value = isEdit ? data.name : '';
                codeInput.value = isEdit ? data.code : '';
                descInput.value = isEdit ? data.description : '';
                statusInput.value = isEdit ? data.status : 'active';
                updateImageDisplay(isEdit && data.imageUrl ? data.imageUrl : null);
                modal.classList.add('open');
                nameInput.focus();
            }

            function closeModal() {
                modal.classList.remove('open');
            }

            if (openAddBtn) openAddBtn.addEventListener('click', () => openModal(false));
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });

            // Edit button handlers
            document.querySelectorAll('.edit-category-btn').forEach(button => {
                button.addEventListener('click', function () {
                    const data = {
                        id: this.getAttribute('data-id'),
                        name: this.getAttribute('data-name'),
                        code: this.getAttribute('data-code'),
                        description: this.getAttribute('data-description'),
                        status: this.getAttribute('data-status'),
                        imageUrl: this.getAttribute('data-image-url'),
                    };
                    openModal(true, data);
                });
            });
        });
    </script>
</body>
</html>
