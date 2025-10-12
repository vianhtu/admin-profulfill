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

// Hàm cập nhật progress bar
function updateProgressBars(dt) {
    // Lấy tất cả các progress đang hiển thị (status = running)
    var ids = [];
    dt.rows().every(function () {
        var data = this.data();
        var rowNode = this.node();
        var $row = $(rowNode);
        var $statusCell = $row.find('.file-status');

        if ($statusCell.length) {
            var text = $statusCell.text().trim().toLowerCase();
            if (text.indexOf('running') !== -1) {
                var id = data && (data.id || data.ID) ? (data.id || data.ID) : $row.data('id');
                if (id !== undefined && id !== null) ids.push(id);
            }
        }
    });

    if (ids.length === 0) {
        return; // Không có row nào cần cập nhật
    }

    // Gọi AJAX lấy dữ liệu % tiến trình
    $.ajax({
        url: '../../ajax.php?action=get-download-products-process', // endpoint của bạn
        method: 'POST',
        data: { ids: ids },
        dataType: 'json',
        success: function (response) {
            // response có thể là mảng [{id:..., progress:..., status:...}, ...]
            response.forEach(function (item) {
                // Nếu status không còn "running" thì ẩn progress overlay
                if (item.status !== 'running') {
                    dt.rows().every(function() {
                        var data = this.data();
                        var rowId = data.id || data.ID || data[0];
                        if (String(rowId) === String(item.id)) {
                            this.cell(this.index(), 5).data(item.status);
                        }
                    });
                }
            });
        },
        error: function (xhr, status, error) {
            console.error('Lỗi khi lấy tiến trình:', error);
        }
    });
}

