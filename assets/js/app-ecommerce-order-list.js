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
        updateItemData();
    } catch (err) {
        alert('Không thể tải danh mục');
    }
}

function showImageModal(base64Str, order_id, order_price, item_index) {
    let item_data = {};
    try {
        // Giải mã Base64 Unicode phiên bản viết gọn
        const jsonStr = decodeURIComponent(atob(base64Str).split('').map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join(''));
        item_data = JSON.parse(jsonStr);
    } catch (e) {
        return console.error("Lỗi giải mã dữ liệu sản phẩm:", e);
    }

    // 1. Reset và đổ dữ liệu vào Form gọn gàng
    const form = document.getElementById('item-modal-form');
    if (form) {
        document.getElementById('item_id').value = item_index;
        document.getElementById('order_id').value = order_id;
        document.getElementById('order_price').value = order_price;
        document.getElementById('item-base-cost').value = item_data?.cost ?? '';
        document.getElementById('item-note').value = item_data?.note ?? '';
    }

    // 2. Cập nhật ảnh (Nhớ kiểm tra hàm getFullSizeImage phòng hờ item_data.imageUrl bị undefined)
    document.getElementById('modalImage').src = getFullSizeImage(item_data?.imageUrl || '');

    // 3. Khởi tạo và hiển thị Bootstrap Modal
    const modalEl = document.getElementById('imageModal');
    const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl, { backdrop: true, focus: true, keyboard: true });
    modal.show();
}

function getFullSizeImage(url) {
    switch (true) {
        // Trường hợp 1: Ảnh từ eBay
        case url.includes('ebayimg.com'):
            return url.replace(/\/s-l\d+\.jpg$/, '/s-l2000.jpg');

        // Trường hợp 2: Ví dụ sau này bạn muốn thêm quy tắc cho Amazon
        // case url.includes('media-amazon.com'):
        //     return url.replace('_SL160_', '_SL1500_');

        // Trường hợp mặc định: Không khớp quy tắc nào thì giữ nguyên URL
        default:
            return url;
    }
}

