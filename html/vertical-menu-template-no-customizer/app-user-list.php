<?php
// Lớp UI: không đủ quyền xem thì không render gì; endpoint vẫn tự kiểm lại.
if (!checkRoles('view', 'users')) {
    return;
}
$can_add_user  = Users::can_add();
$can_see_wage  = Users::can_see_salary();
?>
<!-- Filter — cùng khuôn với Products/Stores: card riêng, có badge đếm, nút Clear và thu gọn -->
<div class="card card-action mb-6" id="filterCard">
    <div class="card-header">
        <h5 class="card-action-title mb-0">
            Filter
            <span id="activeFilterCount" class="badge bg-label-primary ms-2 d-none">0</span>
        </h5>
        <div class="card-action-element">
            <ul class="list-inline mb-0 d-flex align-items-center gap-2">
                <li class="list-inline-item me-0">
                    <button type="button" class="btn btn-label-secondary btn-sm" id="clearFilters" disabled>
                        <i class="icon-base ti tabler-filter-off icon-xs me-1"></i>Clear Filters
                    </button>
                </li>
                <li class="list-inline-item">
                    <a href="javascript:void(0);" class="card-collapsible"><i class="icon-base ti tabler-chevron-up"></i></a>
                </li>
            </ul>
        </div>
    </div>
    <div class="collapse show" id="filterBody">
        <div class="card-body pt-0">
            <div class="row g-4 pt-4">
                <div class="col-md-3 user_role"></div>
                <?php if (is_admin()) : // lọc theo team chỉ dành cho admin ?>
                    <div class="col-md-3 user_team"></div>
                <?php endif; ?>
                <div class="col-md-3 user_status"></div>
            </div>
        </div>
    </div>
</div>

<!-- Users List Table -->
<div class="card">
    <div class="card-datatable">
        <!-- Không có cột checkbox: Users chỉ xóa từng dòng, không có thao tác hàng loạt -->
        <table class="datatables-users table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th>User</th>
                <th>Role</th>
                <th>Team</th>
                <?php if ($can_see_wage) : ?><th>Salary</th><?php endif; ?>
                <th>Status</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
            </thead>
        </table>
    </div>

    <!-- Offcanvas Add/Edit user. data-bs-scroll: giữ scrollbar body -> không xô bảng -->
    <div class="offcanvas offcanvas-end" data-bs-scroll="true" tabindex="-1"
         id="offcanvasUser" aria-labelledby="offcanvasUserLabel">
        <div class="offcanvas-header border-bottom">
            <h5 id="offcanvasUserLabel" class="offcanvas-title">Add User</h5>
            <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body mx-0 flex-grow-0 p-6 h-100">
            <form class="pt-0" id="userForm" onsubmit="return false">
                <input type="hidden" id="user-id" value="0">
                <input type="hidden" id="user-avatar" value="">
                <!-- Avatar: input file thường + fetch (Dropzone của Vuexy là bản GIẢ, không POST) -->
                <div class="mb-6 d-flex align-items-center gap-4">
                    <div class="avatar avatar-lg">
                        <!-- Chưa có ảnh thì hiện chữ cái đầu, giống cách bảng render -->
                        <span class="avatar-initial rounded-circle bg-label-secondary" id="user-avatar-initial">?</span>
                        <img id="user-avatar-preview" src="" alt="Avatar" class="rounded-circle d-none"
                             style="object-fit:cover;width:100%;height:100%;">
                    </div>
                    <div class="flex-grow-1">
                        <label class="btn btn-label-primary btn-sm mb-1" for="user-avatar-file">
                            <i class="icon-base ti tabler-upload icon-xs me-1"></i>Upload avatar
                            <input type="file" id="user-avatar-file" accept="image/png,image/jpeg" hidden>
                        </label>
                        <button type="button" class="btn btn-label-secondary btn-sm mb-1 d-none" id="user-avatar-reset">Remove</button>
                        <div class="form-text" id="user-avatar-hint">PNG or JPG, max 2 MB. Resized to 96×96.</div>
                    </div>
                </div>
                <div class="mb-6">
                    <label class="form-label" for="user-username">Username</label>
                    <input type="text" class="form-control" id="user-username" placeholder="username" maxlength="100">
                    <div class="invalid-feedback">Please enter a username.</div>
                </div>
                <div class="mb-6">
                    <label class="form-label" for="user-email">Email</label>
                    <input type="email" class="form-control" id="user-email" placeholder="user@example.com" maxlength="100">
                    <div class="invalid-feedback">Please enter a valid email.</div>
                </div>
                <div class="mb-6">
                    <label class="form-label" for="user-password">Password</label>
                    <input type="password" class="form-control" id="user-password" placeholder="At least 8 characters" autocomplete="new-password">
                    <div class="form-text" id="user-password-hint">Leave blank to keep the current password.</div>
                    <div class="invalid-feedback">Password must be at least 8 characters.</div>
                </div>
                <div class="alert alert-info d-none" id="user-self-hint" role="alert">
                    <i class="icon-base ti tabler-info-circle me-1"></i>
                    <span class="self-hint-text">You are editing your own account, so role,
                        team and status are locked.</span>
                </div>
                <div class="mb-6">
                    <label class="form-label" for="user-level">Role</label>
                    <select id="user-level" class="form-select"></select>
                </div>
                <div class="mb-6">
                    <label class="form-label" for="user-team">Team</label>
                    <select id="user-team" class="form-select"<?= is_admin() ? '' : ' disabled' ?>></select>
                    <?php if (!is_admin()) : ?>
                        <div class="form-text">New users are always added to your own team.</div>
                    <?php endif; ?>
                </div>
                <div class="mb-6">
                    <label class="form-label" for="user-status">Status</label>
                    <select id="user-status" class="form-select"></select>
                    <div class="form-text text-warning">
                        <i class="icon-base ti tabler-alert-triangle icon-14px me-1"></i>
                        Only Active users can sign in; other states end their access.
                    </div>
                </div>
                <?php if ($can_see_wage) : ?>
                <div class="mb-6">
                    <label class="form-label" for="user-wage">Salary (VND)</label>
                    <input type="number" class="form-control" id="user-wage" min="0" step="1000" value="0">
                </div>
                <div class="mb-6">
                    <label class="form-label" for="user-insurance">Insurance (VND)</label>
                    <input type="number" class="form-control" id="user-insurance" min="0" step="1000" value="0">
                </div>
                <?php endif; ?>
                <button type="button" class="btn btn-primary me-3" id="userSubmit">Save</button>
                <button type="reset" class="btn btn-label-danger" data-bs-dismiss="offcanvas">Cancel</button>
            </form>
        </div>
    </div>
