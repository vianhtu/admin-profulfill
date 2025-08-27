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

    // Variable declaration for table
    const dt_user_table = document.querySelector('.datatables-users'),
        userView = 'app-user-view-account.html'
    var select2 = $('.select2');

    if (select2.length) {
        var $this = select2;
        $this.wrap('<div class="position-relative"></div>').select2({
            placeholder: 'Select Country',
            dropdownParent: $this.parent()
        });
    }

    // Users datatable
    if (dt_user_table) {
        const dt_user = new DataTable(dt_user_table, {
            serverSide: true,
            processing: true,
            ajax: {
                url: '../../ajax.php?action=get-xlsx',
                type: 'POST',
                data: function (d) {
                    //d.minDate = $('#minDate').val();
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
                { data: 'type_id' },
                { data: 'site_id' },
                { data: 'authors_id' },
                { data: 'date_create' },
                { data: 'action' }
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
                        var name = full['full_name'];
                        var email = full['email'];
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
                            '<a href="' +
                            userView +
                            '" class="text-heading text-truncate"><span class="fw-medium">' +
                            name +
                            '</span></a>' +
                            '<small>' +
                            email +
                            '</small>' +
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
                    targets: -1,
                    title: 'Actions',
                    searchable: false,
                    orderable: false,
                    render: (data, type, full, meta) => {
                        return `
              <div class="d-flex align-items-center">
                <a href="index.php?menu=exports_add&id=${full['id']}" class="btn btn-text-secondary rounded-pill waves-effect btn-icon">
                  <i class="icon-base ti tabler-edit icon-22px"></i>
                </a>
                <a href="javascript:;" class="btn btn-text-secondary rounded-pill waves-effect btn-icon duplicate-record">
                  <i class="icon-base ti tabler-copy-check icon-22px"></i>
                </a>
                <a href="javascript:;" class="btn btn-text-secondary rounded-pill waves-effect btn-icon delete-record">
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
                                placeholder: 'Search User',
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
                                            extend: 'print',
                                            text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-printer me-1"></i>Print</span>`,
                                            className: 'dropdown-item',
                                            exportOptions: {
                                                columns: [3, 4, 5, 6, 7],
                                                format: {
                                                    body: function (inner, coldex, rowdex) {
                                                        if (inner.length <= 0) return inner;

                                                        // Check if inner is HTML content
                                                        if (inner.indexOf('<') > -1) {
                                                            const parser = new DOMParser();
                                                            const doc = parser.parseFromString(inner, 'text/html');

                                                            // Get all text content
                                                            let text = '';

                                                            // Handle specific elements
                                                            const userNameElements = doc.querySelectorAll('.user-name');
                                                            if (userNameElements.length > 0) {
                                                                userNameElements.forEach(el => {
                                                                    // Get text from nested structure
                                                                    const nameText =
                                                                        el.querySelector('.fw-medium')?.textContent ||
                                                                        el.querySelector('.d-block')?.textContent ||
                                                                        el.textContent;
                                                                    text += nameText.trim() + ' ';
                                                                });
                                                            } else {
                                                                // Get regular text content
                                                                text = doc.body.textContent || doc.body.innerText;
                                                            }

                                                            return text.trim();
                                                        }

                                                        return inner;
                                                    }
                                                }
                                            },
                                            customize: function (win) {
                                                win.document.body.style.color = config.colors.headingColor;
                                                win.document.body.style.borderColor = config.colors.borderColor;
                                                win.document.body.style.backgroundColor = config.colors.bodyBg;
                                                const table = win.document.body.querySelector('table');
                                                table.classList.add('compact');
                                                table.style.color = 'inherit';
                                                table.style.borderColor = 'inherit';
                                                table.style.backgroundColor = 'inherit';
                                            }
                                        },
                                        {
                                            extend: 'csv',
                                            text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-file-text me-1"></i>Csv</span>`,
                                            className: 'dropdown-item',
                                            exportOptions: {
                                                columns: [3, 4, 5, 6, 7],
                                                format: {
                                                    body: function (inner, coldex, rowdex) {
                                                        if (inner.length <= 0) return inner;

                                                        // Parse HTML content
                                                        const parser = new DOMParser();
                                                        const doc = parser.parseFromString(inner, 'text/html');

                                                        let text = '';

                                                        // Handle user-name elements specifically
                                                        const userNameElements = doc.querySelectorAll('.user-name');
                                                        if (userNameElements.length > 0) {
                                                            userNameElements.forEach(el => {
                                                                // Get text from nested structure - try different selectors
                                                                const nameText =
                                                                    el.querySelector('.fw-medium')?.textContent ||
                                                                    el.querySelector('.d-block')?.textContent ||
                                                                    el.textContent;
                                                                text += nameText.trim() + ' ';
                                                            });
                                                        } else {
                                                            // Handle other elements (status, role, etc)
                                                            text = doc.body.textContent || doc.body.innerText;
                                                        }

                                                        return text.trim();
                                                    }
                                                }
                                            }
                                        },
                                        {
                                            extend: 'excel',
                                            text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-file-spreadsheet me-1"></i>Excel</span>`,
                                            className: 'dropdown-item',
                                            exportOptions: {
                                                columns: [3, 4, 5, 6, 7],
                                                format: {
                                                    body: function (inner, coldex, rowdex) {
                                                        if (inner.length <= 0) return inner;

                                                        // Parse HTML content
                                                        const parser = new DOMParser();
                                                        const doc = parser.parseFromString(inner, 'text/html');

                                                        let text = '';

                                                        // Handle user-name elements specifically
                                                        const userNameElements = doc.querySelectorAll('.user-name');
                                                        if (userNameElements.length > 0) {
                                                            userNameElements.forEach(el => {
                                                                // Get text from nested structure - try different selectors
                                                                const nameText =
                                                                    el.querySelector('.fw-medium')?.textContent ||
                                                                    el.querySelector('.d-block')?.textContent ||
                                                                    el.textContent;
                                                                text += nameText.trim() + ' ';
                                                            });
                                                        } else {
                                                            // Handle other elements (status, role, etc)
                                                            text = doc.body.textContent || doc.body.innerText;
                                                        }

                                                        return text.trim();
                                                    }
                                                }
                                            }
                                        },
                                        {
                                            extend: 'pdf',
                                            text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-file-description me-1"></i>Pdf</span>`,
                                            className: 'dropdown-item',
                                            exportOptions: {
                                                columns: [3, 4, 5, 6, 7],
                                                format: {
                                                    body: function (inner, coldex, rowdex) {
                                                        if (inner.length <= 0) return inner;

                                                        // Parse HTML content
                                                        const parser = new DOMParser();
                                                        const doc = parser.parseFromString(inner, 'text/html');

                                                        let text = '';

                                                        // Handle user-name elements specifically
                                                        const userNameElements = doc.querySelectorAll('.user-name');
                                                        if (userNameElements.length > 0) {
                                                            userNameElements.forEach(el => {
                                                                // Get text from nested structure - try different selectors
                                                                const nameText =
                                                                    el.querySelector('.fw-medium')?.textContent ||
                                                                    el.querySelector('.d-block')?.textContent ||
                                                                    el.textContent;
                                                                text += nameText.trim() + ' ';
                                                            });
                                                        } else {
                                                            // Handle other elements (status, role, etc)
                                                            text = doc.body.textContent || doc.body.innerText;
                                                        }

                                                        return text.trim();
                                                    }
                                                }
                                            }
                                        },
                                        {
                                            extend: 'copy',
                                            text: `<i class="icon-base ti tabler-copy me-1"></i>Copy`,
                                            className: 'dropdown-item',
                                            exportOptions: {
                                                columns: [3, 4, 5, 6, 7],
                                                format: {
                                                    body: function (inner, coldex, rowdex) {
                                                        if (inner.length <= 0) return inner;

                                                        // Parse HTML content
                                                        const parser = new DOMParser();
                                                        const doc = parser.parseFromString(inner, 'text/html');

                                                        let text = '';

                                                        // Handle user-name elements specifically
                                                        const userNameElements = doc.querySelectorAll('.user-name');
                                                        if (userNameElements.length > 0) {
                                                            userNameElements.forEach(el => {
                                                                // Get text from nested structure - try different selectors
                                                                const nameText =
                                                                    el.querySelector('.fw-medium')?.textContent ||
                                                                    el.querySelector('.d-block')?.textContent ||
                                                                    el.textContent;
                                                                text += nameText.trim() + ' ';
                                                            });
                                                        } else {
                                                            // Handle other elements (status, role, etc)
                                                            text = doc.body.textContent || doc.body.innerText;
                                                        }

                                                        return text.trim();
                                                    }
                                                }
                                            }
                                        }
                                    ]
                                },
                                {
                                    text: '<span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-plus icon-xs"></i> <span class="d-none d-sm-inline-block">Add New Record</span></span>',
                                    className: 'add-new btn btn-primary',
                                    action: function () {
                                        window.location.href = 'index.php?menu=exports_add';
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
                createFilter(3, '.xlsx_types', 'xlsxTypes', 'Types', categoryObj);

                // Sites filter
                createFilter(4, '.xlsx_sites', 'xlsxSites', 'Sites', sitesObj);

                // Authors filter
                createFilter(6, '.xlsx_authors', 'xlsxAuthors', 'Authors', authorsObj);

                // Accounts filter
                getAjaxSelect2HTML('xlsx_accounts', 'xlsxAccounts', 'Accounts', 'filter-accounts', true);
                // Add event listener for filtering
                $('#xlsxAccounts').on('change', function (){
                    dt_user.draw();
                });
            }
        });

        //? The 'delete-record' class is necessary for the functionality of the following code.
        function deleteRecord(event) {
            let row = document.querySelector('.dtr-expanded');
            if (event) {
                row = event.target.parentElement.closest('tr');
            }
            if (row) {
                dt_user.row(row).remove().draw();
            }
        }

        function bindDeleteEvent() {
            const userListTable = document.querySelector('.datatables-users');
            const modal = document.querySelector('.dtr-bs-modal');

            if (userListTable && userListTable.classList.contains('collapsed')) {
                if (modal) {
                    modal.addEventListener('click', function (event) {
                        if (event.target.parentElement.classList.contains('delete-record')) {
                            deleteRecord();
                            const closeButton = modal.querySelector('.btn-close');
                            if (closeButton) closeButton.click(); // Simulates a click on the close button
                        }
                    });
                }
            } else {
                const tableBody = userListTable?.querySelector('tbody');
                if (tableBody) {
                    tableBody.addEventListener('click', function (event) {
                        if (event.target.parentElement.classList.contains('delete-record')) {
                            deleteRecord(event);
                        }
                    });
                }
            }
        }

        // Initial event binding
        bindDeleteEvent();

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
