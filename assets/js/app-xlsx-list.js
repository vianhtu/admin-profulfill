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

        // 2️⃣ Sau khi có dữ liệu → tạo bảng
        initTable();
    } catch (err) {
        alert('Không thể tải danh mục');
    }
}

// Datatable (js)
function initTable(){
    let borderColor, bodyBg, headingColor;

    borderColor = config.colors.borderColor;
    bodyBg = config.colors.bodyBg;
    headingColor = config.colors.headingColor;

    // Variable declaration for table
    const dt_user_table = document.querySelector('.datatables-users');
    // Users datatable
    if (dt_user_table) {
        const dt_user = new DataTable(dt_user_table, {
            serverSide: true,
            processing: true,
            ajax: {
                url: '../../ajax.php?action=get-files-table',
                type: 'POST',
                data: function (d) {
                    const urlParams = new URLSearchParams(window.location.search);
                    const initialFilters = {
                        xlsxTypes: urlParams.get('xlsxTypes') || '',
                        xlsxSites: urlParams.get('xlsxSites') || '',
                        xlsxAuthors: urlParams.get('xlsxAuthors') || ''
                    };
                    const mapping = { xlsxTypes: 'type_id', xlsxSites: 'site_id', xlsxAuthors: 'authors_id' }; // data names in your columns config
                    d.columns.forEach(col => {
                        for (const key in mapping) {
                            if (col.data === mapping[key]) {
                                const v = initialFilters[key] || '';
                                col.search.value = v ? `^${v}$` : '';
                                col.search.regex = !!v;
                            }
                        }
                    });
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
                { data: 'file_name' },
                { data: 'type_id' },
                { data: 'site_id' },
                { data: 'authors_id' },
                { data: 'date_create' },
                { data: 'action' }
            ],
            columnDefs: [
                {
                    // For Responsive
                    className: 'control dtr-control',
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
                        var name = full['file_name'];
                        var account_name = full['account_name'];
                        var account_site_id = full['account_site_id'];
                        var image = './../../assets/svg/icons/xlsx_icon.svg';
                        var output;

                        output = '<img src="' + image + '" alt="file.xlsx" class="rounded">';

                        // Creates full output for row
                        var row_output =
                            '<div class="d-flex justify-content-start align-items-center user-name">' +
                            '<div class="avatar-wrapper">' +
                            '<div class="avatar avatar-sm me-4">' +
                            output +
                            '</div>' +
                            '</div>' +
                            '<div class="d-flex flex-column">' +
                            '<a href="index.php?menu=exports_xlsx&form=add&id='+full['id']+'" class="text-heading text-truncate"><span class="fw-medium">' +
                            name +
                            '</span></a>' +
                            '<small>' +
                            sitesObj[account_site_id].title +
                            ' ('+
                            account_name +
                            ')</small>' +
                            '</div>' +
                            '</div>';
                        return row_output;
                    }
                },
                {
                    // Type
                    targets: 3,
                    render: function (data, type, full, meta) {
                        var id = full['type_id'];
                        return '<span>' + categoryObj[id].title + '</span>';
                    }
                },
                {
                    // Site
                    targets: 4,
                    render: function (data, type, full, meta) {
                        let id = full['site_id'];
                        return '<span class="text-heading">' + sitesObj[id].title + '</span>';
                    }
                },
                {
                    // Site
                    targets: 5,
                    render: function (data, type, full, meta) {
                        let id = full['authors_id'];
                        return '<span class="text-heading">' + authorsObj[id].title + '</span>';
                    }
                },
                {
                    // Date
                    targets: 6,
                    render: function (data, type, full, meta) {
                        const status = full['date_create'];
                        return '<span>' + status + '</span>';
                    }
                },
                {
                    targets: 7,
                    title: 'Actions',
                    searchable: false,
                    orderable: false,
                    render: (data, type, full, meta) => {
                        return `
              <div class="d-flex align-items-center">
                <a href="index.php?menu=exports_xlsx&form=add&id=${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon">
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
                }
            ],
            select: {
                style: 'multi',
                selector: 'td:nth-child(2)'
            },
            order: [[1, 'DESC']],
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
                                    text: '<span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-upload icon-xs"></i> <span class="d-none d-sm-inline-block">Actions</span></span>',
                                    buttons: []
                                },
                                {
                                    text: '<span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-plus icon-xs"></i> <span class="d-none d-sm-inline-block">Add New Record</span></span>',
                                    className: 'add-new btn btn-primary',
                                    action: function () {
                                        window.location.href = 'index.php?menu=exports_xlsx&form=add';
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
                            return 'Details of ' + data['file_name'];
                        }
                    }),
                    type: 'column',
                    // Bỏ renderer tự viết của template: dưới DataTables 2.1.8 + Responsive 3.0.4 nó
                    // trả về rỗng, nên bấm '+' chỉ đánh dấu dòng mà không mở ra gì. Bản mặc
                    // định của Responsive chạy đúng và cũng đã tự bỏ qua cột không có tiêu đề.
                    }
            },
            initComplete: function () {
                const api = this.api();
                getSelect2filterTable(api,'xlsxTypes', '.xlsx_types', 3, 'Type', categoryObj);
                getSelect2filterTable(api,'xlsxSites', '.xlsx_sites', 4, 'Site', sitesObj);
                getSelect2filterTable(api,'xlsxAuthors', '.xlsx_authors', 5, 'Author', authorsObj);

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
