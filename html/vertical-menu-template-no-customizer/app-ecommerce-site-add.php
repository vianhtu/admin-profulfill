<?php
$get_id = (int)($_GET['id'] ?? 0);
$isEdit = $get_id > 0;

if (!checkRoles($isEdit ? 'edit' : 'add', 'sites')) {
    return;
}

$defaultData = [
    'ID'               => '',
    'name'             => '',
    'slug'             => '',
    'logo'             => '',
    'system_prompt'    => '',
    'developer_prompt' => '',
    'fields'           => [],
    'ui'               => ['title' => 'Add a new', 'button' => 'Add'],
];

$edit_data = $isEdit ? Site::get_site($get_id) : [];
if ($isEdit && empty($edit_data)) {
    return;
}
if (!empty($edit_data)) {
    $edit_data['ui'] = ['title' => 'Edit', 'button' => 'Update'];
}
$d = array_merge($defaultData, $edit_data ?: []);

// Luôn có sẵn 1 dòng trống để nhập custom field
$fields = $d['fields'] ?: [['text' => '', 'value' => '']];
?>
<div class="app-ecommerce">
    <form id="siteForm" onsubmit="return false">
        <input type="hidden" id="site_id" value="<?= (int)$d['ID'] ?>">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-6 row-gap-4">
            <div class="d-flex flex-column justify-content-center">
                <h4 class="mb-1"><?= htmlspecialchars($d['ui']['title']) ?> site</h4>
                <p class="mb-0">Marketplace information.</p>
            </div>
            <div class="d-flex align-content-center flex-wrap gap-4">
                <div class="d-flex gap-4">
                    <a href="index.php?menu=sites" class="btn btn-label-secondary">Discard</a>
                </div>
                <button type="submit" id="form_submit" class="btn btn-primary waves-effect waves-light">
                    <span class="spinner-border spinner-border-sm me-2 d-none" role="status" id="loading_spinner"></span><?= htmlspecialchars($d['ui']['button']) ?>
                </button>
            </div>
        </div>

        <div class="row">
            <!-- First column -->
            <div class="col-12 col-lg-8">
                <div class="card mb-6">
                    <div class="card-header">
                        <h5 class="card-tile mb-0">Site information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-6">
                            <div class="col form-control-validation">
                                <label class="form-label" for="site_name">Name</label>
                                <input type="text" class="form-control" id="site_name" name="site_name"
                                       placeholder="etsy.com" value="<?= htmlspecialchars($d['name']) ?>" />
                            </div>
                            <div class="col">
                                <label class="form-label" for="site_slug">Slug</label>
                                <input type="text" class="form-control" id="site_slug" name="site_slug"
                                       placeholder="etsy-com" value="<?= htmlspecialchars($d['slug']) ?>" />
                                <small class="text-body-secondary">Leave empty to generate from the name.</small>
                            </div>
                        </div>
                        <?php
                        // Cột logo có 2 dạng: tên file trần (ảnh có sẵn trong
                        // assets/img/icons/brands/) hoặc đường dẫn uploads/... do user tải lên
                        // JS đọc ô ẩn này để hiện sẵn ảnh trong dropzone
                        $logoVal = (string)$d['logo'];
                        ?>
                        <input type="hidden" id="site_logo" value="<?= htmlspecialchars($logoVal) ?>">
                        <div class="mb-2">
                            <label class="form-label">Logo</label>
                            <div class="dropzone needsclick p-0" id="dropzone-logo">
                                <div class="dz-message needsclick">
                                    <p class="h6 needsclick pt-3 mb-1">Drop the logo here or click to browse</p>
                                    <p class="small text-body-secondary d-block mb-2">PNG or JPG — resized to 96x96px automatically</p>
                                    <span class="needsclick btn btn-sm btn-label-primary">Browse file</span>
                                </div>
                                <div class="fallback"><input name="file" type="file" accept=".png,.jpg,.jpeg"/></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Custom fields: giá trị mặc định khi tạo Store của site này -->
                <div class="card mb-6">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Custom Fields</h5>
                        <small class="text-body-secondary">Default fields when adding a store of this site</small>
                    </div>
                    <div class="card-body">
                        <div class="form-repeater" id="fieldRepeater">
                            <div data-repeater-list="fields">
                                <?php foreach ($fields as $f): ?>
                                    <div data-repeater-item>
                                        <div class="row g-sm-6 mb-6 align-items-end">
                                            <div class="col-sm-4">
                                                <input type="text" name="field_text" class="form-control field_text"
                                                       value="<?= htmlspecialchars($f['text']) ?>" placeholder="Enter text" />
                                            </div>
                                            <div class="col-sm-7">
                                                <input type="text" name="field_value" class="form-control field_value"
                                                       value="<?= htmlspecialchars($f['value']) ?>" placeholder="Enter value" />
                                            </div>
                                            <div class="col-sm-1">
                                                <button type="button" data-repeater-delete class="btn btn-text-danger rounded-pill btn-icon">
                                                    <i class="icon-base ti tabler-trash icon-22px"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div>
                                <button type="button" class="btn btn-primary" data-repeater-create>
                                    <i class="icon-base ti tabler-plus icon-xs me-2"></i>Add another field
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /First column -->

            <!-- Second column -->
            <div class="col-12 col-lg-4">
                <div class="card mb-6">
                    <div class="card-header">
                        <h5 class="card-title mb-0">AI prompts</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-6">
                            <label class="form-label" for="site_system_prompt">System prompt</label>
                            <textarea class="form-control" id="site_system_prompt" name="site_system_prompt" rows="8"
                                      placeholder="System prompt..."><?= htmlspecialchars((string)$d['system_prompt']) ?></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="site_developer_prompt">Developer prompt</label>
                            <textarea class="form-control" id="site_developer_prompt" name="site_developer_prompt" rows="8"
                                      placeholder="Developer prompt..."><?= htmlspecialchars((string)$d['developer_prompt']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /Second column -->
        </div>
    </form>
</div>
