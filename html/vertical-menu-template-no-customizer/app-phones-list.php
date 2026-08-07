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

<!-- Sửa MỘT số điện thoại. `number` và `carrier` do Telnyx cấp nên chỉ hiển thị, không sửa;
     ô Team chỉ render cho admin (chuyển số sang nhóm khác là việc của admin).
     data-bs-scroll để mở form không khóa scroll body và xô bảng sang trái. -->
<div class="offcanvas offcanvas-end" data-bs-scroll="true" tabindex="-1" id="offcanvasPhone">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title">Edit Phone Number</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-6">
        <form id="phoneForm" onsubmit="return false">
            <input type="hidden" id="phone-id" value="0">
            <div class="mb-6">
                <label class="form-label" for="phone-number">Number</label>
                <input type="text" class="form-control" id="phone-number" disabled>
                <div class="form-text">Numbers come from Telnyx and cannot be changed here.</div>
            </div>
            <div class="mb-6">
                <label class="form-label" for="phone-carrier">Carrier</label>
                <input type="text" class="form-control" id="phone-carrier" disabled>
            </div>
            <div class="mb-6">
                <label class="form-label" for="phone-status">Status</label>
                <select id="phone-status" class="form-select">
                    <option value="active">Active</option>
                    <option value="suspend">Suspend</option>
                </select>
            </div>
            <?php if (is_admin()) : ?>
                <div class="mb-6">
                    <label class="form-label" for="phone-team">Team</label>
                    <select id="phone-team" class="form-select"></select>
                    <div class="form-text text-warning">
                        <i class="icon-base ti tabler-alert-triangle icon-14px me-1"></i>
                        Moving a number to another team drops its links to accounts of the old team.
                    </div>
                </div>
            <?php endif; ?>
            <button type="button" class="btn btn-primary me-3" id="phoneSubmit">Save</button>
            <button type="button" class="btn btn-label-secondary" data-bs-dismiss="offcanvas">Cancel</button>
        </form>
    </div>
</div>

<!-- Xác nhận xóa hàng loạt. Dùng modal trong app chứ KHÔNG dùng window.confirm: trình duyệt
     được phép chặn hộp thoại đó và khi bị chặn nó trả false, nút bấm xong im lặng không làm
     gì (đã dính đúng vậy ở nút tạo key của Teams, 07/08/2026). -->
<div class="modal fade" id="phonesDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="phonesDeleteTitle">Delete phone numbers</h5>
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
