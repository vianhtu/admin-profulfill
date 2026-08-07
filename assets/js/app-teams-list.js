/**
 * app-teams-list Script (menu Teams — admin-only)
 */

'use strict';

// Escape dữ liệu người dùng (tên team...) trước khi nhét vào HTML -> chặn stored XSS
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

// Thứ tự PHẢI khớp mảng `columns` bên dưới
const TEAM_COLS = ['id', 'name', 'members', 'status', 'id'];
let urlState = null;
let dtTeams = null;

function initTable() {
    const el = document.querySelector('.datatables-teams');
    if (!el) {
        return;
    }

    const avatarStates = ['success', 'danger', 'warning', 'info', 'dark', 'primary', 'secondary'];

    // Teams không có ô lọc riêng — vẫn phải giữ search/sắp xếp/phân trang trên URL
    urlState = dtUrlState({}, 10);

    dtTeams = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: '../../ajax.php?action=get-teams-table',
            type: 'POST',
            data: function (d) {},
            dataSrc: json => json.data
        },
        // Không có thao tác hàng loạt -> KHÔNG có cột checkbox chọn dòng
        columns: [
            { data: 'id' },
            { data: 'name' },
            { data: 'members' },
            { data: 'status' },
            { data: 'id' }
        ],
        columnDefs: [
            { className: 'control', searchable: false, orderable: false, responsivePriority: 2, targets: 0, render: () => '' },
            {
                targets: 1,
                responsivePriority: 3,
                render: function (data, type, full) {
                    const name = String(full['name'] ?? '');
                    // Màu avatar ổn định theo id (không random để không nhấp nháy mỗi lần draw)
                    const state = avatarStates[full['id'] % avatarStates.length];
                    let initials = (name.match(/\b\w/g) || []).map(ch => ch.toUpperCase());
                    initials = ((initials.shift() || '') + (initials.pop() || '')).toUpperCase();
                    return '<div class="d-flex justify-content-start align-items-center user-name">' +
                        '<div class="avatar-wrapper"><div class="avatar avatar-sm me-4">' +
                        '<span class="avatar-initial rounded-circle bg-label-' + state + '">' + esc(initials) + '</span>' +
                        '</div></div>' +
                        '<div class="d-flex flex-column">' +
                        '<span class="text-heading fw-medium">' + esc(name) + '</span>' +
                        '</div></div>';
                }
            },
            {
                targets: 2,
                render: function (data, type, full) {
                    const n = full['members'];
                    // Bấm vào số member -> trang Users lọc theo team
                    return `<a href="index.php?menu=users&UserTeam=${full['id']}" title="View members">` +
                        `<span class="badge ${n > 0 ? 'bg-label-primary' : 'bg-label-secondary'}"><i class="icon-base ti tabler-users icon-14px me-1"></i>${n.toLocaleString()}</span></a>`;
                }
            },
            {
                targets: 3,
                render: (d, t, full) => full['status'] === 1
                    ? '<span class="badge bg-label-success">Active</span>'
                    : '<span class="badge bg-label-secondary">Inactive</span>'
            },
            {
                targets: -1, title: 'Actions', searchable: false, orderable: false,
                render: function (d, t, full) {
                    // Quyền theo từng dòng đúng quy ước chung (trang này admin-only nên luôn true)
                    const viewBtn = `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon view-key" data-id="${Number(full['id'])}" data-name="${esc(full['name'])}" data-key="${esc(full['key'])}" title="View key"><i class="icon-base ti tabler-key icon-22px"></i></button>`;
                    const editBtn = full['can_edit']
                        ? `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon edit-team" data-id="${full['id']}" data-name="${esc(full['name'])}" data-status="${full['status']}" title="Edit"><i class="icon-base ti tabler-edit icon-22px"></i></button>`
                        : lockedBtn('tabler-edit', 'You cannot edit this team');
                    const deleteBtn = full['can_delete']
                        ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-team" data-id="${full['id']}" data-name="${esc(full['name'])}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                        : lockedBtn('tabler-trash', 'You cannot delete this team');
                    return `<div class="d-inline-block text-nowrap">${viewBtn}${editBtn}${deleteBtn}</div>`;
                }
            }
        ],
        order: [[1, 'asc']],
        // PHẢI spread SAU order, nếu không mặc định ghi đè giá trị từ URL
        ...urlState.tableOptions(TEAM_COLS),
        layout: {
            topStart: {
                rowClass: 'row m-3 my-0 justify-content-between',
                features: [{ pageLength: { menu: [10, 25, 50, 100], text: '_MENU_' } }]
            },
            topEnd: {
                features: [
                    { search: { placeholder: 'Search Team', text: '_INPUT_' } },
                    {
                        buttons: [
                            // Teams KHÔNG có Delete Selected: xóa team là thao tác nặng
                            // (kéo theo members/accounts/stores) nên chỉ cho xóa từng dòng.
                            {
                                text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-plus icon-xs"></i> <span class="d-none d-sm-inline-block">Add New Team</span></span>',
                                className: 'add-new btn btn-primary',
                                action: () => openTeamForm()
                            }
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
        // For responsive popup
        responsive: {
            details: {
                display: DataTable.Responsive.display.modal({
                    header: function (row) {
                        const data = row.data();
                        return 'Details of ' + esc(data['name']);
                    }
                }),
                type: 'column',
                renderer: function (api, rowIdx, columns) {
                    const data = columns
                        .map(function (col) {
                            return col.title !== ''
                                ? `<tr data-dt-row="${col.rowIndex}" data-dt-column="${col.columnIndex}">
                      <td>${col.title}:</td>
                      <td>${col.data}</td>
                    </tr>`
                                : '';
                        })
                        .join('');

                    if (data) {
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
                    return false;
                }
            }
        }
    });

    // Ghi search/sắp xếp/phân trang lên URL sau mỗi lần bảng vẽ lại
    urlState.bind(dtTeams, TEAM_COLS);

    // Status select trong offcanvas — mọi <select> đều là select2 theo quy ước template
    const $status = $('#team-status');
    $status.select2({
        dropdownParent: $('#offcanvasTeam'),
        minimumResultsForSearch: Infinity
    });

    // Filter form control to default size
    setTimeout(() => {
        const tweaks = [
            { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary' },
            { selector: '.dt-search .form-control', classToRemove: 'form-control-sm' },
            { selector: '.dt-length .form-select', classToRemove: 'form-select-sm', classToAdd: 'ms-0' },
            { selector: '.dt-length', classToAdd: 'mb-md-6 mb-0' },
            {
                selector: '.dt-layout-end',
                classToRemove: 'justify-content-between',
                classToAdd: 'd-flex gap-md-4 justify-content-md-between justify-content-center gap-2 flex-wrap'
            },
            { selector: '.dt-buttons', classToAdd: 'd-flex gap-4 mb-md-0 mb-4' },
            { selector: '.dt-layout-table', classToRemove: 'row mt-2' },
            { selector: '.dt-layout-full', classToRemove: 'col-md col-12', classToAdd: 'table-responsive' },
            // Bỏ .btn-group do DataTables Buttons thêm vào: nó cắt bo góc các nút rời nhau
            { selector: '.dt-buttons', classToRemove: 'btn-group' }
        ];
        tweaks.forEach(({ selector, classToRemove, classToAdd }) => {
            document.querySelectorAll(selector).forEach(element => {
                if (classToRemove) classToRemove.split(' ').forEach(c => element.classList.remove(c));
                if (classToAdd) classToAdd.split(' ').forEach(c => element.classList.add(c));
            });
        });

        // Select số dòng/trang cũng là select2 theo chuẩn template (giống trang Stores)
        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
    }, 100);
}

// --- Xem key của team (credential extension) ---
const KEY_HINT = 'The extension authenticates with this key. Generating a new one stops the '
    + 'old key working immediately.';

$(document).on('click', '.view-key', function () {
    $('#viewKeyTeamName').text(String($(this).data('name') ?? ''));
    $('#viewKeyValue').val(String($(this).data('key') ?? ''));
    // Nhớ team đang mở để nút tạo key mới biết gửi id nào
    $('#viewKeyModal').data('id', parseInt($(this).data('id'), 10) || 0);
    $('#viewKeyHint').removeClass('text-danger text-success').text(KEY_HINT);
    $('#newKeyConfirm').addClass('d-none');
    $('#newKeyYes').prop('disabled', false);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('viewKeyModal')).show();
});

$(document).on('click', '#copyKeyBtn', function () {
    const $i = $(this).find('i');
    const key = $('#viewKeyValue').val();
    navigator.clipboard?.writeText(key).then(() => {
        // Nút chỉ có icon -> báo bằng cách đổi icon, không đổi chữ
        $i.attr('class', 'icon-base ti tabler-check');
        setTimeout(() => $i.attr('class', 'icon-base ti tabler-copy'), 1500);
    }).catch(() => {
        // Fallback: bôi đen sẵn cho user tự Ctrl+C (clipboard API cần HTTPS)
        $('#viewKeyValue').trigger('select');
    });
});

// Tạo key mới: thu hồi key cũ NGAY, extension đang chạy sẽ hỏng tới khi được cập nhật ->
// phải hỏi trước. KHÔNG dùng window.confirm — trình duyệt có quyền chặn hộp thoại đó và khi
// bị chặn nó trả false, làm nút bấm xong không có gì xảy ra và cũng không báo lỗi gì (đã
// dính đúng vậy 07/08/2026). Hỏi bằng khối cảnh báo ngay trong modal.
$(document).on('click', '#newKeyBtn', function () {
    $('#newKeyConfirm').removeClass('d-none');
});

$(document).on('click', '#newKeyNo', function () {
    $('#newKeyConfirm').addClass('d-none');
});

$(document).on('click', '#newKeyYes', function () {
    const id = $('#viewKeyModal').data('id');
    if (!id) {
        $('#viewKeyHint').removeClass('text-success').addClass('text-danger')
            .text('Could not tell which team this is — close the dialog and try again.');
        return;
    }
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: '../../ajax.php?action=regenerate-team-key',
        type: 'POST',
        dataType: 'json',
        data: { id: id, csrf_token: window.csrfToken }
    }).done(function (res) {
        if (res?.status === 'success') {
            $('#viewKeyValue').val(res.key);
            $('#newKeyConfirm').addClass('d-none');
            $('#viewKeyHint').removeClass('text-danger').addClass('text-success').text(res.message);
            // Nút View key của dòng đó vẫn giữ key CŨ trong data-key -> vẽ lại bảng,
            // nếu không lần mở sau sẽ hiện key đã hết hiệu lực.
            if (dtTeams) {
                dtTeams.ajax.reload(null, false);
            }
        } else {
            $('#viewKeyHint').removeClass('text-success').addClass('text-danger')
                .text(res?.message || 'Failed to generate a new key.');
        }
    }).fail(function () {
        $('#viewKeyHint').removeClass('text-success').addClass('text-danger')
            .text('Server connection error.');
    }).always(() => $btn.prop('disabled', false));
});

// --- Add/Edit qua offcanvas ---
function openTeamForm(team) {
    $('#offcanvasTeamLabel').text(team ? 'Edit Team' : 'Add New Team');
    $('#team-id').val(team?.id ?? 0);
    $('#team-name').val(team?.name ?? '').removeClass('is-invalid');
    $('#team-status').val(String(team?.status ?? 1)).trigger('change.select2');
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasTeam')).show();
}

$(document).on('click', '.edit-team', function () {
    openTeamForm({
        id: parseInt($(this).data('id'), 10),
        name: String($(this).data('name') ?? ''),
        status: parseInt($(this).data('status'), 10)
    });
});

$(document).on('click', '#teamSubmit', function () {
    const $btn = $(this);
    const name = $('#team-name').val().trim();
    if (!name) {
        $('#team-name').addClass('is-invalid').focus();
        return;
    }
    $('#team-name').removeClass('is-invalid');
    $btn.prop('disabled', true);

    $.ajax({
        url: '../../ajax.php?action=save-team',
        type: 'POST',
        data: {
            id: $('#team-id').val(),
            name: name,
            status: $('#team-status').val(),
            csrf_token: window.csrfToken
        },
        success: function (res) {
            if (res?.status === 'success') {
                bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasTeam'))?.hide();
                if (dtTeams) {
                    dtTeams.draw(false);
                }
            } else {
                alert(res?.message || 'Failed to save team.');
            }
        },
        error: function () {
            alert('Server connection error.');
        },
        complete: function () {
            $btn.prop('disabled', false);
        }
    });
});

// --- Xóa team: xóa dây chuyền theo lô, có thanh tiến trình ---
// Chỉ TỪNG DÒNG một (không có thao tác hàng loạt). Mỗi request server xử lý một phần rồi
// trả 'partial'; client gọi tiếp tới khi finished để không request nào chạy quá lâu.
const PURGE_MAX_ROUNDS = 2000;   // chặn vòng lặp vô hạn nếu server cứ trả 'partial'

function openDeleteTeamModal(id, name) {
    const modalEl = document.getElementById('deleteTeamModal');
    $('#deleteTeamName').text(name || ('#' + id));
    $(modalEl).data('id', id);

    // Reset trạng thái modal cho lần mở mới
    $('#deleteTeamLoading').removeClass('d-none');
    $('#deleteTeamSummary').addClass('d-none');
    $('#deleteTeamActiveWarn').addClass('d-none');
    $('#deleteTeamProgress').addClass('d-none');
    $('#deleteTeamBar').css('width', '0%');
    $('#deleteTeamProgressText').text('');
    $('#deleteTeamConfirm').prop('disabled', true).show();
    $('#deleteTeamMerge').prop('disabled', true).show();
    $('#deleteTeamSpinner').addClass('d-none');
    $('#deleteTeamCancel').text('Cancel').prop('disabled', false);
    $('#deleteTeamClose').prop('disabled', false);

    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    // Đếm trước những gì sẽ mất để admin quyết định có tay trong tay
    $.ajax({
        url: '../../ajax.php?action=get-team-purge-preview',
        type: 'POST',
        data: { id: id, csrf_token: window.csrfToken }
    }).done(function (res) {
        $('#deleteTeamLoading').addClass('d-none');
        if (res?.status !== 'success') {
            $('#deleteTeamProgressText').text(res?.message || 'Failed to load team data.');
            $('#deleteTeamProgress').removeClass('d-none');
            return;
        }
        const c = res.counts || {};
        $('#cntMembers').text((c.members || 0).toLocaleString());
        $('#cntAccounts').text((c.accounts || 0).toLocaleString());
        $('#cntProducts').text((c.products || 0).toLocaleString());
        $('#cntOrders').text((c.orders || 0).toLocaleString());
        $('#cntStores').text((c.stores || 0).toLocaleString());
        $('#cntFiles').text((c.files || 0).toLocaleString());
        $('#cntSettings').text((c.settings || 0).toLocaleString());
        $('#cntPhones').text((c.phones || 0).toLocaleString());
        $('#cntMessages').text((c.messages || 0).toLocaleString());
        $(modalEl).data('total', res.total || 0);
        $('#deleteTeamSummary').removeClass('d-none');

        // Team còn Active -> khóa cả hai nút, bắt tắt team trước
        if (res.active) {
            $('#deleteTeamActiveWarn').removeClass('d-none');
            return;
        }

        const $target = $('#deleteTeamTarget').empty();
        (res.targets || []).forEach(t => $target.append(new Option(t.name, t.id, false, false)));
        const canMerge = (res.targets || []).length > 0;
        $('#deleteTeamMergeBox').toggleClass('d-none', !canMerge);
        if (canMerge && !$target.hasClass('select2-hidden-accessible')) {
            $target.select2({ dropdownParent: $(modalEl), minimumResultsForSearch: Infinity });
        }
        $('#deleteTeamMerge').prop('disabled', !canMerge);
        $('#deleteTeamConfirm').prop('disabled', false);
    }).fail(function () {
        $('#deleteTeamLoading').addClass('d-none');
        $('#deleteTeamProgress').removeClass('d-none');
        $('#deleteTeamProgressText').text('Server connection error.');
    });
}

$(document).on('click', '.delete-team', function () {
    openDeleteTeamModal(parseInt($(this).data('id'), 10), String($(this).data('name') ?? ''));
});

// Sáp nhập: chuyển thành viên/account/store riêng sang team khác rồi xóa team rỗng
$(document).on('click', '#deleteTeamMerge', function () {
    const modalEl = document.getElementById('deleteTeamModal');
    const id = $(modalEl).data('id');
    const target = $('#deleteTeamTarget').val();
    if (!id || !target) {
        return;
    }
    const $btn = $(this).prop('disabled', true);
    $('#deleteTeamConfirm').prop('disabled', true);
    $('#deleteTeamProgress').removeClass('d-none');
    $('#deleteTeamProgressText').text('Moving everything over...');

    $.ajax({
        url: '../../ajax.php?action=merge-team',
        type: 'POST',
        data: { id: id, target_team: target, csrf_token: window.csrfToken }
    }).done(function (res) {
        if (res?.status !== 'success') {
            $('#deleteTeamBar').addClass('bg-warning');
            $('#deleteTeamProgressText').text(res?.message || 'Merge failed.');
            $btn.prop('disabled', false);
            $('#deleteTeamConfirm').prop('disabled', false);
            return;
        }
        const m = res.moved || {};
        $('#deleteTeamBar').css('width', '100%');
        $('#deleteTeamProgressText').text('Done — moved ' + (m.members || 0) + ' members, '
            + (m.accounts || 0) + ' accounts, ' + (m.stores || 0) + ' stores, '
            + (m.phones || 0) + ' phone numbers. Conflicting API keys were kept from the target team.');
        setTimeout(function () {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            $(modalEl).removeData('id').removeData('total');
            if (dtTeams) {
                dtTeams.draw(false);
            }
        }, 1200);
    }).fail(function () {
        $('#deleteTeamProgressText').text('Server connection error.');
        $btn.prop('disabled', false);
    });
});

$(document).on('click', '#deleteTeamConfirm', async function () {
    const modalEl = document.getElementById('deleteTeamModal');
    const id = $(modalEl).data('id');
    const total = $(modalEl).data('total') || 0;
    if (!id) {
        return;
    }

    const $bar = $('#deleteTeamBar');
    const $text = $('#deleteTeamProgressText');

    // Khóa mọi đường thoát trong lúc chạy: đứt giữa chừng để lại dữ liệu dở dang
    $(this).prop('disabled', true);
    $('#deleteTeamMerge').prop('disabled', true);
    $('#deleteTeamSpinner').removeClass('d-none');
    $('#deleteTeamCancel').prop('disabled', true);
    $('#deleteTeamClose').prop('disabled', true);
    $('#deleteTeamSummary').addClass('d-none');
    $('#deleteTeamProgress').removeClass('d-none');
    $text.text('Starting...');

    let done = 0;
    let rounds = 0;
    try {
        for (;;) {
            const res = await $.ajax({
                url: '../../ajax.php?action=purge-team',
                type: 'POST',
                data: { id: id, csrf_token: window.csrfToken }
            });
            if (res?.status === 'error') {
                throw new Error(res.message || 'Purge failed');
            }
            done += res?.deleted ?? 0;
            const stage = res?.stage || '';
            // total chỉ là ước lượng (không đếm bảng con) -> chặn trần 99% khi chưa xong
            const pct = total > 0 ? Math.min(99, Math.round((done / total) * 100)) : 50;

            if (res?.status === 'partial') {
                $bar.css('width', pct + '%');
                $text.text(stage + ': removed ' + done.toLocaleString() + ' records...');
                if (++rounds > PURGE_MAX_ROUNDS) {
                    throw new Error('Too much data to process in one go. Please run the delete again.');
                }
                continue;
            }
            // finished
            $bar.css('width', '100%');
            $text.text('Done — removed ' + done.toLocaleString() + ' records.');
            break;
        }

        // Cho admin nhìn thấy kết quả rồi mới đóng
        setTimeout(function () {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            $(modalEl).removeData('id').removeData('total');
            if (dtTeams) {
                dtTeams.draw(false);
            }
        }, 1200);
    } catch (err) {
        // Giữ modal mở để đọc lỗi; phần đã xóa vẫn giữ nguyên, chạy lại sẽ dọn tiếp
        $bar.addClass('bg-warning');
        $text.text(err?.message || 'Server connection error.');
        $('#deleteTeamConfirm').hide();
        $('#deleteTeamCancel').text('Close').prop('disabled', false);
        $('#deleteTeamClose').prop('disabled', false);
        if (dtTeams) {
            dtTeams.draw(false);
        }
    } finally {
        $('#deleteTeamSpinner').addClass('d-none');
    }
});

document.addEventListener('DOMContentLoaded', function () {
    initTable();
});
