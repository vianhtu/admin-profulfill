/**
 * Page User List
 */

'use strict';

let categoryObj = {};
let authorsObj = {};
let sitesObj = {};

async function init() {
    try {
        // 1️⃣ Gọi API trước
        let options = await fetchTableFilter();
        categoryObj = options['types'];
        authorsObj = options['authors'];
        sitesObj = options['sites'];
        console.log(options);

        // 2️⃣ Sau khi có dữ liệu → tạo bảng
        initTable();
    } catch (err) {
        alert('Không thể tải danh mục');
    }
}

async function fetchTableFilter(){
    const res = await fetch('../../ajax.php?action=get-product-table-filter', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    });
    if (!res.ok) throw new Error('Lỗi lấy danh mục');
    return await res.json();
}

// Datatable (js)
function initTable(){
    let borderColor, bodyBg, headingColor;

    borderColor = config.colors.borderColor;
    bodyBg = config.colors.bodyBg;
    headingColor = config.colors.headingColor;

    const statusObj = {
        pending: { title: 'pending', class: 'bg-label-primary' },
        schedule: { title: 'schedule', class: 'bg-label-secondary' },
        listed: { title: 'listed', class: 'bg-label-success' },
        inactive: { title: 'inactive', class: 'bg-label-danger' },
        trademark: { title: 'trademark', class: 'bg-label-warning' }
    }

    // Variable declaration for table
    const dt_user_table = document.querySelector('.datatables-users');
    // Users datatable
    if (dt_user_table) {
        const dt_user = new DataTable(dt_user_table, {
            serverSide: true,
            processing: true,
            ajax: {
                url: '../../ajax.php?action=get-download',
                type: 'POST',
                data: function (d) {
                    d.accounts = $('#xlsxAccounts').val();
                },
                dataSrc: function (json) {
                    return json.data;
                }
            },
            columns: [
                // columns according to JSON
                { data: 'id' },
                { data: 'id', orderable: false, render: DataTable.render.select() },
                { data: 'full_name' },
                { data: 'email' },
                { data: 'site_id' },
                { data: 'status' },
                { data: 'date'},
                { data: 'download_date' },
                { data: 'total_items' },
                { data: 'action' },
                { data: 'author_id' },
                { data: 'type_id' }
            ],
            columnDefs: [
                {
                    // For Responsive
                    className: 'control',
                    searchable: false,
                    orderable: false,
                    responsivePriority: 2,
                    targets: 0,
                    render: function (data, type, full, meta) {
                        return '';
                    }
                },
                {
                    // For Checkboxes
                    targets: 1,
                    orderable: false,
                    searchable: false,
                    responsivePriority: 4,
                    checkboxes: true,
                    render: function () {
                        return '<input type="checkbox" class="dt-checkboxes form-check-input">';
                    },
                    checkboxes: {
                        selectAllRender: '<input type="checkbox" class="form-check-input">'
                    }
                },
                {
                    targets: 2,
                    responsivePriority: 3,
                    render: function (data, type, full, meta) {
                        var name = full['full_name']  + ' - ' + categoryObj[full['type_id']].title;
                        var account_name = full['temp_file_name'];
                        var image = './../../assets/svg/icons/xlsx_icon.svg';
                        var output;

                        var output =
                            '<div class="position-relative">' +
                            '<img src="' + image + '" alt="file.xlsx" class="rounded">' +
                            '<div class="progress-overlay d-none">' + // mặc định ẩn
                            '<div class="progress" style="height: 4px;">' +
                            '<div class="progress-bar bg-info" role="progressbar" style="width: 0%;"></div>' +
                            '</div>' +
                            '</div>' +
                            '</div>';

                        var row_output =
                            '<div class="d-flex justify-content-start align-items-center user-name">' +
                            '<div class="avatar-wrapper">' +
                            '<div class="avatar avatar-sm me-4">' +
                            output +
                            '</div>' +
                            '</div>' +
                            '<div class="d-flex flex-column">' +
                            '<a href="#" class="text-heading text-truncate"><span class="fw-medium">' +
                            name +
                            '</span></a>' +
                            '<small>' +
                            account_name +
                            '</small>' +
                            '</div>' +
                            '</div>';
                        return row_output;
                    }
                },
                {
                    // Email
                    targets: 3,
                    orderable: false,
                    render: function (data, type, full, meta) {
                        let email = full['email'];
                        return '<span>' + email + '</span>';
                    }
                },
                {
                    // Site
                    targets: 4,
                    orderable: false,
                    render: function (data, type, full, meta) {
                        let id = full['site_id'];
                        return '<span class="text-heading">' + sitesObj[id].title + '</span>';
                    }
                },
                {
                    // author
                    targets: 5,
                    render: function (data, type, full, meta) {
                        const status = full['status'];
                        return (
                            '<span class="badge ' +
                            statusObj[status].class +
                            '" text-capitalized>' +
                            statusObj[status].title +
                            '</span>'
                        );
                    }
                },
                {
                    // Date
                    targets: 6,
                    render: function (data, type, full, meta) {
                        const date = full['date'];
                        return '<span>' + date + '</span>';
                    }
                },
                {
                    // Download Date
                    targets: 7,
                    render: function (data, type, full, meta) {
                        const download_date = full['download_date'];
                        return '<span>' + download_date + '</span>';
                    }
                },
                {
                    // Total Items
                    targets: 8,
                    render: function (data, type, full, meta) {
                        const total_items = full['total_items'];
                        return '<span>' + total_items + '</span>';
                    }
                },
                {
                    targets: 9,
                    title: 'Actions',
                    searchable: false,
                    orderable: false,
                    render: (data, type, full, meta) => {
                        return `
              <div class="d-flex align-items-center">
                <a href="index.php?menu=exports_add&id=${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon">
                  <i class="icon-base ti tabler-edit icon-22px"></i>
                </a>
                <a href="javascript:;" data-id="${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon duplicate-record">
                  <i class="icon-base ti tabler-copy-check icon-22px"></i>
                </a>
                <a href="javascript:;" data-id="${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon delete-record">
                  <i class="icon-base ti tabler-trash icon-22px"></i>
                </a>
              </div>
            `;
                    }
                },
                {
                    targets: 10, // chỉ số cột bạn muốn ẩn
                    visible: false,
                    searchable: true // vẫn cho phép lọc
                },
                {
                    targets: 11, // chỉ số cột bạn muốn ẩn
                    visible: false,
                    searchable: true // vẫn cho phép lọc
                }
            ],
            select: {
                style: 'multi',
                selector: 'td:nth-child(2)'
            },
            order: [[2, 'desc']],
            layout: {
                topStart: {
                    rowClass: 'row m-3 my-0 justify-content-between',
                    features: [
                        {
                            pageLength: {
                                menu: [10, 25, 50, 100],
                                text: '_MENU_'
                            }
                        }
                    ]
                },
                topEnd: {
                    features: [
                        {
                            search: {
                                placeholder: 'Search File',
                                text: '_INPUT_'
                            }
                        },
                        {
                            buttons: [
                                {
                                    extend: 'collection',
                                    className: 'btn btn-label-secondary dropdown-toggle',
                                    text: '<span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-upload icon-xs"></i> <span class="d-none d-sm-inline-block">Export</span></span>',
                                    buttons: [
                                        {
                                            text: '<i class="icon-base ti tabler-brand-google me-1"></i>Gemini 2.5 Flash',
                                            action: function (e, dt, node, config) {
                                                const selectedData = dt.rows({ selected: true }).data().toArray();
                                                if (selectedData.length === 0) {
                                                    alert('Chọn một hoặc nhiều file cần sử lý!');
                                                } else {
                                                    console.log('Dữ liệu các dòng đã chọn:', selectedData);
                                                    alert(`Đã chọn ${selectedData.length} dòng`);
                                                    // Bạn có thể xử lý thêm: gửi AJAX, hiển thị modal, v.v.
                                                }
                                            }
                                        }
                                    ]
                                },
                                {
                                    text: '<span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-plus icon-xs"></i> <span class="d-none d-sm-inline-block">Add New Record</span></span>',
                                    className: 'add-new btn btn-primary',
                                    action: function () {
                                        window.location.href = 'index.php?menu=products';
                                    }
                                }
                            ]
                        }
                    ]
                },
                bottomStart: {
                    rowClass: 'row mx-3 justify-content-between',
                    features: ['info']
                },
                bottomEnd: 'paging'
            },
            language: {
                sLengthMenu: '_MENU_',
                search: '',
                searchPlaceholder: 'Search file',
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
                            return 'Details of ' + data['full_name'];
                        }
                    }),
                    type: 'column',
                    renderer: function (api, rowIdx, columns) {
                        const data = columns
                            .map(function (col) {
                                return col.title !== '' // Do not show row in modal popup if title is blank (for check box)
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
            },
            initComplete: function () {
                const api = this.api();

                // Helper function to create a select dropdown and append options
                const createFilter = (columnIndex, containerClass, selectId, label, options) => {
                    const column = api.column(columnIndex);
                    const select = document.createElement('select');
                    select.id = selectId;
                    select.className = 'form-select text-capitalize';
                    select.innerHTML = `<option value="">All</option>`;
                    $(containerClass).html('<label class="form-label">'+label+'</label>');
                    document.querySelector(containerClass).appendChild(select);

                    // Add event listener for filtering
                    select.addEventListener('change', () => {
                        const val = select.value ? `^${select.value}$` : '';
                        column.search(val, true, false).draw();
                    });

                    // Populate options based on unique column data
                    Object.entries(options).forEach(([key, val]) => {
                        const option = document.createElement('option');
                        option.value = key;
                        option.textContent = val.title;
                        select.appendChild(option);
                    });
                };

                // Type filter
                createFilter(11, '.xlsx_types', 'xlsxTypes', 'Types', categoryObj);

                // Sites filter
                createFilter(4, '.xlsx_sites', 'xlsxSites', 'Sites', sitesObj);

                // Authors filter
                createFilter(10, '.xlsx_authors', 'xlsxAuthors', 'Authors', authorsObj);

                // Accounts filter
                getAjaxSelect2HTML('xlsx_accounts', 'xlsxAccounts', 'Accounts', 'filter-accounts', true);
                // Add event listener for filtering
                $('#xlsxAccounts').on('change', function (){
                    dt_user.draw();
                });
            }
        });

        function handleRecordAction(event) {
            event?.preventDefault();

            // Xác định loại hành động: delete hoặc duplicate
            const deleteBtn = event.target.closest('.delete-record');
            const duplicateBtn = event.target.closest('.duplicate-record');

            if (!deleteBtn && !duplicateBtn) return;

            const action = deleteBtn ? 'delete' : 'duplicate';
            const recordId = (deleteBtn || duplicateBtn)?.getAttribute('data-id');
            if (!recordId) return;

            const confirmMsg = action === 'delete'
                ? 'Bạn có chắc muốn xóa bản ghi này?'
                : 'Bạn có chắc muốn nhân bản bản ghi này?';
            if (!confirm(confirmMsg)) return;

            // Xác định dòng trong DataTable
            let row = document.querySelector('.dtr-expanded');
            if (event) {
                row = event.target.closest('tr');
            }

            // Gửi request Ajax
            fetch(`../../ajax.php?action=${action}-xlsx`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${encodeURIComponent(recordId)}&csrf_token=${encodeURIComponent(window.csrfToken)}`
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (action === 'delete') {
                            // Xóa trên DataTable
                            if (row) {
                                dt_user.row(row).remove().draw(false);
                            }
                        } else if (action === 'duplicate' && data.newRecord) {
                            // Thêm bản ghi mới
                            dt_user.ajax.reload(function() {
                                dt_user.order([[2, 'desc']]).draw();
                            }, false);
                        }
                    } else {
                        alert(data.error || `Không thể ${action} dữ liệu`);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Có lỗi kết nối tới server');
                });
        }

        function bindRecordEvents() {
            const userListTable = document.querySelector('.datatables-users');
            const modal = document.querySelector('.dtr-bs-modal');

            if (userListTable && userListTable.classList.contains('collapsed')) {
                if (modal) {
                    modal.addEventListener('click', function (event) {
                        if (event.target.closest('.delete-record') || event.target.closest('.duplicate-record')) {
                            handleRecordAction(event);
                            const closeButton = modal.querySelector('.btn-close');
                            if (closeButton) closeButton.click();
                        }
                    });
                }
            } else {
                const tableBody = userListTable?.querySelector('tbody');
                if (tableBody) {
                    tableBody.addEventListener('click', function (event) {
                        if (event.target.closest('.delete-record') || event.target.closest('.duplicate-record')) {
                            handleRecordAction(event);
                        }
                    });
                }
            }
        }

        // Initial bind
        bindRecordEvents();

        // Re-bind events when modal is shown or hidden
        document.addEventListener('show.bs.modal', function (event) {
            if (event.target.classList.contains('dtr-bs-modal')) {
                bindDeleteEvent();
            }
        });

        document.addEventListener('hide.bs.modal', function (event) {
            if (event.target.classList.contains('dtr-bs-modal')) {
                bindDeleteEvent();
            }
        });
    }

    // Filter form control to default size
    // ? setTimeout used for user-list table initialization
    setTimeout(() => {
        const elementsToModify = [
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
            { selector: '.dt-layout-full', classToRemove: 'col-md col-12', classToAdd: 'table-responsive' }
        ];

        // Delete record
        elementsToModify.forEach(({ selector, classToRemove, classToAdd }) => {
            document.querySelectorAll(selector).forEach(element => {
                if (classToRemove) {
                    classToRemove.split(' ').forEach(className => element.classList.remove(className));
                }
                if (classToAdd) {
                    classToAdd.split(' ').forEach(className => element.classList.add(className));
                }
            });
        });
    }, 100);
}
document.addEventListener('DOMContentLoaded', function (e) {
    init();
});
