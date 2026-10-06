/**
 * app.js
 * Frontend interactivity for Automated ECO Tracker System
 */

document.addEventListener('DOMContentLoaded', function () {
    // -------------------------------------------------------------
    // Toast Notification Utility
    // -------------------------------------------------------------
    function showToast(message, type = 'info', duration = 3500) {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };
        toast.innerHTML = `<span style="font-weight:bold;">${icons[type] || 'ℹ'}</span> <span>${message}</span>`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.transition = 'opacity 0.3s, transform 0.3s';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    // -------------------------------------------------------------
    // Live Dashboard KPI Refresh
    // -------------------------------------------------------------
    function refreshKPIs() {
        fetch('api.php?action=get_dashboard_summary')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.summary) {
                    const s = data.summary;
                    document.getElementById('kpiTotalEco').textContent = s.total_eco;
                    document.getElementById('kpiAgileOpen').textContent = s.agile_open;
                    document.getElementById('kpiAgileClosed').textContent = s.agile_closed;
                    document.getElementById('kpiPendingPmc').textContent = s.pending_pmc;
                    document.getElementById('kpiPendingQa').textContent = s.pending_qa;
                    document.getElementById('kpiCompleted').textContent = s.completed;
                    document.getElementById('kpiOverdue').textContent = s.overdue;
                    
                    const changedEl = document.getElementById('kpiStatusChanged');
                    if (changedEl) changedEl.textContent = s.status_changed;
                    
                    const unmatchedEl = document.getElementById('kpiUnmatched');
                    if (unmatchedEl) unmatchedEl.textContent = s.unmatched_signoff;
                }
            })
            .catch(err => console.error('Failed to refresh KPIs:', err));
    }

    // -------------------------------------------------------------
    // AJAX PMC / QA Toggle Buttons
    // -------------------------------------------------------------
    document.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // prevent accordion toggle
            const ecoNo = this.getAttribute('data-eco');
            const field = this.getAttribute('data-field'); // 'pmc' or 'qa'

            if (!ecoNo || !field) return;

            this.disabled = true;
            const originalHtml = this.innerHTML;
            this.innerHTML = '...';

            const formData = new FormData();
            formData.append('eco_no', ecoNo);
            formData.append('field', field);
            formData.append('updated_by', 'Dashboard User');

            fetch('api.php?action=toggle_status', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                this.disabled = false;
                if (data.success) {
                    const isYes = (field === 'pmc' ? data.pmc_result_completed : data.qa_result_completed) == 1;
                    
                    if (isYes) {
                        this.classList.remove('no');
                        this.classList.add('yes');
                        this.innerHTML = '✓ YES';
                    } else {
                        this.classList.remove('yes');
                        this.classList.add('no');
                        this.innerHTML = '✗ NO';
                    }

                    // Update Row Tracker Status Badge
                    const row = document.getElementById(`row-${ecoNo}`);
                    if (row) {
                        const statusBadge = row.querySelector('.tracker-status-badge');
                        if (statusBadge) {
                            statusBadge.textContent = data.status_progress;
                            statusBadge.className = 'badge tracker-status-badge';
                            if (data.status_progress === 'Completed') {
                                statusBadge.classList.add('badge-completed');
                            } else if (data.status_progress === 'Pending QA') {
                                statusBadge.classList.add('badge-pending-qa');
                            } else {
                                statusBadge.classList.add('badge-pending-pmc');
                            }
                        }
                        // Update data attributes for client filtering
                        row.setAttribute(`data-${field}-completed`, isYes ? '1' : '0');
                        row.setAttribute('data-tracker-status', data.status_progress);
                    }

                    showToast(`${ecoNo}: ${field.toUpperCase()} marked as ${isYes ? 'YES' : 'NO'} (${data.status_progress})`, 'success');
                    refreshKPIs();
                } else {
                    this.innerHTML = originalHtml;
                    showToast(data.message || 'Toggle failed', 'error');
                }
            })
            .catch(err => {
                this.disabled = false;
                this.innerHTML = originalHtml;
                showToast('Network error during toggle: ' + err.message, 'error');
            });
        });
    });

    // -------------------------------------------------------------
    // Accordion Expand/Collapse
    // -------------------------------------------------------------
    document.querySelectorAll('.expand-trigger').forEach(el => {
        el.addEventListener('click', function (e) {
            const subjectCell = e.target.closest('.subject-cell');
            if (subjectCell) {
                e.stopPropagation();
                subjectCell.classList.toggle('expanded');
                return;
            }

            // Do not toggle if clicked on button or link
            if (e.target.closest('button') || e.target.closest('a')) return;

            const ecoNo = this.getAttribute('data-eco');
            const detailRow = document.getElementById(`detail-${ecoNo}`);
            const mainRow = document.getElementById(`row-${ecoNo}`);
            const arrow = this.querySelector('.expand-arrow');

            if (detailRow) {
                const isShowing = detailRow.classList.contains('show');
                if (isShowing) {
                    detailRow.classList.remove('show');
                    if (mainRow) mainRow.classList.remove('expanded');
                    if (arrow) arrow.textContent = '▶';
                } else {
                    detailRow.classList.add('show');
                    if (mainRow) mainRow.classList.add('expanded');
                    if (arrow) arrow.textContent = '▼';
                }
            }
        });
    });

    // -------------------------------------------------------------
    // Tracking Edit Modal
    // -------------------------------------------------------------
    const editModal = document.getElementById('editTrackingModal');
    const closeEditModalBtn = document.getElementById('closeEditModal');
    const cancelEditModalBtn = document.getElementById('cancelEditModal');
    const trackingForm = document.getElementById('editTrackingForm');

    function openEditModal(ecoNo) {
        if (!editModal) return;
        
        document.getElementById('modalEcoNoTitle').textContent = ecoNo;
        document.getElementById('formEcoNo').value = ecoNo;

        // Fetch current values
        fetch(`api.php?action=get_eco&eco_no=${encodeURIComponent(ecoNo)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.eco) {
                    const e = data.eco;
                    document.getElementById('formReworkNeed').value = e.rework_need || '';
                    document.getElementById('formEcrCategory').value = e.ecr_category || '';
                    document.getElementById('formFirstMoResult').value = e.first_mo_result || '';
                    document.getElementById('formImpactChecklist').value = e.impact_assessment_checklist || '';
                    document.getElementById('formPendingChecklist').value = e.pending_checklist || '';
                    document.getElementById('formUpdatedBy').value = e.updated_by || 'User';

                    editModal.classList.add('active');
                } else {
                    showToast('Failed to load tracking data: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => showToast('Error loading details: ' + err.message, 'error'));
    }

    function closeEditModal() {
        if (editModal) editModal.classList.remove('active');
    }

    if (closeEditModalBtn) closeEditModalBtn.addEventListener('click', closeEditModal);
    if (cancelEditModalBtn) cancelEditModalBtn.addEventListener('click', closeEditModal);

    document.querySelectorAll('.open-edit-modal').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const ecoNo = this.getAttribute('data-eco');
            openEditModal(ecoNo);
        });
    });

    if (trackingForm) {
        trackingForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const ecoNo = document.getElementById('formEcoNo').value;
            const submitBtn = document.getElementById('saveTrackingBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            const formData = new FormData(this);
            fetch('api.php?action=update_tracking', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Changes';
                if (data.success) {
                    showToast('Tracking details updated successfully.', 'success');
                    closeEditModal();

                    // Update values in expanded accordion view if rendered
                    const detailRow = document.getElementById(`detail-${ecoNo}`);
                    if (detailRow) {
                        const reworkVal = detailRow.querySelector('.val-rework');
                        if (reworkVal) reworkVal.textContent = document.getElementById('formReworkNeed').value || '—';
                        
                        const catVal = detailRow.querySelector('.val-category');
                        if (catVal) catVal.textContent = document.getElementById('formEcrCategory').value || '—';

                        const moVal = detailRow.querySelector('.val-first-mo');
                        if (moVal) moVal.textContent = document.getElementById('formFirstMoResult').value || '—';

                        const pendVal = detailRow.querySelector('.val-pending');
                        if (pendVal) pendVal.textContent = document.getElementById('formPendingChecklist').value || '—';

                        const impVal = detailRow.querySelector('.val-impact');
                        if (impVal) impVal.textContent = document.getElementById('formImpactChecklist').value || '—';
                    }
                } else {
                    showToast(data.message || 'Failed to save', 'error');
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Changes';
                showToast('Error saving tracking: ' + err.message, 'error');
            });
        });
    }

    // -------------------------------------------------------------
    // File Upload Modal & Pre-Flight Wizard
    // -------------------------------------------------------------
    const uploadModal = document.getElementById('uploadModal');
    const openUploadBtn = document.getElementById('openUploadModal');
    const closeUploadBtn = document.getElementById('closeUploadModal');
    const cancelUploadBtn = document.getElementById('cancelUploadModal');
    const uploadForm = document.getElementById('uploadForm');
    const preFlightBtn = document.getElementById('preFlightCheckBtn');
    const importBtn = document.getElementById('startImportBtn');
    const preFlightResults = document.getElementById('preFlightResults');

    if (openUploadBtn) {
        openUploadBtn.addEventListener('click', () => {
            uploadModal.classList.add('active');
            preFlightResults.innerHTML = '';
            preFlightResults.style.display = 'none';
            importBtn.disabled = false;
        });
    }

    function closeUpload() {
        if (uploadModal) uploadModal.classList.remove('active');
    }
    if (closeUploadBtn) closeUploadBtn.addEventListener('click', closeUpload);
    if (cancelUploadBtn) cancelUploadBtn.addEventListener('click', closeUpload);

    if (preFlightBtn) {
        preFlightBtn.addEventListener('click', function () {
            const searchInput = document.getElementById('uploadSearchFile');
            if (!searchInput.files.length) {
                showToast('Please select the SearchResult file (.xls/.xlsx/.csv) first.', 'warning');
                return;
            }

            preFlightBtn.disabled = true;
            preFlightBtn.textContent = 'Validating Columns...';
            preFlightResults.style.display = 'block';
            preFlightResults.innerHTML = '<p style="color:#0284c7;">Running Pre-Flight Inspection on uploaded headers...</p>';

            const formData = new FormData(uploadForm);
            fetch('api.php?action=validate_upload', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                preFlightBtn.disabled = false;
                preFlightBtn.textContent = 'Run Pre-Flight Check';

                if (data.success && data.valid) {
                    preFlightResults.innerHTML = `
                        <div style="background:#dcfce7; color:#15803d; border:1px solid #86efac; border-radius:6px; padding:12px; font-size:13px;">
                            <strong>✓ Pre-Flight Passed!</strong><br>
                            All required Agile PLM columns are present. Rows detected in SearchResult: ${data.info.search_result_rows || 'N/A'}.<br>
                            ${data.info.signoff_rows ? `Signoff rows detected: ${data.info.signoff_rows}.` : 'No signoff file provided (optional).'}
                        </div>
                    `;
                    importBtn.classList.remove('btn-outline');
                    importBtn.classList.add('btn-primary');
                } else {
                    const errs = data.errors || [data.message || 'Validation failed'];
                    preFlightResults.innerHTML = `
                        <div style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:6px; padding:12px; font-size:13px;">
                            <strong>✕ Pre-Flight Validation Failed:</strong>
                            <ul style="margin:6px 0 0 18px;">
                                ${errs.map(e => `<li>${e}</li>`).join('')}
                            </ul>
                        </div>
                    `;
                }
            })
            .catch(err => {
                preFlightBtn.disabled = false;
                preFlightBtn.textContent = 'Run Pre-Flight Check';
                preFlightResults.innerHTML = `<div style="background:#fee2e2; color:#991b1b; padding:10px;">Network error: ${err.message}</div>`;
            });
        });
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const searchInput = document.getElementById('uploadSearchFile');
            if (!searchInput.files.length) {
                showToast('SearchResult file is required.', 'warning');
                return;
            }

            importBtn.disabled = true;
            importBtn.textContent = 'Importing Data...';
            preFlightResults.style.display = 'block';
            preFlightResults.innerHTML = '<p style="color:#0284c7;">Processing files inside database transaction. Please wait...</p>';

            const formData = new FormData(this);
            fetch('api.php?action=import', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                importBtn.disabled = false;
                importBtn.textContent = 'Start Import';

                if (data.success) {
                    showToast(data.message, 'success', 5000);
                    preFlightResults.innerHTML = `
                        <div style="background:#dcfce7; color:#15803d; border:1px solid #86efac; border-radius:6px; padding:14px; font-size:13px;">
                            <strong>✓ ${data.message}</strong>
                            <p style="margin-top:8px;">Reloading dashboard in 2 seconds...</p>
                        </div>
                    `;
                    setTimeout(() => {
                        window.location.reload();
                    }, 1800);
                } else {
                    const errList = data.errors ? data.errors.map(e => `<li>${e}</li>`).join('') : `<li>${data.message || 'Import failed'}</li>`;
                    preFlightResults.innerHTML = `
                        <div style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:6px; padding:14px; font-size:13px;">
                            <strong>✕ Import Rolled Back:</strong>
                            <ul style="margin:6px 0 0 18px;">${errList}</ul>
                        </div>
                    `;
                }
            })
            .catch(err => {
                importBtn.disabled = false;
                importBtn.textContent = 'Start Import';
                preFlightResults.innerHTML = `<div style="background:#fee2e2; color:#991b1b; padding:10px;">Import request error: ${err.message}</div>`;
            });
        });
    }

    // -------------------------------------------------------------
    // Dismiss Status Change Highlights
    // -------------------------------------------------------------
    const dismissAllBtn = document.getElementById('dismissAllChangesBtn');
    if (dismissAllBtn) {
        dismissAllBtn.addEventListener('click', function () {
            fetch('api.php?action=dismiss_status_change&eco_no=ALL')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('All status change highlights dismissed.', 'info');
                        document.querySelectorAll('.badge-changed').forEach(el => el.remove());
                        document.querySelectorAll('tr.highlight-changed').forEach(el => el.classList.remove('highlight-changed'));
                        this.style.display = 'none';
                        refreshKPIs();
                    }
                });
        });
    }

    // -------------------------------------------------------------
    // Multi-dimensional Client-Side Filtering & Search
    // -------------------------------------------------------------
    const searchBox = document.getElementById('searchBox');
    const filterCustomer = document.getElementById('filterCustomer');
    const filterProject = document.getElementById('filterProject');
    const filterAgileStatus = document.getElementById('filterAgileStatus');
    const filterTrackerStatus = document.getElementById('filterTrackerStatus');
    const filterPmcCompleted = document.getElementById('filterPmcCompleted');
    const filterQaCompleted = document.getElementById('filterQaCompleted');
    const filterYear = document.getElementById('filterYear');
    const filterWw = document.getElementById('filterWw');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');
    const rowCountEl = document.getElementById('tableRowCount');
    let kpiAgileStatus = '';

    function applyFilters() {
        const query = (searchBox ? searchBox.value : '').toLowerCase().trim();
        const cust = filterCustomer ? filterCustomer.value : '';
        const proj = filterProject ? filterProject.value : '';
        const agileSt = kpiAgileStatus || (filterAgileStatus ? filterAgileStatus.value : '');
        const trackSt = filterTrackerStatus ? filterTrackerStatus.value : '';
        const pmcComp = filterPmcCompleted ? filterPmcCompleted.value : '';
        const qaComp = filterQaCompleted ? filterQaCompleted.value : '';
        const yr = filterYear ? filterYear.value : '';
        const ww = filterWw ? filterWw.value : '';

        const rows = document.querySelectorAll('tr.eco-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const ecoNo = (row.getAttribute('data-eco') || '').toLowerCase();
            const ecrNo = (row.getAttribute('data-ecr') || '').toLowerCase();
            const subject = (row.getAttribute('data-subject') || '').toLowerCase();
            const customer = row.getAttribute('data-customer') || '';
            const project = row.getAttribute('data-project') || '';
            const agile = row.getAttribute('data-agile-status') || '';
            const tracker = row.getAttribute('data-tracker-status') || '';
            const pmc = row.getAttribute('data-pmc-completed') || '0';
            const qa = row.getAttribute('data-qa-completed') || '0';
            const year = row.getAttribute('data-year') || '';
            const workWeek = row.getAttribute('data-ww') || '';

            // Search matches eco, ecr, subject, customer, or project
            const matchesQuery = !query ||
                ecoNo.includes(query) ||
                ecrNo.includes(query) ||
                subject.includes(query) ||
                customer.toLowerCase().includes(query) ||
                project.toLowerCase().includes(query);

            const matchesCust = !cust || customer === cust;
            const matchesProj = !proj || project === proj;
            const matchesAgile = !agileSt || agile === agileSt;
            const matchesTracker = !trackSt || tracker === trackSt;
            const matchesPmc = pmcComp === '' || pmc === pmcComp;
            const matchesQa = qaComp === '' || qa === qaComp;
            const matchesYear = !yr || year === yr;
            const matchesWw = !ww || workWeek === ww;

            const isVisible = matchesQuery && matchesCust && matchesProj &&
                              matchesAgile && matchesTracker && matchesPmc &&
                              matchesQa && matchesYear && matchesWw;

            row.style.display = isVisible ? '' : 'none';
            
            // Also hide detail row if main row is hidden
            const detailRow = document.getElementById(`detail-${row.getAttribute('data-eco')}`);
            if (detailRow && !isVisible) {
                detailRow.style.display = 'none';
            }

            if (isVisible) visibleCount++;
        });

        if (rowCountEl) {
            rowCountEl.textContent = `Showing ${visibleCount} of ${rows.length} ECOs`;
        }
    }

    [searchBox, filterCustomer, filterProject, filterAgileStatus,
     filterTrackerStatus, filterPmcCompleted, filterQaCompleted, filterYear, filterWw].forEach(el => {
        if (el) {
            const onFilterChange = function () {
                if (el === filterAgileStatus) kpiAgileStatus = '';
                applyFilters();
            };
            el.addEventListener('input', onFilterChange);
            el.addEventListener('change', onFilterChange);
        }
    });

    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', function () {
            if (searchBox) searchBox.value = '';
            if (filterCustomer) filterCustomer.value = '';
            if (filterProject) filterProject.value = '';
            if (filterAgileStatus) filterAgileStatus.value = '';
            if (filterTrackerStatus) filterTrackerStatus.value = '';
            if (filterPmcCompleted) filterPmcCompleted.value = '';
            if (filterQaCompleted) filterQaCompleted.value = '';
            if (filterYear) filterYear.value = '';
            if (filterWw) filterWw.value = '';

            kpiAgileStatus = '';
            document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('active'));
            applyFilters();
        });
    }

    // -------------------------------------------------------------
    // KPI Card Click Filter Quick-Shortcuts
    // -------------------------------------------------------------
    function clearNonAgileFilters() {
        if (searchBox) searchBox.value = '';
        if (filterCustomer) filterCustomer.value = '';
        if (filterProject) filterProject.value = '';
        if (filterTrackerStatus) filterTrackerStatus.value = '';
        if (filterPmcCompleted) filterPmcCompleted.value = '';
        if (filterQaCompleted) filterQaCompleted.value = '';
        if (filterYear) filterYear.value = '';
        if (filterWw) filterWw.value = '';
    }

    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', function () {
            const filterKey = this.getAttribute('data-filter');
            document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('active'));

            const hadKpiAgileStatus = !!kpiAgileStatus;
            kpiAgileStatus = '';
            if (hadKpiAgileStatus && filterAgileStatus) filterAgileStatus.value = '';
            if (!filterKey || filterKey === 'all') {
                if (filterTrackerStatus) filterTrackerStatus.value = '';
                if (filterAgileStatus) filterAgileStatus.value = '';
                applyFilters();
                return;
            }

            this.classList.add('active');

            if (filterKey === 'pending_pmc' && filterTrackerStatus) {
                filterTrackerStatus.value = 'Pending PMC';
            } else if (filterKey === 'pending_qa' && filterTrackerStatus) {
                filterTrackerStatus.value = 'Pending QA';
            } else if (filterKey === 'completed' && filterTrackerStatus) {
                filterTrackerStatus.value = 'Completed';
            } else if (filterKey === 'agile_open') {
                clearNonAgileFilters();
                kpiAgileStatus = 'Open';
                if (filterAgileStatus) filterAgileStatus.value = 'Open';
            } else if (filterKey === 'agile_closed') {
                clearNonAgileFilters();
                kpiAgileStatus = 'Closed';
                if (filterAgileStatus) filterAgileStatus.value = 'Closed';
            }
            applyFilters();
        });
    });

    // -------------------------------------------------------------
    // Export Button with active filters
    // -------------------------------------------------------------
    const exportBtn = document.getElementById('exportCsvBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            const params = new URLSearchParams();
            if (searchBox && searchBox.value) params.set('search', searchBox.value.trim());
            if (filterCustomer && filterCustomer.value) params.set('customer', filterCustomer.value);
            if (filterProject && filterProject.value) params.set('project', filterProject.value);
            if (filterAgileStatus && filterAgileStatus.value) params.set('agile_status', filterAgileStatus.value);
            if (filterTrackerStatus && filterTrackerStatus.value) params.set('tracker_status', filterTrackerStatus.value);
            if (filterPmcCompleted && filterPmcCompleted.value !== '') params.set('pmc_completed', filterPmcCompleted.value);
            if (filterQaCompleted && filterQaCompleted.value !== '') params.set('qa_completed', filterQaCompleted.value);
            if (filterYear && filterYear.value) params.set('year', filterYear.value);
            if (filterWw && filterWw.value) params.set('ww', filterWw.value);

            window.location.href = 'export.php?' + params.toString();
        });
    }
});
