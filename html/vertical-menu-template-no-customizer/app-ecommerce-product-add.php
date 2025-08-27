<?php
$options = getProductTableFilters();
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
if(!empty($export_data)){
    $export_id = $export_data['ID'];
    $site_id = $export_data['site_id'];
    $type_id = $export_data['type_id'];
    $account_id = $export_data['accounts_id'];
    $authors_id = $export_data['authors_id'];
    $name = $export_data['name'];
    $file_name = $export_data['file_name'];
    $text_add = 'Edit';
    $text_button = 'Update';
    $account = getAccountsByID($account_id);
    $xlsxDir = ROOT_DIR . '/xlsx/'.$export_data['file_dir']; var_dump($xlsxDir);
    $file_header = getXlsxFileHeader(realpath($xlsxDir));
}
?>
<div class="app-ecommerce">
    <input type="hidden" id="export_id" value="<?= $export_id ?>">
    <!-- Add Product -->
    <div
            class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-6 row-gap-4">
        <div class="d-flex flex-column justify-content-center">
            <h4 class="mb-1"><?= $text_add ?></h4>
            <p class="mb-0">setup .xlsx file & default config.</p>
        </div>
        <div class="d-flex align-content-center flex-wrap gap-4">
            <div class="d-flex gap-4">
                <button class="btn btn-label-secondary">Discard</button>
            </div>
            <button id="export_submit" class="btn btn-primary waves-effect waves-light"><span class="spinner-border spinner-border-sm me-2 d-none" role="status" id="loading_spinner"></span><?= $text_button ?></button>
        </div>
    </div>

    <div class="row">
        <!-- First column-->
        <div class="col-12 col-lg-8">
            <!-- Product Information -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-tile mb-0">Information</h5>
                </div>
                <div class="card-body">
                    <div class="mb-6">
                        <label class="form-label" for="export-name">Name</label>
                        <input
                                type="text"
                                class="form-control"
                                id="export-name"
                                placeholder="Product title"
                                name="productTitle"
                                value="<?= $name ?>"
                                aria-label="Product title" required/>
                    </div>
                </div>
            </div>
            <!-- /Product Information -->
            <!-- Media -->
            <div class="card mb-6">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 card-title">File: <?= $file_name ?></h5>
                </div>
                <div class="card-body">
                    <form action="/upload" class="dropzone needsclick p-0" id="dropzone-basic">
                        <div class="dz-message needsclick">
                            <p class="h4 needsclick pt-3 mb-2">Drag and drop your .xlsx file here</p>
                            <p class="h6 text-body-secondary d-block fw-normal mb-2">or</p>
                            <span class="needsclick btn btn-sm btn-label-primary" id="btnBrowse">Browse file</span>
                        </div>
                        <div class="fallback">
                            <input name="file" type="file" required/>
                        </div>
                    </form>
                </div>
            </div>
            <!-- /Media -->
            <!-- Variants -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Default</h5>
                </div>
                <div class="card-body">
                    <form class="form-repeater">
                        <div data-repeater-list="group-a">
                            <div data-repeater-item>
                                <div class="row g-sm-6 mb-6">
                                    <div class="col-sm-4">
                                        <label class="form-label" for="form-repeater-1-1">Options</label>
                                        <select id="form-repeater-1-1" class="select2 form-select" data-placeholder="Size">
                                            <option value="">Size</option>
                                            <option value="size">Size</option>
                                            <option value="color">Color</option>
                                            <option value="weight">Weight</option>
                                            <option value="smell">Smell</option>
                                        </select>
                                    </div>

                                    <div class="col-sm-8">
                                        <label class="form-label invisible" for="form-repeater-1-2">Not visible</label>
                                        <input
                                                type="text"
                                                id="form-repeater-1-2"
                                                class="form-control"
                                                placeholder="Enter size" />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <button class="btn btn-primary" data-repeater-create>
                                <i class="icon-base ti tabler-plus icon-xs me-2"></i>
                                Add another option
                            </button>
                        </div>
                    </form>
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
                    <div class="mb-6 export_accounts">
                        <?php renderSelect('accountsExport', 'Select Account', $account, $account_id); ?>
                    </div>
                </div>
            </div>
            <!-- /Pricing Card -->
            <!-- Organize Card -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Organize</h5>
                </div>
                <div class="card-body">
                    <!-- Type -->
                    <div class="mb-6 col ecommerce-select2-dropdown">
                        <?php renderSelect('export_type', 'Type', $options['types'], $type_id); ?>
                    </div>
                    <!-- Site -->
                    <div class="mb-6 col ecommerce-select2-dropdown">
                        <?php renderSelect('export_site', 'Site', $options['sites'], $site_id); ?>
                    </div>
                    <!-- authors -->
                    <div class="mb-6 col ecommerce-select2-dropdown">
                        <?php renderSelect('export_author', 'Author', $options['authors'], $authors_id); ?>
                    </div>
                </div>
            </div>
            <!-- /Organize Card -->
        </div>
        <!-- /Second column -->
    </div>
</div>