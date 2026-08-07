/**
 * app-ecommerce-order-list Script
 */

'use strict';

let urlState = null;

// Escape dữ liệu người dùng (tên/địa chỉ khách, title item từ sàn...) trước khi nhét vào HTML
// -> chặn stored XSS. Bắt buộc với MỌI field free-text khi render.
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Chỉ cho phép link http(s) — chặn javascript: URL chui vào href từ dữ liệu item
function safeUrl(url) {
    return /^https?:\/\//i.test(url || '') ? url : 'javascript:void(0);';
}

let sitesObj = {};
let orderPerms = { edit: false, delete: false, is_admin: false };

const ORDER_STATUSES = ['unshipped', 'cancel', 'refund', 'shipped', 'replace', 'delivered'];

async function init() {
    try {
        // Orders có endpoint filter riêng — role Orders độc lập với Products
        const options = await fetchTableFilter('get-orders-table-filter');
        sitesObj = options['sites'] ?? {};
        orderPerms = options['perms'] ?? orderPerms;

        initTable();
        updateItemData();
    } catch (err) {
        alert('Failed to load order options');
    }
}

function showImageModal(base64Str, order_id, order_price, item_index) {
    let item_data = {};
    try {
        // Giải mã Base64 Unicode phiên bản viết gọn
        const jsonStr = decodeURIComponent(atob(base64Str).split('').map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join(''));
        item_data = JSON.parse(jsonStr);
    } catch (e) {
        return console.error('Failed to decode item data:', e);
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

    if (dt_order_table) {
        // Thứ tự PHẢI khớp mảng `columns` bên dưới
        const ORDER_COLS = ['id','id','host_id','purchase_date','ship_date','delivery_date',
                            'total_price','status','full_name','id'];
        urlState = dtUrlState({
            minDate: '#minDate', maxDate: '#maxDate',
            account: '#accountFilter', sites: '#sitesFilter'
        }, 10);

        const dt_products = new DataTable(dt_order_table, {
            serverSide: true,
            processing: true,
            // stateSave ĐÃ BỎ: nó khôi phục trạng thái từ localStorage nên đánh nhau với
            // URL — mở link người khác gửi lại ra bộ lọc cũ của mình. URL là nguồn duy nhất.
            ajax: {
                url: '../../ajax.php?action=get-orders-table',
                type: 'POST',
                data: function (d) {
                    // Lần vẽ đầu các ô lọc chưa dựng -> lấy thẳng từ URL
                    d.minDate = $('#minDate').length ? ($('#minDate').val() || '') : urlState.get('minDate');
                    d.maxDate = $('#maxDate').length ? ($('#maxDate').val() || '') : urlState.get('maxDate');
                    d.account = $('#accountFilter').length ? ($('#accountFilter').val() || '') : urlState.get('account');
                    d.sites = $('#sitesFilter').length ? ($('#sitesFilter').val() || []) : urlState.getMulti('sites');
                },
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
                    responsivePriority: 3,
                    render: function () {
                        return '<input type="checkbox" class="dt-checkboxes form-check-input">';
                    }
                },
                {
                    // Order ID
                    targets: 2,
                    render: function (data, type, full, meta) {
                        const siteTitle = sitesObj[full['site_id']]?.title ?? '';
                        return '<div class="d-flex flex-column">' +
                            '<a href="javascript:void(0);">' +
                                '<span class="text-nowrap">#' + esc(full['host_id']) + '</span>' +
                            '</a>' +
                            '<small>' + esc(siteTitle) + ' (' + esc(full['account_name']) + ')</small>' +
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
                            <i class="ti tabler-truck me-1 fs-5"></i>${toLocalDate(full['fulfill_date'])}</small>`;
                        }
                        return '<div class="d-flex flex-column">' +
                            renderShipCountdownHtml(full['status'], full['ship_date'], full['fulfill_date'], full['server_date']) + fulfill_date +
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
                            '<h6 class="text-nowrap mb-0">' + esc(full['full_name']) + '</h6>' +
                            '<small>' + esc(full['address']) + '</small>' +
                            '<small>' + esc(full['phone']) + '</small>' +
                        '</div>';
                    }
                },
                {
                    targets: -1,
                    title: 'Actions',
                    searchable: false,
                    orderable: false,
                    render: function (data, type, full, meta) {
                        // Quyền theo từng dòng: không đủ quyền thì KHÔNG render control
                        const statusItems = full['can_edit']
                            ? ORDER_STATUSES.map(s =>
                                `<a href="javascript:void(0);" class="dropdown-item update-status text-capitalize" data-status="${s}">${s}</a>`
                              ).join('')
                            : '';
                        const deleteItem = full['can_delete']
                            ? '<a href="javascript:void(0);" class="dropdown-item delete-record">Delete</a>'
                            : '';
                        // Nút CON trong nhóm thì ẩn; con ẩn hết thì KHÓA nút cha, không ẩn cả
                        // cụm — giữ cột Actions thẳng hàng giữa các dòng.
                        if (!statusItems && !deleteItem) {
                            return '<div class="d-flex justify-content-sm-start align-items-sm-center">' +
                                '<button class="btn btn-text-secondary rounded-pill btn-icon hide-arrow" disabled' +
                                ' title="You have no actions available on this order">' +
                                '<i class="icon-base ti tabler-dots-vertical"></i></button></div>';
                        }
                        return `
                          <div class="d-flex justify-content-sm-start align-items-sm-center">
                            <button class="btn btn-text-secondary rounded-pill waves-effect btn-icon dropdown-toggle hide-arrow" data-bs-toggle="dropdown">
                              <i class="icon-base ti tabler-dots-vertical"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end m-0">${statusItems}${deleteItem}</div>
                          </div>`;
                    }
                }
            ],
            select: {
                style: 'multi',
                selector: 'td:nth-child(2)'
            },
            order: [3, 'desc'],
            // PHẢI spread SAU order, nếu không mặc định ghi đè giá trị từ URL
            ...urlState.tableOptions(ORDER_COLS),
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
                                menu: [10, 25, 50, 100],
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
                                                    body: exportBodyFormat
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
                                                    body: exportBodyFormat
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
                                                    body: exportBodyFormat
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
                                                    body: exportBodyFormat
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
                                                    body: exportBodyFormat
                                                }
                                            }
                                        }
                                    ]
                                },
                                ...(orderPerms.delete ? [{
                                    text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-trash icon-xs"></i> <span class="d-none d-sm-inline-block">Delete Selected</span></span>',
                                    className: 'btn btn-text-danger',
                                    action: (e, dtApi) => openDeleteOrderModal(getSelectedOrderIds(dtApi))
                                }] : [])
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
                            return 'Details of ' + esc(data['full_name']);
                        }
                    }),
                    type: 'column',
                    // Bỏ renderer tự viết của template: dưới DataTables 2.1.8 + Responsive 3.0.4 nó
                    // trả về rỗng, nên bấm '+' chỉ đánh dấu dòng mà không mở ra gì. Bản mặc
                    // định của Responsive chạy đúng và cũng đã tự bỏ qua cột không có tiêu đề.
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

                // Luôn lưu và giữ chiều dài trang (URL + localStorage) — key riêng của trang Orders
                url.searchParams.set('length', data.length);
                localStorage.setItem('dt_orders_length', data.length);

                // TÍCH HỢP ORDER BY: Lưu lên cả URL lẫn localStorage giống hệt length
                if (data.order && data.order.length > 0) {
                    const col = data.order[0][0];
                    const dir = data.order[0][1];

                    url.searchParams.set('order_col', col);
                    url.searchParams.set('order_dir', dir);

                    localStorage.setItem('dt_orders_order_col', col);
                    localStorage.setItem('dt_orders_order_dir', dir);
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
                const savedLength = parseInt(localStorage.getItem('dt_orders_length'), 10) || settings._iDisplayLength || 10;

                const localOrderCol = localStorage.getItem('dt_orders_order_col');
                const localOrderDir = localStorage.getItem('dt_orders_order_dir');
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
                        try {
                            items = JSON.parse(rowData.items);
                        } catch (e) {
                            items = [];
                        }
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
                                <img src="${esc(item.imageUrl)}"
                                     class="rounded img-fluid"
                                     style="cursor:pointer;"
                                     onclick="showImageModal('${base64Str}', ${Number(order_id)} , ${JSON.stringify(Number(order_price) || 0)} , ${index})">
                              </div>
                            </div>
                            <div class="d-flex flex-column">
                              <a href="${esc(safeUrl(item?.url))}" target="_blank" rel="noopener" class="text-heading">${esc(item.title)}</a>
                              <small>ID: ${esc(item.itemId)}</small>
                              <small>QLT: ${esc(item.quantity)}</small>
                            </div>
                          </div>`;

                            let row_custom = '<div class="d-flex flex-column">';
                            if(item?.sku){
                                row_custom += `<small>SKU: ${esc(item.sku)}</small>`;
                            }
                            (item.attributes || []).forEach(value => {
                                row_custom += `<small>${esc(value)}</small>`;
                            });
                            row_custom += '</div>';

                            const currentService = item.services ? item.services.trim() : '';

                            // Ô tracking: chỉ user có quyền edit mới thấy input, còn lại chỉ đọc
                            let trackingCell;
                            if (rowData.can_edit) {
                                const optionHtml = currentService
                                    ? `<option value="${esc(currentService)}" selected>${esc(currentService)}</option>`
                                    : `<option></option>`;
                                trackingCell = `
                                <div class="d-flex flex-column gap-2">
                                    <select class="form-select form-select-sm shipping-service">${optionHtml}</select>
                                    <input type="text" value="${esc(item?.track || '')}" class="form-control shipping-tracking" placeholder="Add track here...">
                                </div>`;
                            } else {
                                trackingCell = `
                                <div class="d-flex flex-column">
                                    <small>${esc(currentService || '—')}</small>
                                    <small>${esc(item?.track || '—')}</small>
                                </div>`;
                            }

                            html += `
                          <tr class="order-item" data-id="${Number(rowData.id)}" data-item-index="${index}">
                            <td style="display: none;"></td>
                            <td></td>
                            <td colspan="3">${row_image}</td>
                            <td colspan="3">${row_custom}</td>
                            <td>${trackingCell}</td>
                            <td></td>
                          </tr>`;
                    });

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
                // Đổ trạng thái từ URL vào các ô lọc (dựng ngay bên dưới) rồi ghi lại URL
                // sau mỗi lần bảng vẽ. Đặt ở CUỐI initComplete — xem dòng gọi phía dưới.

                // --- Bộ lọc của trang: khoảng ngày mua + account + sites ---
                $('.order_from_date').html('<label class="form-label">From</label><input type="text" class="form-control" id="minDate" placeholder="YYYY-MM-DD">');
                $('.order_to_date').html('<label class="form-label">To</label><input type="text" class="form-control" id="maxDate" placeholder="YYYY-MM-DD">');

                // flatpickr của template: value giữ Y-m-d cho backend, hiển thị d/m/Y cho người dùng
                const maxPicker = document.querySelector('#maxDate').flatpickr({
                    monthSelectorType: 'static',
                    dateFormat: 'Y-m-d',
                    altInput: true,
                    altFormat: 'd/m/Y',
                    onChange: function () {
                        api.draw();
                    }
                });
                document.querySelector('#minDate').flatpickr({
                    monthSelectorType: 'static',
                    dateFormat: 'Y-m-d',
                    altInput: true,
                    altFormat: 'd/m/Y',
                    onChange: function (selectedDates, dateStr) {
                        // Không cho chọn ngày kết thúc trước ngày bắt đầu
                        maxPicker.set('minDate', dateStr || null);
                        api.draw();
                    }
                });

                // Account: select2 ajax (danh sách account đã được server giới hạn theo team)
                getAjaxSelect2HTML('order_account', 'accountFilter', 'Account', 'filter-accounts', false, null, 0);
                // Sites: select2 ajax multiple
                getAjaxSelect2HTML('order_sites', 'sitesFilter', 'Sites', 'filter-sites', true, null, 0);
                $('#accountFilter, #sitesFilter').on('change', function () {
                    api.draw();
                });

                // Các ô lọc đã dựng xong -> đổ trạng thái từ URL vào, rồi ghi lại URL sau
                // mỗi lần bảng vẽ. Lần vẽ đầu đã đọc thẳng URL trong hàm data() nên không
                // cần draw lại ở đây.
                urlState.applyFilters();
                urlState.bind(dt_products, ORDER_COLS);
            }
        });

        // 1. Xóa: nút theo dòng (per-row can_delete) + Delete Selected — dùng chung modal
        $(document).on('click', '.delete-record', function (e) {
            e.preventDefault();
            const orderId = parseInt($(this).closest('tr').attr('data-order-id'), 10);
            if (orderId) {
                openDeleteOrderModal([orderId]);
            }
        });
        $(document).on('click', '#btn-confirm-delete', function () {
            const ids = $('#deleteConfirmModal').data('ids') || [];
            if (ids.length) {
                deleteOrders(ids, dt_products);
            }
            bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'))?.hide();
            $('#deleteConfirmModal').removeData('ids');
        });

        // 2. Xử lý sự kiện click vào nút cập nhật trạng thái (.update-status)
        $(document).on('click', '.update-status', function (e) {
            e.preventDefault(); // Ngăn hành vi mặc định nếu nút là thẻ <a>

            const $updateBtn = $(this); // Đã dùng Event Delegation nên $(this) luôn là .update-status (không lo hụt vào icon bên trong)
            const $row = $updateBtn.closest('tr');

            // Lấy dữ liệu từ attribute bằng hàm .attr() hoặc .data() của jQuery
            const status = $updateBtn.attr('data-status');
            const orderId = $row.attr('data-order-id');

            // Gọi hàm xử lý AJAX cập nhật trạng thái
            updateOrdersStatus({ [orderId]: status });
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
            { selector: '.dt-layout-full', classToRemove: 'col-md col-12', classToAdd: 'table-responsive' },
            // Bỏ .btn-group do DataTables Buttons thêm vào: nó cắt bo góc các nút rời nhau
            { selector: '.dt-buttons', classToRemove: 'btn-group' }
        ];

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

        // Ô chọn số item/trang cũng dùng select2 cho đồng bộ với 4 trang chuẩn
        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
    }, 100);
}

