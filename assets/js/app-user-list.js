/**
 * Page User List
 */

'use strict';

// Escape dữ liệu người dùng (username/email/tên team...) trước khi nhét vào HTML -> chặn stored XSS
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Nút LẺ ở cột Actions: thiếu quyền thì KHÓA chứ không ẩn — ẩn làm các nút còn lại xô lệch
// giữa các dòng, và người dùng tưởng hệ thống không có chức năng đó. Bảo vệ THẬT nằm ở
// endpoint: `disabled` gỡ được bằng DevTools trong 2 giây.
function lockedBtn(icon, why) {
    return `<button type="button" class="btn btn-text-secondary rounded-pill btn-icon" disabled` +
        ` title="${esc(why)}"><i class="icon-base ti ${icon} icon-22px"></i></button>`;
}

let urlState = null;
let rolesObj = {};
let teamsObj = {};
let statusesObj = {};
let userPerms = { add: false, is_admin: false, see_salary: false, filter_team: false };
let dtUsers = null;

const STATUS_CLASS = { 1: 'bg-label-warning', 2: 'bg-label-success', 3: 'bg-label-secondary' };
const AVATAR_STATES = ['success', 'danger', 'warning', 'info', 'dark', 'primary', 'secondary'];

// avatar lưu dạng đường dẫn tương đối gốc app (uploads/avatars/...) — trang nằm sâu 2 cấp
function avatarUrl(path) {
    return '../../' + String(path ?? '').replace(/^\/+/, '');
}

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
                // Có ảnh thì hiện ảnh, không thì rơi về chữ cái đầu.
                // Màu chữ cái ổn định theo id (không random để không nhấp nháy mỗi lần draw)
                const state = AVATAR_STATES[full['id'] % AVATAR_STATES.length];
                let initials = (name.match(/\b\w/g) || []).map(ch => ch.toUpperCase());
                initials = ((initials.shift() || '') + (initials.pop() || '')).toUpperCase();
                const face = full['avatar']
                    ? '<img src="' + esc(avatarUrl(full['avatar'])) + '" alt="" class="rounded-circle" style="object-fit:cover;width:100%;height:100%;">'
                    : '<span class="avatar-initial rounded-circle bg-label-' + state + '">' + esc(initials) + '</span>';
                return '<div class="d-flex justify-content-start align-items-center user-name">' +
                    '<div class="avatar-wrapper"><div class="avatar avatar-sm me-4">' + face + '</div></div>' +
                    '<div class="d-flex flex-column">' +
                    // Tên là link sang trang Account của người đó. Mọi dòng lọt được vào
                    // bảng này đều đã qua scope_where(), tức là đúng tập mà Account cũng
                    // cho xem — không cần cờ riêng, và dòng của chính mình cũng vào được.
                    '<a href="index.php?menu=account&id=' + Number(full['id']) +
                    '" class="text-heading fw-medium">' + esc(name) + '</a>' +
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
                // Quyền theo TỪNG dòng. Nút lẻ -> thiếu quyền thì KHÓA, không ẩn (xem lockedBtn).
                const editBtn = full['can_edit']
                    ? `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon edit-user" data-id="${full['id']}" title="Edit"><i class="icon-base ti tabler-edit icon-22px"></i></button>`
                    : lockedBtn('tabler-edit', 'You cannot edit this user');
                const deleteBtn = full['can_delete']
                    ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-user" data-id="${full['id']}" data-name="${esc(full['username'])}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                    : lockedBtn('tabler-trash', 'You cannot delete this user');
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

    // Khóa cột suy thẳng từ `columns` để không lệch khi cột Salary có/không tùy quyền
    const USER_COLS = columns.map(c => c.data);
    urlState = dtUrlState(
        { UserRole: '#UserRole', UserStatus: '#UserStatus', UserTeam: '#UserTeam' }, 25);

    dtUsers = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: '../../ajax.php?action=get-authors-table',
            type: 'POST',
            data: function (d) {
                // Lần vẽ đầu các ô lọc chưa dựng (buildFilters chạy trong initComplete)
                // nên phải lấy thẳng từ URL, nếu không bảng vẽ sai rồi mới sửa lại.
                d.level = $('#UserRole').length ? ($('#UserRole').val() || '') : urlState.get('UserRole');
                d.status = $('#UserStatus').length ? ($('#UserStatus').val() || '') : urlState.get('UserStatus');
                d.team = $('#UserTeam').length ? ($('#UserTeam').val() || '') : urlState.get('UserTeam');
            },
            dataSrc: json => json.data
        },
        columns: columns,
        columnDefs: columnDefs,
        order: [[1, 'asc']],
        displayLength: 25,
        // PHẢI spread SAU order/displayLength, nếu không mặc định ghi đè giá trị từ URL
        ...urlState.tableOptions(USER_COLS),
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
            urlState.bind(dtUsers, USER_COLS);
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
    // Khối Filter luôn được render; giữ guard phòng khi fragment đổi (các $() vốn no-op)
    if (!document.getElementById('filterCard')) {
        return;
    }
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

    // Đổ trạng thái từ URL vào các ô lọc — gồm cả link từ trang khác sang:
    //   Teams -> ?UserTeam=<id>   |   Roles (cột "Assigned To") -> ?UserRole=<id>
    // Helper tự bỏ qua ô không tồn tại (ô Team chỉ admin có).
    const preset = urlState.applyFilters();

    $('#UserRole, #UserStatus, #UserTeam').on('change', function () {
        refreshFilterBadge();
        api.draw();
    });

    // Mở sẵn khối Filter khi có lọc dựng từ URL để người dùng thấy ngay mình đang bị lọc
    initFilterCollapse(preset);
    // KHÔNG draw lại ở đây: lần vẽ đầu đã đọc thẳng bộ lọc từ URL (xem hàm data() phía
    // trên), draw thêm chỉ tốn một request nữa cho cùng kết quả.
    refreshFilterBadge();
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
    // Role admin là MỤC trong nhóm lựa chọn -> non-admin thì ẨN hẳn mục đó (save_user() từ
    // chối "You cannot assign the admin role", để lại chỉ tổ cho người dùng chọn rồi báo lỗi).
    // Chỉ liệt kê role có cấp THẤP HƠN mình — server cũng từ chối role ngang/cao hơn,
    // để lại trong select chỉ tổ cho người dùng chọn rồi báo lỗi.
    let roles = rolesObj;
    if (Array.isArray(userPerms.assignable_roles)) {
        const cho_phep = userPerms.assignable_roles.map(String);
        roles = Object.fromEntries(Object.entries(rolesObj).filter(([id]) => cho_phep.includes(String(id))));
    }
    fillSelect($('#user-level'), roles, null);
    fillSelect($('#user-team'), teamsObj, null);
    fillSelect($('#user-status'), statusesObj, null);
    $('#user-level, #user-team, #user-status').each(function () {
        $(this).select2({ dropdownParent: $('#offcanvasUser'), minimumResultsForSearch: Infinity });
    });
}

