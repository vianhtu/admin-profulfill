/**
 * app-ecommerce-store-list
 */

'use strict';

let sitesObj = {};
let teamsObj = {};
let dtStores = null;
let storePerms = { add: false, delete: false, is_admin: false };

async function init() {
    try {
        const options = await fetchTableFilter('get-store-table-filter');
        sitesObj = options['sites'] ?? {};
        teamsObj = options['teams'] ?? {};
        storePerms = options['perms'] ?? storePerms;
        initStoreTable();
    } catch (err) {
        alert('Failed to load store options');
    }
}

// logo site lưu 2 dạng: 'uploads/...' hoặc tên file trong assets/img/icons/brands/
function siteLogoUrl(logo) {
    return logo.includes('/') ? '../../' + logo : '../../assets/img/icons/brands/' + logo;
}

function initStoreTable() {
    const el = document.querySelector('.datatables-stores');
    if (!el) {
        return;
    }

    const dt = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: '../../ajax.php?action=get-store-table',
            type: 'POST',
            data: function (d) {
                d.site = $('#siteFilter').val() || '';
                d.status = $('#statusFilter').val() || '';
                d.team = $('#teamFilter').val() ?? '';
            },
            dataSrc: json => json.data
        },
        columns: [
            { data: 'id' },
            { data: 'id', orderable: false, render: DataTable.render.select() },
            { data: 'name' },
            { data: 'slug' },
            { data: 'site_name' },
            { data: 'team_name' },
            { data: 'products_count' },
            { data: 'status' },
            { data: 'id' }
        ],
        columnDefs: [
            { className: 'control', searchable: false, orderable: false, responsivePriority: 2, targets: 0, render: () => '' },
            {
                targets: 1, orderable: false, searchable: false, responsivePriority: 3,
                checkboxes: { selectAllRender: '<input type="checkbox" class="form-check-input">' },
                render: () => '<input type="checkbox" class="dt-checkboxes form-check-input">'
            },
            {
                targets: 2, responsivePriority: 1,
                render: (d, t, full) => `<h6 class="text-nowrap mb-0">${full['name']}</h6>`
            },
            { targets: 3, render: (d, t, full) => `<span class="text-body-secondary">${full['slug']}</span>` },
            {
                targets: 4,
                render: function (d, t, full) {
                    if (!full['site_name']) {
                        return '<span class="text-body-secondary">—</span>';
                    }
                    const logo = full['site_logo']
                        ? `<div class="avatar avatar-sm me-2 rounded-2 bg-label-secondary"><img src="${siteLogoUrl(full['site_logo'])}" alt="" class="rounded"></div>`
                        : '';
                    return `<div class="d-flex align-items-center">${logo}<span>${full['site_name']}</span></div>`;
                }
            },
            {
                // Owner: chưa gán team = dùng chung, ngược lại là store riêng của team
                targets: 5,
                render: (d, t, full) => full['team_id'] > 0
                    ? `<span class="badge bg-label-info">${full['team_name'] || 'Team #' + full['team_id']}</span>`
                    : '<span class="badge bg-label-secondary">Shared</span>'
            },
            {
                targets: 6,
                render: function (d, t, full) {
                    const n = full['products_count'];
                    return `<span class="badge ${n > 0 ? 'bg-label-primary' : 'bg-label-secondary'}">${n.toLocaleString()}</span>`;
                }
            },
            {
                targets: 7,
                render: (d, t, full) => full['status'] === 1
                    ? '<span class="badge bg-label-success">Active</span>'
                    : '<span class="badge bg-label-danger">Inactive</span>'
            },
            {
                targets: -1, title: 'Actions', searchable: false, orderable: false,
                render: function (d, t, full) {
                    // Quyền theo từng dòng: store dùng chung -> chỉ admin, store riêng -> team sở hữu
                    const editBtn = full['can_edit']
                        ? `<a href="index.php?menu=store&form=edit&id=${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon"><i class="icon-base ti tabler-edit icon-22px"></i></a>`
                        : '';
                    const deleteBtn = full['can_delete']
                        ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-store" data-id="${full['id']}" data-count="${full['products_count']}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                        : '';
                    return `<div class="d-inline-block text-nowrap">${editBtn}${deleteBtn}</div>`;
                }
            }
        ],
        select: { style: 'multi', selector: 'td:nth-child(2)' },
        order: [[2, 'asc']],
        displayLength: 25,
        layout: {
            topStart: {
                rowClass: 'card-header d-flex border-top rounded-0 flex-wrap py-0 flex-column flex-md-row align-items-start',
                features: [{ search: { className: 'me-5 ms-n4 pe-5 mb-n6 mb-md-0', placeholder: 'Search Store', text: '_INPUT_' } }]
            },
            topEnd: {
                rowClass: 'row m-3 my-0 justify-content-between',
                features: [{
                    pageLength: { menu: [10, 25, 50, 100], text: '_MENU_' },
                    buttons: [
                        ...(storePerms.delete ? [{
                            text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-trash icon-xs"></i> <span class="d-none d-sm-inline-block">Delete Selected</span></span>',
                            className: 'btn btn-text-danger me-4',
                            action: (e, dtApi) => openDeleteStoreModal(getSelectedStoreRows(dtApi))
                        }] : []),
                        ...(storePerms.add ? [{
                            text: '<i class="icon-base ti tabler-plus me-0 me-sm-1 icon-16px"></i><span class="d-none d-sm-inline-block">Add Store</span>',
                            className: 'add-new btn btn-primary',
                            action: () => { window.location.href = 'index.php?menu=store&form=add'; }
                        }] : [])
                    ]
                }]
            },
            bottomStart: { rowClass: 'row mx-3 justify-content-between', features: ['info'] },
            bottomEnd: 'paging'
        },
        language: {
            paginate: {
                next: '<i class="icon-base ti tabler-chevron-right scaleX-n1-rtl icon-18px"></i>',
                previous: '<i class="icon-base ti tabler-chevron-left scaleX-n1-rtl icon-18px"></i>',
                first: '<i class="icon-base ti tabler-chevrons-left scaleX-n1-rtl icon-18px"></i>',
                last: '<i class="icon-base ti tabler-chevrons-right scaleX-n1-rtl icon-18px"></i>'
            }
        },
        initComplete: function () {
            const api = this.api();

            const $siteBox = $('.store_site');
            $siteBox.html('<label class="form-label" for="siteFilter">Site</label><select id="siteFilter" class="form-select"><option value="">All</option></select>');
            $.each(sitesObj, function (id, item) {
                $('#siteFilter').append($('<option>', { value: id, text: item.title ?? item }));
            });

            const $statusBox = $('.store_status');
            $statusBox.html('<label class="form-label" for="statusFilter">Status</label><select id="statusFilter" class="form-select"><option value="">All</option><option value="1">Active</option><option value="0">Inactive</option></select>');

            // Lọc theo chủ sở hữu — chỉ admin có ô này (role khác chỉ thấy team mình)
            const $teamBox = $('.store_team');
            if ($teamBox.length) {
                $teamBox.html('<label class="form-label" for="teamFilter">Owner</label><select id="teamFilter" class="form-select"><option value="">All</option><option value="0">Shared (all teams)</option></select>');
                $.each(teamsObj, function (id, item) {
                    $('#teamFilter').append($('<option>', { value: id, text: item.title ?? item }));
                });
                $('#teamFilter').select2({ dropdownParent: $teamBox });
                $('#teamFilter').on('change', function () {
                    refreshFilterBadge();
                    api.draw();
                });
            }

            $('#siteFilter').select2({ dropdownParent: $siteBox });
            $('#statusFilter').select2({ dropdownParent: $statusBox, minimumResultsForSearch: Infinity });
            $('#siteFilter, #statusFilter').on('change', function () {
                refreshFilterBadge();
                api.draw();
            });

            initFilterCollapse();
        }
    });

    dtStores = dt;

    setTimeout(() => {
        const tweaks = [
            { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary' },
            { selector: '.dt-search .form-control', classToRemove: 'form-control-sm', classToAdd: 'ms-0' },
            { selector: '.dt-search', classToAdd: 'mb-0 mb-md-6' },
            { selector: '.dt-length .form-select', classToRemove: 'form-select-sm' },
            { selector: '.dt-layout-end', classToAdd: 'gap-md-2 gap-0 mt-0' },
            { selector: '.dt-layout-start', classToAdd: 'mt-0' },
            { selector: '.dt-layout-table', classToRemove: 'row mt-2' },
            { selector: '.dt-layout-full', classToRemove: 'col-md col-12', classToAdd: 'table-responsive' },
            { selector: '.dt-buttons', classToRemove: 'btn-group' }
        ];
        tweaks.forEach(({ selector, classToRemove, classToAdd }) => {
            document.querySelectorAll(selector).forEach(el => {
                if (classToRemove) classToRemove.split(' ').forEach(c => el.classList.remove(c));
                if (classToAdd) classToAdd.split(' ').forEach(c => el.classList.add(c));
            });
        });

        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
    }, 100);
}