// Format dùng chung cho các nút export (print/csv/excel/pdf/copy)
function exportBodyFormat(inner, coldex, rowdex) {
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

// Gom ID các dòng đang chọn (API select + fallback checkbox)
function getSelectedOrderIds(dt) {
    const ids = new Set(dt.rows({ selected: true }).data().toArray().map(r => r.id));
    $('.datatables-order tbody input.dt-checkboxes:checked').each(function () {
        const row = dt.row($(this).closest('tr')).data();
        if (row) {
            ids.add(row.id);
        }
    });
    return [...ids];
}

function openDeleteOrderModal(ids) {
    if (!ids.length) {
        alert('Select at least 1 order.');
        return;
    }
    const modalEl = document.getElementById('deleteConfirmModal');
    if (!modalEl) {
        return; // không có role delete thì fragment không render modal
    }
    $('#deleteOrderCount').text(ids.length.toLocaleString());
    $(modalEl).data('ids', ids);
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function updateOrdersStatus(orders) {
    // Gọi AJAX bằng jQuery
    $.ajax({
        url: '../../ajax.php?action=update-orders-status',
        method: 'POST',
        data: {
            orders: orders,
            csrf_token: window.csrfToken
        },
        success: function(response) {
            if (response?.status === 'success' && !$.isEmptyObject(response?.orders)) {
                $.each(response.orders, function(orderId, status) {
                    var $row = $('tr.order[data-order-id="' + orderId + '"]');
                    if ($row.length) {
                        $row.find('.order-status-column').html(renderStatusHtml(status));
                    }
                });
            } else {
                alert(response?.message || 'Failed to update order status.');
            }
        },
        error: function(xhr, status, error) {
            alert('Server connection error.');
        }
    });
}

function deleteOrders(orders, table) {
    // Gọi AJAX bằng jQuery
    $.ajax({
        url: '../../ajax.php?action=delete-orders',
        method: 'POST',
        data: {
            order_ids: orders,
            csrf_token: window.csrfToken
        },
        success: function(response) {
            if (response?.status === 'success' && !$.isEmptyObject(response?.orders)) {
                let hasRemoved = false;

                // Duyệt qua danh sách các ID đơn hàng đã xóa thành công từ server trả về
                $.each(response.orders, function(orderId) {
                    // Tìm dòng <tr> dựa vào data attribute
                    var $row = $('tr.order[data-order-id="' + orderId + '"]');

                    // Nếu tìm thấy dòng và thực thể DataTable tồn tại
                    if ($row.length > 0 && table) {
                        table.row($row).remove();
                        hasRemoved = true;
                    }
                });

                if (hasRemoved) {
                    table.draw(false);
                }
            } else {
                alert(response?.message || 'Failed to delete orders.');
            }
        },
        error: function(xhr, status, error) {
            alert('Server connection error.');
        }
    });
}

function updateItemData(){
    $('#item-modal-form').on('change', 'input, textarea', function() {
        const data = {
            item_id: $('#item_id').val(),
            order_id: $('#order_id').val(),
            base_cost: $('#item-base-cost').val(),
            note: $('#item-note').val(),
            order_price: $('#order_price').val(),
            csrf_token: window.csrfToken
        };

        // Gọi AJAX bằng jQuery
        $.ajax({
            url: '../../ajax.php?action=update-order-item-data',
            method: 'POST',
            data: data,
            success: function(response) {
                if (response?.status !== 'success') {
                    alert(response?.message || 'Failed to save item data.');
                    return;
                }
                const row = $('tr[data-order-id="'+data.order_id+'"]');
                row.find('.order-price-column').html(renderPriceHtml(data.order_price, data.base_cost));
            },
            error: function(xhr, status, error) {
                alert('Server connection error.');
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
                url: '../../ajax.php?action=add-order-item-tracking',
                type: 'POST',
                dataType: 'json',
                data: {
                    id: orderId,
                    itemIndex: itemIndex,
                    services: services,
                    track: track,
                    csrf_token: window.csrfToken
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
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('Server connection error.');
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
        let profitClass = parseFloat(profitValue) >= 0 ? 'text-success' : 'text-danger';
        base_cost_html = '<small class="text-warning">$' + esc(base_cost) + '</small>';
        profit_html ='<span class="'+profitClass+'"> ($' + profitValue + ')</span>';
    }
    return '<div class="d-flex flex-column"><h6 class="text-nowrap mb-0">$' + esc(total_price) + profit_html +'</h6>' + base_cost_html + '</div>';
}

function renderStatusHtml(status){
    const statusObj = {
        unshipped: { title: 'Unshipped', class: 'bg-label-warning' },
        cancel: { title: 'Cancel', class: 'bg-label-secondary' },
        shipped: { title: 'Shipped', class: 'bg-label-primary' },
        replace: { title: 'Replace', class: 'bg-label-info' },
        refund: { title: 'Refund', class: 'bg-label-danger' },
        delivered: { title: 'Delivered', class: 'bg-label-success' }
    };

    const statusInfo = statusObj[status];
    if (statusInfo) {
        return `<span class="badge px-2 ${statusInfo.class} text-capitalized">${statusInfo.title}</span>`;
    }
    // Trạng thái lạ (dữ liệu cũ/sai): hiển thị trung tính, đã escape
    return `<span class="badge px-2 bg-label-secondary">${esc(status || '—')}</span>`;
}

/**
 * Hàm tính thời gian đếm ngược cho Ship Date
 * @param {string} shipDateStr - Chuỗi ngày tháng dạng "Jun 30, 00:00" hoặc định dạng Date hợp lệ
 * @param {string} fulfillDate - Chuỗi ngày tháng dạng "Jun 30, 00:00" hoặc định dạng Date hợp lệ
 * @param {string} serverDate - Chuỗi ngày tháng dạng "Jun 30, 00:00" hoặc định dạng Date hợp lệ
 * @param {string} status - Trạng thái đơn hàng.
 * @return {string} - Chuỗi HTML chứa text kèm class màu của Bootstrap 5
 */
function renderShipCountdownHtml(status, shipDateStr, fulfillDate , serverDate= '') {
    const targetDate = new Date(shipDateStr);
    const now = fulfillDate ? new Date(serverDate) : new Date();

    if (status !== 'unshipped' || isNaN(targetDate.getTime()) || now >= targetDate) {
        const formattedDate = targetDate.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            timeZone: 'Asia/Ho_Chi_Minh'
        });
        let c = 'text-danger';
        if (fulfillDate) {
            const fd = new Date(fulfillDate);
            if (!isNaN(fd.getTime())) {
                const compareFulfill = new Date(fd).setHours(0, 0, 0, 0);
                const compareTarget = new Date(targetDate).setHours(0, 0, 0, 0);
                // Chỉ so sánh ngày tháng năm
                c = compareFulfill <= compareTarget ? 'text-success' : 'text-danger';
            }
        }
        return `<span class="${c} text-nowrap">${formattedDate}</span>`;
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