// --- Avatar trong form ---
function setAvatarPreview(path, username) {
    const $img = $('#user-avatar-preview');
    const $ini = $('#user-avatar-initial');
    $('#user-avatar').val(path || '');
    if (path) {
        $img.attr('src', avatarUrl(path)).removeClass('d-none');
        $ini.addClass('d-none');
        $('#user-avatar-reset').removeClass('d-none');
    } else {
        $img.attr('src', '').addClass('d-none');
        let initials = (String(username ?? '').match(/\b\w/g) || []).map(c => c.toUpperCase());
        $ini.text(((initials.shift() || '') + (initials.pop() || '')).toUpperCase() || '?').removeClass('d-none');
        $('#user-avatar-reset').addClass('d-none');
    }
}

$(document).on('change', '#user-avatar-file', function () {
    const file = this.files && this.files[0];
    if (!file) {
        return;
    }
    const $hint = $('#user-avatar-hint');
    $hint.removeClass('text-danger').text('Uploading...');

    // Vuexy Dropzone là bản GIẢ (không POST) -> tự gửi bằng fetch
    const fd = new FormData();
    fd.append('file', file);
    fd.append('id', $('#user-id').val() || 0);
    fd.append('csrf_token', window.csrfToken);

    fetch('../../ajax.php?action=upload-user-avatar', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res?.status === 'success') {
                setAvatarPreview(res.avatar, $('#user-username').val());
                $hint.text('Uploaded. Save the form to apply.');
            } else {
                $hint.addClass('text-danger').text(res?.message || 'Upload failed.');
            }
        })
        .catch(() => $hint.addClass('text-danger').text('Server connection error.'))
        .finally(() => { this.value = ''; });   // cho phép chọn lại đúng file vừa rồi
});