function getSelectedStoreRows(dt) {
    const rows = dt.rows({ selected: true }).data().toArray();
    const seen = new Set(rows.map(r => r.id));
    $('.datatables-stores tbody input.dt-checkboxes:checked').each(function () {
        const row = dt.row($(this).closest('tr')).data();
        if (row && !seen.has(row.id)) {
            seen.add(row.id);
            rows.push(row);
        }
    });
    return rows;
}

// --- Xóa store ---
// Chọn nhiều store, mỗi store có thể hàng nghìn sản phẩm -> gửi theo lô và hiện tiến trình,
// popup chỉ đóng khi chạy xong. Server trả 'partial' khi chưa xử lý hết -> gửi lại đúng lô đó.
const DELETE_BATCH_SIZE = 20;
const DELETE_MAX_ROUNDS = 500; // chặn vòng lặp vô hạn nếu server cứ trả 'partial'

function openDeleteStoreModal(rows) {
    if (!rows.length) {
        alert('Select at least 1 store.');
        return;
    }
    const products = rows.reduce((n, r) => n + (r.products_count || 0), 0);
    const $modal = $('#deleteStoreModal');

    $modal.data('ids', rows.map(r => r.id));
    $('#deleteStoreCount').text(rows.length.toLocaleString());
    $('#deleteStoreProducts').text(products.toLocaleString());
    $('#deleteStoreWarning').toggleClass('d-none', products === 0);
    $('#deleteStoreProgress').addClass('d-none');
    $('#deleteStoreProgressBar').css('width', '0%').removeClass('bg-danger');
    $('#deleteStoreProgressText').text('');
    $('#deleteStoreConfirm').prop('disabled', false).show();
    $('#deleteStoreCancel').text('Cancel');

    bootstrap.Modal.getOrCreateInstance($modal[0]).show();
}

