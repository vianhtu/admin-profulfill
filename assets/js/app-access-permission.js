/**
 * app-access-permission (js) — menu Roles & Permissions
 *
 * Gộp cả 3 file cũ (list + modal-add + modal-edit) về một chỗ theo đúng khuôn các trang
 * danh sách khác. Đây là bảng định nghĩa quyền của mọi người nên KHÓA/ẨN ở đây chỉ là lớp
 * 1: mọi endpoint bên server đều tự kiểm lại.
 */

'use strict';

// Escape dữ liệu người dùng (tên role) trước khi nhét vào HTML -> chặn stored XSS
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Nút LẺ ở cột Actions: thiếu quyền thì KHÓA chứ không ẩn — ẩn làm các nút còn lại xô lệch
// giữa các dòng. Bảo vệ THẬT nằm ở endpoint.
function lockedBtn(icon, why) {
    return `<button type="button" class="btn btn-text-secondary rounded-pill btn-icon" disabled` +
        ` title="${esc(why)}"><i class="icon-base ti ${icon} icon-22px"></i></button>`;
}

// Thứ tự PHẢI khớp mảng `columns` bên dưới — helper URL đổi tên cột <-> chỉ số cột
const ROLE_COLS = ['id', 'name', 'level', 'menus', 'count', 'id'];
let urlState = null;
let dtRoles = null;
let levelsObj = {};
let rolePerms = { add: false, delete_any: false, is_admin: false, my_level: 0 };

async function initRoles() {
    try {
        const res = await $.ajax({
            url: '../../ajax.php?action=get-roles-table-filter',
            type: 'POST', dataType: 'json'
        });
        if (res?.status === 'error') {
            return;
        }
        levelsObj = res?.levels ?? {};
        rolePerms = res?.perms ?? rolePerms;
    } catch (e) {
        /* vẫn dựng bảng: endpoint danh sách tự chặn nếu không có quyền */
    }
    initTable();
}