$(document).on('click', '#user-avatar-reset', function () {
    setAvatarPreview('', $('#user-username').val());
    $('#user-avatar-hint').removeClass('text-danger').text('Avatar removed. Save the form to apply.');
});

function openUserForm(row) {
    $('#offcanvasUserLabel').text(row ? 'Edit User' : 'Add User');
    $('#user-id').val(row?.id ?? 0);
    setAvatarPreview(row?.avatar ?? '', row?.username ?? '');
    $('#user-avatar-hint').removeClass('text-danger').text('PNG or JPG, max 2 MB. Resized to 96×96.');
    $('#user-username').val(row?.username ?? '').removeClass('is-invalid');
    $('#user-email').val(row?.email ?? '').removeClass('is-invalid');
    $('#user-password').val('').removeClass('is-invalid');
    $('#user-password-hint').text(row ? 'Leave blank to keep the current password.' : 'At least 8 characters.');
    // Select Role chỉ liệt kê cấp THẤP HƠN mình, nên khi sửa một dòng có cấp không nằm
    // trong đó (rõ nhất là sửa CHÍNH MÌNH) thì không có option nào để hiện -> ô trống trơ.
    // Bơm tạm một option cho đúng cấp của dòng đang sửa, chỉ để HIỂN THỊ: select đã bị
    // khóa nên không chọn được gì khác, và server vẫn từ chối mọi thay đổi cấp.
    $('#user-level option.level-readonly').remove();
    if (row && row.level && !$('#user-level option[value="' + row.level + '"]').length) {
        $('#user-level').append(
            $('<option class="level-readonly">').val(String(row.level)).text(row.role_name || ''));
    }
    // Thêm mới: chọn sẵn giá trị đầu tiên (và team của chính mình) — để trống thì
    // select2 hiện ô rỗng và lưu sẽ báo "Invalid role".
    const firstLevel = $('#user-level option').first().val() ?? '';
    const firstTeam = String(userPerms.own_team || '') || ($('#user-team option').first().val() ?? '');
    $('#user-level').val(String(row?.level ?? firstLevel)).trigger('change.select2');
    $('#user-team').val(String(row?.team_id ?? firstTeam)).trigger('change.select2');
    editingOriginalTeam = row ? String(row.team_id) : null;
    $('#user-status').val(String(row?.status ?? 2)).trigger('change.select2');

    // KHÓA theo TỪNG TRƯỜNG những gì server sẽ từ chối, thay vì để người dùng bấm Save mới
    // biết. Sửa chính mình: save_user() chặn đổi role/team/status/LƯƠNG của bản thân (tự hạ
    // quyền, tự khóa tài khoản, tự nâng lương). Team của non-admin vốn đã khóa trong fragment.
    const isSelf = !!row && Number(row.id) === Number(userPerms.my_id || 0);
    $('#user-level').prop('disabled', isSelf)
        .attr('title', isSelf ? 'You cannot change your own role' : null);
    $('#user-status').prop('disabled', isSelf)
        .attr('title', isSelf ? 'You cannot change your own status' : null);
    if (userPerms.is_admin) {
        $('#user-team').prop('disabled', isSelf)
            .attr('title', isSelf ? 'You cannot move yourself to another team' : null);
    }
    $('#user-level, #user-team, #user-status').trigger('change.select2');
    $('#user-self-hint').toggleClass('d-none', !isSelf)
        .find('.self-hint-text').text(userPerms.see_salary
            ? 'You are editing your own account, so role, team, status and salary are locked.'
            : 'You are editing your own account, so role, team and status are locked.');

    if (userPerms.see_salary) {
        // wage trả về đã format tiền tệ -> lấy lại phần số để đưa vào ô nhập
        $('#user-wage').val(String(row?.wage ?? '').replace(/\D/g, '') || 0);
        $('#user-insurance').val(String(row?.insurance ?? '').replace(/\D/g, '') || 0);
        // Tự sửa mình thì KHÓA lương: manager nhìn thấy ô này nên không khóa là tự nâng
        // lương được. Khóa chứ không ẩn — lương của CHÍNH MÌNH thì được quyền xem.
        $('#user-wage, #user-insurance').prop('disabled', isSelf)
            .attr('title', isSelf ? 'You cannot change your own salary' : null);
    }
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasUser')).show();
}

