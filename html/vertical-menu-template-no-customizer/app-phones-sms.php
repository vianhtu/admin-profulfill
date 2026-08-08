<?php
if (!checkRoles('view', 'phones_sms')) {
    return;
}
// Lớp UI: thao tác hàng loạt là ẩn (không render) khi thiếu quyền; nút lẻ ở cột Actions thì
// khóa lại (dựng trong JS từ cờ theo dòng). Endpoint vẫn tự kiểm lại, đây chỉ là lớp 1.
$sms_can_edit   = checkRoles('edit', 'phones_sms');
$sms_can_delete = checkRoles('delete', 'phones_sms');
?>
<!-- Bộ lọc. Trang cũ render sẵn 20 tin mới nhất bằng PHP, không lọc, không tìm, không phân
     trang — và echo thẳng nội dung tin nhắn ra HTML (stored XSS: nội dung do người lạ soạn).
     Nay là DataTables server-side như mọi bảng khác, mọi field đi qua esc() bên JS. -->
<div class="card mb-6">
    <h5 class="card-header">Filters</h5>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6">
                <label class="form-label" for="SmsPhone">Phone Number</label>
                <select id="SmsPhone" class="form-select">
                    <option value="">All numbers</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="SmsStatus">Read state</label>
                <select id="SmsStatus" class="form-select">
                    <option value="">All messages</option>
                    <option value="pending">Unread</option>
                    <option value="viewed">Read</option>
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <?php if ($sms_can_edit || $sms_can_delete) : ?>
        <div class="card-header d-none align-items-center gap-3" id="smsBulkBar">
            <span class="fw-medium"><span id="smsBulkCount">0</span> selected</span>
            <?php if ($sms_can_edit) : ?>
                <button type="button" class="btn btn-sm btn-label-success" data-status="viewed">
                    <i class="icon-base ti tabler-mail-opened icon-xs me-1"></i>Mark as read
                </button>
                <button type="button" class="btn btn-sm btn-label-warning" data-status="pending">
                    <i class="icon-base ti tabler-mail icon-xs me-1"></i>Mark as unread
                </button>
            <?php endif; ?>
            <?php if ($sms_can_delete) : ?>
                <button type="button" class="btn btn-sm btn-label-danger ms-auto" id="smsBulkDelete">
                    <i class="icon-base ti tabler-trash icon-xs me-1"></i>Delete Selected
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="card-datatable">
        <table class="datatables-sms table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>To</th>
                <th>From</th>
                <th>Message</th>
                <th>State</th>
                <th>Received</th>
                <th>Actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>

<!-- Xem trọn một tin. Cột Message phải cắt ngắn (nội dung SMS là chuỗi dài không có chỗ ngắt,
     để nguyên sẽ bóp các cột sau về 0px), nên cần một chỗ đọc đầy đủ. -->
<div class="modal fade" id="smsViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-4">
                    <dt class="col-4 text-body-secondary fw-normal">To</dt>
                    <dd class="col-8" id="smsViewTo"></dd>
                    <dt class="col-4 text-body-secondary fw-normal">From</dt>
                    <dd class="col-8" id="smsViewFrom"></dd>
                    <dt class="col-4 text-body-secondary fw-normal">Received</dt>
                    <dd class="col-8 mb-0" id="smsViewDate"></dd>
                </dl>
                <div class="border rounded p-4" id="smsViewText" style="white-space:pre-wrap"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Xác nhận xóa. Dùng modal trong app chứ KHÔNG dùng window.confirm: trình duyệt được phép
     chặn hộp thoại đó và khi bị chặn nó trả false — nút bấm xong im lặng không làm gì. -->
<div class="modal fade" id="smsDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="smsDeleteTitle">Delete messages</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-4">Delete <strong id="smsDeleteCount">0</strong> selected message(s)?</p>
                <div class="alert alert-warning d-flex mb-4" role="alert">
                    <i class="icon-base ti tabler-alert-triangle me-2"></i>
                    <span>Deleted messages cannot be recovered. The phone numbers themselves
                          are not affected.</span>
                </div>
                <div id="smsDeleteResult" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="smsDeleteConfirm">Delete</button>
            </div>
        </div>
    </div>
</div>