$(document).on('click', '#deleteStoreConfirm', async function () {
    const $btn = $(this);
    const ids = $('#deleteStoreModal').data('ids') || [];
    if (!ids.length) {
        return;
    }
    const $bar = $('#deleteStoreProgressBar');
    const $text = $('#deleteStoreProgressText');

    $btn.prop('disabled', true);
    $('#deleteStoreSpinner').removeClass('d-none');
    $('#deleteStoreProgress').removeClass('d-none');
    $bar.css('width', '0%').removeClass('bg-danger');
    $text.text('Deleting 0/' + ids.length + ' stores...');

    let deleted = 0;
    let deactivated = 0;
    try {
        for (let i = 0; i < ids.length; i += DELETE_BATCH_SIZE) {
            const batch = ids.slice(i, i + DELETE_BATCH_SIZE);
            let rounds = 0;
            // Lặp cùng 1 lô tới khi server xử lý hết sản phẩm của lô đó
            for (;;) {
                const res = await $.ajax({
                    url: '../../ajax.php?action=delete-stores',
                    type: 'POST',
                    data: { ids: batch }
                });
                if (res?.status === 'partial') {
                    deactivated += res.deactivated ?? 0;
                    $text.text('Deleting ' + i + '/' + ids.length + ' stores — '
                        + deactivated.toLocaleString() + ' products set to Inactive...');
                    if (++rounds > DELETE_MAX_ROUNDS) {
                        throw new Error('Too many products to process in one go. Please try again.');
                    }
                    continue;
                }
                if (res?.status !== 'success') {
                    throw new Error(res?.message || 'Delete failed');
                }
                deleted += res.deleted ?? 0;
                deactivated += res.deactivated ?? 0;
                break;
            }
            const done = Math.min(i + DELETE_BATCH_SIZE, ids.length);
            $bar.css('width', Math.round((done / ids.length) * 100) + '%');
            $text.text('Deleting ' + done + '/' + ids.length + ' stores — '
                + deactivated.toLocaleString() + ' products set to Inactive...');
        }

        $bar.css('width', '100%');
        $text.text('Done: ' + deleted.toLocaleString() + ' stores deleted, '
            + deactivated.toLocaleString() + ' products set to Inactive.');

        // Cho user thấy kết quả rồi mới đóng popup
        setTimeout(function () {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteStoreModal')).hide();
            if (dtStores) {
                dtStores.rows().deselect?.();
                dtStores.draw(false);
            }
        }, 1200);
    } catch (err) {
        // Dừng lại, giữ popup để user đọc lỗi — phần đã xóa vẫn giữ nguyên
        $bar.addClass('bg-danger');
        $text.text(err?.message || 'Server connection error');
        $btn.hide();
        $('#deleteStoreCancel').text('Close');
        if (dtStores) {
            dtStores.draw(false);
        }
    } finally {
        $('#deleteStoreSpinner').addClass('d-none');
    }
});

