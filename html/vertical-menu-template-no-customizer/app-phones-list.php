<?php
if(!checkRoles('view', 'phones_numbers')){
    return;
}
?>
<!-- Phone Numbers Table.
     Bỏ card "Filters" cũ: nó chỉ có 4 ô rỗng chép từ trang xlsx (xlsx_types/sites/authors/
     accounts) mà không JS nào render vào, nên hiện ra một khối trống không bấm được gì. -->
<div class="card">
    <?php
    // Lớp UI: thao tác hàng loạt là ẩn (không render) khi thiếu quyền — đúng luật của dự án,
    // khác với nút lẻ ở cột Actions (khóa lại). Endpoint vẫn tự kiểm lại.
    $ph_can_delete = checkRoles('delete', 'phones_numbers');
    $ph_can_edit   = checkRoles('edit', 'phones_numbers');
    if ($ph_can_delete || $ph_can_edit) : ?>
        <div class="card-header d-none align-items-center gap-3" id="phonesBulkBar">
            <span class="fw-medium"><span id="phonesBulkCount">0</span> selected</span>
            <?php if ($ph_can_edit) : ?>
                <button type="button" class="btn btn-sm btn-label-success" data-status="active">
                    <i class="icon-base ti tabler-circle-check icon-xs me-1"></i>Set Active
                </button>
                <button type="button" class="btn btn-sm btn-label-warning" data-status="suspend">
                    <i class="icon-base ti tabler-ban icon-xs me-1"></i>Set Suspend
                </button>
            <?php endif; ?>
            <?php if ($ph_can_delete) : ?>
                <button type="button" class="btn btn-sm btn-label-danger ms-auto" id="phonesBulkDelete">
                    <i class="icon-base ti tabler-trash icon-xs me-1"></i>Delete Selected
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="card-datatable">
        <table class="datatables-phones table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>Numbers</th>
                <th>Status</th>
                <th>Carriers</th>
                <th>Notices</th>
                <th>Accounts</th>
                <th>Actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>

<!-- Xác nhận xóa hàng loạt. Dùng modal trong app chứ KHÔNG dùng window.confirm: trình duyệt
     được phép chặn hộp thoại đó và khi bị chặn nó trả false, nút bấm xong im lặng không làm
     gì (đã dính đúng vậy ở nút tạo key của Teams, 07/08/2026). -->
<div class="modal fade" id="phonesDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete phone numbers</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-4">Delete <strong id="phonesDeleteCount">0</strong> selected number(s)?</p>
                <div class="alert alert-warning d-flex mb-4" role="alert">
                    <i class="icon-base ti tabler-alert-triangle me-2"></i>
                    <span>Their messages and account links go too. The numbers stay rented at
                          Telnyx — release them there to stop being billed.</span>
                </div>
                <div id="phonesDeleteResult" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="phonesDeleteConfirm">Delete</button>
            </div>
        </div>
    </div>
</div>
