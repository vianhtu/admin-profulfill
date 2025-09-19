/**
 * app-ecommerce-order-list Script
 */

'use strict';

// Validation & Phone mask
(function () {
    const eCommerceCustomerAddForm = document.getElementById('eCommerceKeywordsAddForm');
    let isValidated = false; // Cờ đánh dấu đã validate

    const fv = FormValidation.formValidation(eCommerceCustomerAddForm, {
        fields: {
            keywords: {
                validators: {
                    notEmpty: {
                        message: 'Please enter keywords!'
                    }
                }
            },
            keywordsStatus: {
                validators: {
                    notEmpty: {
                        message: 'Please select a status!'
                    }
                }
            }
        },
        plugins: {
            trigger: new FormValidation.plugins.Trigger(),
            bootstrap5: new FormValidation.plugins.Bootstrap5({
                eleValidClass: '',
                rowSelector: function (field, ele) {
                    return '.form-control-validation';
                }
            }),
            submitButton: new FormValidation.plugins.SubmitButton(),
            autoFocus: new FormValidation.plugins.AutoFocus()
        }
    })
        .on('core.form.valid', function () {
            if (!isValidated) {
                // Lần đầu hợp lệ → chỉ đánh dấu và đổi nút
                isValidated = true;
                const btn = eCommerceCustomerAddForm.querySelector('[type="submit"]');
                const spinner = btn.querySelector('.spinner-border');
                const alertBox = eCommerceCustomerAddForm.querySelector('.alert');
                // Hiện spinner + disable nút
                spinner.classList.remove('d-none');
                btn.disabled = true;
                // Lần thứ hai → chạy AJAX
                const formData = new FormData(eCommerceCustomerAddForm);
                fetch('../../ajax.php?action=add-keywords', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => {
                        if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
                        return res.json();
                    })
                    .then(data => {
                        // Ẩn spinner + enable nút
                        spinner.classList.add('d-none');
                        btn.disabled = false;
                        if (data.error && Object.keys(data.error).length > 0) {
                            // Lỗi → alert-danger
                            alertBox.className = 'alert alert-danger';
                            alertBox.textContent = Array.isArray(data.error) ? data.error.join(', ') : 'Có lỗi xảy ra!';
                        } else {
                            // Thành công → alert-success
                            alertBox.className = 'alert alert-success';
                            alertBox.textContent = 'Thêm từ khóa thành công!';
                            eCommerceCustomerAddForm.reset();
                            // Reload DataTable
                            $('.datatables-taxonomy').DataTable().ajax.reload(null, false);
                        }
                        alertBox.classList.remove('d-none'); // Hiện alert
                    })
                    .catch(err => {
                        console.error(err);
                        alertBox.className = 'alert alert-danger';
                        alertBox.textContent = 'Lỗi kết nối máy chủ!';
                        alertBox.classList.remove('d-none');
                        spinner.classList.add('d-none');
                        btn.disabled = false;
                    });
            }
        });
})();

// Datatable (js)
function initTable(){
    // Variable declaration for table
    const dt_order_table = document.querySelector('.datatables-taxonomy'),
        statusObj = {
            1: { title: 'Replace', class: 'bg-label-warning' },
            2: { title: 'Trademark', class: 'bg-label-danger' },
            3: { title: 'Cannot be Used', class: 'bg-label-dark' }
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
                { data: 'name' },
                { data: 'status' },
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
                        return '<h6>'+full['name']+'</h6>';
                    }
                },
                {
                    targets: 3,
                    render: function (data, type, full, meta) {
                        return '<span class="badge px-2 '+statusObj[full['status']].class+' text-capitalized">'+statusObj[full['status']].title+'</span>';
                    }
                },
                {
                    targets: -1,
                    title: 'Actions',
                    searchable: false,
                    orderable: false,
                    render: function (data, type, full, meta) {
                        return `
                          <div class="d-flex align-items-center" data-id="${full['id']}">
                            <a href="javascript:;" class="btn btn-text-secondary rounded-pill waves-effect btn-icon">
                              <i class="icon-base ti tabler-edit icon-22px"></i>
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
            order: [2, 'desc'],
            layout: {
                topStart: {
                    rowClass: 'card-header d-flex border-top rounded-0 flex-wrap py-0 flex-column flex-md-row align-items-start',
                    features: [
                        {
                            search: {
                                className: 'me-5 ms-n4 pe-5 mb-n6 mb-md-0',
                                placeholder: 'Search Keyword',
                                text: '_INPUT_'
                            }
                        }
                    ]
                },
                topEnd: {
                    rowClass: 'row m-3 my-0 justify-content-between',
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
                                    text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-plus icon-xs"></i> <span class="d-none d-sm-inline-block">Add Keywords</span></span>',
                                    className: 'add-new-keywords btn btn-primary',
                                    attr: {
                                        'data-bs-toggle': 'offcanvas',
                                        'data-bs-target': '#offcanvasEcommerceKeywordsAdd'
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
    }

    // Filter form control to default size
    // ? setTimeout used for order-list table initialization
    setTimeout(() => {
        const elementsToModify = [
            { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary'},
            { selector: '.dt-search .form-control', classToRemove: 'form-control-sm', classToAdd: 'ms-0' },
            { selector: '.dt-length .form-select', classToRemove: 'form-select-sm' },
            { selector: '.dt-length', classToAdd: 'mt-md-6 mt-0' },
            { selector: '.dt-layout-table', classToRemove: 'row mt-2' },
            { selector: '.dt-layout-end', classToAdd: 'px-3 mt-0' },
            {
                selector: '.dt-layout-end .dt-buttons',
                classToAdd: 'gap-2 mt-0 mb-md-0 mb-6'
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
    initTable();
});
