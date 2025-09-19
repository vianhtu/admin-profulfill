<!-- Taxonomy List Table -->
<div class="card">
    <div class="card-datatable table-responsive">
        <table class="datatables-taxonomy table border-top">
            <thead>
            <tr>
                <th></th>
                <th></th>
                <th>name</th>
                <th>status</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>

<!-- Offcanvas to add new customer -->
<div
        class="offcanvas offcanvas-end"
        tabindex="-1"
        id="offcanvasEcommerceKeywordsAdd"
        aria-labelledby="offcanvasEcommerceCustomerAddLabel">
    <div class="offcanvas-header">
        <h5 id="offcanvasEcommerceCustomerAddLabel" class="offcanvas-title">Add Keywords</h5>
        <button
                type="button"
                class="btn-close text-reset"
                data-bs-dismiss="offcanvas"
                aria-label="Close"></button>
    </div>
    <div class="offcanvas-body border-top mx-0 flex-grow-0">
        <form class="ecommerce-customer-add pt-0" id="eCommerceCustomerAddForm" onsubmit="return false">
            <div class="ecommerce-customer-add-basic mb-4">
                <h6 class="mb-6">Information</h6>
                <div class="mb-6 form-control-validation">
                    <label class="form-label" for="add-name">Name*</label>
                    <input
                            type="text"
                            class="form-control"
                            id="add-name"
                            placeholder="Apple"
                            name="customerName"
                            aria-label="Apple"/>
                </div>
                <div class="form-control-validation">
                    <label class="form-label" for="select-status">Status</label>
                    <select id="select-status" class="select2 form-select">
                        <option value="0">Select</option>
                        <option value="1">Replace</option>
                        <option value="2">Trademark</option>
                        <option value="3">Cannot be Used</option>
                    </select>
                </div>
            </div>
            <div class="d-sm-flex mb-6">
                <div class="me-auto mb-2 mb-md-0">
                    <h6 class="mb-1">Use as a billing address?</h6>
                    <small class="text-body-secondary">If you need more info, please check budget.</small>
                </div>
                <div class="form-check form-switch my-auto me-n2">
                    <input type="checkbox" class="form-check-input" checked/>
                </div>
            </div>
            <div>
                <button type="submit" class="btn btn-primary me-sm-4 data-submit">Add</button>
                <button type="reset" class="btn btn-label-danger" data-bs-dismiss="offcanvas">Discard</button>
            </div>
        </form>
    </div>
</div>