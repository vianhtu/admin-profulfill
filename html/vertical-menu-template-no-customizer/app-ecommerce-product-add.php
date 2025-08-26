<?php
$options = getProductTableFilters();
?>
<div class="app-ecommerce">
    <!-- Add Product -->
    <div
            class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-6 row-gap-4">
        <div class="d-flex flex-column justify-content-center">
            <h4 class="mb-1">Add a new</h4>
            <p class="mb-0">setup .xlsx file & default config.</p>
        </div>
        <div class="d-flex align-content-center flex-wrap gap-4">
            <div class="d-flex gap-4">
                <button class="btn btn-label-secondary">Discard</button>
                <button class="btn btn-label-primary">Save draft</button>
            </div>
            <button type="submit" class="btn btn-primary">Publish</button>
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
                        <label class="form-label" for="ecommerce-product-name">Name</label>
                        <input
                                type="text"
                                class="form-control"
                                id="ecommerce-product-name"
                                placeholder="Product title"
                                name="productTitle"
                                aria-label="Product title" />
                    </div>
                </div>
            </div>
            <!-- /Product Information -->
            <!-- Media -->
            <div class="card mb-6">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 card-title">File .xlsx</h5>
                </div>
                <div class="card-body">
                    <form action="/upload" class="dropzone needsclick p-0" id="dropzone-basic">
                        <div class="dz-message needsclick">
                            <p class="h4 needsclick pt-3 mb-2">Drag and drop your .xlsx file here</p>
                            <p class="h6 text-body-secondary d-block fw-normal mb-2">or</p>
                            <span class="needsclick btn btn-sm btn-label-primary" id="btnBrowse">Browse file</span>
                        </div>
                        <div class="fallback">
                            <input name="file" type="file" />
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
                                                type="number"
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
                    <div class="mb-6 export_accounts"></div>
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
                        <?php renderSelect('export_type', 'Type', $options['types']); ?>
                    </div>
                    <!-- Site -->
                    <div class="mb-6 col ecommerce-select2-dropdown">
                        <?php renderSelect('export_site', 'Site', $options['sites']); ?>
                    </div>
                    <!-- authors -->
                    <div class="mb-6 col ecommerce-select2-dropdown">
                        <?php renderSelect('export_author', 'Author', $options['authors']); ?>
                    </div>
                </div>
            </div>
            <!-- /Organize Card -->
        </div>
        <!-- /Second column -->
    </div>
</div>