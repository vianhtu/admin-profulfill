<?php
// Lớp UI: không đủ quyền xem thì không render gì; endpoint vẫn tự kiểm lại.
// Menu này admin FULL, manager phải được cấp role mới vào, vai user thì không bao giờ.
if (!Roles::can_manage('view')) {
    return;
}
$can_add_role   = Roles::can_add();
$allowed_levels = Roles::allowed_levels();
$menu_actions   = Role::valid_menu_actions();
$menus          = menuArgs();
$all_actions    = Roles::ACTIONS;
?>
<!-- Filter — cùng khuôn với Products/Stores: card riêng, badge đếm, nút Clear, thu gọn được -->
<div class="card card-action mb-6" id="filterCard">
    <div class="card-header">
        <h5 class="card-action-title mb-0">
            Filter
            <span id="activeFilterCount" class="badge bg-label-primary ms-2 d-none">0</span>
        </h5>
        <div class="card-action-element">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-label-secondary btn-sm" id="clearFilters" disabled>
                    <i class="icon-base ti tabler-filter-off icon-xs me-1"></i>Clear Filters
                </button>
                <a href="javascript:void(0);" class="card-collapsible">
                    <i class="icon-base ti tabler-chevron-up"></i>
                </a>
            </div>
        </div>
    </div>
    <div class="collapse show" id="filterBody">
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-3 role_level"></div>
                <div class="col-md-3 role_assigned"></div>
            </div>
        </div>
    </div>
</div>

<!-- Roles Table -->
<div class="card">
    <div class="card-datatable">
        <!-- Không có cột checkbox: role xóa từng dòng, mỗi lần xóa phải chọn role thay thế -->
        <table class="datatables-permissions table border-top">
            <thead>
            <tr>
                <th></th>
                <th>Name</th>
                <th>Level</th>
                <th>Menus</th>
                <th>Assigned To</th>
                <th>Actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>

<style>
    /* Vuexy đặt .modal-simple .modal-content { padding: 3rem } — viền dày hai bên ngốn
       gần hết bề ngang, ma trận quyền bị bóp lại. Bỏ đi; .modal-body vốn đã có đệm riêng. */
    #addPermissionModal.modal-simple .modal-content,
    #addPermissionModal .modal-simple .modal-content { padding: 0; }
</style>

