/**
 * app-ecommerce-category-list
 */

'use strict';
// Escape dữ liệu người dùng (title/name/sku...) trước khi nhét vào HTML -> chặn stored XSS
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


// Thứ tự PHẢI khớp mảng `columns` bên dưới — helper URL đổi tên cột <-> chỉ số cột
const CATEGORY_COLS = ['id','id','name','products_count','prompt_preview','id'];
let urlState = null;
let dtCategories = null;
// Quyền cấp trang; sửa/xóa từng dòng lấy theo can_edit/can_delete server trả về
let categoryPerms = { add: false, delete: false, is_admin: false };

async function init() {
    try {
        const options = await fetchTableFilter('get-categories-table-filter');
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

    urlState = dtUrlState({}, 25);

    const dt = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: '../../ajax.php?action=get-categories-table',
            type: 'POST',
            dataSrc: json => json.data
        },
        columns: [
            { data: 'id' },
            { data: 'id', orderable: false, render: DataTable.render.select() },
            { data: 'name' },
            { data: 'products_count' },
            { data: 'prompt_preview' },
            { data: 'id' }
        ],
        columnDefs: [
            {
                className: 'control dtr-control', searchable: false, orderable: false,
                responsivePriority: 2, targets: 0, render: () => ''
            },
            {
                targets: 1, orderable: false, searchable: false, responsivePriority: 3,
                checkboxes: { selectAllRender: '<input type="checkbox" class="form-check-input">' },
                render: () => '<input type="checkbox" class="dt-checkboxes form-check-input">'
            },
            {
                targets: 2, responsivePriority: 1,
                render: (data, type, full) => `<h6 class="text-nowrap mb-0">${esc(full['name'])}</h6>`
            },
            {
                targets: 3,
                render: function (data, type, full) {
                    const n = full['products_count'];
                    return `<span class="badge ${n > 0 ? 'bg-label-primary' : 'bg-label-secondary'}">${n.toLocaleString()}</span>`;
                }
            },
            {
                targets: 4, orderable: false,
                render: function (data, type, full) {
                    if (!full['has_prompt']) {
                        return '<span class="text-body-secondary">—</span>';
                    }
                    return `<small class="text-truncate d-inline-block" style="max-width:320px">${esc(full['prompt_preview'])}</small>`;
                }
            },
            {
                targets: -1, title: 'Actions', searchable: false, orderable: false,
                render: function (data, type, full) {
                    // Sửa: admin, hoặc chính người đã thêm category này (can_edit theo dòng)
                    const editBtn = full['can_edit']
                        ? `<a href="index.php?menu=categories&form=edit&id=${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon"><i class="icon-base ti tabler-edit icon-22px"></i></a>`
                        : lockedBtn('tabler-edit', 'Only an admin or the person who added it can edit');
                    // Nút xóa để thẳng ra ngoài dạng icon, không giấu trong dropdown
                    const deleteBtn = full['can_delete']
                        ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-category" data-id="${full['id']}" data-count="${full['products_count']}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                        : lockedBtn('tabler-trash', 'Only an admin can delete a category');
                    return `<div class="d-inline-block text-nowrap">${editBtn}${deleteBtn}</div>`;
                }
            }
        ],
        select: { style: 'multi', selector: 'td:nth-child(2)' },
        order: [[2, 'asc']],
        displayLength: 25,
        // PHẢI spread SAU order/displayLength, nếu không mặc định ghi đè URL
        ...urlState.tableOptions(CATEGORY_COLS),
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
        }
    });

    dtCategories = dt;
    urlState.applyFilters();
    urlState.bind(dtCategories, CATEGORY_COLS);

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
            // Bỏ .btn-group do DataTables Buttons thêm vào: nó làm Bootstrap cắt bo góc
            // phải của các nút không đứng cuối, trong khi đây là các nút rời nhau
            { selector: '.dt-buttons', classToRemove: 'btn-group' }
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
        data: { ids: ids, csrf_token: window.csrfToken }
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

document.addEventListener('DOMContentLoaded', function () {
    init();
});