function initTable() {
    const el = document.querySelector('.datatables-permissions');
    if (!el) {
        return;
    }

    urlState = dtUrlState({ level: '#RoleLevel', assigned: '#RoleAssigned' }, 25);

    dtRoles = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: '../../ajax.php?action=get-roles-permissions-table',
            type: 'POST',
            data: function (d) {
                // Lần vẽ đầu các ô lọc chưa được dựng (initComplete mới chạy sau) nên
                // phải lấy thẳng từ URL, nếu không bảng vẽ sai rồi mới sửa lại.
                d.level = $('#RoleLevel').length ? ($('#RoleLevel').val() || '') : urlState.get('level');
                d.assigned = $('#RoleAssigned').length ? ($('#RoleAssigned').val() || '') : urlState.get('assigned');
            },
            dataSrc: json => json.data
        },
        columns: [
            { data: 'id' },
            { data: 'name' },
            { data: 'level' },
            { data: 'menus' },
            { data: 'count' },
            { data: 'id' }
        ],
        columnDefs: [
            { className: 'control', searchable: false, orderable: false, responsivePriority: 2, targets: 0, render: () => '' },
            {
                targets: 1,
                render: (d, t, full) => `<span class="text-nowrap text-heading fw-medium">${esc(full['name'])}</span>`
            },
            {
                targets: 2,
                render: function (d, t, full) {
                    const map = { admin: 'danger', manager: 'warning', user: 'primary', customer: 'secondary' };
                    const tone = map[full['slug']] ?? 'secondary';
                    return `<span class="badge bg-label-${tone}">${esc(full['level'])}</span>`;
                }
            },
            {
                targets: 3, orderable: false,
                render: (d, t, full) => {
                    const n = full['menus'] ?? 0;
                    return `<span class="badge ${n > 0 ? 'bg-label-info' : 'bg-label-secondary'}">${n} menu${n === 1 ? '' : 's'}</span>`;
                }
            },
            {
                targets: 4,
                render: function (d, t, full) {
                    const n = full['count'] ?? 0;
                    // Bấm vào số người -> trang Users LỌC SẴN theo role này, giống cách
                    // Teams link sang members bằng ?UserTeam=. Không có người thì đừng
                    // dựng link (bấm vào chỉ ra bảng rỗng).
                    if (n === 0) {
                        return '<span class="badge bg-label-secondary">' +
                            '<i class="icon-base ti tabler-users icon-14px me-1"></i>0</span>';
                    }
                    return `<a href="index.php?menu=users&UserRole=${full['id']}" title="View users with this role">` +
                        `<span class="badge bg-label-primary">` +
                        `<i class="icon-base ti tabler-users icon-14px me-1"></i>${n.toLocaleString()}</span></a>`;
                }
            },
            {
                targets: -1, title: 'Actions', searchable: false, orderable: false,
                render: function (d, t, full) {
                    // Quyền theo TỪNG dòng; nút lẻ thiếu quyền thì KHÓA (xem lockedBtn)
                    const editBtn = full['can_edit']
                        ? `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon edit-role" data-id="${full['id']}" title="Edit"><i class="icon-base ti tabler-edit icon-22px"></i></button>`
                        : lockedBtn('tabler-edit', full['slug'] === 'admin'
                            ? 'The Admin role cannot be edited here'
                            : 'You cannot edit this role');
                    const deleteBtn = full['can_delete']
                        ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-role" data-id="${full['id']}" data-name="${esc(full['name'])}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                        : lockedBtn('tabler-trash', full['slug'] === 'admin'
                            ? 'The Admin role can never be deleted'
                            : 'You cannot delete this role');
                    return `<div class="d-inline-block text-nowrap">${editBtn}${deleteBtn}</div>`;
                }
            }
        ],
        order: [[1, 'asc']],
        displayLength: 25,
        // Đọc URL TRƯỚC khi dựng bảng -> vẽ đúng ngay lần đầu, không phải draw lần hai.
        // PHẢI spread SAU order/displayLength, nếu không mặc định sẽ ghi đè giá trị từ URL.
        ...urlState.tableOptions(ROLE_COLS),
        layout: {
            topStart: {
                rowClass: 'row m-3 my-0 justify-content-between',
                features: [{ pageLength: { menu: [10, 25, 50, 100], text: '_MENU_' } }]
            },
            topEnd: {
                features: [
                    { search: { placeholder: 'Search Role', text: '_INPUT_' } },
                    {
                        buttons: [
                            // Không có Delete Selected: xóa role phải chọn role thay thế cho
                            // từng nhóm người, không gộp hàng loạt được.
                            ...(rolePerms.add ? [{
                                text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-plus icon-xs"></i> <span class="d-none d-sm-inline-block">Add New Role</span></span>',
                                className: 'add-new btn btn-primary',
                                action: () => openRoleForm(null)
                            }] : [])
                        ]
                    }
                ]
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
        responsive: {
            details: {
                display: DataTable.Responsive.display.modal({
                    header: row => 'Details of ' + esc(row.data()['name'])
                }),
                type: 'column',
                renderer: function (api, rowIdx, columns) {
                    const data = columns.map(col => col.title !== ''
                        ? `<tr data-dt-row="${col.rowIndex}" data-dt-column="${col.columnIndex}"><td>${col.title}:</td><td>${col.data}</td></tr>`
                        : '').join('');
                    if (!data) {
                        return false;
                    }
                    const div = document.createElement('div');
                    div.classList.add('table-responsive');
                    const table = document.createElement('table');
                    table.classList.add('table');
                    const tbody = document.createElement('tbody');
                    tbody.innerHTML = data;
                    table.appendChild(tbody);
                    div.appendChild(table);
                    return div;
                }
            }
        },
        initComplete: function () {
            buildFilters();
            const preset = urlState.applyFilters();
            initFilterUi(preset);
            urlState.bind(dtRoles, ROLE_COLS);
        }
    });

    // Chỉnh class DataTables cho khớp 4 trang chuẩn
    setTimeout(() => {
        [
            { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary' },
            { selector: '.dt-buttons', classToRemove: 'btn-group', classToAdd: 'mb-0 w-auto' },
            { selector: '.dt-search', classToAdd: 'me-4' },
            { selector: '.dt-search .form-control', classToRemove: 'form-control-sm' },
            { selector: '.dt-length', classToAdd: 'mb-0 mb-md-5' },
            { selector: '.dt-length .form-select', classToRemove: 'form-select-sm' },
            { selector: '.dt-layout-start', classToAdd: 'mt-0 px-5' },
            {
                selector: '.dt-layout-end',
                classToRemove: 'justify-content-between',
                classToAdd: 'justify-content-md-between justify-content-center d-flex flex-wrap gap-md-4 mb-sm-0 mb-6 mt-0'
            },
            { selector: '.dt-layout-table', classToRemove: 'row mt-2' },
            { selector: '.dt-layout-full', classToRemove: 'col-md col-12', classToAdd: 'table-responsive' }
        ].forEach(({ selector, classToRemove, classToAdd }) => {
            document.querySelectorAll(selector).forEach(el2 => {
                if (classToRemove) { classToRemove.split(' ').forEach(c => el2.classList.remove(c)); }
                if (classToAdd) { classToAdd.split(' ').forEach(c => el2.classList.add(c)); }
            });
        });
        // Ô số dòng/trang phải là select2 như 4 trang chuẩn
        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
    }, 100);
}

