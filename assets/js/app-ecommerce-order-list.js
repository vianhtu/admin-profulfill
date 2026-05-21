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
    let borderColor, bodyBg, headingColor;

    borderColor = config.colors.borderColor;
    bodyBg = config.colors.bodyBg;
    headingColor = config.colors.headingColor;

    // Variable declaration for table

    const dt_order_table = document.querySelector('.datatables-order'),
        statusObj = {
            Unshipped: { title: 'Unshipped', class: 'bg-label-warning' },
            2: { title: 'Delivered', class: 'bg-label-success' },
            3: { title: 'Out for Delivery', class: 'bg-label-primary' },
            4: { title: 'Ready to Pickup', class: 'bg-label-info' }
        },
        paymentObj = {
            1: { title: 'Paid', class: 'text-success' },
            2: { title: 'Pending', class: 'text-warning' },
            3: { title: 'Failed', class: 'text-danger' },
            4: { title: 'Cancelled', class: 'text-secondary' }
        };

    // E-commerce Products datatable

    if (dt_order_table) {
        const dt_products = new DataTable(dt_order_table, {
            serverSide: true,
            processing: true,
            stateSave: true,
            ajax: {
                url: '../../ajax.php?action=get-orders-table',
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
                { data: 'ship_date' },
                { data: 'delivery_date' },
                { data: 'total_price' },
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
                        return '<div class="d-flex flex-column">' +
                            '<a href="javascript:void(0);">' +
                                '<span class="text-nowrap">#' + full['host_id'] + '</span>' +
                            '</a>' +
                            '<small>'+sitesObj[full['site_id']].title+' ('+full['account_name']+')</small>' +
                            '</div>';
                    }
                },
                {
                    targets: 3,
                    render: function (data, type, full, meta) {
                        const formattedDate = toLocalDate(full['purchase_date']);
                        return `<span class="text-nowrap">${formattedDate}</span>`;
                    }
                },
                {
                    targets: 4,
                    render: function (data, type, full, meta) {
                        const date = new Date(full['ship_date']);
                        const formattedDate = date.toLocaleString('en-US', {
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                            hour12: false,
                            timeZone: 'Asia/Ho_Chi_Minh'
                        });
                        return `<span class="text-nowrap">${formattedDate}</span>`;
                    }
                },
                {
                    targets: 5,
                    render: function (data, type, full, meta) {
                        const date = new Date(full['delivery_date']);
                        const formattedDate = date.toLocaleString('en-US', {
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                            hour12: false,
                            timeZone: 'Asia/Ho_Chi_Minh'
                        });
                        return `<span class="text-nowrap">${formattedDate}</span>`;
                    }
                },
                {
                    targets: 6,
                    render: function (data, type, full, meta) {
                        return '<h6 class="text-nowrap mb-0">$'+full['total_price']+'</h6>';
                    }
                },
                {
                    targets: 7,
                    render: function (data, type, full, meta) {
                        const status = full['status'];
                        const statusInfo = statusObj[status];
                        if (statusInfo) {
                            return `<span class="badge px-2 ${statusInfo.class} text-capitalized">${statusInfo.title}</span>`;
                        }
                        return data;
                    }
                },
                {
                    targets: 8,
                    orderable: false,
                    responsivePriority: 1,
                    render: function (data, type, full, meta) {
                        return '<div class="d-flex flex-column">' +
                            '<h6 class="text-nowrap mb-0">'+full['full_name']+'</h6>' +
                            '<small>'+full['address']+'</small>' +
                            '<small>'+full['phone']+'</small>' +
                        '</div>';
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
                                                        const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                                                        let result = '';
                                                        el.forEach(item => {
                                                            if (item.classList && item.classList.contains('user-name')) {
                                                                result += item.lastChild.firstChild.textContent;
                                                            } else {
                                                                result += item.textContent || item.innerText || '';
                                                            }
                                                        });
                                                        return result;
                                                    }
                                                }
                                            },
                                            customize: function (win) {
                                                win.document.body.style.color = headingColor;
                                                win.document.body.style.borderColor = borderColor;
                                                win.document.body.style.backgroundColor = bodyBg;
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
                                                        const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                                                        let result = '';
                                                        el.forEach(item => {
                                                            if (item.classList && item.classList.contains('user-name')) {
                                                                result += item.lastChild.firstChild.textContent;
                                                            } else {
                                                                result += item.textContent || item.innerText || '';
                                                            }
                                                        });
                                                        return result;
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
                                                        const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                                                        let result = '';
                                                        el.forEach(item => {
                                                            if (item.classList && item.classList.contains('user-name')) {
                                                                result += item.lastChild.firstChild.textContent;
                                                            } else {
                                                                result += item.textContent || item.innerText || '';
                                                            }
                                                        });
                                                        return result;
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
                                                        const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                                                        let result = '';
                                                        el.forEach(item => {
                                                            if (item.classList && item.classList.contains('user-name')) {
                                                                result += item.lastChild.firstChild.textContent;
                                                            } else {
                                                                result += item.textContent || item.innerText || '';
                                                            }
                                                        });
                                                        return result;
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
                                                        const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                                                        let result = '';
                                                        el.forEach(item => {
                                                            if (item.classList && item.classList.contains('user-name')) {
                                                                result += item.lastChild.firstChild.textContent;
                                                            } else {
                                                                result += item.textContent || item.innerText || '';
                                                            }
                                                        });
                                                        return result;
                                                    }
                                                }
                                            }
                                        }
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
            // 1. Cập nhật URL và bộ nhớ trình duyệt mỗi khi trạng thái bảng thay đổi
            stateSaveCallback: function(settings, data) {
                const url = new URL(window.location.href);
                const currentPage = (data.start / data.length) + 1;

                // Xử lý ẩn/hiện page và search trên URL
                if (currentPage === 1 && !data.search.search) {
                    url.searchParams.delete('page');
                    url.searchParams.delete('search');
                } else {
                    url.searchParams.set('page', currentPage);
                    url.searchParams.set('search', data.search.search || '');
                }

                // Luôn lưu và giữ chiều dài trang (URL + localStorage)
                url.searchParams.set('length', data.length);
                localStorage.setItem('dt_products_length', data.length);

                // TÍCH HỢP ORDER BY: Lưu lên cả URL lẫn localStorage giống hệt length
                if (data.order && data.order.length > 0) {
                    const col = data.order[0][0];
                    const dir = data.order[0][1];

                    url.searchParams.set('order_col', col);
                    url.searchParams.set('order_dir', dir);

                    localStorage.setItem('dt_products_order_col', col);
                    localStorage.setItem('dt_products_order_dir', dir);
                } else {
                    url.searchParams.delete('order_col');
                    url.searchParams.delete('order_dir');
                    // Không xóa localStorage ở đây để giữ làm bộ nhớ dự phòng khi URL trống
                }

                window.history.replaceState(null, null, url);
            },

            // 2. Đọc dữ liệu: Ưu tiên từ URL, nếu URL trống thì lấy dữ liệu đã lưu từ bộ nhớ
            stateLoadCallback: function(settings) {
                const urlParams = new URLSearchParams(window.location.search);

                // Đọc các giá trị dự phòng từ localStorage (nếu chưa từng lưu thì dùng mặc định của hệ thống)
                const savedLength = parseInt(localStorage.getItem('dt_products_length'), 10) || settings._iDisplayLength || 10;

                const localOrderCol = localStorage.getItem('dt_products_order_col');
                const localOrderDir = localStorage.getItem('dt_products_order_dir');
                const defaultOrder = settings.aaSorting || [[0, 'asc']];

                // Khởi tạo cấu hình order dự phòng từ bộ nhớ máy
                const savedOrder = (localOrderCol !== null && localOrderDir) ? [[parseInt(localOrderCol, 10), localOrderDir]] : defaultOrder;

                // Kiểm tra xem URL hiện tại có bất kỳ tham số nào không
                const hasParams = urlParams.has('page') || urlParams.has('search') || urlParams.has('length') || urlParams.has('order_col');

                // TRƯỜNG HỢP 1: Nếu URL trống hoàn toàn (Vào link gốc) -> Lấy toàn bộ từ bộ nhớ máy ra áp dụng lại
                if (!hasParams) {
                    return {
                        time: +new Date(),
                        start: 0,
                        length: savedLength,  // Giữ số lượng hàng đã chọn
                        order: savedOrder,    // Giữ cột đang sắp xếp đã chọn trước đó
                        search: { search: '' }
                    };
                }

                // TRƯỜNG HỢP 2: Nếu URL đang có param -> Ưu tiên bốc dữ liệu trên URL trước, thiếu mới bù bằng bộ nhớ máy
                const length = parseInt(urlParams.get('length'), 10) || savedLength;
                const page = parseInt(urlParams.get('page'), 10) || 1;
                const search = urlParams.get('search') || '';

                const order_col = urlParams.has('order_col') ? parseInt(urlParams.get('order_col'), 10) : null;
                const order_dir = urlParams.get('order_dir') || null;
                const currentOrder = (order_col !== null && order_dir) ? [[order_col, order_dir]] : savedOrder;

                return {
                    time: +new Date(),
                    start: (page - 1) * length,
                    length: length,
                    order: currentOrder, // Áp dụng sắp xếp chuẩn xác
                    search: {
                        search: search,
                        smart: true,
                        regex: false,
                        caseInsensitive: true
                    }
                };
            },
            // Thêm items.
            drawCallback: function(settings) {
                const api = this.api();
                api.rows({ page: 'current' }).every(function() {
                    const rowData = this.data();
                    let items = [];

                    // Kiểm tra tránh lỗi nếu rowData.items đã là object/array hoặc bị null
                    if (typeof rowData.items === 'string') {
                        items = JSON.parse(rowData.items);
                    } else {
                        items = rowData.items || [];
                    }

                    let html = '';
                    items.forEach(item => {
                        const row_image = `
                          <div class="d-flex justify-content-start align-items-center product-name">
                            <div class="avatar-wrapper">
                              <div class="avatar avatar me-2 me-sm-4 rounded-2 bg-label-secondary" style="width:80px; height:80px;">
                                <img src="${item.imageUrl}" 
                                     class="rounded img-fluid" 
                                     style="cursor:pointer;" 
                                     onclick="showImageModal('${item.imageUrl}')">
                              </div>
                            </div>
                            <div class="d-flex flex-column">
                              <a href="#" class="text-heading">${item.title}</a>
                              <small>ID: ${item.itemId}</small>
                              <small>QLT: ${item.quantity}</small>
                            </div>
                          </div>`;

                            let row_custom = '<div class="d-flex flex-column">';
                            item.attributes.forEach(value => {
                                row_custom += `<small>${value}</small>`;
                            });
                            row_custom += '</div>';

                            html += `
                          <tr class="shown">
                            <td style="display: none;"></td>
                            <td></td>
                            <td colspan="3">${row_image}</td>
                            <td colspan="3">${row_custom}</td>
                            <td>
                                <!-- Hộp chọn (Select) -->
                                <select class="form-select shipping-service">
                                    <option></option>
                                </select>
                                <!-- Ô nhập liệu (Input text) -->
                                <input type="text" class="form-control form-control-sm" placeholder="Add track here...">
                            </td>
                            <td></td>
                          </tr>`;
                    });

                    html += '';

                    // Chèn hàng con ngay sau hàng cha ở trang hiện tại
                    $(this.node()).after(html);
                    $(this.node()).addClass('shown');
                });

                // 🚀 KHỞI TẠO SELECT2 CHO CÁC Ô INPUT VỪA SINH RA
                // Tìm tất cả các select chưa được khởi tạo Select2 trong trang hiện tại
                $(api.table().container()).find('.shipping-service:not(.select2-hidden-accessible)').each(function() {
                    const $select = $(this);
                    $select.select2({
                        theme: 'bootstrap-5',
                        tags: true,
                        placeholder: 'Service...',
                        allowClear: true,
                        dropdownParent: $select.parent(),
                        ajax: {
                            url: '../../ajax.php?action=get-common-filter',
                            dataType: 'json',
                            type: 'POST',
                            delay: 250,
                            data: function (params) {
                                return {
                                    q: params.term,
                                    page: params.page || 1,
                                    type: 'accounts',
                                    folow_val: $select.val()
                                };
                            },
                            processResults: function (data, params) {
                                params.page = params.page || 1;
                                return {
                                    results: data.items,
                                    pagination: {
                                        more: (params.page * 30) < data.total_count
                                    }
                                };
                            },
                            cache: true
                        }
                    });
                });
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
