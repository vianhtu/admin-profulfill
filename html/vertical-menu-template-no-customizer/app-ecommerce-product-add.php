<?php
$get_id = (int)($_GET['id'] ?? 0);
$isEdit = $get_id > 0;

if (!checkRoles($isEdit ? 'edit' : 'add', 'products')) {
    return;
}

// Dữ liệu mặc định cho form Add
$defaultData = [
    'ID'          => '',
    'title'       => '',
    'description' => '',
    'sku'         => '',
    'badge'       => '',
    'status'      => 'pending',
    'type_id'     => '',
    'site_id'     => '',
    'store_id'    => '',
    'store_name'  => '',
    'image_list'  => [''],
    'tags'        => [],
    'ui'          => ['title' => 'Add a new', 'button' => 'Add'],
];

$edit_data = $isEdit ? Product::get_product($get_id) : [];
if ($isEdit && empty($edit_data)) {
    // Ngoài phạm vi dữ liệu hoặc không tồn tại
    return;
}
if (!empty($edit_data)) {
    $edit_data['ui'] = ['title' => 'Edit', 'button' => 'Update'];
}

$d = array_merge($defaultData, $edit_data ?: []);
if (empty($d['image_list'])) {
    $d['image_list'] = [''];
}

$statusOptions = [
    'pending'   => ['title' => 'Pending'],
    'schedule'  => ['title' => 'Schedule'],
    'listed'    => ['title' => 'Listed'],
    'inactive'  => ['title' => 'Inactive'],
    'trademark' => ['title' => 'Trademark'],
];
$options = Products::get_products_filters();
?>
<div class="app-ecommerce">
    <form id="productForm" onsubmit="return false">
        <input type="hidden" id="product_id" value="<?= (int)$d['ID'] ?>">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-6 row-gap-4">
            <div class="d-flex flex-column justify-content-center">
                <h4 class="mb-1"><?= htmlspecialchars($d['ui']['title']) ?> product</h4>
                <p class="mb-0">Product information.</p>
            </div>
            <div class="d-flex align-content-center flex-wrap gap-4">
                <div class="d-flex gap-4">
                    <a href="index.php?menu=products" class="btn btn-label-secondary">Discard</a>
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
                        <h5 class="card-tile mb-0">Product information</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-6 form-control-validation">
                            <label class="form-label" for="product_title">Title</label>
                            <input type="text" class="form-control" id="product_title" name="product_title"
                                   placeholder="Product title" value="<?= htmlspecialchars($d['title']) ?>" />
                        </div>
                        <div class="row mb-6">
                            <div class="col form-control-validation">
                                <label class="form-label" for="product_sku">SKU</label>
                                <input type="text" class="form-control" id="product_sku" name="product_sku"
                                       placeholder="Marketplace listing ID" value="<?= htmlspecialchars($d['sku']) ?>" />
                            </div>
                            <div class="col">
                                <label class="form-label" for="product_badge">Badge</label>
                                <input type="text" class="form-control" id="product_badge" name="product_badge"
                                       placeholder="hot, best seller..." value="<?= htmlspecialchars((string)$d['badge']) ?>" />
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Description</label>
                            <div id="snow-toolbar">
                                <span class="ql-formats">
                                    <select class="ql-font"></select>
                                    <select class="ql-size"></select>
                                </span>
                                <span class="ql-formats">
                                    <button class="ql-bold"></button>
                                    <button class="ql-italic"></button>
                                    <button class="ql-underline"></button>
                                    <button class="ql-strike"></button>
                                </span>
                                <span class="ql-formats">
                                    <select class="ql-color"></select>
                                    <select class="ql-background"></select>
                                </span>
                                <span class="ql-formats">
                                    <button class="ql-script" value="sub"></button>
                                    <button class="ql-script" value="super"></button>
                                </span>
                                <span class="ql-formats">
                                    <button class="ql-header" value="1"></button>
                                    <button class="ql-header" value="2"></button>
                                    <button class="ql-blockquote"></button>
                                    <button class="ql-code-block"></button>
                                </span>
                                <span class="ql-formats">
                                    <button class="ql-list" value="ordered"></button>
                                    <button class="ql-list" value="bullet"></button>
                                    <button class="ql-link"></button>
                                    <button class="ql-clean"></button>
                                </span>
                            </div>
                            <div id="snow-editor"><?= $d['description'] ?></div>
                        </div>
                    </div>
                </div>

                <!-- Images -->
                <div class="card mb-6">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Images</h5>
                        <small class="text-body-secondary">The first image is the main one</small>
                    </div>
                    <div class="card-body">
                        <!-- Ô ẩn để FormValidation báo lỗi ngay trên form khi chưa có ảnh -->
                        <div class="form-control-validation mb-0">
                            <input type="hidden" id="images_state" name="images_state" value="<?= $d['image_list'][0] !== '' ? '1' : '' ?>">
                        </div>
                        <div class="form-repeater" id="imageRepeater">
                            <div data-repeater-list="images">
                                <?php foreach ($d['image_list'] as $url): ?>
                                    <div data-repeater-item>
                                        <div class="row g-sm-6 mb-6 align-items-center">
                                            <div class="col-sm-2">
                                                <div class="avatar avatar-xl rounded-2 bg-label-secondary">
                                                    <img src="<?= htmlspecialchars($url) ?>" class="rounded img-preview<?= $url === '' ? ' d-none' : '' ?>" alt="">
                                                </div>
                                            </div>
                                            <div class="col-sm-9">
                                                <input type="text" name="image_url" class="form-control image_url"
                                                       value="<?= htmlspecialchars($url) ?>" placeholder="https://..." />
                                            </div>
                                            <div class="col-sm-1">
                                                <button type="button" data-repeater-delete class="btn btn-text-secondary rounded-pill btn-icon">
                                                    <i class="icon-base ti tabler-trash icon-22px"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div>
                                <button type="button" class="btn btn-primary" data-repeater-create>
                                    <i class="icon-base ti tabler-plus icon-xs me-2"></i>Add another image
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tags -->
                <div class="card mb-6">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Tags</h5>
                    </div>
                    <div class="card-body">
                        <input id="product_tags" class="form-control" name="product_tags"
                               value="<?= htmlspecialchars(implode(',', (array)$d['tags'])) ?>" />
                    </div>
                </div>
            </div>
            <!-- /First column -->

            <!-- Second column -->
            <div class="col-12 col-lg-4">
                <div class="card mb-6">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2 form-control-validation product_status_box">
                            <?php render_select('product_status', 'Status', $statusOptions, $d['status']); ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-6">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Organize</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-6 form-control-validation product_category_box">
                            <?php render_select('product_type', 'Category', $options['types'], $d['type_id']); ?>
                        </div>
                        <div class="mb-6 form-control-validation product_site_box">
                            <?php render_select('product_site', 'Site', $options['sites'], $d['site_id']); ?>
                        </div>
                        <div class="mb-6 form-control-validation product_store_box">
                            <label class="form-label" for="product_store">Store</label>
                            <select id="product_store" name="product_store">
                                <?php if (!empty($d['store_id'])): ?>
                                    <option value="<?= (int)$d['store_id'] ?>" selected>
                                        <?= htmlspecialchars(($d['site_name'] ?? '') . ' (' . ($d['store_name'] ?? '') . ')') ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <?php if (is_admin() || is_manager()):
                            // Admin/Manager được gán sản phẩm cho thành viên khác;
                            // user thường luôn là chính họ nên không hiện ô này.
                            $curAuthorId = (int)($d['author_id'] ?? ($_SESSION['auth']['user_id'] ?? 0));
                            $curAuthorName = get_field_by_id('authors', 'username', $curAuthorId) ?? '';
                            ?>
                            <div class="mb-2 form-control-validation product_author_box">
                                <label class="form-label" for="product_author">Manager</label>
                                <select id="product_author" name="product_author">
                                    <?php if ($curAuthorId): ?>
                                        <option value="<?= $curAuthorId ?>" selected><?= htmlspecialchars($curAuthorName) ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- /Second column -->
        </div>
    </form>
</div>
