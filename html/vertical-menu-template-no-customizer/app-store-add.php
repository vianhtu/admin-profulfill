<?php
if(!checkRoles(['add', 'edit'], 'exports_xlsx')){
    return;
}
$options = getProductsTableFilters();
$get_id = $_GET['id'] ?? '';
$export_data = getXlsxByID($get_id);
$export_id = '';
$site_id = '';
$type_id = '';
$account = [];
$account_id = '';
$authors_id = '';
$name = '';
$file_name = '';
$text_add = 'Add a new';
$text_button = 'Add';
$file_header = [];
$file_tabs = [];
$file_default = [['location'=>'', 'text'=>'', 'value'=>'']];
$row_header = '';
$row_item = '';
$sheet_name = '';
$repeater_count = 0;
if(!empty($export_data)){
    $export_id = (int)$export_data['ID'];
    $site_id = (int)$export_data['site_id'];
    $type_id = (int)$export_data['type_id'];
    $account_id = (int)$export_data['accounts_id'];
    $authors_id = (int)$export_data['authors_id'];
    $file_name = $export_data['file_name'];
    $row_header = (int)$export_data['row_header'];
    $row_item = (int)$export_data['row_item'];
    $sheet_name = $export_data['sheet_name'] ?? '';
    $text_add = 'Edit';
    $text_button = 'Update';
    $account = getAccountsByID($account_id);
    $xlsxDir = ROOT_DIR . '/xlsx/'.$export_data['file_dir'];
    $file = getXlsxFileHeader(realpath($xlsxDir), $sheet_name, $row_header);
    $file_header = $file['xlxs']['columns'] ?? [];
    $file_tabs = $file['xlxs']['tabs'] ?? [];
    if(!empty($export_data['file_default']) && $export_data['file_default'] != '[]'){
        $file_default = json_decode($export_data['file_default'], true);
        $repeater_count = count($file_default);
    }
}
?>
<div class="app-ecommerce">
    <form id="addXlsxFile" onsubmit="return false">
    <input type="hidden" id="export_id" value="<?= $export_id ?>">
    <!-- Add Product -->
    <div
            class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-6 row-gap-4">
        <div class="d-flex flex-column justify-content-center">
            <h4 class="mb-1"><?= $text_add ?></h4>
            <p class="mb-0">store info.</p>
        </div>
        <div class="d-flex align-content-center flex-wrap gap-4">
            <div class="d-flex gap-4">
                <a href="index.php?menu=stores" class="btn btn-label-secondary">Discard</a>
            </div>
            <button type="submit" id="export_submit" class="btn btn-primary waves-effect waves-light"><span class="spinner-border spinner-border-sm me-2 d-none" role="status" id="loading_spinner"></span><?= $text_button ?></button>
        </div>
    </div>

    <div class="row">
        <!-- First column-->
        <div class="col-12 col-lg-8">
            <!-- /Product Information -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-tile mb-0">Information</h5>
                </div>
                <div class="card-body">
                    <div class="mb-6">
                        <label class="form-label" for="store-name">Name</label>
                        <input
                                type="text"
                                class="form-control"
                                id="store-name"
                                placeholder="Store title"
                                name="storeName"
                                aria-label="Store title" />
                    </div>
                    <div class="row mb-6">
                        <div class="col">
                            <label class="form-label" for="store-sku">SKU (Optional)</label>
                            <input
                                    type="text"
                                    class="form-control"
                                    id="store-sku"
                                    placeholder="SKU"
                                    name="storeSku"
                                    aria-label="Store SKU" />
                        </div>
                        <div class="col">
                            <label class="form-label" for="store-id">ID (Optional)</label>
                            <input
                                    type="text"
                                    class="form-control"
                                    id="store-id"
                                    name="storeID"
                                    aria-label="Store ID" />
                        </div>
                    </div>
                    <!-- Description -->
                    <div>
                        <label class="mb-1">Description (Optional)</label>
                        <div class="form-control p-0">
                            <div class="comment-toolbar border-0 border-bottom">
                                <div class="d-flex justify-content-start">
                                <span class="ql-formats me-0">
                                  <button class="ql-bold"></button>
                                  <button class="ql-italic"></button>
                                  <button class="ql-underline"></button>
                                  <button class="ql-list" value="ordered"></button>
                                  <button class="ql-list" value="bullet"></button>
                                  <button class="ql-link"></button>
                                  <button class="ql-image"></button>
                                </span>
                                </div>
                            </div>
                            <div class="comment-editor border-0 pb-6" id="ecommerce-category-description"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Media -->
            <div class="card mb-6">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 card-title" id="fileTitle"><?= $file_name ?></h5>
                </div>
                <div class="card-body">
                    <div class="dropzone needsclick p-0" id="dropzone-basic">
                        <div class="dz-message needsclick form-control-validation">
                            <p class="h4 needsclick pt-3 mb-2">Drag and drop your .xlsx file here</p>
                            <p class="h6 text-body-secondary d-block fw-normal mb-2">or</p>
                            <span class="needsclick btn btn-sm btn-label-primary" id="btnBrowse">Browse file</span>
                            <!-- field ẩn để validation -->
                            <input type="hidden" name="xlsxFilePresent" id="xlsxFilePresent" value="<?= $file_name ?>">
                        </div>
                        <div class="fallback">
                            <input name="file" type="file" required/>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /Media -->
            <!-- Variants -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Default</h5>
                </div>
                <div class="card-body">
                    <div class="form-repeater" data-repeat-count="<?= $repeater_count ?>">
                        <div data-repeater-list="group-a">
                            <?php foreach ($file_default as $key => $value): ?>
                            <div data-repeater-item>
                                <div class="row g-sm-6 mb-6 align-items-end">
                                    <div class="col-sm-4 form-control-validation">
                                        <label class="form-label" for="form-repeater-<?= $key ?>-1">Options</label>
                                        <select name="" id="form-repeater-<?= $key ?>-1" class="select2 form-select" data-placeholder="Select a option">
                                            <option value=""></option>
                                            <?php if(!empty($file_name)): ?>
                                            <?php foreach ($file_header as $header): ?>
                                                <?php $selected = $value['text'] == $header['value'] ? ' selected' : ''; ?>
                                                <option value="<?= $header['column'] ?>"<?= $selected ?>><?= $header['value'] ?></option>
                                            <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="<?= $value['location'] ?>" selected><?= $value['text'] ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                    <div class="col-sm-7">
                                        <label class="form-label invisible" for="form-repeater-<?= $key ?>-2">Not visible</label>
                                        <input
                                                type="text"
                                                id="form-repeater-<?= $key ?>-2"
                                                class="form-control"
                                                value="<?= $value['value']; ?>"
                                                placeholder="Enter value" />
                                    </div>
                                    <div class="col-sm-1">
                                        <div class="d-flex align-items-center">
                                            <a href="javascript:;" class="btn btn-text-secondary rounded-pill waves-effect btn-icon btn-delete-row">
                                                <i class="icon-base ti tabler-trash icon-22px"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <button type="button" class="btn btn-primary" data-repeater-create>
                                <i class="icon-base ti tabler-plus icon-xs me-2"></i>
                                Add another option
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /Variants -->
        </div>
        <!-- /Second column -->

        <!-- Second column -->
        <div class="col-12 col-lg-4">
            <!-- Pricing Card -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Export Account</h5>
                </div>
                <div class="card-body">
                    <!-- Base Price -->
                    <div class="mb-6 form-control-validation export_accounts">
                        <?php renderSelect('accountsExport', 'Select Account', $account, $account_id); ?>
                    </div>
                </div>
            </div>
            <!-- /Pricing Card -->
            <!-- File Card -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">File Settings</h5>
                </div>
                <div class="card-body">
                    <div class="mb-6 form-control-validation col export_header">
                        <label class="form-label mb-1" for="export_file_header">Row Header</label>
                        <input type="number" min="0" class="form-control" id="export_file_header" name="export_file_header" placeholder="Row containing column headers." value="<?= $row_header ?>">
                    </div>
                    <div class="mb-6 form-control-validation col export_start">
                        <label class="form-label mb-1" for="export_file_start">Start Row Item</label>
                        <input type="number" min="0" class="form-control" id="export_file_start" name="export_file_start" placeholder="Write new item starting from this line." value="<?= $row_item ?>">
                    </div>
                    <?php if($file_name): ?>
                    <div class="mb-6 form-control-validation col export_sheet_name">
                        <?php renderSelect('export_sheet_name', 'Sheet Name', $file_tabs, $sheet_name); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- /File Card -->
            <!-- Organize Card -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Organize</h5>
                </div>
                <div class="card-body">
                    <!-- Type -->
                    <div class="mb-6 form-control-validation col ecommerce-select2-dropdown">
                        <?php renderSelect('export_type', 'Type', $options['types'], $type_id); ?>
                    </div>
                    <!-- Site -->
                    <div class="mb-6 form-control-validation col ecommerce-select2-dropdown">
                        <?php renderSelect('export_site', 'Site', $options['sites'], $site_id); ?>
                    </div>
                    <!-- authors -->
                    <?php if(isAdmin()): ?>
                    <div class="mb-6 col ecommerce-select2-dropdown">
                        <?php renderSelect('export_author', 'Author', $options['authors'], $authors_id); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- /Organize Card -->
        </div>
        <!-- /Second column -->
    </div>
    </form>
</div>