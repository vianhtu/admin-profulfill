/**
 * app-ecommerce-site-list
 */

'use strict';

// Thứ tự PHẢI khớp mảng `columns` bên dưới — helper URL đổi tên cột <-> chỉ số cột
const SITE_COLS = ['id','id','name','slug','products_count','accounts_count','stores_count','has_prompt','fields_count','id'];
let urlState = null;
let dtSites = null;
// Quyền cấp trang; sửa/xóa từng dòng lấy theo can_edit/can_delete server trả về
let sitePerms = { add: false, delete: false, is_admin: false };

// Escape trước khi nhét dữ liệu người dùng (name/logo) vào HTML -> chặn stored XSS
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

async function init() {
    try {
        const options = await fetchTableFilter('get-sites-table-filter');
        sitePerms = options['perms'] ?? sitePerms;
        initSiteTable();
    } catch (err) {
        alert('Failed to load site options');
    }
}

function initSiteTable() {
    const el = document.querySelector('.datatables-sites');
    if (!el) {
        return;
    }

    urlState = dtUrlState({}, 25);

    const dt = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: { url: '../../ajax.php?action=get-sites-table', type: 'POST', dataSrc: json => json.data },
        columns: [
            { data: 'id' },
            { data: 'id', orderable: false, render: DataTable.render.select() },
            { data: 'name' },
            { data: 'slug' },
            { data: 'products_count' },
            { data: 'accounts_count' },
            { data: 'stores_count' },
            { data: 'has_prompt' },
            { data: 'fields_count' },
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
                render: function (data, type, full) {
                    // Cột logo chỉ lưu TÊN FILE, ảnh nằm trong assets/img/icons/brands/
                    // (cùng quy ước với trang Stores). File thiếu thì rơi về chữ cái đầu.
                    const initials = esc((full['name'].match(/\b\w/g) || []).slice(0, 2).join('').toUpperCase());
                    const fallback = `<span class="avatar-initial rounded-2 bg-label-secondary">${initials}</span>`;
                    const avatar = full['logo']
                        ? `<img src="${esc(siteLogoUrl(full['logo']))}" alt="" class="rounded" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'avatar-initial rounded-2 bg-label-secondary',textContent:'${initials}'}))">`
                        : fallback;
                    return `
              <div class="d-flex justify-content-start align-items-center">
                <div class="avatar-wrapper">
                  <div class="avatar me-2 me-sm-4 rounded-2 bg-label-secondary">${avatar}</div>
                </div>
                <h6 class="text-nowrap mb-0">${esc(full['name'])}</h6>
              </div>`;
                }
            },
            { targets: 3, render: (d, t, full) => `<span class="text-body-secondary">${esc(full['slug'])}</span>` },
            { targets: 4, render: (d, t, full) => countBadge(full['products_count']) },
            { targets: 5, render: (d, t, full) => countBadge(full['accounts_count']) },
            { targets: 6, render: (d, t, full) => countBadge(full['stores_count']) },
            {
                targets: 7,
                render: (d, t, full) => full['has_prompt']
                    ? '<span class="badge bg-label-success">Yes</span>'
                    : '<span class="text-body-secondary">—</span>'
            },
            { targets: 8, render: (d, t, full) => countBadge(full['fields_count']) },
            {
                targets: -1, title: 'Actions', searchable: false, orderable: false,
                render: function (data, type, full) {
                    // Sửa: admin, hoặc chính người đã thêm site này (can_edit theo từng dòng)
                    const editBtn = full['can_edit']
                        ? `<a href="index.php?menu=sites&form=edit&id=${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon"><i class="icon-base ti tabler-edit icon-22px"></i></a>`
                        : lockedBtn('tabler-edit', 'Only an admin or the person who added it can edit');
                    const inUse = full['products_count'] + full['accounts_count'] + full['stores_count'];
                    const deleteBtn = full['can_delete']
                        ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-site" data-id="${full['id']}" data-inuse="${inUse}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                        : lockedBtn('tabler-trash', 'Only an admin can delete a site');
                    return `<div class="d-inline-block text-nowrap">${editBtn}${deleteBtn}</div>`;
                }
            }
        ],
        select: { style: 'multi', selector: 'td:nth-child(2)' },
        order: [[2, 'asc']],
        displayLength: 25,
        // PHẢI spread SAU order/displayLength, nếu không mặc định ghi đè URL
        ...urlState.tableOptions(SITE_COLS),
        layout: {
            topStart: {
                rowClass: 'card-header d-flex border-top rounded-0 flex-wrap py-0 flex-column flex-md-row align-items-start',
                features: [{ search: { className: 'me-5 ms-n4 pe-5 mb-n6 mb-md-0', placeholder: 'Search Site', text: '_INPUT_' } }]
            },
            topEnd: {
                rowClass: 'row m-3 my-0 justify-content-between',
                features: [{
                    pageLength: { menu: [10, 25, 50, 100], text: '_MENU_' },
                    buttons: [
                        ...(sitePerms.delete ? [{
                            text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-trash icon-xs"></i> <span class="d-none d-sm-inline-block">Delete Selected</span></span>',
                            className: 'btn btn-text-danger me-4',
                            action: (e, dtApi) => deleteSites(getSelectedSiteIds(dtApi), dtApi)
                        }] : []),
                        ...(sitePerms.add ? [{
                            text: '<i class="icon-base ti tabler-plus me-0 me-sm-1 icon-16px"></i><span class="d-none d-sm-inline-block">Add Site</span>',
                            className: 'add-new btn btn-primary',
                            action: () => { window.location.href = 'index.php?menu=sites&form=add'; }
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

    dtSites = dt;
    urlState.applyFilters();
    urlState.bind(dtSites, SITE_COLS);

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
            // DataTables Buttons thêm .btn-group làm Bootstrap cắt bo góc các nút
            { selector: '.dt-buttons', classToRemove: 'btn-group' }
        ];
        tweaks.forEach(({ selector, classToRemove, classToAdd }) => {
            document.querySelectorAll(selector).forEach(el => {
                if (classToRemove) classToRemove.split(' ').forEach(c => el.classList.remove(c));
                if (classToAdd) classToAdd.split(' ').forEach(c => el.classList.add(c));
            });
        });

        // Ô chọn số item/trang dùng select2 cho đồng bộ; nới wrapper để số không bị cắt
        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
    }, 100);
}

// logo lưu 2 dạng: 'uploads/...' (user tải lên) hoặc tên file trong assets/img/icons/brands/
function siteLogoUrl(logo) {
    return logo.includes('/') ? '../../' + logo : '../../assets/img/icons/brands/' + logo;
}

function countBadge(n) {
    return `<span class="badge ${n > 0 ? 'bg-label-primary' : 'bg-label-secondary'}">${Number(n).toLocaleString()}</span>`;
}

function getSelectedSiteIds(dt) {
    const ids = dt.rows({ selected: true }).data().toArray().map(r => r.id);
    $('.datatables-sites tbody input.dt-checkboxes:checked').each(function () {
        const row = dt.row($(this).closest('tr')).data();
        if (row && !ids.includes(row.id)) {
            ids.push(row.id);
        }
    });
    return ids;
}

function deleteSites(ids, dt) {
    if (!ids.length) {
        alert('Select at least 1 site.');
        return;
    }
    if (!confirm('Delete ' + ids.length + ' selected sites? Sites still in use cannot be deleted.')) {
        return;
    }
    $.ajax({ url: '../../ajax.php?action=delete-sites', type: 'POST', data: { ids: ids, csrf_token: window.csrfToken } })
        .done(function (res) {
            if (res?.status === 'success') {
                dt.rows().deselect?.();
                dt.draw(false);
            } else {
                alert(res?.message || 'Delete failed');
            }
        })
        .fail(() => alert('Server connection error'));
}

// Xóa 1 site từ icon trên dòng
$(document).on('click', '.delete-site', function () {
    const id = $(this).data('id');
    const inUse = parseInt($(this).data('inuse'), 10) || 0;
    if (inUse > 0) {
        alert('This site is still used by ' + inUse.toLocaleString() + ' records and cannot be deleted.');
        return;
    }
    if (dtSites) {
        deleteSites([id], dtSites);
    }
});

document.addEventListener('DOMContentLoaded', function () {
    init();
});
