/**
 * app-ecommerce-order-list Script
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

function showImageModal(src) {
    const modalImg = document.getElementById('modalImage');
    modalImg.src = src;
    const modal = new bootstrap.Modal(document.getElementById('imageModal'));
    modal.show();
}

function getFullSizeImage(url) {
    return url.replace(/_.*\.jpg$/, '.jpg');
}

function replaceAmazonImageSize(url, newSize) {
    // newSize chỉ cần số, ví dụ 200
    return url.replace(/SX\d+/, `SX${newSize}`);
}

// Datatable (js)
function initTable(){
    // Variable declaration for table
    const dt_order_table = document.querySelector('.datatables-taxonomy'),
        statusObj = {
            Unshipped: { title: 'Unshipped', class: 'bg-label-warning' },
            2: { title: 'Delivered', class: 'bg-label-success' },
            3: { title: 'Out for Delivery', class: 'bg-label-primary' },
            4: { title: 'Ready to Pickup', class: 'bg-label-info' }
        }

    // E-commerce Products datatable

    if (dt_order_table) {
        const dt_products = new DataTable(dt_order_table, {
            serverSide: true,
            processing: true,
            ajax: {
                url: '../../ajax.php?action=get-keywords-table',
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
                { data: 'host_id' },
                { data: 'purchase_date' },
                { data: 'total_price' },
                { data: 'ship_date' },
                { data: 'delivery_date' },
                { data: 'status' },
                { data: 'full_name' },
                { data: 'id' }
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
                    responsivePriority: 3,
                    checkboxes: true,
                    render: function () {
                        return '<input type="checkbox" class="dt-checkboxes form-check-input">';
                    },
                    checkboxes: {
                        selectAllRender: '<input type="checkbox" class="form-check-input">'
                    }
                },
                {
                    // Order ID
                    targets: 2,
                    render: function (data, type, full, meta) {
                        return '';
                    }
                },
                {
                    targets: 3,
                    render: function (data, type, full, meta) {
                        return '';
                    }
                },
                {
                    targets: 4,
                    render: function (data, type, full, meta) {
                        return '';
                    }
                },
                {
                    targets: 5,
                    render: function (data, type, full, meta) {
                        return '';
                    }
                },
                {
                    targets: 6,
                    render: function (data, type, full, meta) {
                        return '';
                    }
                },
                {
                    targets: 7,
                    render: function (data, type, full, meta) {
                        return '';
                    }
                },
                {
                    targets: 8,
                    responsivePriority: 1,
                    render: function (data, type, full, meta) {
                        return '';
                    }
                },
                {
                    targets: -1,
                    title: 'Actions',
                    searchable: false,
                    orderable: false,
                    render: function (data, type, full, meta) {
                        return `
              <div class="d-flex justify-content-sm-start align-items-sm-center">
                <button class="btn btn-text-secondary rounded-pill waves-effect btn-icon dropdown-toggle hide-arrow" data-bs-toggle="dropdown">
                  <i class="icon-base ti tabler-dots-vertical"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end m-0">
                  <a href="app-ecommerce-order-details.html" class="dropdown-item">View</a>
                  <a href="javascript:void(0);" class="dropdown-item delete-record">Delete</a>
                </div>
              </div>`;
                    }
                }
            ],
            select: {
                style: 'multi',
                selector: 'td:nth-child(2)'
            },
            order: [3, 'desc'],
            layout: {
                topStart: {
                    search: {
                        placeholder: 'Search Order',
                        text: '_INPUT_'
                    }
                },
                topEnd: {
                    rowClass: 'row mx-3 my-0 justify-content-between',
                    features: [
                        {
                            pageLength: {
                                menu: [7, 10, 25, 50, 100],
                                text: '_MENU_'
                            }
                        },
                        {
                            buttons: [
                                {
                                    extend: 'collection',
                                    className: 'btn btn-label-primary dropdown-toggle',
                                    text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-upload icon-xs"></i> <span class="d-none d-sm-inline-block">Actions</span></span>',
                                    buttons: [
                                    ]
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
                            return 'Details of ' + data['customer'];
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
            initComplete:function(settings, json) {

            }
        });

        //? The 'delete-record' class is necessary for the functionality of the following code.
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('delete-record')) {
                dt_products.row(e.target.closest('tr')).remove().draw();
                const modalEl = document.querySelector('.dtr-bs-modal');
                if (modalEl && modalEl.classList.contains('show')) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    modal?.hide();
                }
            }
        });
    }

    // Filter form control to default size
    // ? setTimeout used for order-list table initialization
    setTimeout(() => {
        const elementsToModify = [
            { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary', classToAdd: 'btn-label-secondary' },
            { selector: '.dt-search .form-control', classToRemove: 'form-control-sm', classToAdd: 'ms-0' },
            { selector: '.dt-length .form-select', classToRemove: 'form-select-sm' },
            { selector: '.dt-length', classToAdd: 'mt-md-6 mt-0' },
            { selector: '.dt-layout-table', classToRemove: 'row mt-2' },
            { selector: '.dt-layout-end', classToAdd: 'px-3 mt-0' },
            {
                selector: '.dt-layout-end .dt-buttons',
                classToAdd: 'gap-2 px-3 mt-0 mb-md-0 mb-6'
            },
            {
                selector: '.dt-layout-end .dt-buttons .btn-group',
                classToAdd: 'mx-auto'
            },
            { selector: '.dt-layout-start', classToAdd: 'px-3 mt-0' },
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
