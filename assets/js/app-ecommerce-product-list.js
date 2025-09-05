/**
 * app-ecommerce-product-list
 */

'use strict';

let categoryObj = {};
let authorsObj = {};
let sitesObj = {};
let lastPostData = {};

async function init() {
    try {
        // 1️⃣ Gọi API trước
        let options = await fetchProductTableFilter();
        categoryObj = options['types'];
        authorsObj = options['authors'];
        sitesObj = options['sites'];

        // 2️⃣ Sau khi có dữ liệu → tạo bảng
        initProductTable();
    } catch (err) {
        alert('Không thể tải danh mục');
    }
}

async function fetchProductTableFilter(){
    const res = await fetch('../../ajax.php?action=get-product-table-filter', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    });
    if (!res.ok) throw new Error('Lỗi lấy danh mục');
    return await res.json();
}

function initProductTable(){
    let borderColor, bodyBg, headingColor;

    borderColor = config.colors.borderColor;
    bodyBg = config.colors.bodyBg;
    headingColor = config.colors.headingColor;

    // Variable declaration for table
    const dt_product_table = document.querySelector('.datatables-products'),
        productAdd = 'app-ecommerce-product-add.html',
        statusObj = {
            pending: { title: 'pending', class: 'bg-label-primary' },
            schedule: { title: 'schedule', class: 'bg-label-secondary' },
            listed: { title: 'listed', class: 'bg-label-success' },
            inactive: { title: 'inactive', class: 'bg-label-danger' },
            trademark: { title: 'trademark', class: 'bg-label-warning' }
        }
    // E-commerce Products datatable

    if (dt_product_table) {
        var dt_products = new DataTable(dt_product_table, {
            serverSide: true,
            processing: true,
            ajax: {
                url: '../../ajax.php?action=get-products',
                type: 'POST',
                data: function (d) {
                    d.minDate = $('#minDate').val();
                    d.maxDate = $('#maxDate').val();
                    d.stores = $('#storeFilter').val();
                    d.sites = getCheckedSites();
                    d.accounts = $('#accountsFilter').val();
                    d.exported = $('#exportAccount').val();
                    lastPostData = d;
                },
                dataSrc: function (json) {
                    //console.log(json);
                    return json.data;
                }
            },
            columns: [
                // columns according to JSON
                { data: 'id' },
                { data: 'id', orderable: false, render: DataTable.render.select() },
                { data: 'title' },
                { data: 'sku' },
                { data: 'type_id' },
                { data: 'author_id' },
                { data: 'badge' },
                { data: 'date' },
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
                    targets: 2,
                    responsivePriority: 1,
                    render: function (data, type, full, meta) {
                        let name = full['title'],
                            id = full['id'],
                            productBrand = full['product_brand'],
                            image = full['image'];

                        let output;

                        if (image) {
                            // For Product image
                            output = `<img src="${image}" alt="Product-${id}" class="rounded">`;
                        } else {
                            // For Product badge
                            let stateNum = Math.floor(Math.random() * 6);
                            let states = ['success', 'danger', 'warning', 'info', 'dark', 'primary', 'secondary'];
                            let state = states[stateNum];
                            let initials = (productBrand.match(/\b\w/g) || []).slice(0, 2).join('').toUpperCase();

                            output = `<span class="avatar-initial rounded-2 bg-label-${state}">${initials}</span>`;
                        }

                        // Creates full output for Product name and product_brand
                        let rowOutput = `
              <div class="d-flex justify-content-start align-items-center product-name">
                <div class="avatar-wrapper">
                  <div class="avatar avatar me-2 me-sm-4 rounded-2 bg-label-secondary">${output}</div>
                </div>
                <div class="d-flex flex-column">
                  <h6 class="text-nowrap mb-0">${name}</h6>
                  <small class="text-truncate d-none d-sm-block">${productBrand}</small>
                </div>
              </div>
            `;

                        return rowOutput;
                    }
                },
                {
                    // Sku
                    targets: 3,
                    render: function (data, type, full, meta) {
                        const sku = full['sku'];

                        return '<span>' + sku + '</span>';
                    }
                },
                {
                    targets: 4,
                    responsivePriority: 5,
                    render: function (data, type, full, meta) {
                        let category = categoryObj[full['type_id']].title;

                        return '<span>' + category + '</span>';
                    }
                },
                {
                    targets: 5,
                    orderable: false,
                    responsivePriority: 3,
                    render: function (data, type, full, meta) {
                        let stock = full['author_id'];
                        let stockTitle = authorsObj[stock].title;

                        return '<span>' + stockTitle + '</span>';
                    }
                },
                {
                    // badge
                    targets: 6,
                    render: function (data, type, full, meta) {
                        let badge = full['badge'];
                        if(badge === null || badge === ''){
                            return '<i class="icon-base ti tabler-shopping-cart-off"></i>';
                        }
                        return '<span>' + badge + '</span>';
                    }
                },
                {
                    // qty
                    targets: 7,
                    responsivePriority: 4,
                    render: function (data, type, full, meta) {
                        const qty = full['date'];

                        return '<span>' + qty + '</span>';
                    }
                },
                {
                    // Status
                    targets: -2,
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
                    targets: -1,
                    title: 'Actions',
                    searchable: false,
                    orderable: false,
                    render: function (data, type, full, meta) {
                        return `
              <div class="d-inline-block text-nowrap">
                <button class="btn btn-text-secondary rounded-pill waves-effect btn-icon"><i class="icon-base ti tabler-edit icon-22px"></i></button>
                <button class="btn btn-text-secondary rounded-pill waves-effect btn-icon dropdown-toggle hide-arrow" data-bs-toggle="dropdown">
                  <i class="icon-base ti tabler-dots-vertical icon-22px"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end m-0">
                  <a href="javascript:void(0);" class="dropdown-item">View</a>
                  <a href="javascript:void(0);" class="dropdown-item">Suspend</a>
                </div>
              </div>
            `;
                    }
                }
            ],
            select: {
                style: 'multi',
                selector: 'td:nth-child(2)'
            },
            order: [7, 'desc'],
            displayLength: 10,
            layout: {
                topStart: {
                    rowClass: 'card-header d-flex border-top rounded-0 flex-wrap py-0 flex-column flex-md-row align-items-start',
                    features: [
                        {
                            search: {
                                className: 'me-5 ms-n4 pe-5 mb-n6 mb-md-0',
                                placeholder: 'Search Product',
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
                                menu: [10, 25, 50, 100, 500, 1000, 2000],
                                text: '_MENU_'
                            },
                            buttons: [
                                {
                                    extend: 'collection',
                                    className: 'btn btn-label-secondary dropdown-toggle me-4',
                                    text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-upload icon-xs"></i> <span class="d-none d-sm-inline-block">Export</span></span>',
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
                                                            const userNameElements = doc.querySelectorAll('.product-name');
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
                                            text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-file me-1"></i>Csv</span>`,
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

                                                        // Handle product-name elements specifically
                                                        const userNameElements = doc.querySelectorAll('.product-name');
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
                                            text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-upload me-1"></i>Excel</span>`,
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

                                                        // Handle product-name elements specifically
                                                        const userNameElements = doc.querySelectorAll('.product-name');
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
                                            text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-file-text me-1"></i>Pdf</span>`,
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

                                                        // Handle product-name elements specifically
                                                        const userNameElements = doc.querySelectorAll('.product-name');
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

                                                        // Handle product-name elements specifically
                                                        const userNameElements = doc.querySelectorAll('.product-name');
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
                                    text: '<i class="icon-base ti tabler-plus me-0 me-sm-1 icon-16px"></i><span class="d-none d-sm-inline-block">Add Product</span>',
                                    className: 'add-new btn btn-primary',
                                    action: function () {
                                        window.location.href = productAdd;
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
                            return 'Details of ' + data['title'];
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

                // Adding status filter once table is initialized
                api.columns(-2).every(function () {
                    const column = this;
                    const select = document.createElement('select');
                    select.id = 'ProductStatus';
                    select.className = 'form-select text-capitalize';
                    select.innerHTML = '<option value="">All</option>';
                    $('.product_status').html('<label class="form-label">Status</label>');
                    document.querySelector('.product_status').appendChild(select);

                    select.addEventListener('change', function () {
                        const val = select.value ? `^${select.value}$` : '';
                        column.search(val, true, false).draw();
                    });
                    Object.entries(statusObj).forEach(([key, val]) => {
                        const option = document.createElement('option');
                        option.value = val.title;
                        option.textContent = val.title;
                        select.appendChild(option);
                    });
                });

                // Adding category filter once table is initialized
                api.columns(3).every(function () {
                    const column = this;
                    const select = document.createElement('select');
                    select.id = 'ProductCategory';
                    select.className = 'form-select text-capitalize';
                    select.innerHTML = '<option value="">All</option>';
                    $('.product_category').html('<label class="form-label">Category</label>');
                    document.querySelector('.product_category').appendChild(select);

                    select.addEventListener('change', function () {
                        const val = select.value ? `^${select.value}$` : '';
                        column.search(val, true, false).draw();
                        // Trigger sự kiện change thủ công
                        const event = new Event('change');
                        document.getElementById('exportAccount').dispatchEvent(event);
                    });
                    Object.entries(categoryObj).forEach(([key, val]) => {
                        const option = document.createElement('option');
                        option.value = key;
                        option.textContent = val.title;
                        select.appendChild(option);
                    });
                });

                // Adding stock filter once table is initialized
                api.columns(4).every(function () {
                    const column = this;
                    const select = document.createElement('select');
                    select.id = 'ProductStock';
                    select.className = 'form-select text-capitalize';
                    select.innerHTML = '<option value="">All</option>';
                    $('.product_stock').html('<label class="form-label">Manager</label>');
                    document.querySelector('.product_stock').appendChild(select);

                    select.addEventListener('change', function () {
                        const val = select.value ? `^${select.value}$` : '';
                        column.search(val, true, false).draw();
                    });
                    Object.entries(authorsObj).forEach(([key, val]) => {
                        const option = document.createElement('option');
                        option.value = key;
                        option.textContent = val.title;
                        select.appendChild(option);
                    });
                });

                // Adding store filter once table is initialized
                getAjaxSelect2HTML('product_store', 'storeFilter', 'Store', 'filter-stores', true);

                // Adding accounts filter once table is initialized
                getAjaxSelect2HTML('product_accounts', 'accountsFilter', 'Listed Accounts', 'filter-accounts', true);

                // Adding date filter once table is initialized
                const tableApi = this.api();
                $('.product_from_date').html('<label class="form-label">From</label><input type="date" class="form-control" id="minDate" min="2025-01-01">');
                $('.product_to_date').html('<label class="form-label">To</label><input type="date" class="form-control" id="maxDate" min="2025-01-01">');
                $('#minDate').on('change', function () {
                    // Lấy giá trị từ minDate
                    const minVal = $(this).val();
                    // Cập nhật minDate cho #maxDate
                    $('#maxDate').attr('min', minVal || '');
                    // Vẽ lại bảng
                    tableApi.draw();
                });

                // For date range filter
                let typesHTML = '<div class="mb-2"><label class="form-label">From sites</label></div>';
                $.each(sitesObj, function(key, value) {
                    typesHTML += '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" value="'+key+'" id="check'+key+'"><label class="form-check-label">'+value.title+'</label></div>';
                });
                $('.product_sites').html(typesHTML);

                // Export.
                getAjaxSelect2HTML('export_accounts', 'exportAccount', 'Export to Account', 'filter-accounts');
                // file.
                $('.export_file').html('<label class="form-label">Export File</label><select id="exportFile" disabled></select>');
                $('#exportFile').select2({
                    placeholder: 'Chọn file xuất',
                    allowClear: true
                });
                $('#exportAccount').on('change', function () {
                    let e_id = $('#exportAccount').val();
                    if(!e_id){
                        return;
                    }
                    $.ajax({
                        url: '../../ajax.php?action=filter-export-file',
                        type: 'POST',
                        data: {
                            id: $('#exportAccount').val(),
                            type: $('#ProductCategory').val()
                        },
                    }).done(function(data) {
                        // Xóa option cũ
                        $('#exportFile').empty();
                        if (!data || Object.keys(data).length === 0) {
                            $('#exportFile').prop('disabled', true);
                            return;
                        }
                        $.each(data, function (index, item) {
                            $('#exportFile').append(
                                $('<option>', {
                                    value: index,
                                    text: item
                                })
                            );
                        });
                        $('#exportFile').prop('disabled', false);
                        // Khởi tạo hoặc refresh Select2
                        $('#exportFile').select2({
                            placeholder: 'Chọn file xuất',
                            allowClear: true
                        });
                    });
                });

                $('.export_limited').html('<label class="form-label">Limited</label><input type="number" class="form-control" id="exportLimited" value="2000" min="0">');
                $('.export_offset').html('<label class="form-label">Offset</label><input type="number" class="form-control" id="exportOffset" value="0" min="0">');
                $('.export_save').html('<button class="btn btn-primary w-100" tabindex="1" type="button"><span><span class="spinner-border spinner-border-sm me-2 d-none" role="status" id="loading_spinner"></span><span class="d-none d-sm-inline-block">Save Query</span></span></button>');

                // save query
                $('.export_save button').on('click', function () {
                    const $btn = $(this);
                    const $spinner = $('#loading_spinner');

                    // Hiển thị spinner và disable nút
                    $spinner.removeClass('d-none');
                    $btn.prop('disabled', true);
                    let isValid = true;
                    $('#exportAccount, #exportFile').each(function () {
                        const value = ($(this).val() || '').trim();
                        if (!value) {
                            $(this).addClass('is-invalid').removeClass('is-valid');
                            isValid = false;
                        } else {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                        }
                    });
                    if(!isValid){
                        $spinner.addClass('d-none');
                        $btn.prop('disabled', false);
                        return;
                    }
                    lastPostData.exported = $('#exportAccount').val();
                    lastPostData.length = parseInt($('#exportLimited').val());
                    lastPostData.start = parseInt($('#exportOffset').val());
                    lastPostData.file = $('#exportFile').val();
                    $.ajax({
                        url: '../../ajax.php?action=save-export-query',
                        type: 'POST',
                        data: lastPostData,
                    }).done(function(data) {
                        console.log(data);
                        $spinner.addClass('d-none');
                        $btn.prop('disabled', false);
                    });
                });

                $('#maxDate,#storeFilter,#accountsFilter,.product_sites input').on('change', function () {
                    tableApi.draw();
                });
            }
        });

        // Khi bảng vẽ xong, enable nút
        dt_products.on('draw.dt', function () {
            $('.export_save button').prop('disabled', false);
        });
        dt_products.on('preDraw.dt', function () {
            $('.export_save button').prop('disabled', true);
        });
    }

    // Filter form control to default size
    // ? setTimeout used for product-list table initialization
    setTimeout(() => {
        const elementsToModify = [
            { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary' },
            { selector: '.dt-buttons.btn-group', classToAdd: 'mb-md-0 mb-6' },
            { selector: '.dt-search .form-control', classToRemove: 'form-control-sm', classToAdd: 'ms-0' },
            { selector: '.dt-search', classToAdd: 'mb-0 mb-md-6' },
            { selector: '.dt-length .form-select', classToRemove: 'form-select-sm' },
            { selector: '.dt-layout-end', classToAdd: 'gap-md-2 gap-0 mt-0' },
            { selector: '.dt-layout-start', classToAdd: 'mt-0' },
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

// Datatable (js)
document.addEventListener('DOMContentLoaded', function (e) {
    init();
});

function getCheckedSites() {
    const selectedValues = [];
    // Lấy tất cả checkbox đã được chọn
    $('.product_sites input.form-check-input:checked').each(function () {
        selectedValues.push($(this).val());
    });
    return selectedValues;
}