$(document).on('click', '.edit-user', function () {
    const id = parseInt($(this).data('id'), 10);
    const row = dtUsers ? dtUsers.rows().data().toArray().find(r => r.id === id) : null;
    openUserForm(row);
});

// Team gốc của bản ghi đang mở — dùng để phát hiện admin đổi team
let editingOriginalTeam = null;

// Đổi team kéo theo cả tầm nhìn dữ liệu (sản phẩm đi theo tác giả) nên phải xác nhận
// có số liệu trước, thay vì lặng lẽ chuyển.
function confirmTeamMove(id, targetTeam) {
    return new Promise(resolve => {
        const modalEl = document.getElementById('moveUserModal');
        if (!modalEl) {
            resolve(true);   // không phải admin -> không đổi được team, khỏi hỏi
            return;
        }
        $.ajax({
            url: '../../ajax.php?action=get-user-move-preview',
            type: 'POST',
            data: { id: id, team_id: targetTeam, csrf_token: window.csrfToken }
        }).done(function (res) {
            if (res?.status !== 'success') {
                alert(res?.message || 'Failed to check the team change.');
                resolve(false);
                return;
            }
            $('#moveUserName').text(res.username);
            $('#moveUserFrom').text(res.from);
            $('#moveUserTo').text(res.to);
            $('#moveCntProducts').text((res.counts.products || 0).toLocaleString());
            $('#moveCntAccounts').text((res.counts.accounts || 0).toLocaleString());
            $('#moveCntStores').text((res.counts.stores || 0).toLocaleString());

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            let confirmed = false;
            $('#moveUserConfirm').off('click.move').on('click.move', function () {
                confirmed = true;
                modal.hide();
            });
            $(modalEl).off('hidden.bs.modal.move').on('hidden.bs.modal.move', () => resolve(confirmed));
            modal.show();
        }).fail(function () {
            alert('Server connection error.');
            resolve(false);
        });
    });
}

