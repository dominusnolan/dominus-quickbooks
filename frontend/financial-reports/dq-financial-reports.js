/**
 * Financial Reports Shortcode JavaScript
 *
 * Handles the [dq-financial-reports] shortcode modal functionality:
 * - Opening/closing modal with accessibility (focus trap, ESC key)
 * - Fetching data from REST API
 * - Rendering table with sorting, filtering, pagination
 * - CSV download
 *
 * @package DominusQuickBooks
 * @subpackage Frontend
 * @since 0.3.0
 */

(function() {
    'use strict';

    // Configuration constants.
    var ITEMS_PER_PAGE = 50;
    var FALLBACK_DATE = '9999-12-31';
    var FALLBACK_DAYS = 999999;

    // Store for each shortcode instance.
    var instances = {};

    /**
     * Initialize all shortcode instances on the page.
     */
    function initAll() {
        var wrappers = document.querySelectorAll('.dq-fr-shortcode-wrapper');
        wrappers.forEach(function(wrapper) {
            initInstance(wrapper);
        });
    }

    /**
     * Initialize a single shortcode instance.
     *
     * @param {HTMLElement} wrapper The wrapper element.
     */
    function initInstance(wrapper) {
        var instanceId = wrapper.id;
        var modalId = instanceId + '-modal';
        var modal = document.getElementById(modalId);

        if (!modal) {
            return;
        }

        var instance = {
            wrapper: wrapper,
            modal: modal,
            modalContent: modal.querySelector('.dq-fr-modal-content'),
            closeBtn: modal.querySelector('.dq-fr-modal-close'),
            openBtn: wrapper.querySelector('.dq-fr-open-modal-btn'),
            isInline: wrapper.dataset.inline === 'true',
            allInvoices: [],
            filteredInvoices: [],
            currentPage: 1,
            currentFilter: 'all',
            currentSort: 'due_date',
            currentSortDir: 'asc',
            isLoading: false,
            dataLoaded: false
        };

        instances[instanceId] = instance;

        // Bind events.
        bindEvents(instance);

        // If inline mode, load data immediately.
        if (instance.isInline) {
            loadData(instance);
        }
    }

    /**
     * Bind event handlers for an instance.
     *
     * @param {Object} instance The instance object.
     */
    function bindEvents(instance) {
        // Open modal button.
        if (instance.openBtn) {
            instance.openBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openModal(instance);
            });
        }

        // Close button.
        if (instance.closeBtn) {
            instance.closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                closeModal(instance);
            });
        }

        // Close on overlay click.
        instance.modal.addEventListener('click', function(e) {
            if (e.target === instance.modal) {
                closeModal(instance);
            }
        });

        // Close on ESC key.
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && instance.modal.style.display === 'block') {
                closeModal(instance);
            }
        });

        // Inline view button (if exists).
        if (instance.isInline) {
            instance.wrapper.addEventListener('click', function(e) {
                if (e.target.classList.contains('dq-fr-inline-view-btn')) {
                    e.preventDefault();
                    openModal(instance);
                }
            });
        }
    }

    /**
     * Open the modal.
     *
     * @param {Object} instance The instance object.
     */
    function openModal(instance) {
        instance.modal.style.display = 'block';
        document.body.style.overflow = 'hidden';

        // Load data if not already loaded.
        if (!instance.dataLoaded) {
            loadData(instance);
        }

        // Focus trap - focus the close button.
        if (instance.closeBtn) {
            instance.closeBtn.focus();
        }

        // Setup focus trap.
        setupFocusTrap(instance);
    }

    /**
     * Close the modal.
     *
     * @param {Object} instance The instance object.
     */
    function closeModal(instance) {
        instance.modal.style.display = 'none';
        document.body.style.overflow = '';

        // Return focus to the open button.
        if (instance.openBtn) {
            instance.openBtn.focus();
        }
    }

    /**
     * Setup focus trap within the modal.
     *
     * @param {Object} instance The instance object.
     */
    function setupFocusTrap(instance) {
        var focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

        instance.modal.addEventListener('keydown', function(e) {
            if (e.key !== 'Tab') {
                return;
            }

            var focusableElements = instance.modal.querySelectorAll(focusableSelector);
            var firstFocusable = focusableElements[0];
            var lastFocusable = focusableElements[focusableElements.length - 1];

            if (e.shiftKey) {
                if (document.activeElement === firstFocusable) {
                    lastFocusable.focus();
                    e.preventDefault();
                }
            } else {
                if (document.activeElement === lastFocusable) {
                    firstFocusable.focus();
                    e.preventDefault();
                }
            }
        });
    }

    /**
     * Load data from REST API.
     *
     * @param {Object} instance The instance object.
     */
    function loadData(instance) {
        if (instance.isLoading) {
            return;
        }

        instance.isLoading = true;

        var vars = window.dqFinancialReportsVars || {};
        var restUrl = vars.restUrl || '';
        var nonce = vars.nonce || '';

        if (!restUrl) {
            showError(instance, 'Configuration error: REST API URL not found.');
            return;
        }

        fetch(restUrl, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(function(data) {
            instance.isLoading = false;
            instance.dataLoaded = true;

            if (data.success) {
                instance.allInvoices = data.invoices || [];
                instance.totals = data.totals || { overdue: 0, incoming: 0, total: 0 };
                renderModalContent(instance);

                // Also update inline summary if applicable.
                if (instance.isInline) {
                    renderInlineSummary(instance);
                }
            } else {
                showError(instance, 'Failed to load data.');
            }
        })
        .catch(function(error) {
            instance.isLoading = false;
            console.error('DQ Financial Reports Error:', error);
            showError(instance, 'Failed to load data. Please try again.');
        });
    }

    /**
     * Show error message in modal.
     *
     * @param {Object} instance The instance object.
     * @param {string} message  Error message.
     */
    function showError(instance, message) {
        instance.modalContent.innerHTML = '<div class="dq-fr-no-results">' + escapeHtml(message) + '</div>';
    }

    /**
     * Render inline summary.
     *
     * @param {Object} instance The instance object.
     */
    function renderInlineSummary(instance) {
        var summary = instance.wrapper.querySelector('.dq-fr-inline-summary');
        if (!summary) {
            return;
        }

        var totals = instance.totals;
        var html = '<div class="dq-fr-inline-content">';
        html += '<div class="dq-fr-inline-stat overdue">Total Overdue<strong>' + formatMoney(totals.overdue) + '</strong></div>';
        html += '<div class="dq-fr-inline-stat incoming">Total Incoming<strong>' + formatMoney(totals.incoming) + '</strong></div>';
        html += '<button type="button" class="dq-fr-inline-view-btn">View Details</button>';
        html += '</div>';

        summary.innerHTML = html;
    }

    /**
     * Render modal content with controls and table.
     *
     * @param {Object} instance The instance object.
     */
    function renderModalContent(instance) {
        var totals = instance.totals;

        var html = '';

        // Controls section.
        html += '<div class="dq-fr-modal-controls">';

        // Summary stats.
        html += '<div class="dq-fr-summary">';
        html += '<div class="dq-fr-summary-item overdue">Total Overdue<strong>' + formatMoney(totals.overdue) + '</strong></div>';
        html += '<div class="dq-fr-summary-item incoming">Total Incoming<strong>' + formatMoney(totals.incoming) + '</strong></div>';
        html += '</div>';

        // Filters.
        html += '<div class="dq-fr-filters">';
        html += '<label>Filter:</label>';
        html += '<button type="button" class="dq-fr-filter-btn active" data-filter="all">Show All</button>';
        html += '<button type="button" class="dq-fr-filter-btn" data-filter="overdue">Overdue</button>';
        html += '<button type="button" class="dq-fr-filter-btn" data-filter="incoming">Incoming</button>';
        html += '</div>';

        // CSV button.
        html += '<button type="button" class="dq-fr-csv-btn">Download CSV</button>';

        html += '</div>'; // .dq-fr-modal-controls

        // Table wrapper.
        html += '<div class="dq-fr-table-wrapper">';
        html += '<table class="dq-fr-table">';
        html += '<thead><tr>';
        html += '<th>Invoice #</th>';
        html += '<th>Amount</th>';
        html += '<th>Balance</th>';
        html += '<th class="sortable" data-sort="invoice_date">Invoice Date</th>';
        html += '<th class="sortable asc" data-sort="due_date">Due Date</th>';
        html += '<th class="sortable" data-sort="remaining_days">Remaining Days</th>';
        html += '</tr></thead>';
        html += '<tbody class="dq-fr-tbody"></tbody>';
        html += '</table>';
        html += '</div>';

        // No results message.
        html += '<div class="dq-fr-no-results" style="display:none;">No invoices match the current filter.</div>';

        // Pagination.
        html += '<div class="dq-fr-pagination">';
        html += '<div class="dq-fr-pagination-info"></div>';
        html += '<div class="dq-fr-pagination-controls">';
        html += '<button type="button" class="dq-fr-prev-btn" disabled>&laquo; Previous</button>';
        html += '<div class="dq-fr-page-numbers"></div>';
        html += '<button type="button" class="dq-fr-next-btn">Next &raquo;</button>';
        html += '</div>';
        html += '</div>';

        instance.modalContent.innerHTML = html;

        // Bind modal-specific events.
        bindModalEvents(instance);

        // Initial render.
        renderTable(instance);
    }

    /**
     * Bind events within the modal content.
     *
     * @param {Object} instance The instance object.
     */
    function bindModalEvents(instance) {
        var content = instance.modalContent;

        // Filter buttons.
        var filterBtns = content.querySelectorAll('.dq-fr-filter-btn');
        filterBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                filterBtns.forEach(function(b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');
                instance.currentFilter = btn.dataset.filter;
                instance.currentPage = 1;
                renderTable(instance);
            });
        });

        // Sortable headers.
        var sortHeaders = content.querySelectorAll('.dq-fr-table th.sortable');
        sortHeaders.forEach(function(th) {
            th.addEventListener('click', function() {
                var sortKey = th.dataset.sort;
                if (instance.currentSort === sortKey) {
                    instance.currentSortDir = instance.currentSortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    instance.currentSort = sortKey;
                    instance.currentSortDir = 'asc';
                }

                // Update header classes.
                sortHeaders.forEach(function(h) {
                    h.classList.remove('asc', 'desc');
                });
                th.classList.add(instance.currentSortDir);

                instance.currentPage = 1;
                renderTable(instance);
            });
        });

        // Pagination buttons.
        var prevBtn = content.querySelector('.dq-fr-prev-btn');
        var nextBtn = content.querySelector('.dq-fr-next-btn');

        if (prevBtn) {
            prevBtn.addEventListener('click', function() {
                if (instance.currentPage > 1) {
                    instance.currentPage--;
                    renderTable(instance);
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function() {
                var totalPages = Math.ceil(instance.filteredInvoices.length / ITEMS_PER_PAGE) || 1;
                if (instance.currentPage < totalPages) {
                    instance.currentPage++;
                    renderTable(instance);
                }
            });
        }

        // CSV download button.
        var csvBtn = content.querySelector('.dq-fr-csv-btn');
        if (csvBtn) {
            csvBtn.addEventListener('click', function() {
                downloadCSV(instance);
            });
        }
    }

    /**
     * Apply filter to invoices.
     *
     * @param {Object} instance The instance object.
     */
    function applyFilter(instance) {
        var filter = instance.currentFilter;

        if (filter === 'all') {
            instance.filteredInvoices = instance.allInvoices.slice();
        } else if (filter === 'overdue') {
            instance.filteredInvoices = instance.allInvoices.filter(function(inv) {
                return inv.is_overdue === true;
            });
        } else if (filter === 'incoming') {
            instance.filteredInvoices = instance.allInvoices.filter(function(inv) {
                return inv.is_overdue === false;
            });
        }
    }

    /**
     * Apply sort to filtered invoices.
     *
     * @param {Object} instance The instance object.
     */
    function applySort(instance) {
        var sortKey = instance.currentSort;
        var sortDir = instance.currentSortDir;

        instance.filteredInvoices.sort(function(a, b) {
            var aVal, bVal;

            if (sortKey === 'invoice_date') {
                aVal = a.invoice_date_sort || '';
                bVal = b.invoice_date_sort || '';
            } else if (sortKey === 'due_date') {
                aVal = a.due_date_sort || FALLBACK_DATE;
                bVal = b.due_date_sort || FALLBACK_DATE;
            } else if (sortKey === 'remaining_days') {
                aVal = a.remaining_days_num !== null ? a.remaining_days_num : FALLBACK_DAYS;
                bVal = b.remaining_days_num !== null ? b.remaining_days_num : FALLBACK_DAYS;
            }

            var cmp = 0;
            if (typeof aVal === 'number' && typeof bVal === 'number') {
                cmp = aVal - bVal;
            } else {
                cmp = String(aVal).localeCompare(String(bVal));
            }

            return sortDir === 'asc' ? cmp : -cmp;
        });
    }

    /**
     * Render the table with current filter, sort, and pagination.
     *
     * @param {Object} instance The instance object.
     */
    function renderTable(instance) {
        applyFilter(instance);
        applySort(instance);

        var content = instance.modalContent;
        var tbody = content.querySelector('.dq-fr-tbody');
        var noResults = content.querySelector('.dq-fr-no-results');
        var tableWrapper = content.querySelector('.dq-fr-table-wrapper');
        var paginationInfo = content.querySelector('.dq-fr-pagination-info');
        var pageNumbers = content.querySelector('.dq-fr-page-numbers');
        var prevBtn = content.querySelector('.dq-fr-prev-btn');
        var nextBtn = content.querySelector('.dq-fr-next-btn');

        var totalPages = Math.ceil(instance.filteredInvoices.length / ITEMS_PER_PAGE) || 1;

        if (instance.currentPage > totalPages) {
            instance.currentPage = totalPages;
        }
        if (instance.currentPage < 1) {
            instance.currentPage = 1;
        }

        var start = (instance.currentPage - 1) * ITEMS_PER_PAGE;
        var end = Math.min(start + ITEMS_PER_PAGE, instance.filteredInvoices.length);
        var pageInvoices = instance.filteredInvoices.slice(start, end);

        // Clear tbody.
        tbody.innerHTML = '';

        if (pageInvoices.length === 0) {
            noResults.style.display = 'block';
            tableWrapper.style.display = 'none';
        } else {
            noResults.style.display = 'none';
            tableWrapper.style.display = '';

            pageInvoices.forEach(function(inv) {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><a href="' + escapeHtml(inv.permalink) + '" target="_blank" rel="noopener">' + escapeHtml(inv.invoice_no) + '</a></td>' +
                    '<td>' + formatMoney(inv.total_billed) + '</td>' +
                    '<td><span class="dq-fr-balance">' + formatMoney(inv.balance_due) + '</span></td>' +
                    '<td>' + escapeHtml(inv.invoice_date || 'N/A') + '</td>' +
                    '<td>' + escapeHtml(inv.due_date) + '</td>' +
                    '<td><span class="' + escapeHtml(inv.remaining_class) + '">' + escapeHtml(inv.remaining_days_text) + '</span></td>';
                tbody.appendChild(tr);
            });
        }

        // Update pagination info.
        if (instance.filteredInvoices.length === 0) {
            paginationInfo.textContent = 'No invoices to display';
        } else {
            paginationInfo.textContent = 'Showing ' + (start + 1) + '-' + end + ' of ' + instance.filteredInvoices.length + ' invoices';
        }

        // Render page numbers.
        renderPageNumbers(instance, totalPages, pageNumbers);

        // Update prev/next buttons.
        prevBtn.disabled = instance.currentPage <= 1;
        nextBtn.disabled = instance.currentPage >= totalPages;
    }

    /**
     * Render page number buttons.
     *
     * @param {Object}      instance    The instance object.
     * @param {number}      totalPages  Total number of pages.
     * @param {HTMLElement} container   Container element.
     */
    function renderPageNumbers(instance, totalPages, container) {
        container.innerHTML = '';

        var maxVisible = 5;
        var currentPage = instance.currentPage;
        var startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        var endPage = Math.min(totalPages, startPage + maxVisible - 1);

        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        // First page.
        if (startPage > 1) {
            container.appendChild(createPageButton(instance, 1));
            if (startPage > 2) {
                var dots = document.createElement('span');
                dots.className = 'dq-fr-page-ellipsis';
                dots.textContent = '...';
                container.appendChild(dots);
            }
        }

        // Page range.
        for (var i = startPage; i <= endPage; i++) {
            container.appendChild(createPageButton(instance, i));
        }

        // Last page.
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                var dots = document.createElement('span');
                dots.className = 'dq-fr-page-ellipsis';
                dots.textContent = '...';
                container.appendChild(dots);
            }
            container.appendChild(createPageButton(instance, totalPages));
        }
    }

    /**
     * Create a page button element.
     *
     * @param {Object} instance The instance object.
     * @param {number} page     Page number.
     * @return {HTMLElement} Button element.
     */
    function createPageButton(instance, page) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dq-fr-page-num' + (page === instance.currentPage ? ' active' : '');
        btn.textContent = page;
        btn.addEventListener('click', function() {
            instance.currentPage = page;
            renderTable(instance);
        });
        return btn;
    }

    /**
     * Download CSV of current filtered data.
     *
     * @param {Object} instance The instance object.
     */
    function downloadCSV(instance) {
        applyFilter(instance);
        applySort(instance);

        var csvRows = [];

        // Header row.
        csvRows.push(['Invoice #', 'Amount', 'Balance', 'Invoice Date', 'Due Date', 'Remaining Days']);

        // Data rows.
        instance.filteredInvoices.forEach(function(inv) {
            csvRows.push([
                inv.invoice_no || '',
                parseFloat(inv.total_billed || 0).toFixed(2),
                parseFloat(inv.balance_due || 0).toFixed(2),
                inv.invoice_date || '',
                inv.due_date || '',
                inv.remaining_days_text || ''
            ]);
        });

        // Convert to CSV string.
        var csvContent = csvRows.map(function(row) {
            return row.map(function(cell) {
                var str = String(cell);
                if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1 || str.indexOf('\n') !== -1) {
                    str = '"' + str.replace(/"/g, '""') + '"';
                }
                return str;
            }).join(',');
        }).join('\n');

        // Download.
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', 'unpaid-invoices.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * Format number as currency.
     *
     * @param {number} val Value to format.
     * @return {string} Formatted string.
     */
    function formatMoney(val) {
        var num = parseFloat(val) || 0;
        return '$' + num.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} str String to escape.
     * @return {string} Escaped string.
     */
    function escapeHtml(str) {
        if (!str) {
            return '';
        }
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Initialize when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
