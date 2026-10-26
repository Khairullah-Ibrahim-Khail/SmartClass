            <?php if (!isset($hide_all)): ?>
            </main>

            <!-- Footer -->
            <footer class="main-footer px-4 py-3">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
                    <p class="mb-0 text-muted">
                        &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved.
                    </p>
                    <div class="footer-links">
                        <a href="#" class="text-muted mx-2 text-decoration-none">Privacy Policy</a>
                        <a href="#" class="text-muted mx-2 text-decoration-none">Terms of Service</a>
                        <a href="#" class="text-muted mx-2 text-decoration-none">Help Center</a>
                    </div>
                </div>
            </footer>
            </div>
            </div>

            <!-- jQuery -->
            <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

            <!-- Bootstrap 5 JS -->
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

            <!-- Custom JS -->
            <script src="<?php echo APP_URL; ?>/assets/js/script.js"></script>

            <!-- Page specific JS -->
            <?php if (isset($page_js)): ?>
            <?php foreach ($page_js as $js): ?>
            <script src="<?php echo APP_URL; ?>/assets/js/<?php echo $js; ?>"></script>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Toast notification helper -->
            <script>
function showToast(type, message, duration = 3000) {
    const toastContainer = document.getElementById('toast-container');
    const toastId = 'toast-' + Date.now();

    const toastClass = type === 'success' ? 'bg-success' :
        type === 'error' ? 'bg-danger' :
        type === 'warning' ? 'bg-warning' : 'bg-info';

    const icon = type === 'success' ? 'check-circle' :
        type === 'error' ? 'exclamation-circle' :
        type === 'warning' ? 'exclamation-triangle' : 'info-circle';

    const toastHtml = `
                <div id="${toastId}" class="toast ${toastClass} text-white" role="alert">
                    <div class="toast-header ${toastClass} text-white">
                        <i class="fas fa-${icon} me-2"></i>
                        <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body">
                        ${message}
                    </div>
                </div>
            `;

    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastEl = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastEl, {
        autohide: true,
        delay: duration
    });
    toast.show();

    toastEl.addEventListener('hidden.bs.toast', () => {
        toastEl.remove();
    });
}

// Success/Error helpers
function showSuccess(message) {
    showToast('success', message);
}

function showError(message) {
    showToast('error', message);
}

function showWarning(message) {
    showToast('warning', message);
}

function showInfo(message) {
    showToast('info', message);
}

// Confirm dialog helper
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// AJAX helper
function ajaxRequest(url, data, callback, errorCallback) {
    $.ajax({
        url: url,
        type: 'POST',
        data: data,
        dataType: 'json',
        success: callback,
        error: errorCallback || function(xhr, status, error) {
            showError('An error occurred: ' + error);
        }
    });
}

// Form submission helper
function submitForm(formId, url, successCallback) {
    $(formId).on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        $.ajax({
            url: url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: successCallback,
            error: function(xhr) {
                showError(xhr.responseJSON?.message || 'An error occurred');
            }
        });
    });
}

// Loading state
function setLoading(button, isLoading, originalText) {
    if (isLoading) {
        button.prop('disabled', true);
        button.data('original-text', button.html());
        button.html('<span class="spinner-border spinner-border-sm me-2"></span>Loading...');
    } else {
        button.prop('disabled', false);
        button.html(button.data('original-text'));
    }
}
            </script>
            </body>

            </html>
            <?php endif; ?>