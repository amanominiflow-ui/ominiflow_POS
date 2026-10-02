        </main><!-- end adm-content -->

        <footer style="margin-top: auto; padding: 18px 24px; text-align: center; color: #94a3b8; font-size: 12px; border-top: 1px solid var(--adm-border); background: #ffffff;">
            &copy; <?= date('Y') ?> <strong>OminiFlow POS</strong> &bull; Super Admin Console
        </footer>
    </div><!-- end app-main -->
</div><!-- end adm-layout -->

    <!-- Toast Notification Container -->
    <div id="admToastContainer" style="position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px;"></div>

    <script>
        // Profile Menu Toggle (Rail Avatar)
        function toggleAdminProfileMenu(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('adminProfileMenu');
            if (menu) {
                menu.classList.toggle('show');
            }
        }

        // Close Profile Menu on Click Outside
        document.addEventListener('click', function(e) {
            const menu = document.getElementById('adminProfileMenu');
            const trigger = document.getElementById('adminProfileTrigger');
            if (menu && menu.classList.contains('show')) {
                if (!menu.contains(e.target) && (!trigger || !trigger.contains(e.target))) {
                    menu.classList.remove('show');
                }
            }
        });

        // Focus search from rail or keyboard
        function focusAdminSearch() {
            const searchInput = document.getElementById('adminGlobalSearch');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }

        // Keyboard shortcut: Pressing "/" focuses the global search
        document.addEventListener('keydown', function(e) {
            if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
                e.preventDefault();
                focusAdminSearch();
            }
        });

        // Toast notification helper
        function showAdmToast(msg, type = 'success') {
            const container = document.getElementById('admToastContainer');
            const toast = document.createElement('div');
            toast.style.cssText = `
                background: ${type === 'success' ? '#0f766e' : '#b91c1c'};
                color: #ffffff;
                padding: 12px 18px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 500;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                display: flex;
                align-items: center;
                gap: 8px;
                animation: slideToast 0.2s ease;
                min-width: 250px;
            `;
            toast.innerHTML = `
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${type === 'success' ? 'M5 13l4 4L19 7' : 'M6 18L18 6M6 6l12 12'}"/></svg>
                <span>${msg}</span>
            `;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // Live Auto-suggest search for clients and 1-click login
        const searchInput = document.getElementById('adminGlobalSearch');
        const searchResults = document.getElementById('adminSearchResults');

        if (searchInput && searchResults) {
            let debounceTimer = null;

            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                clearTimeout(debounceTimer);

                if (query.length < 2) {
                    searchResults.innerHTML = '';
                    searchResults.classList.remove('show');
                    return;
                }

                debounceTimer = setTimeout(() => {
                    fetch('<?= asset("admin/api.php?action=search&q=") ?>' + encodeURIComponent(query))
                        .then(r => r.json())
                        .then(data => {
                            if (!data.results || data.results.length === 0) {
                                searchResults.innerHTML = `
                                    <div style="padding: 16px; text-align: center; color: #64748b; font-size: 13px;">
                                        No clients found matching "<strong>${escapeHtml(query)}</strong>"
                                    </div>
                                `;
                                searchResults.classList.add('show');
                                return;
                            }

                            let html = '';
                            data.results.forEach(item => {
                                const subBadge = item.subscription_status === 'active' 
                                    ? '<span style="background: #dcfce7; color: #166534; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">Active</span>'
                                    : '<span style="background: #fee2e2; color: #991b1b; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">' + (item.subscription_status || 'Suspended') + '</span>';

                                html += `
                                    <div class="search-res-item">
                                        <div style="display: flex; flex-direction: column; gap: 2px;">
                                            <div style="font-weight: 600; font-size: 13px; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                                <span>${escapeHtml(item.business_name)}</span>
                                                ${subBadge}
                                            </div>
                                            <div style="font-size: 12px; color: #475569;">
                                                <strong>${escapeHtml(item.user_name)}</strong> &bull; <span style="color: #64748b;">${escapeHtml(item.user_email)}</span>
                                            </div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <a href="<?= asset("admin/client-features.php?id=") ?>${item.business_id}" class="btn-adm btn-adm-secondary btn-adm-sm" title="Configure Features">
                                                Features
                                            </a>
                                            <a href="<?= asset("admin/impersonate.php?user_id=") ?>${item.user_id}" class="btn-adm btn-adm-login btn-adm-sm" title="1-Click Login As Client">
                                                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                                Login As
                                            </a>
                                        </div>
                                    </div>
                                `;
                            });
                            searchResults.innerHTML = html;
                            searchResults.classList.add('show');
                        })
                        .catch(() => {
                            searchResults.innerHTML = '';
                            searchResults.classList.remove('show');
                        });
                }, 220);
            });

            // Close dropdown if clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.classList.remove('show');
                }
            });
        }

        // Live Toggle Subscription (AJAX)
        function toggleClientSubscription(checkbox, businessId) {
            const isChecked = checkbox.checked;
            const newStatus = isChecked ? 'active' : 'suspended';
            
            checkbox.disabled = true;

            const formData = new FormData();
            formData.append('action', 'toggle_subscription');
            formData.append('business_id', businessId);
            formData.append('status', newStatus);

            fetch('<?= asset("admin/api.php") ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                checkbox.disabled = false;
                if (data.success) {
                    showAdmToast(`Subscription ${isChecked ? 'activated' : 'suspended'} successfully!`);
                    const badge = document.getElementById(`sub-badge-${businessId}`);
                    if (badge) {
                        badge.className = `status-badge ${newStatus}`;
                        badge.innerHTML = `<span class="status-dot"></span>${isChecked ? 'Active' : 'Suspended'}`;
                    }
                } else {
                    checkbox.checked = !isChecked; // Revert
                    showAdmToast(data.error || 'Failed to update subscription', 'error');
                }
            })
            .catch(() => {
                checkbox.disabled = false;
                checkbox.checked = !isChecked; // Revert
                showAdmToast('Network error updating subscription', 'error');
            });
        }

        // Live Toggle Feature for client (AJAX)
        function toggleClientFeature(checkbox, businessId, featureKey) {
            const isChecked = checkbox.checked;
            checkbox.disabled = true;

            const formData = new FormData();
            formData.append('action', 'toggle_feature');
            formData.append('business_id', businessId);
            formData.append('feature_key', featureKey);
            formData.append('is_enabled', isChecked ? 1 : 0);

            fetch('<?= asset("admin/api.php") ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                checkbox.disabled = false;
                if (data.success) {
                    showAdmToast(`Feature "${featureKey}" updated!`);
                } else {
                    checkbox.checked = !isChecked;
                    showAdmToast(data.error || 'Failed to update feature', 'error');
                }
            })
            .catch(() => {
                checkbox.disabled = false;
                checkbox.checked = !isChecked;
                showAdmToast('Network error updating feature', 'error');
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>
