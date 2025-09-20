<?php
$menus = menuArgs();
$allRoles = ['view','add','edit','delete'];
?>
<!-- Permission Table -->
<div class="card">
    <div class="card-datatable table-responsive">
        <table class="datatables-permissions table border-top">
            <thead>
            <tr>
                <th></th>
                <th></th>
                <th>Name</th>
                <th>Assigned To</th>
                <th>Actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>
<!--/ Permission Table -->

<!-- Modal -->
<!-- Add Permission Modal -->
<div class="modal fade" id="addPermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-simple">
        <div class="modal-content">
            <div class="modal-body">
                <button
                        type="button"
                        class="btn-close btn-pinned"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                <div class="text-center mb-6">
                    <h3>Add New Permission</h3>
                    <p class="text-body-secondary">Permissions you may use and assign to your users.</p>
                </div>
                <form id="addPermissionForm" class="row" onsubmit="return false">
                    <div class="col-12 form-control-validation mb-4">
                        <label class="form-label" for="modalPermissionName">Permission Name</label>
                        <input
                                type="text"
                                id="modalPermissionName"
                                name="modalPermissionName"
                                class="form-control"
                                placeholder="Permission Name"
                                autofocus />
                    </div>
                    <div class="col-12 mb-2">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Module</th>
                                <?php foreach ($allRoles as $role): ?>
                                    <th class="text-center text-capitalize"><?= $role ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($menus as $menuName => $menuData): ?>
                                <?php
                                // Nếu có sub menu
                                if (isset($menuData['sub'])) {
                                    // Lọc sub có roles
                                    $validSubs = array_filter($menuData['sub'], fn($sub) => !empty($sub['roles']));
                                    if (empty($validSubs)) continue; // bỏ qua nếu không có sub hợp lệ
                                    ?>
                                    <tr class="table-secondary">
                                        <td colspan="<?= count($allRoles) + 1 ?>">
                                            <i class="<?= $menuData['icon'] ?>"></i> <strong><?= $menuName ?></strong>
                                        </td>
                                    </tr>
                                    <?php foreach ($validSubs as $subKey => $subData): ?>
                                        <tr>
                                            <td class="ps-4"><?= $subData['label'] ?></td>
                                            <?php foreach ($allRoles as $role): ?>
                                                <td class="text-center">
                                                    <input type="checkbox" name="permissions[<?= $subKey ?>][<?= $role ?>]"
                                                            <?= in_array($role, $subData['roles']) ? 'checked' : '' ?>>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php
                                } else {
                                    // Không có sub menu
                                    if (empty($menuData['roles'])) continue; // bỏ qua nếu không có roles
                                    ?>
                                    <tr>
                                        <td><i class="<?= $menuData['icon'] ?>"></i> <?= $menuName ?></td>
                                        <?php foreach ($allRoles as $role): ?>
                                            <td class="text-center">
                                                <input type="checkbox" name="permissions[<?= strtolower(str_replace(' ', '_', $menuName)) ?>][<?= $role ?>]"
                                                        <?= in_array($role, $menuData['roles']) ? 'checked' : '' ?>>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php } ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-12 text-center demo-vertical-spacing">
                        <button type="submit" class="btn btn-primary me-sm-4 me-1">Create Permission</button>
                        <button
                                type="reset"
                                class="btn btn-label-secondary"
                                data-bs-dismiss="modal"
                                aria-label="Close">
                            Discard
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!--/ Add Permission Modal -->

<!-- Edit Permission Modal -->
<div class="modal fade" id="editPermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-simple">
        <div class="modal-content">
            <div class="modal-body">
                <button
                        type="button"
                        class="btn-close btn-pinned"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                <div class="text-center mb-6">
                    <h3>Edit Permission</h3>
                    <p class="text-body-secondary">Edit permission as per your requirements.</p>
                </div>
                <div class="alert alert-warning" role="alert">
                    <h6 class="alert-heading mb-2">Warning</h6>
                    <p class="mb-0">
                        By editing the permission name, you might break the system permissions functionality. Please
                        ensure you're absolutely certain before proceeding.
                    </p>
                </div>
                <form id="editPermissionForm" class="row" onsubmit="return false">
                    <div class="col-sm-9 form-control-validation">
                        <label class="form-label" for="editPermissionName">Permission Name</label>
                        <input
                                type="text"
                                id="editPermissionName"
                                name="editPermissionName"
                                class="form-control"
                                placeholder="Permission Name"
                                tabindex="-1" />
                    </div>
                    <div class="col-sm-3 mb-4">
                        <label class="form-label invisible d-none d-sm-inline-block">Button</label>
                        <button type="submit" class="btn btn-primary mt-1 mt-sm-0">Update</button>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="editCorePermission" />
                            <label class="form-check-label" for="editCorePermission"> Set as core permission </label>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