// Datatable (js)
function initTable(){
    const statusObj = {
        schedule: { title: 'schedule', class: 'bg-label-secondary' },
        running: { title: 'running', class: 'bg-label-warning' },
        ready: { title: 'ready', class: 'bg-label-success' },
        error: { title: 'error', class: 'bg-label-danger' }
    }

    // Variable declaration for table
    const dt_user_table = document.querySelector('.datatables-users');
    // Users datatable
    if (dt_user_table) {
        const dt_user = new DataTable(dt_user_table, {
            serverSide: true,
            processing: true,
            ajax: {
                url: '../../ajax.php?action=get-download-table',
                type: 'POST',
                data: function (d) {
                    const urlParams = new URLSearchParams(window.location.search);
                    const initialFilters = {
                        xlsxTypes: urlParams.get('xlsxTypes') || '',
                        xlsxSites: urlParams.get('xlsxSites') || '',
                        xlsxAuthors: urlParams.get('xlsxAuthors') || ''
                    };
                    const mapping = { xlsxTypes: 'type_id', xlsxSites: 'site_id', xlsxAuthors: 'author_id' }; // data names in your columns config
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
            drawCallback: function(settings) {
                updateProgressBars(dt_user);
                $('#DataTables_Table_0 .user-name .avatar-wrapper').on('click', function () {
                    const $icon = $(this);
                    const $container = $(this).find('.position-relative');
                    const $spinner = $container.find('.spinner-border');
                    const $tr = $icon.closest('tr');
                    // Hiện spinner
                    $spinner.removeClass('d-none');
                    $tr.addClass('tr-loading');
                    // Gọi AJAX
                    var id = $tr.find('.user-name').data('id');

                    $.ajax({
                        url: '../../ajax.php?action=download-xlsx',
                        method: 'POST',
                        data: { id: id },
                        xhrFields: { responseType: 'blob' },
                        beforeSend: function () {
                            $spinner.removeClass('d-none');
                            $tr.addClass('tr-loading');
                        },
                        success: function (response, textStatus, jqXHR) {
                            // Lấy filename từ header nếu server gửi Content-Disposition
                            var disposition = jqXHR.getResponseHeader('Content-Disposition');
                            var filename = '';
                            if (disposition && disposition.indexOf('filename') !== -1) {
                                var matches = disposition.match(/filename\*=UTF-8''([^;]+)|filename="([^"]+)"|filename=([^;]+)/);
                                if (matches) {
                                    filename = decodeURIComponent(matches[1] || matches[2] || matches[3] || '');
                                }
                            }
                            if (!filename) {
                                // fallback filename
                                var dt = new Date();
                                filename = 'export-' + dt.toLocaleString().replace(/[:\/, ]+/g, '-') + '.xlsx';
                            }
                            var url = window.URL.createObjectURL(response);
                            var link = document.createElement('a');
                            link.href = url;
                            link.download = filename;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            window.URL.revokeObjectURL(url);
                        },
                        error: function (jqXHR, textStatus, errorThrown) {
                            // Thường ở đây server trả lỗi JSON/text, không phải blob
                            console.error('Download error:', textStatus, errorThrown);
                        },
                        complete: function () {
                            $spinner.addClass('d-none');
                            $tr.removeClass('tr-loading');
                        }
                    });
                });
            },
            columns: [
                // columns according to JSON
                { data: 'id' },
                { data: 'id', orderable: false, render: DataTable.render.select() },
                { data: 'name' },
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
                        var name = sitesObj[full['account_site_id']].title + ' - ' + full['name'];
                        var account_name = full['temp_file_name'] + ' - ' + categoryObj[full['type_id']].title;
                        var image = './../../assets/svg/icons/xlsx_icon.svg';
                        var output = '<div class="position-relative">' +
                            '<img src="' + image + '" alt="file.xlsx" class="rounded">' +
                            '<div class="position-absolute top-0 start-0 w-100 h-100 d-flex justify-content-center align-items-center">'+
                            '<div class="spinner-border text-primary d-none" role="status" style="width: 1rem; height: 1rem;">' +
                            '    <span class="visually-hidden">Loading...</span>' +
                            '</div>' +
                            '</div>' +
                            '</div>';

                        var row_output =
                            '<div class="d-flex justify-content-start align-items-center user-name" data-id="'+full['id']+'">' +
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
                        if (statusObj[status] === undefined) {
                            return '<div class="d-flex align-items-center"><div class="spinner-border spinner-border-sm me-2" role="status"></div><span>Loading...</span></div>';
                        }
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
                        return '<span class="total-complete">' + total_items + '</span>';
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
                                    text: '<span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-automation icon-xs"></i> <span class="d-none d-sm-inline-block">Actions</span></span>',
                                    buttons: [
                                        {
                                            text: '<span class="d-flex align-items-center"><i class="icon-base ti tabler-brand-google-podcasts me-1"></i>Google Gemini 2.5 Flash</span>',
                                            className: 'dropdown-item',
                                            action: function (e, dt, node, config) {
                                                let columnIndex = 5;
                                                // lấy các id đã chọn từ DataTable (ví dụ chọn hàng)
                                                var selectedIds = [];
                                                if (dt && dt.rows && dt.rows({ selected: true }).data().length) {
                                                    dt.rows({ selected: true }).every(function () {
                                                        var data = this.data();
                                                        var rowId = data.id || data.ID || data[0];
                                                        selectedIds.push(rowId);
                                                        var rowNode = this.node();
                                                        dt.cell(rowNode, columnIndex).data('loading');
                                                    });
                                                }

                                                if(selectedIds.length === 0){
                                                    alert('Select at least one record to process');
                                                }

                                                // AJAX request
                                                $.ajax({
                                                    url: '../../ajax.php?action=action-download-table-gemini25flash',
                                                    method: 'POST',
                                                    data: {ids:selectedIds},
                                                    dataType: 'json',
                                                    success: function (response) {
                                                        if (response.status === 'success' && response.ids) {
                                                            Object.keys(response.ids).forEach(function(id) {
                                                                var payload = response.ids[id];
                                                                // nếu không dùng rowId, tìm row bằng so sánh data.id
                                                                dt.rows().every(function() {
                                                                    var data = this.data();
                                                                    var rowId = data.id || data.ID || data[0];
                                                                    if (String(rowId) === String(id)) {
                                                                        let status = 'running';
                                                                        if(payload.status === 'error'){
                                                                            status = 'error';
                                                                            console.log('Error:', payload);
                                                                        }
                                                                        this.cell(this.index(), columnIndex).data(status);
                                                                    }
                                                                });
                                                            });
                                                        } else {
                                                            console.log('Unexpected response', response);
                                                        }
                                                    },
                                                    error: function (xhr) {
                                                        console.error('Error:', xhr.responseText);
                                                        alert('Upload thất bại!');
                                                    }
                                                });
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
            // For responsive popup
            responsive: {
                details: {
                    display: DataTable.Responsive.display.modal({
                        header: function (row) {
                            const data = row.data();
                            return 'Details of ' + data['name'];
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
                getSelect2filterTable(api,'xlsxStatus', '.xlsx_status', 5, 'Status', statusObj);
                getSelect2filterTable(api,'xlsxTypes', '.xlsx_types', 11, 'Type', categoryObj);
                getSelect2filterTable(api,'xlsxSites', '.xlsx_sites', 4, 'Site', sitesObj);
                getSelect2filterTable(api,'xlsxAuthors', '.xlsx_authors', 10, 'Author', authorsObj);

                // Accounts filter
                getAjaxSelect2HTML('xlsx_accounts', 'xlsxAccounts', 'Accounts', 'filter-accounts', true);
                // Add event listener for filtering
                $('#xlsxAccounts').on('change', function (){
                    dt_user.draw();
                });
            }
        });
        setInterval(() => updateProgressBars(dt_user), 15000);
    }

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
