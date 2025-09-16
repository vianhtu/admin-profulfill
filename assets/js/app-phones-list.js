/**
 * Page User List
 */

'use strict';

async function init() {
    initTable();
}

// Datatable (js)
function initTable(){
    const statusObj = {
        active: { title: 'active', class: 'bg-label-success' },
        suspend: { title: 'suspend', class: 'bg-label-danger' }
    }

    // Variable declaration for table
    const dt_user_table = document.querySelector('.datatables-users');
    // Users datatable
    if (dt_user_table) {
        const dt_user = new DataTable(dt_user_table, {
            serverSide: true,
            processing: true,
            ajax: {
                url: '../../ajax.php?action=get-phones',
                type: 'POST',
                data: function (d) {},
                dataSrc: function (json) {
                    return json.data;
                }
            },
            columns: [
                // columns according to JSON
                { data: 'id' },
                { data: 'id', orderable: false, render: DataTable.render.select() },
                { data: 'number' },
                { data: 'status' },
                { data: 'carrier'},
                { data: 'notice'},
                { data: 'account'},
                { data: 'action' },
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
                    orderable: true,
                    searchable: true,
                    responsivePriority: 3,
                    render: function (data, type, full, meta) {
                        var name = full['number'];
                        return '<div class="d-flex flex-column">' +
                            '<a href="index.php?menu=phones_sms&id='+full['id']+'" class="text-heading text-truncate">' +
                            '<span class="fw-medium">' + name + '</span>' +
                            '</a>' +
                            '<small>'+ full['latest_sms_text'] +'</small>' +
                            '</div>';
                    }
                },
                {
                    // status
                    targets: 3,
                    orderable: true,
                    searchable: false,
                    render: function (data, type, full, meta) {
                        const status = full['status'];
                        return (
                            '<span class="file-status badge ' +
                            statusObj[status].class +
                            '" text-capitalized>' +
                            statusObj[status].title +
                            '</span>'
                        );
                    }
                },
                {
                    // Carrier
                    targets: 4,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, full, meta) {
                        const carrier = full['carrier'];
                        return '<span>' + carrier + '</span>';
                    }
                },
                {
                    // notice
                    targets: 5,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, full, meta) {
                        const notice = full['notice'];
                        return '<div class="position-relative d-inline-block">' +
                            '  <i class="icon-base ti tabler-mail icon-22px"></i>' +
                            '  <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">' +
                                 notice['sms_count'] +
                            '  </span>' +
                            '</div>';
                    }
                },
                {
                    // account
                    targets: 6,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, full, meta) {
                        const account = full['account'];
                        return '';
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
                            <a href="javascript:;" class="btn btn-text-secondary rounded-pill waves-effect btn-icon">
                              <i class="icon-base ti tabler-edit icon-22px"></i>
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
                                placeholder: 'Search Number',
                                text: '_INPUT_'
                            }
                        },
                        {
                            buttons: [
                                {
                                    extend: 'collection',
                                    className: 'btn btn-label-secondary dropdown-toggle',
                                    text: '<span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-upload icon-xs"></i> <span class="d-none d-sm-inline-block">Actions</span></span>',
                                    buttons: [
                                    ]
                                },
                                {
                                    text: '<span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-plus icon-xs"></i> <span class="d-none d-sm-inline-block">Get New Phone</span></span>',
                                    className: 'add-new btn btn-primary',
                                    action: function () {
                                        window.location.href = 'https://portal.telnyx.com/#/numbers/buy-numbers';
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
                searchPlaceholder: 'Search Number',
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
                            return 'Details of ' + data['number'];
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