$(document).on('click', '.delete-store', function () {
    // Lấy từ data-* thay vì DataTables row: nút có thể nằm trong child row khi bảng thu gọn
    openDeleteStoreModal([{
        id: parseInt($(this).data('id'), 10),
        products_count: parseInt($(this).data('count'), 10) || 0
    }]);
});

// --- Filter card ---
function countActiveFilters() {
    let n = 0;
    if ($('#siteFilter').val()) n++;
    if ($('#statusFilter').val()) n++;
    if ($('#teamFilter').val()) n++; // '' = All, '0' = Shared cũng tính là đang lọc
    return n;
}

function refreshFilterBadge() {
    const n = countActiveFilters();
    $('#activeFilterCount').text(n).toggleClass('d-none', n === 0);
    const hasSearch = !!(dtStores && dtStores.search());
    $('#clearFilters').prop('disabled', n === 0 && !hasSearch);
}

function setFilterCollapsed(collapsed, animate) {
    const $header = $('#filterCard .card-header');
    const $icon = $('#filterCard .card-collapsible i');
    const body = document.getElementById('filterBody');
    if (!body) {
        return;
    }
    if (animate) {
        bootstrap.Collapse.getOrCreateInstance(body, { toggle: false })[collapsed ? 'hide' : 'show']();
    } else {
        $(body).toggleClass('show', !collapsed);
    }
    $header.toggleClass('collapsed', collapsed);
    $icon.toggleClass('tabler-chevron-up', !collapsed).toggleClass('tabler-chevron-down', collapsed);
}

function initFilterCollapse() {
    refreshFilterBadge();
    $(document).on('input', '.dt-search input', refreshFilterBadge);

    $('#clearFilters').on('click', function () {
        $('#siteFilter, #statusFilter, #teamFilter').val('').trigger('change.select2');
        if (dtStores) {
            dtStores.search('').draw();
        }
        refreshFilterBadge();
    });

    $('#filterCard .card-collapsible').on('click', function (e) {
        e.preventDefault();
        setFilterCollapsed(!$('#filterCard .card-header').hasClass('collapsed'), true);
    });

    setFilterCollapsed(true, false);
}

document.addEventListener('DOMContentLoaded', function () {
    init();
});
