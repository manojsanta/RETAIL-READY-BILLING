        </div><!-- /.container-fluid -->
    </div><!-- /.main-content -->

        <?php $footerCompany = getCompany(); ?>
        <footer class="footer-bar">
            <div class="d-flex justify-content-between align-items-center">
                <span>&copy; <?php echo date('Y'); ?> <?= h($footerCompany['name'] ?? 'Computer Shop Billing') ?>. All rights reserved.</span>
                <span>Powered by <?= h($footerCompany['name'] ?? 'Computer Shop Billing') ?></span>
            </div>
        </footer>

    <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
            <div class="modal-content" style="border-radius:14px;overflow:hidden;">
                <div class="modal-body text-center py-4 px-4">
                    <div style="width:56px;height:56px;border-radius:50%;background:#fff0f0;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                        <i class="fas fa-exclamation-triangle" style="font-size:26px;color:#e74c3c;"></i>
                    </div>
                    <h5 class="modal-title fw-semibold mb-2" id="confirmModalTitle">Are you sure?</h5>
                    <p class="text-muted mb-0" id="confirmModalBody" style="font-size:14px;">This action cannot be undone.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center pb-4 pt-0 gap-2">
                    <button type="button" class="btn btn-light px-3" style="border-radius:8px;font-size:14px;" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmModalBtn" class="btn px-3" style="border-radius:8px;font-size:14px;background:#e74c3c;color:#fff;">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!empty($loadChartjs)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <?php endif; ?>
    <script src="assets/script.js"></script>
    <script>
    function confirmDelete(url, title, message) {
        var modal = document.getElementById('confirmModal');
        document.getElementById('confirmModalTitle').textContent = title || 'Are you sure?';
        document.getElementById('confirmModalBody').textContent = message || 'This action cannot be undone.';
        document.getElementById('confirmModalBtn').href = url;
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
    </script>
</body>
</html>
