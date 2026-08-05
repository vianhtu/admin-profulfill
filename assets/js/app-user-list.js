/**
 * Page User List
 */

'use strict';

// Escape dữ liệu người dùng (username/email/tên team...) trước khi nhét vào HTML -> chặn stored XSS
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

let rolesObj = {};
let teamsObj = {};
let statusesObj = {};
let userPerms = { add: false, is_admin: false, see_salary: false, filter_team: false };
let dtUsers = null;

const STATUS_CLASS = { 1: 'bg-label-warning', 2: 'bg-label-success', 3: 'bg-label-secondary' };
const AVATAR_STATES = ['success', 'danger', 'warning', 'info', 'dark', 'primary', 'secondary'];

async function init() {
    try {
        const options = await fetchTableFilter('get-authors-table-filter');
        rolesObj = options['roles'] ?? {};
        teamsObj = options['teams'] ?? {};
        statusesObj = options['statuses'] ?? {};
        userPerms = options['perms'] ?? userPerms;
        initTable();
    } catch (err) {
        alert('Failed to load user options');
    }
}

function initTable() {
    const el = document.querySelector('.datatables-users');
    if (!el) {
        return;
    }

    // Cột Salary chỉ tồn tại khi người xem được phép — phải khớp <thead> của fragment
    const columns = [
        { data: 'id' },
        { data: 'username' },
        { data: 'level' },
        { data: 'team_id' }
    ];
    if (userPerms.see_salary) {
        columns.push({ data: 'wage' });
    }
    columns.push({ data: 'status' }, { data: 'date' }, { data: 'id', orderable: false });

    const iSalary = userPerms.see_salary ? 4 : -1;
    const iStatus = userPerms.see_salary ? 5 : 4;
    const iDate   = userPerms.see_salary ? 6 : 5;

    const columnDefs = [
        { className: 'control', searchable: false, orderable: false, responsivePriority: 2, targets: 0, render: () => '' },
        {
            targets: 1,
            responsivePriority: 1,
            render: function (d, t, full) {
                const name = String(full['username'] ?? '');
                // Màu avatar ổn định theo id (không random để không nhấp nháy mỗi lần draw)
                const state = AVATAR_STATES[full['id'] % AVATAR_STATES.length];
                let initials = (name.match(/\b\w/g) || []).map(ch => ch.toUpperCase());
                initials = ((initials.shift() || '') + (initials.pop() || '')).toUpperCase();
                return '<div class="d-flex justify-content-start align-items-center user-name">' +
                    '<div class="avatar-wrapper"><div class="avatar avatar-sm me-4">' +
                    '<span class="avatar-initial rounded-circle bg-label-' + state + '">' + esc(initials) + '</span>' +
                    '</div></div>' +
                    '<div class="d-flex flex-column">' +
                    '<span class="text-heading fw-medium">' + esc(name) + '</span>' +
                    '<small>' + esc(full['email']) + '</small>' +
                    '</div></div>';
            }
        },
        {
            targets: 2,
            render: (d, t, full) => full['role_name']
                ? `<span class="text-nowrap">${esc(full['role_name'])}</span>`
                : '<span class="text-body-secondary">—</span>'
        },
        {
            targets: 3,
            render: (d, t, full) => full['team_name']
                ? `<span class="badge bg-label-info">${esc(full['team_name'])}</span>`
                : '<span class="text-body-secondary">—</span>'
        },
        {
            targets: iStatus,
            render: function (d, t, full) {
                const s = full['status'];
                return `<span class="badge ${STATUS_CLASS[s] || 'bg-label-secondary'}">${esc(statusesObj[s] || s)}</span>`;
            }
        },
        {
            targets: iDate,
            render: (d, t, full) => full['date']
                ? `<span class="text-nowrap">${toLocalDate(full['date'])}</span>`
                : '<span class="text-body-secondary">—</span>'
        },
        {
            targets: -1, title: 'Actions', searchable: false, orderable: false,
            render: function (d, t, full) {
                // Quyền theo TỪNG dòng: không đủ quyền thì KHÔNG render nút
                const editBtn = full['can_edit']
                    ? `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon edit-user" data-id="${full['id']}" title="Edit"><i class="icon-base ti tabler-edit icon-22px"></i></button>`
                    : '';
                const deleteBtn = full['can_delete']
                    ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-user" data-id="${full['id']}" data-name="${esc(full['username'])}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                    : '';
                return `<div class="d-inline-block text-nowrap">${editBtn}${deleteBtn}</div>`;
            }
        }
    ];
    if (iSalary > 0) {
        columnDefs.push({
            targets: iSalary,
            render: (d, t, full) => `<span class="text-nowrap">${esc(full['wage'] ?? '')}</span>`
        });
    }

    dtUsers = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: '../../ajax.php?action=get-authors-table',
            type: 'POST',
            data: function (d) {
                d.level = $('#UserRole').val() || '';
                d.status = $('#UserStatus').val() || '';
                d.team = $('#UserTeam').val() || '';
            },
            dataSrc: json => json.data
        },
        columns: columns,
        columnDefs: columnDefs,
        order: [[1, 'asc']],
        displayLength: 25,
        layout: {
            topStart: {
                rowClass: 'row m-3 my-0 justify-content-between',
                features: [{ pageLength: { menu: [10, 25, 50, 100], text: '_MENU_' } }]
            },
            topEnd: {
                features: [
                    { search: { placeholder: 'Search User', text: '_INPUT_' } },
                    {
                        buttons: [
                            ...(userPerms.add ? [{
                                text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-plus icon-xs"></i> <span class="d-none d-sm-inline-block">Add User</span></span>',
                                className: 'add-new btn btn-primary',
                                action: () => openUserForm()
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
                    header: row => 'Details of ' + esc(row.data()['username'])
                }),
                type: 'column',
                renderer: function (api, rowIdx, cols) {
                    const data = cols.map(col => col.title !== ''
                        ? `<tr data-dt-row="${col.rowIndex}" data-dt-column="${col.columnIndex}">
                      <td>${col.title}:</td><td>${col.data}</td></tr>`
                        : '').join('');
                    if (!data) {
                        return false;
                    }
                    const div = document.createElement('div');
                    div.classList.add('table-responsive');
                    const table = document.createElement('table');
                    div.appendChild(table);
                    table.classList.add('table');
                    const tbody = document.createElement('tbody');
                    tbody.innerHTML = data;
                    table.appendChild(tbody);
                    return div;
                }
            }
        },
        initComplete: function () {
            const api = this.api();
            buildFilters(api);
            buildFormSelects();
        }
    });

    setTimeout(() => {
        const tweaks = [
            { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary' },
            { selector: '.dt-search .form-control', classToRemove: 'form-control-sm', classToAdd: 'ms-0' },
            { selector: '.dt-length .form-select', classToRemove: 'form-select-sm' },
            { selector: '.dt-length', classToAdd: 'mb-md-6 mb-0' },
            { selector: '.dt-layout-end', classToAdd: 'gap-md-2 gap-0 mt-0' },
            { selector: '.dt-layout-start', classToAdd: 'mt-0' },
            { selector: '.dt-layout-table', classToRemove: 'row mt-2' },
            { selector: '.dt-layout-full', classToRemove: 'col-md col-12', classToAdd: 'table-responsive' },
            // Bỏ .btn-group do DataTables Buttons thêm vào: nó cắt bo góc các nút rời nhau
            { selector: '.dt-buttons', classToRemove: 'btn-group' }
        ];
        tweaks.forEach(({ selector, classToRemove, classToAdd }) => {
            document.querySelectorAll(selector).forEach(elm => {
                if (classToRemove) classToRemove.split(' ').forEach(c => elm.classList.remove(c));
                if (classToAdd) classToAdd.split(' ').forEach(c => elm.classList.add(c));
            });
        });

        // Ô chọn số item/trang cũng dùng select2 cho đồng bộ với các trang chuẩn
        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
    }, 100);
}

// --- Bộ lọc ---
function fillSelect($sel, map, allLabel) {
    $sel.empty();
    if (allLabel !== null) {
        $sel.append(new Option(allLabel, '', false, false));
    }
    $.each(map, (id, item) => $sel.append(new Option(item.title ?? item, id, false, false)));
}

function buildFilters(api) {
    const $role = $('.user_role');
    $role.html('<label class="form-label" for="UserRole">Role</label><select id="UserRole" class="form-select"></select>');
    fillSelect($('#UserRole'), rolesObj, 'All');
    $('#UserRole').select2({ dropdownParent: $role });

    const $status = $('.user_status');
    $status.html('<label class="form-label" for="UserStatus">Status</label><select id="UserStatus" class="form-select"></select>');
    fillSelect($('#UserStatus'), statusesObj, 'All');
    $('#UserStatus').select2({ dropdownParent: $status, minimumResultsForSearch: Infinity });

    // Lọc theo team chỉ dành cho admin — fragment không render ô này cho role khác
    const $team = $('.user_team');
    if ($team.length && userPerms.filter_team) {
        $team.html('<label class="form-label" for="UserTeam">Team</label><select id="UserTeam" class="form-select"></select>');
        fillSelect($('#UserTeam'), teamsObj, 'All');
        $('#UserTeam').select2({ dropdownParent: $team });
    }

    // Vào từ trang Teams: index.php?menu=users&UserTeam=<id> -> lọc sẵn theo team đó
    const urlTeam = new URLSearchParams(window.location.search).get('UserTeam');
    if (urlTeam && $('#UserTeam').length) {
        $('#UserTeam').val(urlTeam).trigger('change.select2');
    }

    $('#UserRole, #UserStatus, #UserTeam').on('change', function () {
        refreshFilterBadge();
        api.draw();
    });

    initFilterCollapse(!!urlTeam);
    if (urlTeam && $('#UserTeam').length) {
        api.draw();
    }
}

// --- Khối Filter: badge đếm, nút Clear, thu gọn (cùng khuôn Products/Stores) ---
function countActiveFilters() {
    let n = 0;
    if ($('#UserRole').val()) { n++; }
    if ($('#UserStatus').val()) { n++; }
    if ($('#UserTeam').val()) { n++; }
    return n;
}

function refreshFilterBadge() {
    const n = countActiveFilters();
    $('#activeFilterCount').text(n).toggleClass('d-none', n === 0);
    const hasSearch = !!(dtUsers && dtUsers.search());
    $('#clearFilters').prop('disabled', n === 0 && !hasSearch);
}

function clearAllFilters() {
    $('#UserRole, #UserStatus, #UserTeam').val('').trigger('change.select2');
    if (dtUsers) {
        dtUsers.search('').draw();
    }
    refreshFilterBadge();
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

/**
 * @param {boolean} keepOpen mở sẵn khi vào trang với filter dựng từ URL (vd. từ Teams
 *        sang bằng ?UserTeam=..), để người dùng thấy ngay mình đang bị lọc
 */
function initFilterCollapse(keepOpen) {
    refreshFilterBadge();
    // Gõ vào ô search cũng ảnh hưởng trạng thái nút Clear
    $(document).on('input', '.dt-search input', refreshFilterBadge);
    $('#clearFilters').on('click', clearAllFilters);

    $('#filterCard .card-collapsible').on('click', function (e) {
        e.preventDefault();
        setFilterCollapsed(!$('#filterCard .card-header').hasClass('collapsed'), true);
    });

    setFilterCollapsed(!keepOpen, false);
}

// --- Form Add/Edit ---
function buildFormSelects() {
    fillSelect($('#user-level'), rolesObj, null);
    fillSelect($('#user-team'), teamsObj, null);
    fillSelect($('#user-status'), statusesObj, null);
    $('#user-level, #user-team, #user-status').each(function () {
        $(this).select2({ dropdownParent: $('#offcanvasUser'), minimumResultsForSearch: Infinity });
    });
}

function openUserForm(row) {
    $('#offcanvasUserLabel').text(row ? 'Edit User' : 'Add User');
    $('#user-id').val(row?.id ?? 0);
    $('#user-username').val(row?.username ?? '').removeClass('is-invalid');
    $('#user-email').val(row?.email ?? '').removeClass('is-invalid');
    $('#user-password').val('').removeClass('is-invalid');
    $('#user-password-hint').text(row ? 'Leave blank to keep the current password.' : 'At least 8 characters.');
    $('#user-level').val(String(row?.level ?? '')).trigger('change.select2');
    $('#user-team').val(String(row?.team_id ?? '')).trigger('change.select2');
    $('#user-status').val(String(row?.status ?? 2)).trigger('change.select2');
    if (userPerms.see_salary) {
        // wage trả về đã format tiền tệ -> lấy lại phần số để đưa vào ô nhập
        $('#user-wage').val(String(row?.wage ?? '').replace(/\D/g, '') || 0);
        $('#user-insurance').val(String(row?.insurance ?? '').replace(/\D/g, '') || 0);
    }
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasUser')).show();
}

$(document).on('click', '.edit-user', function () {
    const id = parseInt($(this).data('id'), 10);
    const row = dtUsers ? dtUsers.rows().data().toArray().find(r => r.id === id) : null;
    openUserForm(row);
});

$(document).on('click', '#userSubmit', function () {
    const $btn = $(this);
    const isNew = parseInt($('#user-id').val(), 10) === 0;
    const username = $('#user-username').val().trim();
    const email = $('#user-email').val().trim();
    const password = $('#user-password').val();

    let ok = true;
    $('#user-username').toggleClass('is-invalid', !username);
    if (!username) { ok = false; }
    $('#user-email').toggleClass('is-invalid', !email);
    if (!email) { ok = false; }
    const badPass = (isNew && password.length < 8) || (!isNew && password !== '' && password.length < 8);
    $('#user-password').toggleClass('is-invalid', badPass);
    if (badPass) { ok = false; }
    if (!ok) {
        return;
    }

    const data = {
        id: $('#user-id').val(),
        username: username,
        email: email,
        password: password,
        level: $('#user-level').val(),
        team_id: $('#user-team').val(),
        status: $('#user-status').val(),
        csrf_token: window.csrfToken
    };
    if (userPerms.see_salary) {
        data.wage = $('#user-wage').val() || 0;
        data.insurance = $('#user-insurance').val() || 0;
    }

    $btn.prop('disabled', true);
    $.ajax({ url: '../../ajax.php?action=save-user', type: 'POST', data: data })
        .done(function (res) {
            if (res?.status === 'success') {
                bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasUser'))?.hide();
                if (dtUsers) {
                    dtUsers.draw(false);
                }
            } else {
                alert(res?.message || 'Failed to save user.');
            }
        })
        .fail(() => alert('Server connection error.'))
        .always(() => $btn.prop('disabled', false));
});

// --- Xóa: chỉ TỪNG DÒNG một, không có thao tác hàng loạt ---
$(document).on('click', '.delete-user', function () {
    const modalEl = document.getElementById('deleteUserModal');
    if (!modalEl) {
        return;   // không phải admin -> fragment không render modal
    }
    $('#deleteUserName').text(String($(this).data('name') ?? ''));
    $(modalEl).data('id', parseInt($(this).data('id'), 10));
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
});

$(document).on('click', '#deleteUserConfirm', function () {
    const modalEl = document.getElementById('deleteUserModal');
    const id = $(modalEl).data('id');
    if (!id) {
        return;
    }
    $.ajax({
        url: '../../ajax.php?action=delete-users',
        type: 'POST',
        data: { ids: [id], csrf_token: window.csrfToken }
    }).done(function (res) {
        if (res?.status === 'success') {
            bootstrap.Modal.getInstance(modalEl)?.hide();
            $(modalEl).removeData('id');
            if (dtUsers) {
                dtUsers.draw(false);
            }
        } else {
            // Giữ modal mở để đọc lý do (vd còn sản phẩm/account đứng tên)
            alert(res?.message || 'Failed to delete user.');
        }
    }).fail(() => alert('Server connection error.'));
});

document.addEventListener('DOMContentLoaded', function () {
    init();
});