// --- Filter ---
function fillSelect($sel, map, allLabel) {
    $sel.empty();
    if (allLabel !== null) {
        $sel.append(new Option(allLabel, '', false, false));
    }
    $.each(map, (id, item) => $sel.append(new Option(item.title ?? item, id, false, false)));
}

function buildFilters() {
    const $lv = $('.role_level');
    $lv.html('<label class="form-label" for="RoleLevel">Level</label><select id="RoleLevel" class="form-select"></select>');
    fillSelect($('#RoleLevel'), levelsObj, 'All');
    $('#RoleLevel').select2({ dropdownParent: $lv, minimumResultsForSearch: Infinity });

    const $as = $('.role_assigned');
    $as.html('<label class="form-label" for="RoleAssigned">Assigned</label><select id="RoleAssigned" class="form-select"></select>');
    fillSelect($('#RoleAssigned'), { yes: 'In use', no: 'Not used' }, 'All');
    $('#RoleAssigned').select2({ dropdownParent: $as, minimumResultsForSearch: Infinity });

    $('#RoleLevel, #RoleAssigned').on('change', function () {
        updateFilterUi();
        dtRoles.ajax.reload();
    });
}

function updateFilterUi() {
    let n = 0;
    if ($('#RoleLevel').val()) { n++; }
    if ($('#RoleAssigned').val()) { n++; }
    const hasSearch = !!(dtRoles && dtRoles.search());
    $('#activeFilterCount').text(n).toggleClass('d-none', n === 0);
    $('#clearFilters').prop('disabled', n === 0 && !hasSearch);
}

function clearAllFilters() {
    $('#RoleLevel, #RoleAssigned').val('').trigger('change.select2');
    if (dtRoles) { dtRoles.search('').draw(); }
    updateFilterUi();
}

function setFilterCollapsed(collapsed, animate) {
    const body = document.getElementById('filterBody');
    if (!body) { return; }
    const $header = $('#filterCard .card-header');
    const $icon = $('#filterCard .card-collapsible i');
    if (animate) {
        bootstrap.Collapse.getOrCreateInstance(body, { toggle: false })[collapsed ? 'hide' : 'show']();
    } else {
        $(body).toggleClass('show', !collapsed);
    }
    $header.toggleClass('collapsed', collapsed);
    $icon.toggleClass('tabler-chevron-up', !collapsed).toggleClass('tabler-chevron-down', collapsed);
}

function initFilterUi(keepOpen) {
    $('#clearFilters').on('click', clearAllFilters);
    $('#filterCard .card-collapsible').on('click', function (e) {
        e.preventDefault();
        setFilterCollapsed(!$('#filterCard .card-header').hasClass('collapsed'), true);
    });
    if (dtRoles) {
        dtRoles.on('search.dt', updateFilterUi);
    }
    // Có lọc dựng sẵn từ URL -> mở khối Filter để người dùng thấy ngay mình đang bị lọc
    setFilterCollapsed(!keepOpen, false);
    updateFilterUi();
}

// --- Form Add/Edit ---
function setAllBoxes(on) {
    $('#addPermissionForm .perm-box').each(function () {
        if (!this.disabled) { this.checked = on; }
    });
}

function openRoleForm(row) {
    const isEdit = !!row;
    $('#roleId').val(isEdit ? row.id : 0);
    $('#roleModalTitle').text(isEdit ? 'Edit Role' : 'Add New Role');
    $('#roleSubmit').text(isEdit ? 'Save Changes' : 'Create Role');
    $('#modalPermissionName').val(isEdit ? row.role_name : '').removeClass('is-invalid');
    $('#alertPermissionModal').removeClass('show alert-danger alert-success').text('');
    setAllBoxes(false);
    if (isEdit) {
        $('#modalRoleLevel').val(row.level);
        const perms = row.permissions || {};
        $('#addPermissionForm .perm-box').each(function () {
            const m = this.dataset.menu, a = this.dataset.action;
            if (perms[m] && perms[m][a]) { this.checked = true; }
        });
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addPermissionModal')).show();
}

$(document).on('click', '.edit-role', function () {
    const id = parseInt($(this).data('id'), 10);
    $.ajax({
        url: '../../ajax.php?action=get-roles-permissions',
        type: 'POST', dataType: 'json',
        data: { id: id, csrf_token: window.csrfToken },
        success: function (res) {
            if (res?.status !== 'success') {
                alert(res?.message || 'Failed to load role.');
                return;
            }
            openRoleForm(res);
        },
        error: () => alert('Server connection error.')
    });
});

$(document).on('click', '#roleSubmit', function () {
    const $name = $('#modalPermissionName');
    if (!$name.val().trim()) {
        $name.addClass('is-invalid');
        return;
    }
    $name.removeClass('is-invalid');

    const permissions = {};
    $('#addPermissionForm .perm-box:checked').each(function () {
        const m = this.dataset.menu, a = this.dataset.action;
        (permissions[m] = permissions[m] || {})[a] = 'on';
    });

    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: '../../ajax.php?action=add-roles-permissions',
        type: 'POST', dataType: 'json',
        data: {
            id: $('#roleId').val(),
            role_name: $name.val().trim(),
            level: $('#modalRoleLevel').val(),
            permissions: permissions,
            csrf_token: window.csrfToken
        },
        success: function (res) {
            if (res?.status !== 'success') {
                $('#alertPermissionModal').addClass('show alert-danger').text(res?.message || 'Save failed.');
                return;
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('addPermissionModal')).hide();
            if (dtRoles) { dtRoles.draw(false); }
        },
        error: () => $('#alertPermissionModal').addClass('show alert-danger').text('Server connection error.'),
        complete: () => $btn.prop('disabled', false)
    });
});

