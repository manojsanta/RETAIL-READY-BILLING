/* ============================================
   Vyapaar Style - JavaScript
   Computer Billing Shop
   ============================================ */

document.addEventListener('DOMContentLoaded', function () {

    /* ============================================
       1. Sidebar Toggle
       ============================================ */
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    let overlay = document.querySelector('.sidebar-overlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }

    function toggleSidebar() {
        document.body.classList.toggle('sidebar-collapsed');
        if (sidebar) sidebar.classList.toggle('show');
        if (overlay) overlay.classList.toggle('show');
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }

    overlay.addEventListener('click', function () {
        if (sidebar) sidebar.classList.remove('show');
        overlay.classList.remove('show');
        document.body.classList.remove('sidebar-collapsed');
    });

    /* ============================================
       2. Mobile Sidebar: Click Outside to Close
       ============================================ */
    document.addEventListener('click', function (e) {
        if (window.innerWidth > 992) return;
        if (!sidebar || !sidebar.classList.contains('show')) return;
        if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        }
    });

    /* ============================================
       3. Sidebar Submenu Accordion
       ============================================ */
    document.querySelectorAll('.nav-item.has-submenu > .nav-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const parent = this.closest('.nav-item.has-submenu');
            const wasOpen = parent.classList.contains('open');

            /* Close other open submenus */
            document.querySelectorAll('.nav-item.has-submenu.open').forEach(function (item) {
                if (item !== parent) item.classList.remove('open');
            });

            parent.classList.toggle('open', !wasOpen);
        });
    });

    /* ============================================
       4. Active Link Highlight
       ============================================ */
    function setActiveLink() {
        const currentPage = window.location.pathname.split('/').pop() || 'index.php';
        document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
            link.classList.remove('active');
            const href = link.getAttribute('href');
            if (href) {
                const linkPage = href.split('/').pop();
                if (linkPage === currentPage) {
                    link.classList.add('active');
                    const parentSubmenu = link.closest('.nav-submenu');
                    if (parentSubmenu) {
                        const parentItem = parentSubmenu.closest('.nav-item.has-submenu');
                        if (parentItem) parentItem.classList.add('open');
                    }
                }
            }
        });
    }
    setActiveLink();

    /* ============================================
       5. Delete Confirmation
       ============================================ */
    document.querySelectorAll('.btn-delete').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (!confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });

    /* ============================================
       6. Dynamic Row Management (Invoice Forms)
       ============================================ */
    let rowIndex = document.querySelectorAll('.invoice-item-row').length || 0;

    /* Add Row */
    window.addRow = function () {
        const container = document.getElementById('items-container');
        if (!container) return;

        const template = document.getElementById('row-template');
        if (!template) return;

        const html = template.innerHTML.replace(/\{\{INDEX\}\}/g, rowIndex);
        const temp = document.createElement('div');
        temp.innerHTML = html;
        const newRow = temp.firstElementChild;

        container.appendChild(newRow);
        rowIndex++;

        /* Initialize search on new row */
        initItemSearch(newRow);

        /* Re-calculate totals */
        calculateGrand();
    };

    /* Remove Row */
    window.removeRow = function (btn) {
        const row = btn.closest('.invoice-item-row');
        if (!row) return;

        const totalRows = document.querySelectorAll('.invoice-item-row').length;
        if (totalRows <= 1) {
            showToast('At least one item row is required', 'warning');
            return;
        }

        row.classList.add('removing');
        setTimeout(function () {
            row.remove();
            calculateGrand();
        }, 300);
    };

    /* Calculate Row Total */
    window.calculateRow = function (row) {
        if (!row) return;

        const qty = parseFloat(row.querySelector('.item-qty')?.value) || 0;
        const rate = parseFloat(row.querySelector('.item-rate')?.value) || 0;
        const discount = parseFloat(row.querySelector('.item-discount')?.value) || 0;
        const taxPercent = parseFloat(row.querySelector('.item-tax')?.value) || 0;

        const subtotal = qty * rate;
        const discountAmount = (subtotal * discount) / 100;
        const afterDiscount = subtotal - discountAmount;
        const taxAmount = (afterDiscount * taxPercent) / 100;
        const total = afterDiscount + taxAmount;

        const totalField = row.querySelector('.item-total');
        if (totalField) {
            totalField.value = total.toFixed(2);
        }

        const totalDisplay = row.querySelector('.item-total-display');
        if (totalDisplay) {
            totalDisplay.textContent = formatCurrency(total);
        }

        /* Stock warning */
        const stockAvailable = parseFloat(row.querySelector('.item-stock')?.value) || 0;
        const stockWarning = row.querySelector('.stock-warning');
        if (stockWarning) {
            if (qty > stockAvailable && stockAvailable > 0) {
                stockWarning.style.display = 'flex';
                stockWarning.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Only ' + stockAvailable + ' in stock';
            } else {
                stockWarning.style.display = 'none';
            }
        }
    };

    /* Calculate Grand Totals */
    window.calculateGrand = function () {
        let subtotal = 0;
        let totalTax = 0;
        let totalDiscount = 0;

        document.querySelectorAll('.invoice-item-row').forEach(function (row) {
            const qty = parseFloat(row.querySelector('.item-qty')?.value) || 0;
            const rate = parseFloat(row.querySelector('.item-rate')?.value) || 0;
            const discount = parseFloat(row.querySelector('.item-discount')?.value) || 0;
            const taxPercent = parseFloat(row.querySelector('.item-tax')?.value) || 0;

            const lineSubtotal = qty * rate;
            const lineDiscount = (lineSubtotal * discount) / 100;
            const afterDiscount = lineSubtotal - lineDiscount;
            const lineTax = (afterDiscount * taxPercent) / 100;

            subtotal += lineSubtotal;
            totalDiscount += lineDiscount;
            totalTax += lineTax;

            calculateRow(row);
        });

        const grandTotal = subtotal - totalDiscount + totalTax;

        const subtotalEl = document.getElementById('subtotal');
        const discountEl = document.getElementById('total-discount');
        const taxEl = document.getElementById('total-tax');
        const grandEl = document.getElementById('grand-total');
        const amountWords = document.getElementById('amount-words');

        if (subtotalEl) subtotalEl.textContent = formatCurrency(subtotal);
        if (discountEl) discountEl.textContent = '-' + formatCurrency(totalDiscount);
        if (taxEl) taxEl.textContent = formatCurrency(totalTax);
        if (grandEl) grandEl.textContent = formatCurrency(grandTotal);
        if (amountWords) amountWords.textContent = numberToWords(grandTotal);

        /* Hidden inputs for form submission */
        const hiddenSubtotal = document.querySelector('input[name="subtotal"]');
        const hiddenDiscount = document.querySelector('input[name="total_discount"]');
        const hiddenTax = document.querySelector('input[name="total_tax"]');
        const hiddenGrand = document.querySelector('input[name="grand_total"]');

        if (hiddenSubtotal) hiddenSubtotal.value = subtotal.toFixed(2);
        if (hiddenDiscount) hiddenDiscount.value = totalDiscount.toFixed(2);
        if (hiddenTax) hiddenTax.value = totalTax.toFixed(2);
        if (hiddenGrand) hiddenGrand.value = grandTotal.toFixed(2);
    };

    /* Event delegation for row inputs */
    document.addEventListener('input', function (e) {
        const target = e.target;
        if (target.matches('.item-qty, .item-rate, .item-discount, .item-tax')) {
            const row = target.closest('.invoice-item-row');
            if (row) {
                calculateRow(row);
                calculateGrand();
            }
        }
    });

    /* ============================================
       6b. Item Search AJAX
       ============================================ */
    function initItemSearch(scope) {
        const searchInputs = (scope || document).querySelectorAll('.item-search');
        searchInputs.forEach(function (input) {
            let debounceTimer;
            let dropdown = input.parentElement.querySelector('.item-search-dropdown');
            if (!dropdown) {
                dropdown = document.createElement('div');
                dropdown.className = 'item-search-dropdown';
                input.parentElement.appendChild(dropdown);
            }

            input.addEventListener('keyup', function () {
                clearTimeout(debounceTimer);
                const query = this.value.trim();

                if (query.length < 2) {
                    dropdown.classList.remove('show');
                    return;
                }

                debounceTimer = setTimeout(function () {
                    fetchItems(query, dropdown, input);
                }, 300);
            });

            input.addEventListener('focus', function () {
                if (this.value.trim().length >= 2) {
                    dropdown.classList.add('show');
                }
            });

            /* Close dropdown on outside click */
            document.addEventListener('click', function (e) {
                if (!input.parentElement.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });
        });
    }

    function fetchItems(query, dropdown, input) {
        fetch('api/items_search.php?q=' + encodeURIComponent(query))
            .then(function (response) {
                if (!response.ok) throw new Error('Network error');
                return response.json();
            })
            .then(function (items) {
                dropdown.innerHTML = '';

                if (items.length === 0) {
                    dropdown.innerHTML = '<div class="no-results">No items found</div>';
                    dropdown.classList.add('show');
                    return;
                }

                items.forEach(function (item) {
                    const div = document.createElement('div');
                    div.className = 'search-item';
                    div.innerHTML =
                        '<div>' +
                        '<div class="item-name">' + escapeHtml(item.name) + '</div>' +
                        '<div class="item-stock">Stock: ' + item.current_stock + '</div>' +
                        '</div>' +
                        '<div class="item-price">' + formatCurrency(parseFloat(item.sale_price)) + '</div>';

                    div.addEventListener('click', function () {
                        selectItem(item, input);
                        dropdown.classList.remove('show');
                    });

                    dropdown.appendChild(div);
                });

                dropdown.classList.add('show');
            })
            .catch(function (err) {
                dropdown.innerHTML = '<div class="no-results">Error fetching items</div>';
                dropdown.classList.add('show');
            });
    }

    function selectItem(item, input) {
        const row = input.closest('.invoice-item-row');
        if (!row) return;

        const nameField = row.querySelector('.item-name-field');
        const idField = row.querySelector('.item-id-field');
        const rateField = row.querySelector('.item-rate');
        const taxField = row.querySelector('.item-tax');
        const stockField = row.querySelector('.item-stock');
        const qtyField = row.querySelector('.item-qty');
        const unitField = row.querySelector('.item-unit');

        if (nameField) nameField.value = item.name;
        if (idField) idField.value = item.id;
        if (rateField) rateField.value = parseFloat(item.sale_price).toFixed(2);
        if (taxField) taxField.value = item.tax_rate || 0;
        if (stockField) stockField.value = item.current_stock || 0;
        if (unitField) unitField.value = item.unit || 'pcs';
        if (qtyField && !qtyField.value) qtyField.value = 1;

        calculateRow(row);
        calculateGrand();
    }

    /* Initialize search on page load */
    initItemSearch();

    /* ============================================
       7. Date Range Picker Helpers
       ============================================ */
    window.setDateRange = function (start, end) {
        const startInput = document.getElementById('date-from');
        const endInput = document.getElementById('date-to');
        if (startInput) startInput.value = start;
        if (endInput) endInput.value = end;

        const filterForm = document.getElementById('filter-form');
        if (filterForm) filterForm.submit();
    };

    window.setQuickDate = function (period) {
        const today = new Date();
        let start, end;

        end = formatDate(today);

        switch (period) {
            case 'today':
                start = formatDate(today);
                break;
            case 'yesterday':
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                start = formatDate(yesterday);
                end = start;
                break;
            case 'this-week':
                const weekStart = new Date(today);
                weekStart.setDate(today.getDate() - today.getDay());
                start = formatDate(weekStart);
                break;
            case 'last-week':
                const lastWeekEnd = new Date(today);
                lastWeekEnd.setDate(today.getDate() - today.getDay() - 1);
                const lastWeekStart = new Date(lastWeekEnd);
                lastWeekStart.setDate(lastWeekEnd.getDate() - 6);
                start = formatDate(lastWeekStart);
                end = formatDate(lastWeekEnd);
                break;
            case 'this-month':
                start = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
                break;
            case 'last-month':
                const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
                const lastMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                start = formatDate(lastMonthStart);
                end = formatDate(lastMonthEnd);
                break;
            case 'this-year':
                start = formatDate(new Date(today.getFullYear(), 0, 1));
                break;
            case 'last-year':
                start = formatDate(new Date(today.getFullYear() - 1, 0, 1));
                end = formatDate(new Date(today.getFullYear() - 1, 11, 31));
                break;
            default:
                start = '';
                end = '';
        }

        setDateRange(start, end);
    };

    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    /* ============================================
       8. Number Formatting (Indian Currency)
       ============================================ */
    function formatCurrency(amount) {
        return '₹' + formatIndianNumber(amount);
    }

    function formatIndianNumber(num) {
        if (isNaN(num) || num === null || num === undefined) return '0.00';

        const isNegative = num < 0;
        num = Math.abs(num);

        const parts = num.toFixed(2).split('.');
        let intPart = parts[0];
        const decPart = parts[1];

        /* Indian comma system */
        if (intPart.length > 3) {
            const lastThree = intPart.substring(intPart.length - 3);
            const remaining = intPart.substring(0, intPart.length - 3);
            const formatted = remaining.replace(/\B(?=(\d{2})+(?!\d))/g, ',');
            intPart = formatted + ',' + lastThree;
        }

        const result = intPart + '.' + decPart;
        return isNegative ? '-' + result : result;
    }

    window.formatCurrency = formatCurrency;
    window.formatIndianNumber = formatIndianNumber;

    /* ============================================
       9. Print Invoice
       ============================================ */
    window.printInvoice = function () {
        const invoiceArea = document.querySelector('.invoice-print');
        if (!invoiceArea) {
            showToast('No invoice content found', 'error');
            return;
        }

        const printWindow = window.open('', '_blank', 'width=800,height=600');
        if (!printWindow) {
            showToast('Pop-up blocked. Please allow pop-ups for printing.', 'warning');
            return;
        }

        printWindow.document.write(
            '<!DOCTYPE html><html><head><title>Invoice Print</title>' +
            '<link rel="stylesheet" href="assets/style.css">' +
            '<style>body{background:white;padding:20px;font-family:"Segoe UI",sans-serif;}</style>' +
            '</head><body>' +
            invoiceArea.innerHTML +
            '<script>window.onload=function(){window.print();window.close();}<\/script>' +
            '</body></html>'
        );
        printWindow.document.close();
    };

    window.printArea = function (selector) {
        const area = document.querySelector(selector);
        if (!area) {
            showToast('No content to print', 'error');
            return;
        }

        const printWindow = window.open('', '_blank', 'width=800,height=600');
        if (!printWindow) {
            showToast('Pop-up blocked. Please allow pop-ups for printing.', 'warning');
            return;
        }

        printWindow.document.write(
            '<!DOCTYPE html><html><head><title>Print</title>' +
            '<link rel="stylesheet" href="assets/style.css">' +
            '<style>body{background:white;padding:20px;font-family:"Segoe UI",sans-serif;}</style>' +
            '</head><body>' +
            area.innerHTML +
            '<script>window.onload=function(){window.print();window.close();}<\/script>' +
            '</body></html>'
        );
        printWindow.document.close();
    };

    /* ============================================
       10. Form Validation Helper
       ============================================ */
    window.validateForm = function (formId) {
        const form = document.getElementById(formId);
        if (!form) return true;

        let isValid = true;
        const requiredFields = form.querySelectorAll('[required]');

        requiredFields.forEach(function (field) {
            field.classList.remove('is-invalid');

            if (!field.value || field.value.trim() === '') {
                field.classList.add('is-invalid');
                isValid = false;
            }

            if (field.type === 'email' && field.value) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(field.value)) {
                    field.classList.add('is-invalid');
                    isValid = false;
                }
            }

            if (field.type === 'number' && field.value) {
                const min = parseFloat(field.getAttribute('min'));
                const max = parseFloat(field.getAttribute('max'));
                const val = parseFloat(field.value);

                if (!isNaN(min) && val < min) {
                    field.classList.add('is-invalid');
                    isValid = false;
                }
                if (!isNaN(max) && val > max) {
                    field.classList.add('is-invalid');
                    isValid = false;
                }
            }
        });

        if (!isValid) {
            showToast('Please fill in all required fields correctly', 'error');
            /* Scroll to first invalid field */
            const firstInvalid = form.querySelector('.is-invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
            }
        }

        return isValid;
    };

    /* Remove invalid state on input */
    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('is-invalid')) {
            e.target.classList.remove('is-invalid');
        }
    });

    /* ============================================
       11. Toast Notification
       ============================================ */
    let toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);
    }

    window.showToast = function (message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;

        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-times-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };

        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.innerHTML =
            '<i class="toast-icon ' + (icons[type] || icons.info) + '"></i>' +
            '<span>' + escapeHtml(message) + '</span>' +
            '<button class="toast-close" aria-label="Close">&times;</button>';

        toastContainer.appendChild(toast);

        /* Close button */
        toast.querySelector('.toast-close').addEventListener('click', function () {
            removeToast(toast);
        });

        /* Auto dismiss */
        setTimeout(function () {
            removeToast(toast);
        }, duration);
    };

    function removeToast(toast) {
        if (!toast || toast.classList.contains('removing')) return;
        toast.classList.add('removing');
        setTimeout(function () {
            toast.remove();
        }, 300);
    }

    /* ============================================
       12. Toggle Password Visibility
       ============================================ */
    document.querySelectorAll('.password-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const input = targetId ? document.getElementById(targetId) : this.parentElement.querySelector('input');

            if (!input) return;

            if (input.type === 'password') {
                input.type = 'text';
                this.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                input.type = 'password';
                this.innerHTML = '<i class="fas fa-eye"></i>';
            }
        });
    });

    /* ============================================
       13. Auto-dismiss Alerts
       ============================================ */
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function () {
                alert.remove();
            }, 300);
        }, 5000);

        /* Manual close */
        const closeBtn = alert.querySelector('.btn-close-alert');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                alert.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function () {
                    alert.remove();
                }, 300);
            });
        }
    });

    /* ============================================
       14. Stock Warning
       ============================================ */
    document.addEventListener('change', function (e) {
        if (e.target.matches('.item-qty')) {
            const row = e.target.closest('.invoice-item-row');
            if (!row) return;

            const qty = parseFloat(e.target.value) || 0;
            const stockField = row.querySelector('.item-stock');
            const stockAvailable = stockField ? parseFloat(stockField.value) || 0 : 0;
            const warningEl = row.querySelector('.stock-warning');

            if (warningEl && stockAvailable > 0 && qty > stockAvailable) {
                warningEl.style.display = 'flex';
                warningEl.innerHTML =
                    '<i class="fas fa-exclamation-triangle"></i> ' +
                    'Only ' + stockAvailable + ' units available in stock!';

                showToast('Warning: Quantity exceeds available stock for ' + (row.querySelector('.item-name-field')?.value || 'item'), 'warning');
            } else if (warningEl) {
                warningEl.style.display = 'none';
            }
        }
    });

    /* ============================================
       Helper: Number to Words (Indian)
       ============================================ */
    function numberToWords(num) {
        if (isNaN(num) || num === 0) return 'Zero';

        const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
            'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
            'Seventeen', 'Eighteen', 'Nineteen'];
        const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        const isNegative = num < 0;
        num = Math.abs(Math.round(num * 100));

        const paise = num % 100;
        num = Math.floor(num / 100);

        if (num === 0 && paise === 0) return 'Zero Rupees';

        function convertHundreds(n) {
            let result = '';
            if (n >= 100) {
                result += ones[Math.floor(n / 100)] + ' Hundred ';
                n %= 100;
            }
            if (n >= 20) {
                result += tens[Math.floor(n / 10)] + ' ';
                n %= 10;
            }
            if (n > 0) {
                result += ones[n] + ' ';
            }
            return result.trim();
        }

        let words = '';
        let crores = Math.floor(num / 10000000);
        num %= 10000000;
        let lakhs = Math.floor(num / 100000);
        num %= 100000;
        let thousands = Math.floor(num / 1000);
        num %= 1000;

        if (crores > 0) words += convertHundreds(crores) + ' Crore ';
        if (lakhs > 0) words += convertHundreds(lakhs) + ' Lakh ';
        if (thousands > 0) words += convertHundreds(thousands) + ' Thousand ';
        if (num > 0) words += convertHundreds(num);

        words = words.trim() + ' Rupees';

        if (paise > 0) {
            words += ' and ' + convertHundreds(paise) + ' Paise';
        }

        words += ' Only';

        return (isNegative ? 'Minus ' : '') + words;
    }

    window.numberToWords = numberToWords;

    /* ============================================
       Helper: Escape HTML
       ============================================ */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    window.escapeHtml = escapeHtml;

    /* ============================================
       Global Enter Key Support
       ============================================ */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            /* Close any open dropdowns */
            document.querySelectorAll('.item-search-dropdown.show').forEach(function (dd) {
                dd.classList.remove('show');
            });

            /* Close modals */
            document.querySelectorAll('.modal.show .btn-close, .modal.show [data-bs-dismiss="modal"]').forEach(function (btn) {
                btn.click();
            });
        }
    });

    /* ============================================
       Tooltip Init (simple title-based)
       ============================================ */
    document.querySelectorAll('[data-toggle="tooltip"]').forEach(function (el) {
        el.setAttribute('title', el.getAttribute('data-tooltip') || '');
    });

});
