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
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasEcommerceKeywordsAdd" aria-labelledby="offcanvasEcommerceCustomerAddLabel">
    <div class="offcanvas-header">
        <h5 id="offcanvasEcommerceCustomerAddLabel" class="offcanvas-title">Add Keywords</h5>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body border-top mx-0 flex-grow-0">
        <form class="ecommerce-customer-add pt-0" id="eCommerceCustomerAddForm" onsubmit="return false">
            <div class="mb-6 pt-4">
                <h6 class="mb-6">Information</h6>
                <div class="mb-6 form-control-validation">
                    <label class="form-label" for="add-name">Name*</label>
                    <textarea class="form-control" id="add-name" placeholder="Nhập tên từ khóa mỗi dòng một từ" rows="15"></textarea>
                </div>
                <div class="form-control-validation">
                    <label class="form-label" for="select-status">Status*</label>
                    <select id="select-status" class="select2 form-select">
                        <option value="0">Select</option>
                        <option value="1">Replace</option>
                        <option value="2">Trademark</option>
                        <option value="3">Cannot be Used</option>
                    </select>
                </div>
            </div>
            <div>
                <button type="submit" class="btn btn-primary me-sm-4 data-submit">Add</button>
                <button type="reset" class="btn btn-label-danger" data-bs-dismiss="offcanvas">Discard</button>
            </div>
        </form>
    </div>
</div>