<!-- Add/Edit Role Modal -->
<div class="modal fade" id="addPermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-simple modal-lg">
        <div class="modal-content">
            <div class="modal-body">
                <button type="button" class="btn-close btn-pinned" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="text-center mb-6">
                    <h3 id="roleModalTitle">Add New Role</h3>
                    <p class="text-body-secondary">Permissions you may use and assign to your users.</p>
                    <div class="alert alert-dismissible fade" id="alertPermissionModal" role="alert"></div>
                </div>
                <form id="addPermissionForm" class="row" onsubmit="return false">
                    <input type="hidden" id="roleId" value="0">
                    <div class="col-md-7 form-control-validation mb-4">
                        <label class="form-label" for="modalPermissionName">Role Name</label>
                        <input type="text" id="modalPermissionName" name="modalPermissionName"
                               class="form-control" placeholder="Role Name" maxlength="50" autofocus />
                        <div class="invalid-feedback">Role name is required.</div>
                    </div>
                    <div class="col-md-5 mb-4">
                        <label class="form-label" for="modalRoleLevel">Level</label>
                        <!-- Chọn được cấp cùng hàng hoặc thấp hơn (allowed_levels đã lọc sẵn),
                             nên không khóa select nữa; server vẫn kiểm lại. -->
                        <select id="modalRoleLevel" class="form-select">
                            <?php foreach ($allowed_levels as $slug => $label) : ?>
                                <option value="<?= h($slug) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!is_admin()) : ?>
                            <div class="form-text">You can only assign your own level or below.</div>
                        <?php endif; ?>
                    </div>
                    <?php if (!is_admin()) : ?>
                        <div class="col-12 mb-4">
                            <div class="alert alert-info mb-0" role="alert">
                                <i class="icon-base ti tabler-info-circle me-1"></i>
                                You can only grant permissions you hold yourself — the rest are locked.
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="col-12 mb-2">
                        <!-- overflow-x:auto để bảng rộng cuộn TRONG khung này, không đẩy
                             tràn thân modal (đã dính: modal-body 759px > khung 704px) -->
                        <div class="table-responsive" style="max-height:60vh; overflow:auto; max-width:100%;">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Module</th>
                                <?php foreach ($all_actions as $action) : ?>
                                    <th class="text-center text-capitalize"><?= h($action) ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            // Ô nào bản thân không có quyền thì KHÓA (disabled) chứ không ẩn —
                            // giữ bảng thẳng hàng và cho thấy quyền đó có tồn tại.
                            $grantable = Roles::grantable();
                            $cell = function (string $menuKey, string $action, string $menuLabel)
                                use ($menu_actions, $grantable): string {
                                $supported = in_array($action, $menu_actions[$menuKey] ?? [], true);
                                $mayGrant  = $grantable === null
                                    || in_array($action, $grantable[$menuKey] ?? [], true);
                                $off = !$supported || !$mayGrant;
                                $title = !$supported ? 'This menu has no such action'
                                    : (!$mayGrant ? 'You do not hold this permission yourself' : '');
                                // aria-label BẮT BUỘC: ma trận này là 56 ô tick giống hệt nhau,
                                // không nhãn thì trình đọc màn hình chỉ đọc "checkbox" 56 lần.
                                $aria = $menuLabel . ' — ' . ucfirst($action);
                                return '<td class="text-center"><input type="checkbox" class="perm-box"'
                                    . ' aria-label="' . h($aria) . '"'
                                    . ' data-menu="' . h($menuKey) . '" data-action="' . h($action) . '"'
                                    . ($off ? ' disabled title="' . h($title) . '"' : '') . '></td>';
                            };
                            foreach ($menus as $menuName => $menuData) :
                                if (!empty($menuData['sub'])) {
                                    $validSubs = array_filter($menuData['sub'],
                                        fn($s, $k) => isset($menu_actions[$k]), ARRAY_FILTER_USE_BOTH);
                                    if (!$validSubs) {
                                        continue;
                                    } ?>
                                    <tr class="table-secondary">
                                        <td colspan="<?= count($all_actions) + 1 ?>">
                                            <i class="icon-base ti <?= h($menuData['icon'] ?? '') ?>"></i>
                                            <strong><?= h($menuName) ?></strong>
                                        </td>
                                    </tr>
                                    <?php foreach ($validSubs as $subKey => $subData) : ?>
                                        <tr>
                                            <td class="ps-4"><?= h($subData['label'] ?? $subKey) ?></td>
                                            <?php foreach ($all_actions as $action) {
                                                echo $cell((string)$subKey, $action,
                                                    (string)($subData['label'] ?? $subKey));
                                            } ?>
                                        </tr>
                                    <?php endforeach;
                                } else {
                                    $key = (string)($menuData['link'] ?? '');
                                    if (!isset($menu_actions[$key])) {
                                        continue;   // menu admin_only (Teams) không đi qua roles
                                    } ?>
                                    <tr class="table-warning fw-bold">
                                        <td>
                                            <i class="icon-base ti <?= h($menuData['icon'] ?? '') ?>"></i>
                                            <?= h($menuName) ?>
                                        </td>
                                        <?php foreach ($all_actions as $action) {
                                            echo $cell($key, $action, (string)$menuName);
                                        } ?>
                                    </tr>
                                <?php }
                            endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <div class="col-12 text-center demo-vertical-spacing">
                        <button type="submit" class="btn btn-primary me-sm-4 me-1" id="roleSubmit">Save</button>
                        <button type="reset" class="btn btn-label-secondary" data-bs-dismiss="modal">Discard</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Role Modal — xóa role phải chuyển người đang mang nó sang role khác -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" id="deleteRoleClose"></button>
            </div>
            <div class="modal-body">
                <p class="mb-4">Are you sure you want to delete <strong id="deleteRoleName"></strong>?</p>
                <div id="deleteRoleAssigned" class="d-none">
                    <div class="alert alert-warning d-flex" role="alert">
                        <i class="icon-base ti tabler-alert-triangle me-2"></i>
                        <span><strong id="deleteRoleCount">0</strong> user(s) still use this role.
                              Choose the role they move to.</span>
                    </div>
                    <label class="form-label" for="deleteRoleReplacement">Move these users to</label>
                    <select id="deleteRoleReplacement" class="form-select"></select>
                </div>
                <div id="deleteRoleProgress" class="d-none mt-4">
                    <div class="progress"><div class="progress-bar" id="deleteRoleBar" style="width:0%"></div></div>
                    <small class="text-body-secondary" id="deleteRoleProgressText"></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal" id="deleteRoleCancel">Cancel</button>
                <button type="button" class="btn btn-danger" id="deleteRoleConfirm" disabled>Delete</button>
            </div>
        </div>
    </div>
</div>
