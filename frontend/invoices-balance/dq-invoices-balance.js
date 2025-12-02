/**
 * Invoices Balance Shortcode JavaScript
 *
 * Handles filtering, sorting, pagination, and CSV download for the
 * [invoices-balance] shortcode without page reload.
 *
 * @package Dominus_QuickBooks
 * @since 0.3.0
 */

(function () {
    'use strict';

    // Constants
    var ITEMS_PER_PAGE = 50;
    var FALLBACK_DATE = '9999-12-31';
    var FALLBACK_DAYS = 999999;

    // State
    var allInvoices = [];
    var filteredInvoices = [];
    var currentFilter = 'all';
    var currentSort = 'due_date';
    var currentSortDir = 'asc';
    var currentPage = 1;

    // DOM Elements
    var wrapper, tbody, table, noResults;
    var filterBtns, sortableHeaders, csvBtn;
    var prevBtn, nextBtn, pageNumbers, paginationInfo;
    var overdueTotal, incomingTotal;

    /**
     * Initialize the component when DOM is ready.
     */
    function init() {
        wrapper = document.getElementById('dq-invoices-balance');
        if (!wrapper) return;

        // Get DOM elements
        tbody = document.getElementById('dq-ib-tbody');
        table = document.getElementById('dq-ib-table');
        noResults = document.getElementById('dq-ib-no-results');
        filterBtns = wrapper.querySelectorAll('.dq-ib-filter-btn');
        sortableHeaders = wrapper.querySelectorAll('.dq-ib-sortable');
        csvBtn = document.getElementById('dq-ib-download-csv');
        prevBtn = document.getElementById('dq-ib-prev-btn');
        nextBtn = document.getElementById('dq-ib-next-btn');
        pageNumbers = document.getElementById('dq-ib-page-numbers');
        paginationInfo = document.getElementById('dq-ib-pagination-info');
        overdueTotal = document.getElementById('dq-ib-overdue-total');
        incomingTotal = document.getElementById('dq-ib-incoming-total');

        // Load initial data
        var dataEl = document.getElementById('dq-ib-initial-data');
        if (dataEl) {
            try {
                var initialData = JSON.parse(dataEl.textContent);
                allInvoices = initialData.invoices || [];
            } catch (e) {
                console.error('DQ Invoices Balance: Failed to parse initial data', e);
                return;
            }
        }

        // Bind events
        bindEvents();

        // Initial render
        renderTable();
    }

    /**
     * Bind event handlers.
     */
    function bindEvents() {
        // Filter buttons
        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', handleFilterClick);
            btn.addEventListener('keydown', handleFilterKeydown);
        });

        // Sortable headers
        sortableHeaders.forEach(function (th) {
            th.addEventListener('click', handleSortClick);
            th.addEventListener('keydown', handleSortKeydown);
        });

        // CSV download
        if (csvBtn) {
            csvBtn.addEventListener('click', handleCsvDownload);
        }

        // Pagination
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                var totalPages = Math.ceil(filteredInvoices.length / ITEMS_PER_PAGE) || 1;
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            });
        }
    }

    /**
     * Handle filter button click.
     *
     * @param {Event} e Click event.
     */
    function handleFilterClick(e) {
        var btn = e.currentTarget;
        var filter = btn.getAttribute('data-filter');

        // Update active state
        filterBtns.forEach(function (b) {
            b.classList.remove('active');
            b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('active');
        btn.setAttribute('aria-pressed', 'true');

        // Apply filter
        currentFilter = filter;
        currentPage = 1;
        renderTable();
    }

    /**
     * Handle filter button keyboard navigation.
     *
     * @param {KeyboardEvent} e Keydown event.
     */
    function handleFilterKeydown(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            handleFilterClick(e);
        }
    }

    /**
     * Handle sortable header click.
     *
     * @param {Event} e Click event.
     */
    function handleSortClick(e) {
        var th = e.currentTarget;
        var sortKey = th.getAttribute('data-sort');

        // Toggle direction if same column
        if (currentSort === sortKey) {
            currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            currentSort = sortKey;
            currentSortDir = 'asc';
        }

        // Update header classes
        updateSortHeaders(th);
        currentPage = 1;
        renderTable();
    }

    /**
     * Handle sortable header keyboard navigation.
     *
     * @param {KeyboardEvent} e Keydown event.
     */
    function handleSortKeydown(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            handleSortClick(e);
        }
    }

    /**
     * Update sort header visual indicators.
     *
     * @param {HTMLElement} activeHeader The currently sorted header.
     */
    function updateSortHeaders(activeHeader) {
        sortableHeaders.forEach(function (h) {
            h.classList.remove('dq-ib-sorted-asc', 'dq-ib-sorted-desc');
            h.setAttribute('aria-sort', 'none');
            var icon = h.querySelector('.dq-ib-sort-icon');
            if (icon) icon.textContent = '⇅';
        });

        var sortClass = currentSortDir === 'asc' ? 'dq-ib-sorted-asc' : 'dq-ib-sorted-desc';
        activeHeader.classList.add(sortClass);
        activeHeader.setAttribute('aria-sort', currentSortDir === 'asc' ? 'ascending' : 'descending');
        var icon = activeHeader.querySelector('.dq-ib-sort-icon');
        if (icon) icon.textContent = currentSortDir === 'asc' ? '↑' : '↓';
    }

    /**
     * Apply current filter to invoices.
     */
    function applyFilter() {
        if (currentFilter === 'all') {
            filteredInvoices = allInvoices.slice();
        } else if (currentFilter === 'overdue') {
            filteredInvoices = allInvoices.filter(function (inv) {
                return inv.is_overdue === true;
            });
        } else if (currentFilter === 'incoming') {
            filteredInvoices = allInvoices.filter(function (inv) {
                return inv.is_overdue === false;
            });
        }
    }

    /**
     * Apply current sort to filtered invoices.
     */
    function applySort() {
        filteredInvoices.sort(function (a, b) {
            var aVal, bVal;

            if (currentSort === 'invoice_no') {
                aVal = String(a.invoice_no || '').toLowerCase();
                bVal = String(b.invoice_no || '').toLowerCase();
            } else if (currentSort === 'invoice_date') {
                aVal = a.invoice_date_sort || '';
                bVal = b.invoice_date_sort || '';
            } else if (currentSort === 'due_date') {
                aVal = a.due_date_sort || FALLBACK_DATE;
                bVal = b.due_date_sort || FALLBACK_DATE;
            } else if (currentSort === 'remaining_days') {
                aVal = a.remaining_days_num !== null ? a.remaining_days_num : FALLBACK_DAYS;
                bVal = b.remaining_days_num !== null ? b.remaining_days_num : FALLBACK_DAYS;
            }

            var cmp = 0;
            if (typeof aVal === 'number' && typeof bVal === 'number') {
                cmp = aVal - bVal;
            } else {
                cmp = String(aVal).localeCompare(String(bVal));
            }

            return currentSortDir === 'asc' ? cmp : -cmp;
        });
    }

    /**
     * Render the table with current filter, sort, and pagination.
     */
    function renderTable() {
        applyFilter();
        applySort();

        var totalPages = Math.ceil(filteredInvoices.length / ITEMS_PER_PAGE) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        var start = (currentPage - 1) * ITEMS_PER_PAGE;
        var end = Math.min(start + ITEMS_PER_PAGE, filteredInvoices.length);
        var pageInvoices = filteredInvoices.slice(start, end);

        // Clear tbody
        tbody.innerHTML = '';

        if (pageInvoices.length === 0) {
            noResults.style.display = 'block';
            table.style.display = 'none';
        } else {
            noResults.style.display = 'none';
            table.style.display = '';

            pageInvoices.forEach(function (inv) {
                var tr = document.createElement('tr');
                tr.setAttribute('data-overdue', inv.is_overdue ? 'true' : 'false');

                var balanceClass = inv.is_overdue ? 'dq-ib-overdue-cell' : '';
                var daysClass = inv.is_overdue ? 'dq-ib-days-overdue' : 'dq-ib-days-remaining';

                tr.innerHTML =
                    '<td><a href="' + escapeHtml(inv.permalink) + '" aria-label="View invoice ' + escapeHtml(inv.invoice_no) + '">' +
                    escapeHtml(inv.invoice_no) + '</a></td>' +
                    '<td>$' + formatNumber(inv.total_billed) + '</td>' +
                    '<td class="' + balanceClass + '">$' + formatNumber(inv.balance_due) + '</td>' +
                    '<td>' + escapeHtml(inv.invoice_date) + '</td>' +
                    '<td>' + escapeHtml(inv.due_date) + '</td>' +
                    '<td class="' + daysClass + '">' + escapeHtml(inv.remaining_days_text) + '</td>';

                tbody.appendChild(tr);
            });
        }

        // Update pagination
        updatePagination(totalPages, start, end);

        // Update count in filter button
        updateFilterCount();
    }

    /**
     * Update pagination controls.
     *
     * @param {number} totalPages Total number of pages.
     * @param {number} start Start index.
     * @param {number} end End index.
     */
    function updatePagination(totalPages, start, end) {
        // Update info text
        if (filteredInvoices.length === 0) {
            paginationInfo.textContent = 'No invoices to display';
        } else {
            paginationInfo.textContent = 'Showing ' + (start + 1) + '-' + end + ' of ' + filteredInvoices.length + ' invoices';
        }

        // Update prev/next buttons
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;

        // Render page numbers
        renderPageNumbers(totalPages);
    }

    /**
     * Render page number buttons.
     *
     * @param {number} totalPages Total number of pages.
     */
    function renderPageNumbers(totalPages) {
        pageNumbers.innerHTML = '';

        if (totalPages <= 1) return;

        var maxVisible = 5;
        var startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        var endPage = Math.min(totalPages, startPage + maxVisible - 1);

        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        // First page
        if (startPage > 1) {
            pageNumbers.appendChild(createPageButton(1));
            if (startPage > 2) {
                pageNumbers.appendChild(createEllipsis());
            }
        }

        // Page range
        for (var i = startPage; i <= endPage; i++) {
            pageNumbers.appendChild(createPageButton(i));
        }

        // Last page
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                pageNumbers.appendChild(createEllipsis());
            }
            pageNumbers.appendChild(createPageButton(totalPages));
        }
    }

    /**
     * Create a page number button.
     *
     * @param {number} page Page number.
     * @return {HTMLElement} Button element.
     */
    function createPageButton(page) {
        var btn = document.createElement('button');
        btn.className = 'dq-ib-page-num' + (page === currentPage ? ' active' : '');
        btn.textContent = page;
        btn.setAttribute('aria-label', 'Page ' + page);
        if (page === currentPage) {
            btn.setAttribute('aria-current', 'page');
        }
        btn.addEventListener('click', function () {
            currentPage = page;
            renderTable();
        });
        return btn;
    }

    /**
     * Create an ellipsis element.
     *
     * @return {HTMLElement} Span element.
     */
    function createEllipsis() {
        var span = document.createElement('span');
        span.className = 'dq-ib-page-ellipsis';
        span.textContent = '...';
        span.setAttribute('aria-hidden', 'true');
        return span;
    }

    /**
     * Update the count in the "Show All" filter button.
     */
    function updateFilterCount() {
        filterBtns.forEach(function (btn) {
            if (btn.getAttribute('data-filter') === 'all') {
                var countSpan = btn.querySelector('.dq-ib-count');
                if (countSpan) {
                    countSpan.textContent = '(' + allInvoices.length + ')';
                }
            }
        });
    }

    /**
     * Handle CSV download button click.
     */
    function handleCsvDownload() {
        applyFilter();
        applySort();

        var csvRows = [];

        // Header row
        csvRows.push(['Invoice #', 'Amount', 'Balance', 'Invoice Date', 'Due Date', 'Remaining Days']);

        // Data rows
        filteredInvoices.forEach(function (inv) {
            csvRows.push([
                inv.invoice_no || '',
                parseFloat(inv.total_billed || 0).toFixed(2),
                parseFloat(inv.balance_due || 0).toFixed(2),
                inv.invoice_date || '',
                inv.due_date || '',
                inv.remaining_days_text || ''
            ]);
        });

        // Convert to CSV string
        var csvContent = csvRows.map(function (row) {
            return row.map(function (cell) {
                var str = String(cell);
                if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1 || str.indexOf('\n') !== -1) {
                    str = '"' + str.replace(/"/g, '""') + '"';
                }
                return str;
            }).join(',');
        }).join('\n');

        // Download CSV
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', 'unpaid-invoices-' + getDateString() + '.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * Get current date as YYYY-MM-DD string.
     *
     * @return {string} Date string.
     */
    function getDateString() {
        var d = new Date();
        var year = d.getFullYear();
        var month = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} str Input string.
     * @return {string} Escaped string.
     */
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Format number with 2 decimal places and thousands separator.
     *
     * @param {number} val Number to format.
     * @return {string} Formatted string.
     */
    function formatNumber(val) {
        var num = parseFloat(val) || 0;
        return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
