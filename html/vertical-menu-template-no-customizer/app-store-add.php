<?php
if(!checkRoles(['add', 'edit'], 'exports_xlsx')){
    return;
}
$options = getStoresTableFilters();
$get_id = $_GET['id'] ?? '';
$export_data = getXlsxByID($get_id);
$export_id = '';
$site_id = '';
$team_id = '';
$account = [];
$account_id = '';
$status = [1 => ['title'=>'Active'], 2 => ['title'=>'Review'], 3 => ['title'=>'Inactive']];
$status_id = '';
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
    $team_id = (int)$export_data['type_id'];
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
            <p class="mb-0">Account information.</p>
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
                    <h5 class="card-tile mb-0">Owner</h5>
                </div>
                <div class="card-body">
                    <div class="mb-6">
                        <label class="form-label" for="owner_name">Name</label>
                        <input
                                type="text"
                                class="form-control"
                                id="owner_name"
                                placeholder="Owner title"
                                name="owner_name"
                                aria-label="Owner title" />
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="owner_address">Address</label>
                        <input
                                type="text"
                                class="form-control"
                                id="owner_address"
                                placeholder="Owner Address"
                                name="owner_address"
                                aria-label="Owner Address" />
                    </div>
                    <div class="row mb-6">
                        <div class="col">
                            <label class="form-label" for="owner_dob">DOB</label>
                            <input
                                    type="text"
                                    class="form-control"
                                    id="owner_dob"
                                    name="owner_dob"
                                    placeholder="yyyy-mm-dd"
                                    aria-label="Owner DOB" />
                        </div>
                        <div class="col">
                            <label class="form-label" for="owner_ssn">SSN</label>
                            <input
                                    type="text"
                                    class="form-control"
                                    id="owner_ssn"
                                    name="owner_ssn"
                                    placeholder="000-00-0000"
                                    aria-label="Owner SSN" />
                        </div>
                        <div class="col">
                            <label class="form-label" for="owner_phone">Phone</label>
                            <input
                                    type="text"
                                    class="form-control"
                                    id="owner_phone"
                                    name="owner_phone"
                                    placeholder="(000)-000-0000"
                                    aria-label="Owner Phone" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-tile mb-0">Account</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-6">
                        <div class="col">
                            <label class="form-label" for="account_sku">SKU</label>
                            <input
                                    type="text"
                                    class="form-control"
                                    id="account_sku"
                                    placeholder="SKU"
                                    name="account_sku"
                                    aria-label="Account SKU" />
                        </div>
                        <div class="col">
                            <label class="form-label" for="account_id">ID</label>
                            <input
                                    type="text"
                                    class="form-control"
                                    id="account_id"
                                    name="account_id"
                                    aria-label="Account ID" />
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col">
                            <label class="form-label" for="account_password">Password</label>
                            <input
                                    type="password"
                                    class="form-control"
                                    id="account_password"
                                    placeholder="*********"
                                    name="account_password"
                                    aria-label="Account Password" />
                        </div>
                        <div class="col">
                            <label class="form-label" for="account_2fa">2FA Code</label>
                            <input
                                    type="password"
                                    class="form-control"
                                    id="account_2fa"
                                    name="account_2fa"
                                    placeholder="***************************"
                                    aria-label="Account 2FA" />
                        </div>
                    </div>
                </div>
            </div>
            <!-- Media -->
            <div class="card mb-6">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 card-title" id="fileTitle">Files</h5>
                </div>
                <div class="card-body">
                    <div class="dropzone needsclick p-0" id="dropzone-basic">
                        <div class="dz-message needsclick form-control-validation">
                            <p class="h4 needsclick pt-3 mb-2">Drag and drop your file here</p>
                            <p class="h6 text-body-secondary d-block fw-normal mb-2">or</p>
                            <span class="needsclick btn btn-sm btn-label-primary" id="btnBrowse">Browse file</span>
                        </div>
                        <div class="fallback">
                            <input name="file" type="file" required/>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /Media -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Custom Fields</h5>
                </div>
                <div class="card-body">
                    <div class="form-repeater">
                        <div data-repeater-list="group-a">
                            <?php foreach ($file_default as $key => $value): ?>
                                <div data-repeater-item>
                                    <div class="row g-sm-6 mb-6 align-items-end">
                                        <div class="col-sm-4">
                                            <input
                                                    type="text"
                                                    name="custom_key"
                                                    class="form-control"
                                                    value="<?= htmlspecialchars($value['text']); ?>"
                                                    placeholder="Enter key" />
                                        </div>
                                        <div class="col-sm-7">
                                            <input
                                                    type="text"
                                                    name="custom_value"
                                                    class="form-control"
                                                    value="<?= htmlspecialchars($value['value']); ?>"
                                                    placeholder="Enter value" />
                                        </div>
                                        <div class="col-sm-1">
                                            <div class="d-flex align-items-center">
                                                <button type="button" data-repeater-delete class="btn btn-text-secondary rounded-pill btn-icon">
                                                    <i class="icon-base ti tabler-trash icon-22px"></i>
                                                </button>
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
        </div>
        <!-- /Second column -->

        <!-- Second column -->
        <div class="col-12 col-lg-4">
            <!-- Pricing Card -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Account Settings</h5>
                </div>
                <div class="card-body">
                    <div class="mb-6 form-control-validation accounts_status">
                        <?php renderSelect('accounts_status', 'Status', $status, $status_id); ?>
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
                    <!-- Site -->
                    <div class="mb-6 form-control-validation col ecommerce-select2-dropdown">
                        <?php renderSelect('account_site', 'Site', $options['sites'], $site_id); ?>
                    </div>
                    <!-- Team -->
                    <div class="mb-6 form-control-validation col ecommerce-select2-dropdown">
                        <?php renderSelect('account_team', 'Team', $options['teams'], $team_id); ?>
                    </div>
                    <!-- authors -->
                    <div class="mb-6 col ecommerce-select2-dropdown">
                        <?php renderSelect('account_author', 'Author', $options['authors'], $authors_id); ?>
                    </div>
                </div>
            </div>
            <!-- /Organize Card -->
            <!-- Link to Accounts Card -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Link To Accounts</h5>
                </div>
                <div class="card-body">
                    <div class="mb-6 col ecommerce-select2-dropdown">
                        <?php renderSelect('link_accounts', 'Accounts', [], null, true); ?>
                    </div>
                </div>
            </div>
            <!-- /Link to Accounts Card -->
            <!-- Note Card -->
            <div class="card mb-6">
                <div class="card-header">
                    <h5 class="card-title mb-0">Note</h5>
                </div>
                <div class="card-body">
                    <label for="account_note" class="d-none"></label>
                    <textarea class="form-control" name="account_note" id="account_note" rows="10" placeholder="Enter a note..."></textarea>
                </div>
            </div>
            <!-- /Note Card -->
        </div>
        <!-- /Second column -->
    </div>
    </form>
</div>