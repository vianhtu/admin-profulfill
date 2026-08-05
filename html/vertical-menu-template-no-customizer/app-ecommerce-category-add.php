<?php
$get_id = (int)($_GET['id'] ?? 0);
$isEdit = $get_id > 0;

// Thêm mới: theo role add. Sửa: admin, hoặc chính người đã thêm category đó
// (category dùng chung toàn hệ thống — xem class.categories.php).
if (!$isEdit && !Categories::can_add()) {
    return;
}

$defaultData = [
    'ID'          => '',
    'name'        => '',
    'user_prompt' => '',
    'created_by'  => 0,
    'ui'          => ['title' => 'Add a new', 'button' => 'Add'],
];

$edit_data = $isEdit ? Category::get_category($get_id) : [];
if ($isEdit && (empty($edit_data) || !Categories::can_edit_row((int)$edit_data['created_by']))) {
    return;
}
if (!empty($edit_data)) {
    $edit_data['ui'] = ['title' => 'Edit', 'button' => 'Update'];
}
$d = array_merge($defaultData, $edit_data ?: []);
?>
<div class="app-ecommerce">
    <form id="categoryForm" onsubmit="return false">
        <input type="hidden" id="category_id" value="<?= (int)$d['ID'] ?>">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-6 row-gap-4">
            <div class="d-flex flex-column justify-content-center">
                <h4 class="mb-1"><?= htmlspecialchars($d['ui']['title']) ?> category</h4>
                <p class="mb-0">Categories are shared by every team.</p>
            </div>
            <div class="d-flex align-content-center flex-wrap gap-4">
                <div class="d-flex gap-4">
                    <a href="index.php?menu=categories" class="btn btn-label-secondary">Discard</a>
                </div>
                <button type="submit" id="form_submit" class="btn btn-primary waves-effect waves-light">
                    <span class="spinner-border spinner-border-sm me-2 d-none" role="status" id="loading_spinner"></span><?= htmlspecialchars($d['ui']['button']) ?>
                </button>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card mb-6">
                    <div class="card-header">
                        <h5 class="card-tile mb-0">Category information</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-6 form-control-validation">
                            <label class="form-label" for="category_name">Name</label>
                            <input type="text" class="form-control" id="category_name" name="category_name"
                                   placeholder="Dress, Hoodies..." value="<?= htmlspecialchars($d['name']) ?>" />
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="category_prompt">AI prompt</label>
                            <textarea class="form-control" id="category_prompt" name="category_prompt" rows="8"
                                      placeholder="Prompt used when generating content for this category..."><?= htmlspecialchars((string)$d['user_prompt']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>