$(document).on('click', '#userSubmit', async function () {
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

    // Sửa user và team bị đổi -> hỏi trước, huỷ thì không lưu gì cả
    const targetTeam = $('#user-team').val();
    if (!isNew && editingOriginalTeam !== null && String(targetTeam) !== String(editingOriginalTeam)) {
        const okMove = await confirmTeamMove($('#user-id').val(), targetTeam);
        if (!okMove) {
            return;
        }
    }

    const data = {
        id: $('#user-id').val(),
        username: username,
        email: email,
        password: password,
        level: $('#user-level').val(),
        team_id: $('#user-team').val(),
        status: $('#user-status').val(),
        avatar: $('#user-avatar').val() || '',
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

// --- Xóa: chỉ TỪNG DÒNG một, kèm bàn giao (hoặc xóa) sản phẩm theo lô ---
const DELETE_MAX_ROUNDS = 2000;   // chặn vòng lặp vô hạn nếu server cứ trả 'partial'

// Chọn None thì sản phẩm bị xóa hẳn — nói rõ để admin không bấm nhầm
function updateTransferHint() {
    const isNone = $('#deleteUserTransfer').val() === 'none';
    $('#deleteUserTransferHint')
        .toggleClass('text-danger', isNone)
        .text(isNone
            ? 'Their products will be deleted permanently along with the account.'
            : 'Only members of the same team can take them over.');
    $('#deleteUserConfirm').text(isNone ? 'Delete user and products' : 'Delete')
        .prepend($('#deleteUserSpinner'));
}

$(document).on('change', '#deleteUserTransfer', updateTransferHint);

$(document).on('click', '.delete-user', function () {
    const modalEl = document.getElementById('deleteUserModal');
    if (!modalEl) {
        return;   // không đủ quyền xóa -> fragment không render modal
    }
    const id = parseInt($(this).data('id'), 10);
    $('#deleteUserName').text(String($(this).data('name') ?? ''));
    $(modalEl).data('id', id);

    // Reset trạng thái cho lần mở mới
    $('#deleteUserLoading').removeClass('d-none');
    $('#deleteUserSummary').addClass('d-none');
    $('#deleteUserTransferBox').addClass('d-none');
    $('#deleteUserOrphanNote').addClass('d-none');
    $('#deleteUserProgress').addClass('d-none');
    $('#deleteUserBar').css('width', '0%').removeClass('bg-warning');
    $('#deleteUserProgressText').text('');
    $('#deleteUserConfirm').prop('disabled', true).show();
    $('#deleteUserSpinner').addClass('d-none');
    $('#deleteUserCancel').text('Cancel').prop('disabled', false);
    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    // Đếm trước + lấy danh sách người có thể nhận bàn giao
    $.ajax({
        url: '../../ajax.php?action=get-user-delete-preview',
        type: 'POST',
        data: { id: id, csrf_token: window.csrfToken }
    }).done(function (res) {
        $('#deleteUserLoading').addClass('d-none');
        if (res?.status !== 'success') {
            $('#deleteUserProgress').removeClass('d-none');
            $('#deleteUserProgressText').text(res?.message || 'Failed to load user data.');
            return;
        }
        renderDeleteStats(res);
        $(modalEl).data('products', res.products || 0);
        // Customer: xóa thẳng, không bàn giao — server đã quyết, JS chỉ trình bày cho khớp.
        $(modalEl).data('orphan', !!res.orphan);
        $('#deleteUserSummary').removeClass('d-none');

        if (res.orphan) {
            $('#deleteUserOrphanNote').removeClass('d-none');
        } else if (res.products > 0) {
            const $sel = $('#deleteUserTransfer').empty();
            (res.candidates || []).forEach(c => $sel.append(new Option(c.username, c.id, false, false)));
            // 'none' = không bàn giao, xóa luôn sản phẩm. Để cuối danh sách để không thành
            // lựa chọn mặc định — bàn giao mới là hành vi an toàn.
            $sel.append(new Option('None — delete their products', 'none', false, false));
            $('#deleteUserTransferBox').removeClass('d-none');
            if (!$sel.hasClass('select2-hidden-accessible')) {
                $sel.select2({ dropdownParent: $(modalEl) });
            }
            $sel.val((res.candidates || []).length ? String(res.candidates[0].id) : 'none')
                .trigger('change.select2');
            updateTransferHint();
        }
        $('#deleteUserConfirm').prop('disabled', false);
    }).fail(function () {
        $('#deleteUserLoading').addClass('d-none');
        $('#deleteUserProgress').removeClass('d-none');
        $('#deleteUserProgressText').text('Server connection error.');
    });
});

// Số phận từng bảng -> nhãn + màu. 'choice' để trống vì ô chọn bàn giao ngay bên dưới
// đã nói rõ hơn bất kỳ nhãn nào.
const FATE_BADGE = {
    removed: ['Deleted', 'bg-label-danger'],
    unlink:  ['Unlinked', 'bg-label-warning'],
    kept:    ['Kept', 'bg-label-secondary'],
    orphan:  ['Left behind', 'bg-label-warning'],
    choice:  ['', '']
};

function renderDeleteStats(res) {
    const rows = Array.isArray(res.stats) ? res.stats : [];
    const $tb = $('#delStatsRows');
    if (!rows.length) {
        $tb.html('<tr><td class="text-body-secondary">Nothing else is linked to this user.</td></tr>');
        return;
    }
    $tb.html(rows.map(r => {
        const [label, cls] = FATE_BADGE[r.fate] || ['', ''];
        const badge = label ? `<span class="badge ${cls} ms-2">${esc(label)}</span>` : '';
        return `<tr><td>${esc(r.label)}${badge}</td>` +
            `<td class="text-end fw-medium">${Number(r.n).toLocaleString()}</td></tr>`;
    }).join(''));
}

$(document).on('click', '#deleteUserConfirm', async function () {
    const modalEl = document.getElementById('deleteUserModal');
    const id = $(modalEl).data('id');
    const products = $(modalEl).data('products') || 0;
    if (!id) {
        return;
    }
    const transferTo = (products > 0 && !$(modalEl).data('orphan'))
        ? ($('#deleteUserTransfer').val() || '') : '';
    const removing = transferTo === 'none';
    const $bar = $('#deleteUserBar');
    const $text = $('#deleteUserProgressText');

    $(this).prop('disabled', true);
    $('#deleteUserSpinner').removeClass('d-none');
    $('#deleteUserCancel').prop('disabled', true);
    $('#deleteUserProgress').removeClass('d-none');
    $text.text(products > 0
        ? (removing ? 'Deleting their products...' : 'Handing over products...')
        : 'Deleting...');

    let done = 0;
    let rounds = 0;
    try {
        for (;;) {
            const res = await $.ajax({
                url: '../../ajax.php?action=delete-users',
                type: 'POST',
                data: { ids: [id], transfer_to: transferTo, csrf_token: window.csrfToken }
            });
            if (res?.status === 'error') {
                throw new Error(res.message || 'Delete failed');
            }
            if (res?.status === 'partial') {
                done += (res.transferred ?? 0) + (res.removed ?? 0);
                const pct = products > 0 ? Math.min(99, Math.round((done / products) * 100)) : 50;
                $bar.css('width', pct + '%');
                $text.text((removing ? 'Deleted ' : 'Handed over ') + done.toLocaleString()
                    + '/' + products.toLocaleString() + ' products...');
                if (++rounds > DELETE_MAX_ROUNDS) {
                    throw new Error('Too much data to process in one go. Please run the delete again.');
                }
                continue;
            }
            done += (res?.transferred ?? 0) + (res?.removed ?? 0);
            $bar.css('width', '100%');
            $text.text(done > 0
                ? 'Done — ' + (removing ? 'deleted ' : 'handed over ') + done.toLocaleString()
                    + ' products, user deleted.'
                : 'User deleted.');
            break;
        }

        setTimeout(function () {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            $(modalEl).removeData('id').removeData('products');
            if (dtUsers) {
                dtUsers.draw(false);
            }
        }, 1000);
    } catch (err) {
        // Giữ modal mở để đọc lỗi; phần đã bàn giao vẫn giữ nguyên, chạy lại sẽ tiếp tục
        $bar.addClass('bg-warning');
        $text.text(err?.message || 'Server connection error.');
        $('#deleteUserConfirm').hide();
        $('#deleteUserCancel').text('Close').prop('disabled', false);
        if (dtUsers) {
            dtUsers.draw(false);
        }
    } finally {
        $('#deleteUserSpinner').addClass('d-none');
    }
});

document.addEventListener('DOMContentLoaded', function () {
    init();
});
