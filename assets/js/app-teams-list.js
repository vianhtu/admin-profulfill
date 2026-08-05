/**
 * app-teams-list Script (menu Teams — admin-only)
 */

'use strict';

// Escape dữ liệu người dùng (tên team...) trước khi nhét vào HTML -> chặn stored XSS
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

let dtTeams = null;

function initTable() {
    const el = document.querySelector('.datatables-teams');
    if (!el) {
        return;
    }

    const avatarStates = ['success', 'danger', 'warning', 'info', 'dark', 'primary', 'secondary'];

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
                    const viewBtn = `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon view-key" data-name="${esc(full['name'])}" data-key="${esc(full['key'])}" title="View key"><i class="icon-base ti tabler-key icon-22px"></i></button>`;
                    const editBtn = full['can_edit']
                        ? `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon edit-team" data-id="${full['id']}" data-name="${esc(full['name'])}" data-status="${full['status']}" title="Edit"><i class="icon-base ti tabler-edit icon-22px"></i></button>`
                        : '';
                    const deleteBtn = full['can_delete']
                        ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-team" data-id="${full['id']}" data-name="${esc(full['name'])}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                        : '';
                    return `<div class="d-inline-block text-nowrap">${viewBtn}${editBtn}${deleteBtn}</div>`;
                }
            }
        ],
        order: [[1, 'asc']],
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
$(document).on('click', '.view-key', function () {
    $('#viewKeyTeamName').text(String($(this).data('name') ?? ''));
    $('#viewKeyValue').val(String($(this).data('key') ?? ''));
    bootstrap.Modal.getOrCreateInstance(document.getElementById('viewKeyModal')).show();
});

$(document).on('click', '#copyKeyBtn', function () {
    const $btn = $(this);
    const key = $('#viewKeyValue').val();
    navigator.clipboard?.writeText(key).then(() => {
        $btn.text('Copied!');
        setTimeout(() => $btn.text('Copy'), 1500);
    }).catch(() => {
        // Fallback: bôi đen sẵn cho user tự Ctrl+C (clipboard API cần HTTPS)
        $('#viewKeyValue').trigger('select');
    });
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

// --- Xóa: chỉ TỪNG DÒNG một, không có thao tác hàng loạt ---
function openDeleteTeamModal(id, name) {
    const modalEl = document.getElementById('deleteTeamModal');
    $('#deleteTeamName').text(name || ('#' + id));
    $(modalEl).data('id', id);
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

$(document).on('click', '.delete-team', function () {
    openDeleteTeamModal(parseInt($(this).data('id'), 10), String($(this).data('name') ?? ''));
});

$(document).on('click', '#deleteTeamConfirm', function () {
    const modalEl = document.getElementById('deleteTeamModal');
    const id = $(modalEl).data('id');
    if (!id) {
        return;
    }
    $.ajax({
        url: '../../ajax.php?action=delete-teams',
        type: 'POST',
        data: { ids: [id], csrf_token: window.csrfToken },
        success: function (res) {
            if (res?.status === 'success') {
                bootstrap.Modal.getInstance(modalEl)?.hide();
                $(modalEl).removeData('id');
                if (dtTeams) {
                    dtTeams.draw(false);
                }
            } else {
                // Giữ modal mở để đọc lý do (vd còn members/stores tham chiếu)
                alert(res?.message || 'Failed to delete team.');
            }
        },
        error: function () {
            alert('Server connection error.');
        }
    });
});

document.addEventListener('DOMContentLoaded', function () {
    initTable();
});