// --- Xóa: phải chọn role thay thế cho người đang mang role bị xóa ---
$(document).on('click', '.delete-role', function () {
    const id = parseInt($(this).data('id'), 10);
    const name = $(this).data('name');
    const modalEl = document.getElementById('deleteRoleModal');
    $(modalEl).data('id', id);
    $('#deleteRoleName').text(name);
    $('#deleteRoleAssigned').addClass('d-none');
    $('#deleteRoleProgress').addClass('d-none');
    $('#deleteRoleProgressText').text('');
    $('#deleteRoleBar').css('width', '0%').removeClass('bg-warning');
    $('#deleteRoleConfirm').prop('disabled', true).show();
    $('#deleteRoleCancel').text('Cancel').prop('disabled', false);
    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    $.ajax({
        url: '../../ajax.php?action=get-role-delete-preview',
        type: 'POST', dataType: 'json',
        data: { id: id, csrf_token: window.csrfToken },
        success: function (res) {
            if (res?.status !== 'success') {
                $('#deleteRoleProgress').removeClass('d-none');
                $('#deleteRoleProgressText').text(res?.message || 'Failed to load role.');
                return;
            }
            if (res.users > 0) {
                $('#deleteRoleCount').text(res.users.toLocaleString());
                const $sel = $('#deleteRoleReplacement').empty();
                (res.targets || []).forEach(t =>
                    $sel.append(new Option(t.name + ' (' + t.level + ')', t.id, false, false)));
                $('#deleteRoleAssigned').removeClass('d-none');
                // Không còn role nào nhận được -> không cho xóa
                $('#deleteRoleConfirm').prop('disabled', (res.targets || []).length === 0);
                if (!(res.targets || []).length) {
                    $('#deleteRoleProgress').removeClass('d-none');
                    $('#deleteRoleProgressText').text('No replacement role is available to you.');
                }
            } else {
                $('#deleteRoleConfirm').prop('disabled', false);
            }
        },
        error: function () {
            $('#deleteRoleProgress').removeClass('d-none');
            $('#deleteRoleProgressText').text('Server connection error.');
        }
    });
});

$(document).on('click', '#deleteRoleConfirm', function () {
    const modalEl = document.getElementById('deleteRoleModal');
    const id = $(modalEl).data('id');
    if (!id) { return; }
    const $btn = $(this).prop('disabled', true);
    $('#deleteRoleCancel').prop('disabled', true);
    $('#deleteRoleProgress').removeClass('d-none');
    $('#deleteRoleProgressText').text('Deleting...');

    $.ajax({
        url: '../../ajax.php?action=delete-role',
        type: 'POST', dataType: 'json',
        data: {
            id: id,
            replacement: $('#deleteRoleAssigned').hasClass('d-none') ? 0 : $('#deleteRoleReplacement').val(),
            csrf_token: window.csrfToken
        },
        success: function (res) {
            if (res?.status !== 'success') {
                $('#deleteRoleBar').addClass('bg-warning');
                $('#deleteRoleProgressText').text(res?.message || 'Delete failed.');
                $btn.prop('disabled', false);
                $('#deleteRoleCancel').prop('disabled', false);
                return;
            }
            $('#deleteRoleBar').css('width', '100%');
            $('#deleteRoleProgressText').text(res.message || 'Done.');
            setTimeout(function () {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                $(modalEl).removeData('id');
                if (dtRoles) { dtRoles.draw(false); }
            }, 1000);
        },
        error: function () {
            $('#deleteRoleBar').addClass('bg-warning');
            $('#deleteRoleProgressText').text('Server connection error.');
            $btn.prop('disabled', false);
            $('#deleteRoleCancel').prop('disabled', false);
        }
    });
});

document.addEventListener('DOMContentLoaded', initRoles);
