<?php
if(!checkRoles('view', 'exports_xlsx')){
    return;
}
?>
<!-- Users List Table -->
<div class="card">
    <div class="card-header border-bottom">
        <h5 class="card-title mb-0">Filters</h5>
        <div class="d-flex justify-content-between align-items-center row pt-4 gap-4 gap-md-0">
            <div class="col-md-4 filter-sites"></div>
            <div class="col-md-4 filter-teams"></div>
            <div class="col-md-4 filter-authors"></div>
        </div>
    </div>
    <div class="card-datatable">
        <table class="datatables-users table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>Stores</th>
                <th>Teams</th>
                <th>Authors</th>
                <th>Status</th>
                <th>Date</th>
                <th id="financial-header">Funds</th>
                <th id="fees-header">Fees</th>
                <th>Actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>