// Datatable (js)
function initTable(){
    let borderColor, bodyBg, headingColor;

    borderColor = config.colors.borderColor;
    bodyBg = config.colors.bodyBg;
    headingColor = config.colors.headingColor;

    // Variable declaration for table
    const dt_order_table = document.querySelector('.datatables-order')
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
                    render: function () {
                        return '<input type="checkbox" class="dt-checkboxes form-check-input">';
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
                        return `<span class="text-nowrap">${toLocalDate(full['purchase_date'])}</span>`;
                    }
                },
                {
                    targets: 4,
                    render: function (data, type, full, meta) {
                        let fulfill_date = "";
                        if(full['fulfill_date']){
                            fulfill_date = `<small class="d-inline-flex align-items-center">
                            <i class="ti tabler-truck me-1 fs-3"></i>${toLocalDate(full['fulfill_date'])}</small>`;
                        }
                        return '<div class="d-flex flex-column">' +
                            renderShipCountdownHtml(full['ship_date'], full['status']) + fulfill_date +
                            '</div>';
                    }
                },
                {
                    targets: 5,
                    render: function (data, type, full, meta) {
                        return `<span class="text-nowrap">${toLocalDate(full['delivery_date'])}</span>`;
                    }
                },
                {
                    targets: 6,
                    className: 'order-price-column',
                    render: function (data, type, full, meta) {
                        return renderPriceHtml(full['total_price'], full['base_cost']);
                    }
                },
                {
                    targets: 7,
                    className: 'order-status-column',
                    render: function (data, type, full, meta) {
                        return renderStatusHtml(full['status']);
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

                    const order_id = rowData.id;
                    const order_price = rowData.total_price;

                    let html = '';
                    items.forEach((item, index) => {
                        // Chuyển Object thành JSON -> Mã hóa sang Base64 an toàn tuyệt đối
                        const jsonStr = JSON.stringify(item);
                        const base64Str = btoa(encodeURIComponent(jsonStr).replace(/%([0-9A-F]{2})/g, function(match, p1) {
                            return String.fromCharCode('0x' + p1);
                        }));
                        const row_image = `
                          <div class="d-flex justify-content-start align-items-center product-name">
                            <div class="avatar-wrapper">
                              <div class="avatar avatar me-2 me-sm-4 rounded-2 bg-label-secondary" style="width:80px; height:80px;">
                                <img src="${item.imageUrl}" 
                                     class="rounded img-fluid" 
                                     style="cursor:pointer;" 
                                     onclick="showImageModal('${base64Str}', ${order_id} , ${order_price} , ${index})">
                              </div>
                            </div>
                            <div class="d-flex flex-column">
                              <a href="${item?.url || 'javascript:void(0);'}" target="_blank" class="text-heading">${item.title}</a>
                              <small>ID: ${item.itemId}</small>
                              <small>QLT: ${item.quantity}</small>
                            </div>
                          </div>`;

                            let row_custom = '<div class="d-flex flex-column">';
                            if(item?.sku){
                                row_custom += `<small>SKU: ${item.sku}</small>`;
                            }
                            item.attributes.forEach(value => {
                                row_custom += `<small>${value}</small>`;
                            });
                            row_custom += '</div>';

                            const currentService = item.services ? item.services.trim() : '';
                            const optionHtml = currentService
                                ? `<option value="${currentService}" selected>${currentService}</option>`
                                : `<option></option>`;

                            html += `
                          <tr class="order-item" data-id="${rowData.id}" data-item-index="${index}">
                            <td style="display: none;"></td>
                            <td></td>
                            <td colspan="3">${row_image}</td>
                            <td colspan="3">${row_custom}</td>
                            <td>
                                <div class="d-flex flex-column gap-2">
                                    <!-- Hộp chọn (Select) -->
                                    <select class="form-select form-select-sm shipping-service">${optionHtml}</select>
                                    <!-- Ô nhập liệu (Input text) -->
                                    <input type="text" value="${item?.track || ''}" class="form-control shipping-tracking" placeholder="Add track here...">
                                </div>
                            </td>
                            <td></td>
                          </tr>`;
                    });

                    html += '';

                    // Chèn hàng con ngay sau hàng cha ở trang hiện tại
                    $(this.node()).after(html);
                    $(this.node()).addClass('order').attr('data-order-id', order_id);
                });

                // 🚀 KHỞI TẠO SELECT2 CHO CÁC Ô INPUT VỪA SINH RA
                // Tìm tất cả các select chưa được khởi tạo Select2 trong trang hiện tại
                $(api.table().container()).find('.shipping-service:not(.select2-hidden-accessible)').each(function() {
                    const $select = $(this);
                    $select.select2({
                        placeholder: 'Shipping service...',
                        multiple: false,
                        tags: true,
                        allowClear: true,
                        ajax: {
                            // URL nên giữ action cố định, 'type' sẽ được gửi trong POST data
                            url: '../../ajax.php?action=get-common-filter',
                            dataType: 'json',
                            type: 'POST',
                            delay: 300,
                            data: function (params) {
                                return {
                                    q: params.term || '',
                                    page: params.page || 1,
                                    type: 'shipping'
                                };
                            },
                            processResults: function (data, params) {
                                return {
                                    results: data.results || [],
                                    pagination: {
                                        more: data.pagination ? data.pagination.more : false
                                    }
                                };
                            },
                            cache: true
                        },
                        minimumInputLength: 0
                    });
                });
            },
            initComplete: function(settings, json) {
                const api = this.api();
                addTrackingNumber(api);
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

function updateItemData(api){
    $('#item-modal-form').on('change', 'input, textarea', function() {
        const data = {
            item_id: $('#item_id').val(),
            order_id: $('#order_id').val(),
            base_cost: $('#item-base-cost').val(),
            note: $('#item-note').val(),
            order_price: $('#order_price').val()
        };

        // Gọi AJAX bằng jQuery
        $.ajax({
            url: '../../ajax.php?action=update-order-item',
            method: 'POST',
            data: data,
            success: function(response) {
                const row = $('tr[data-order-id="'+data.order_id+'"]');
                row.find('.order-price-column').html(renderPriceHtml(data.order_price, data.base_cost));
                console.log('Cập nhật thành công:', response);
            },
            error: function(xhr, status, error) {
                console.error('Lỗi AJAX:', error);
            }
        });
    });
}

function addTrackingNumber(api) {
    // Chỉ lắng nghe các sự kiện thực tế cần thiết ngắt quãng
    const $container = $(api.table().container());

    // 1. Đối với ô Select2: Chỉ nghe sự kiện chọn và xóa của Select2, bỏ qua 'change' thuần
    $container.on('select2:select select2:clear', '.shipping-service', function() {
        triggerAjaxUpdate($(this));
    });

    // 2. Đối với ô Input text: Sử dụng 'change' (khi trỏ chuột ra ngoài) thay vì 'input' (gõ từng chữ)
    // Việc dùng 'change' trên input giúp giảm tải tối đa, chỉ bắn AJAX khi user gõ xong và tab/click ra ngoài
    $container.on('change', '.shipping-tracking', function() {
        triggerAjaxUpdate($(this));
    });

    // Hàm xử lý logic lõi dùng chung để tránh trùng code
    function triggerAjaxUpdate($element) {
        let $row = $element.closest('tr');
        let orderId = $row.data('id');
        let itemIndex  = $row.data('item-index');

        let selectedText = $row.find('.shipping-service').find(':selected').text();
        let services     = selectedText ? selectedText.trim() : '';
        let track        = $row.find('.shipping-tracking').val() ? $row.find('.shipping-tracking').val().trim() : '';

        // Điều kiện: Đầy đủ thông tin và không dính trạng thái khóa (đang gửi)
        if (services !== '' && services !== 'Shipping service...' && track !== '' && !$row.hasClass('is-sending')) {

            // Đánh dấu dòng này đang gửi để chặn mọi hành vi kích hoạt trùng nếu có
            $row.addClass('is-sending');
            $row.find('.shipping-service, .shipping-tracking').prop('disabled', true);

            $.ajax({
                url: '../../ajax.php?action=add-order-tracking',
                type: 'POST',
                dataType: 'json',
                data: {
                    id: orderId,
                    itemIndex: itemIndex,
                    services: services,
                    track: track
                },
                success: function(response) {
                    if (response.status === 'success') {
                        if (response.data && response.data.order_status) {
                            const newStatus = response.data.order_status;
                            let $parentRow = $row.prevAll('tr.order').first();
                            let dtRow = api.row($parentRow);

                            if (dtRow.any()) {
                                let rowData = dtRow.data();
                                if (rowData.status !== newStatus) {
                                    rowData.status = newStatus;
                                    dtRow.data(rowData).invalidate();
                                    $parentRow.find('.order-status-column').html(renderStatusHtml(newStatus));
                                }
                            }
                        }
                        console.log(response.message);
                    } else {
                        alert('Lỗi: ' + response.message);
                    }
                },
                error: function() {
                    alert('Hệ thống gặp lỗi khi kết nối!');
                },
                complete: function() {
                    // Xóa trạng thái chờ và mở khóa input
                    $row.removeClass('is-sending');
                    $row.find('.shipping-service, .shipping-tracking').prop('disabled', false);
                }
            });
        }
    }
}

function renderPriceHtml(total_price, base_cost){
    let base_cost_html = '';
    let profit_html = '';
    if(total_price && base_cost) {
        let _total_price = parseFloat(total_price) || 0;
        let _base_cost = parseFloat(base_cost) || 0;
        let profitValue = (_total_price - _base_cost).toFixed(2);
        base_cost_html = '<small class="text-warning">$' + base_cost + '</small>';
        profit_html ='<span class="text-success"> ($' + profitValue + ')</span>';
    }
    return '<div class="d-flex flex-column"><h6 class="text-nowrap mb-0">$' + total_price + profit_html +'</h6>' + base_cost_html + '</div>';
}

function renderStatusHtml(status){
    const statusObj = {
        unshipped: { title: 'Unshipped', class: 'bg-label-warning' },
        shipped: { title: 'Shipped', class: 'bg-label-secondary' },
        delivered: { title: 'Delivered', class: 'bg-label-success' },
        3: { title: 'Out for Delivery', class: 'bg-label-primary' },
        4: { title: 'Ready to Pickup', class: 'bg-label-info' }
    };

    const statusInfo = statusObj[status];
    if (statusInfo) {
        return `<span class="badge px-2 ${statusInfo.class} text-capitalized">${statusInfo.title}</span>`;
    }
}

/**
 * Hàm tính thời gian đếm ngược cho Ship Date
 * @param {string} shipDateStr - Chuỗi ngày tháng dạng "Jun 30, 00:00" hoặc định dạng Date hợp lệ
 * @return {string} - Chuỗi HTML chứa text kèm class màu của Bootstrap 5
 */
function renderShipCountdownHtml(shipDateStr, status) {
    const targetDate = new Date(shipDateStr);
    const now = new Date();

    if (status !== 'unshipped' || isNaN(targetDate.getTime()) || now >= targetDate) {
        const formattedDate = targetDate.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            timeZone: 'Asia/Ho_Chi_Minh'
        });
        return `<span class="text-nowrap">${formattedDate}</span>`;
    }

    // Tính khoảng cách thời gian (miligiây)
    const diffTime = targetDate - now;

    // Tính toán các thành phần thời gian cơ bản
    const days = Math.floor(diffTime / (1000 * 60 * 60 * 24));
    const minutes = Math.floor((diffTime % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diffTime % (1000 * 60)) / 1000);
    const mm = minutes.toString().padStart(2, '0');
    const ss = seconds.toString().padStart(2, '0');

    let textClass = "";
    let countdownText = "";

    if (days < 1) {
        // Còn dưới 1 ngày -> Màu đỏ gấp
        const hours = Math.floor((diffTime % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const hh = hours.toString().padStart(2, '0');
        textClass = "text-danger";
        countdownText = `<i class="icon-base ti tabler-alert-circle me-1"></i>${hh}:${mm}:${ss}`;
    } else if (days < 2) {
        // Còn từ 1 đến dưới 2 ngày -> Tính tổng số giờ (Ví dụ: từ 24h đến 47h)
        const totalHours = Math.floor(diffTime / (1000 * 60 * 60));
        const hh = totalHours.toString().padStart(2, '0');
        textClass = "text-warning";
        countdownText = `<i class="icon-base ti tabler-alert-triangle me-1"></i>${hh}:${mm}:${ss}`; // Hiển thị tổng số giờ
    } else {
        // Còn trên 2 ngày -> Hiện số ngày thong thả
        textClass = "text-success";
        countdownText = `<i class="icon-base ti tabler-clock me-1"></i>${days} days left`;
    }

    return `<span class="${textClass} text-nowrap d-inline-flex align-items-center">${countdownText}</span>`;
}

document.addEventListener('DOMContentLoaded', function (e) {
    init();
});
