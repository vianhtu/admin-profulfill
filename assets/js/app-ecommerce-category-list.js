/**
 * app-ecommerce-category-list
 */

'use strict';

let teamsObj = {};
let dtCategories = null;
let categoryPerms = { add: false, edit: false, delete: false, filter_team: false };

async function init() {
    try {
        const options = await fetchTableFilter('get-categories-table-filter');
        teamsObj = options['teams'] ?? {};
        categoryPerms = options['perms'] ?? categoryPerms;
        initCategoryTable();
    } catch (err) {
        alert('Failed to load filter options');
    }
}

function initCategoryTable() {
    const el = document.querySelector('.datatables-categories');
    if (!el) {
        return;
    }

    const dt = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: '../../ajax.php?action=get-categories-table',
            type: 'POST',
            data: function (d) {
                d.team = $('#teamFilter').val() || '';
            },
            dataSrc: json => json.data
        },
        columns: [
            { data: 'id' },
            { data: 'id', orderable: false, render: DataTable.render.select() },
            { data: 'name' },
            { data: 'teams' },
            { data: 'products_count' },
            { data: 'prompt_preview' },
            { data: 'id' }
        ],
        columnDefs: [
            {
                className: 'control', searchable: false, orderable: false,
                responsivePriority: 2, targets: 0, render: () => ''
            },
            {
                targets: 1, orderable: false, searchable: false, responsivePriority: 3,
                checkboxes: { selectAllRender: '<input type="checkbox" class="form-check-input">' },
                render: () => '<input type="checkbox" class="dt-checkboxes form-check-input">'
            },
            {
                targets: 2, responsivePriority: 1,
                render: (data, type, full) => `<h6 class="text-nowrap mb-0">${full['name']}</h6>`
            },
            {
                targets: 3, orderable: false,
                render: function (data, type, full) {
                    const teams = full['teams'] || '';
                    if (!teams) {
                        return '<span class="text-body-secondary">—</span>';
                    }
                    // Nhiều team = category dùng chung
                    return teams.split(', ').map(t => `<span class="badge bg-label-secondary me-1">${t}</span>`).join('');
                }
            },
            {
                targets: 4,
                render: function (data, type, full) {
                    const n = full['products_count'];
                    return `<span class="badge ${n > 0 ? 'bg-label-primary' : 'bg-label-secondary'}">${n.toLocaleString()}</span>`;
                }
            },
            {
                targets: 5, orderable: false,
                render: function (data, type, full) {
                    if (!full['has_prompt']) {
                        return '<span class="text-body-secondary">—</span>';
                    }
                    return `<small class="text-truncate d-inline-block" style="max-width:320px">${full['prompt_preview']}</small>`;
                }
            },
            {
                targets: -1, title: 'Actions', searchable: false, orderable: false,
                render: function (data, type, full) {
                    const editBtn = categoryPerms.edit
                        ? `<a href="index.php?menu=categories&form=edit&id=${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon"><i class="icon-base ti tabler-edit icon-22px"></i></a>`
                        : '';
                    // Nút xóa để thẳng ra ngoài dạng icon, không giấu trong dropdown
                    const deleteBtn = categoryPerms.delete
                        ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-category" data-id="${full['id']}" data-count="${full['products_count']}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                        : '';
                    if (!editBtn && !deleteBtn) {
                        return '';
                    }
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
                features: [{ search: { className: 'me-5 ms-n4 pe-5 mb-n6 mb-md-0', placeholder: 'Search Category', text: '_INPUT_' } }]
            },
            topEnd: {
                rowClass: 'row m-3 my-0 justify-content-between',
                features: [{
                    pageLength: { menu: [10, 25, 50, 100], text: '_MENU_' },
                    buttons: [
                        ...(categoryPerms.delete ? [{
                            text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-trash icon-xs"></i> <span class="d-none d-sm-inline-block">Delete Selected</span></span>',
                            className: 'btn btn-text-danger me-4',
                            action: function (e, dtApi) {
                                deleteCategories(getSelectedCategoryIds(dtApi), dtApi);
                            }
                        }] : []),
                        ...(categoryPerms.add ? [{
                            text: '<i class="icon-base ti tabler-plus me-0 me-sm-1 icon-16px"></i><span class="d-none d-sm-inline-block">Add Category</span>',
                            className: 'add-new btn btn-primary',
                            action: function () {
                                window.location.href = 'index.php?menu=categories&form=add';
                            }
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
            // Team: chỉ admin mới lọc chéo team
            if (categoryPerms.filter_team) {
                const $box = $('.category_team').removeClass('d-none');
                $box.html('<label class="form-label" for="teamFilter">Team</label><select id="teamFilter" class="form-select"><option value="">All</option></select>');
                $.each(teamsObj, function (id, item) {
                    $('#teamFilter').append($('<option>', { value: id, text: item.title ?? item }));
                });
                $('#teamFilter').select2({ dropdownParent: $box }).on('change', function () {
                    refreshFilterBadge();
                    api.draw();
                });
            }
            initFilterCollapse();
        }
    });

    dtCategories = dt;

    setTimeout(() => {
        const tweaks = [
            { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary' },
            { selector: '.dt-search .form-control', classToRemove: 'form-control-sm', classToAdd: 'ms-0' },
            { selector: '.dt-search', classToAdd: 'mb-0 mb-md-6' },
            { selector: '.dt-length .form-select', classToRemove: 'form-select-sm' },
            { selector: '.dt-layout-end', classToAdd: 'gap-md-2 gap-0 mt-0' },
            { selector: '.dt-layout-start', classToAdd: 'mt-0' },
            { selector: '.dt-layout-table', classToRemove: 'row mt-2' },
            { selector: '.dt-layout-full', classToRemove: 'col-md col-12', classToAdd: 'table-responsive' }
        ];
        tweaks.forEach(({ selector, classToRemove, classToAdd }) => {
            document.querySelectorAll(selector).forEach(el => {
                if (classToRemove) classToRemove.split(' ').forEach(c => el.classList.remove(c));
                if (classToAdd) classToAdd.split(' ').forEach(c => el.classList.add(c));
            });
        });

        // Ô chọn số item/trang cũng dùng select2 cho đồng bộ UI template
        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            // .dt-length mặc định chỉ ~66px nên phải nới wrapper trước, nếu không
            // width của select2 bị chặn lại và các số như "2,000" bị cắt/xuống dòng
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({
                minimumResultsForSearch: Infinity,
                width: '100%'
            });
        }
    }, 100);
}

// Lấy ID các category đang chọn (select extension + checkbox)
function getSelectedCategoryIds(dt) {
    const ids = dt.rows({ selected: true }).data().toArray().map(r => r.id);
    $('.datatables-categories tbody input.dt-checkboxes:checked').each(function () {
        const row = dt.row($(this).closest('tr')).data();
        if (row && !ids.includes(row.id)) {
            ids.push(row.id);
        }
    });
    return ids;
}

function deleteCategories(ids, dt) {
    if (!ids.length) {
        alert('Select at least 1 category.');
        return;
    }
    if (!confirm('Delete ' + ids.length + ' selected categories? Categories still used by products cannot be deleted.')) {
        return;
    }
    $.ajax({
        url: '../../ajax.php?action=delete-categories',
        type: 'POST',
        data: { ids: ids }
    }).done(function (res) {
        if (res?.status === 'success') {
            dt.rows().deselect?.();
            dt.draw(false);
        } else {
            alert(res?.message || 'Delete failed');
        }
    }).fail(function () {
        alert('Server connection error');
    });
}

// Xóa 1 category từ dropdown của dòng
$(document).on('click', '.delete-category', function () {
    const id = $(this).data('id');
    const count = parseInt($(this).data('count'), 10) || 0;
    if (count > 0) {
        alert('This category is used by ' + count.toLocaleString() + ' products and cannot be deleted.');
        return;
    }
    if (dtCategories) {
        deleteCategories([id], dtCategories);
    }
});

// --- Filter card: thu gọn + badge + clear (giống trang Products) ---
function countActiveFilters() {
    return $('#teamFilter').val() ? 1 : 0;
}

function refreshFilterBadge() {
    const n = countActiveFilters();
    $('#activeFilterCount').text(n).toggleClass('d-none', n === 0);
    const hasSearch = !!(dtCategories && dtCategories.search());
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
        $('#teamFilter').val('').trigger('change.select2');
        if (dtCategories) {
            dtCategories.search('').draw();
        }
        refreshFilterBadge();
    });

    $('#filterCard .card-collapsible').on('click', function (e) {
        e.preventDefault();
        setFilterCollapsed(!$('#filterCard .card-header').hasClass('collapsed'), true);
    });

    // Chỉ admin mới có filter Team — người khác không có gì để lọc nên ẩn cả card
    if (!categoryPerms.filter_team) {
        $('#filterCard').addClass('d-none');
    } else {
        setFilterCollapsed(true, false);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    init();
});