</div>

<?php if (is_admin()) : ?>
<!-- Modal xác nhận CHUYỂN TEAM: đổi team kéo theo cả tầm nhìn dữ liệu nên phải xác nhận -->
<div class="modal fade" id="moveUserModal" tabindex="-1" aria-hidden="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-warning">Move user to another team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-4">
                    Moving <strong id="moveUserName"></strong> from <strong id="moveUserFrom"></strong>
                    to <strong id="moveUserTo"></strong> also moves what they own.
                </p>
                <table class="table table-sm mb-4">
                    <tbody>
                    <tr><td>Products that change team</td><td class="text-end fw-medium" id="moveCntProducts">0</td></tr>
                    <tr><td>Account links removed</td><td class="text-end fw-medium" id="moveCntAccounts">0</td></tr>
                    <tr><td>Products unlinked from private stores</td><td class="text-end fw-medium" id="moveCntStores">0</td></tr>
                    </tbody>
                </table>
                <div class="alert alert-info d-flex mb-0" role="alert">
                    <i class="icon-base ti tabler-info-circle me-2"></i>
                    <span>The user is signed out and must sign in again to get the new team.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary waves-effect" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning waves-effect waves-light" id="moveUserConfirm">Move user</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (Users::can_delete_any()) : ?>
<!-- Modal xác nhận xóa MỘT user (không có xóa hàng loạt) -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm delete!</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-4">Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>

                <div id="deleteUserLoading" class="text-center py-3">
                    <span class="spinner-border spinner-border-sm me-2" role="status"></span>Checking their data...
                </div>

                <div id="deleteUserSummary" class="d-none">
                    <!-- Thống kê ĐẦY ĐỦ mọi bảng trỏ tới người này + số phận từng bảng.
                         Trước đây chỉ có 2 dòng, người bấm Delete không thấy được thứ họ
                         sắp mất (file, site/category họ tạo, lương, lịch sử export...). -->
                    <table class="table table-sm mb-2">
                        <tbody id="delStatsRows"></tbody>
                    </table>
                    <div class="d-flex flex-wrap gap-3 mb-4 small text-body-secondary">
                        <span><span class="badge bg-label-info">Handed over</span> moves to the person you pick</span>
                        <span><span class="badge bg-label-danger">Deleted</span> removed with the user</span>
                        <span><span class="badge bg-label-warning">Unlinked</span> record stays, owner cleared</span>
                        <span><span class="badge bg-label-secondary">Kept</span> left untouched</span>
                    </div>
                    <!-- Customer là khách, xóa thẳng: không hỏi bàn giao, dữ liệu liên quan
                         được phép mồ côi. Vẫn phải NÓI RÕ cho người bấm biết. -->
                    <div id="deleteUserOrphanNote" class="alert alert-warning d-flex d-none" role="alert">
                        <i class="icon-base ti tabler-alert-triangle me-2"></i>
                        <span>Customer accounts are deleted outright. Anything linked to them is
                              left behind and will no longer point to a real user.</span>
                    </div>
                    <!-- Sản phẩm phải có người nhận: xóa user mà bỏ lại sản phẩm sẽ thành dữ liệu mồ côi -->
                    <div id="deleteUserTransferBox" class="d-none">
                        <!-- Nhãn do JS đặt: có thể là sản phẩm, liên kết account, hoặc cả hai -->
                        <label class="form-label" for="deleteUserTransfer"
                               id="deleteUserTransferLabel">Hand their products over to</label>
                        <select id="deleteUserTransfer" class="form-select"></select>
                        <div class="form-text" id="deleteUserTransferHint">
                            Only members of the same team can take them over.
                        </div>
                    </div>
                </div>

                <div id="deleteUserProgress" class="d-none mt-4">
                    <div class="progress mb-2" style="height: 8px;">
                        <div class="progress-bar bg-danger" id="deleteUserBar" style="width: 0%"></div>
                    </div>
                    <small class="text-body-secondary" id="deleteUserProgressText"></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary waves-effect" data-bs-dismiss="modal" id="deleteUserCancel">Cancel</button>
                <button type="button" class="btn btn-danger waves-effect waves-light" id="deleteUserConfirm" disabled>
                    <span class="spinner-border spinner-border-sm me-2 d-none" id="deleteUserSpinner" role="status"></span>Delete
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
