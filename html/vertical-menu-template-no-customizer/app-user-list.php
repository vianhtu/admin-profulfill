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
                Are you sure you want to delete <strong id="deleteUserName"></strong>?
                Users who still own products or accounts cannot be deleted — reassign their data first.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary waves-effect" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger waves-effect waves-light" id="deleteUserConfirm">Delete</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
