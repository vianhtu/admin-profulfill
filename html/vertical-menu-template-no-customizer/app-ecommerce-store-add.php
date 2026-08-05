<?php
$get_id = (int)($_GET['id'] ?? 0);
$isEdit = $get_id > 0;

// Thêm mới: theo role. Sửa: theo chủ sở hữu của chính store đó
// (store dùng chung -> chỉ admin; store riêng -> team sở hữu).
if (!$isEdit && !Stores::can_add()) {
    return;
}

$defaultData = [
    'ID'      => '',
    'name'    => '',
    'slug'    => '',
    'site_id' => '',
    'status'  => 1,
    'team_id' => is_admin() ? 0 : (int)($_SESSION['auth']['team'] ?? 0),
    'ui'      => ['title' => 'Add a new', 'button' => 'Add'],
];

$edit_data = $isEdit ? Store::get_store($get_id) : [];
if ($isEdit && (empty($edit_data) || !Stores::can_edit_row((int)$edit_data['team_id']))) {
    return;
}
if (!empty($edit_data)) {
    $edit_data['ui'] = ['title' => 'Edit', 'button' => 'Update'];
}
$d = array_merge($defaultData, $edit_data ?: []);

$statusOptions = [1 => ['title' => 'Active'], 0 => ['title' => 'Inactive']];
$sites = get_all_sites();

// Chủ sở hữu: 0 = dùng chung cho mọi team. Chỉ admin được chọn.
$teamOptions = [0 => ['title' => 'Shared (all teams)']] + get_data_map('team', 'name');
$ownTeamName = is_admin() ? '' : (string)get_field_by_id('team', 'name', (int)$d['team_id']);
?>
<div class="app-ecommerce">
    <form id="storeForm" onsubmit="return false">
        <input type="hidden" id="store_id" value="<?= (int)$d['ID'] ?>">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-6 row-gap-4">
            <div class="d-flex flex-column justify-content-center">
                <h4 class="mb-1"><?= htmlspecialchars($d['ui']['title']) ?> store</h4>
                <p class="mb-0">A shop on a marketplace — shared by every team, or private to one team.</p>
            </div>
            <div class="d-flex align-content-center flex-wrap gap-4">
                <div class="d-flex gap-4">
                    <a href="index.php?menu=store" class="btn btn-label-secondary">Discard</a>
                </div>
                <button type="submit" id="form_submit" class="btn btn-primary waves-effect waves-light">
                    <span class="spinner-border spinner-border-sm me-2 d-none" role="status" id="loading_spinner"></span><?= htmlspecialchars($d['ui']['button']) ?>
                </button>
            </div>
        </div>

        <div class="row">
            <div class="col-12 col-lg-8">
                <div class="card mb-6">
                    <div class="card-header">
                        <h5 class="card-tile mb-0">Store information</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-6 form-control-validation">
                            <label class="form-label" for="store_name">Name</label>
                            <input type="text" class="form-control" id="store_name" name="store_name"
                                   placeholder="0FineArtPrint" value="<?= htmlspecialchars($d['name']) ?>" />
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="store_slug">Slug</label>
                            <input type="text" class="form-control" id="store_slug" name="store_slug"
                                   placeholder="0fineartprint" value="<?= htmlspecialchars($d['slug']) ?>" />
                            <small class="text-body-secondary">
                                Unique across the whole system — this is what stops the same shop being added twice.
                                Leave empty to generate from the name.
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card mb-6">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Organize</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-6 form-control-validation store_site_box">
                            <?php render_select('store_site', 'Site', $sites, $d['site_id']); ?>
                        </div>
                        <div class="mb-6 form-control-validation store_status_box">
                            <?php render_select('store_status', 'Status', $statusOptions, (int)$d['status']); ?>
                        </div>
                        <div class="mb-2">
                            <?php if (is_admin()): ?>
                                <?php render_select('store_team', 'Owner', $teamOptions, (int)$d['team_id']); ?>
                                <small class="text-body-secondary">
                                    Shared stores can be used by every team but only an admin can change them.
                                    Pick a team to make this store private to that team.
                                </small>
                            <?php else: ?>
                                <label class="form-label mb-1">Owner</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($ownTeamName) ?>" disabled>
                                <small class="text-body-secondary">This store belongs to your team; only your team can see and change it.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
