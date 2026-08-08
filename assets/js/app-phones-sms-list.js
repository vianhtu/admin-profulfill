/**
 * Page Phones — SMS
 *
 * Trang này nguy hiểm hơn các trang khác một bậc: `text` và `from_number` là nội dung TIN
 * NHẮN ĐẾN, tức do bất kỳ ai nhắn tới số đó soạn ra — kẻ tấn công không cần tài khoản nào.
 * Mọi field free-text bắt buộc đi qua esc() trước khi vào HTML.
 */

'use strict';

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Nút LẺ ở cột Actions: thiếu quyền thì KHÓA kèm lý do, không ẩn — ẩn làm các nút còn lại
// xô lệch giữa các dòng.
function lockedBtn(icon, why) {
    return `<button type="button" class="btn btn-text-secondary rounded-pill btn-icon" disabled` +
        ` title="${esc(why)}"><i class="icon-base ti ${icon} icon-22px"></i></button>`;
}

// Cột theo THỨ TỰ trong bảng, để dịch giữa chỉ số cột và tên khóa trên URL.
const SMS_COLS = [null, null, 'number', 'from', 'text', 'status', 'date', null];

let urlState = null;
let dtSms = null;

function fmtSmsDate(s) {
    if (!s) {
        return '—';
    }
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? String(s) : d.toLocaleString('en-GB',
        { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const SMS_PHONES_URL = '../../ajax.php?action=get-sms-phones';

function init() {
    initTable();
}

/* ---------------- Ô lọc số điện thoại (select2 ajax) ---------------- */

/**
 * Nạp danh sách số theo yêu cầu thay vì nhét sẵn vào trang.
 *
 * Bản đầu `await` một lượt lấy TOÀN BỘ số rồi mới dựng DataTable — bảng phải chờ xong một
 * vòng mạng cộng một truy vấn quét cả bảng `phones` mới bắt đầu tải, và danh sách đó chỉ
 * dài thêm theo thời gian. Nay select2 tự gọi khi người dùng mở ô lọc, mỗi lượt 30 dòng.
 */
function dungOLocSo(banDau) {
    const $sel = $('#SmsPhone');
    if (!$sel.length) {
        return;
    }
    // Giá trị dựng từ URL: gắn option TẠM ngay lập tức để `.val()` có giá trị đúng ở lần
    // vẽ bảng đầu tiên. Chờ nhãn thật rồi mới gắn thì lần draw đầu đọc ra rỗng và bảng
    // hiện tin của mọi số.
    if (banDau) {
        $sel.append(new Option('#' + banDau, banDau, true, true));
    }
    $sel.select2({
        width: '100%',
        placeholder: 'All numbers',
        allowClear: true,
        ajax: {
            url: SMS_PHONES_URL,
            type: 'POST',
            dataType: 'json',
            delay: 250,   // gõ tới đâu gọi tới đó thì mỗi phím một request
            data: params => ({ csrf_token: window.csrfToken,
                               q: params.term || '', page: params.page || 1 }),
            processResults: data => ({ results: data.results || [],
                                       pagination: { more: !!(data.pagination || {}).more } }),
            cache: true
        }
    });
    if (!banDau) {
        return;
    }
    // Đổi nhãn tạm '#123' thành số thật khi server trả lời. `change.select2` chỉ đánh thức
    // phần vẽ của select2, KHÔNG chạm handler `change` của mình -> bảng không nạp lại.
    $.post(SMS_PHONES_URL, { csrf_token: window.csrfToken, id: banDau }, null, 'json')
        .done(res => {
            const p = ((res && res.results) || [])[0];
            if (p) {
                $sel.find('option[value="' + Number(p.id) + '"]').text(p.text);
                $sel.trigger('change.select2');
            }
        });
}

/* ---------------- Bảng ---------------- */

function initTable() {
    const stateObj = {
        pending: { title: 'unread', class: 'bg-label-warning' },
        viewed: { title: 'read', class: 'bg-label-secondary' }
    };

    // Đọc tham số URL TRƯỚC khi dựng bảng rồi nhét vào config, để bảng không vẽ hai lần
    urlState = dtUrlState({ SmsPhone: '#SmsPhone', SmsStatus: '#SmsStatus' }, 10);

    // Trang Numbers link sang đây bằng `?id=<phone>`. Coi đó là giá trị khởi tạo của ô lọc
    // rồi BỎ HẲN khỏi URL: `id` nằm trong danh sách tham số dt-url-state luôn giữ lại, nên
    // để nguyên thì đổi ô lọc xong URL thành `id=2928&SmsPhone=2927` — tự mâu thuẫn, ai
    // copy đường dẫn đó cũng không đoán được đang xem số nào.
    const seed = new URLSearchParams(window.location.search).get('id');
    if (seed) {
        const con = new URLSearchParams(window.location.search);
        con.delete('id');
        const qs = con.toString();
        history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
    }
    // `SmsPhone` trên URL thắng, `id` chỉ là đường vào từ trang Numbers
    dungOLocSo(Number(urlState.get('SmsPhone') || seed || 0) || 0);
    urlState.applyFilters();

    const el = document.querySelector('.datatables-sms');
    if (!el) {
        return;
    }
    dtSms = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: '../../ajax.php?action=get-sms-table',
            type: 'POST',
            data: function (d) {
                d.phone_id = $('#SmsPhone').val() || 0;
                d.sms_status = $('#SmsStatus').val() || '';
            },
            dataSrc: function (json) {
                return json.data;
            }
        },
        columns: [
            { data: 'id' },
            { data: 'id', orderable: false, render: DataTable.render.select() },
            { data: 'number' },
            { data: 'from' },
            { data: 'text' },
            { data: 'status' },
            { data: 'date' },
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
                render: function () {
                    return '';
                }
            },
            {
                // For Checkboxes
                targets: 1,
                orderable: false,
                searchable: false,
                responsivePriority: 4,
                render: function () {
                    return '<input type="checkbox" class="dt-checkboxes form-check-input">';
                },
                checkboxes: {
                    selectAllRender: '<input type="checkbox" class="form-check-input">'
                }
            },
            {
                // To — số của mình đã nhận tin; bấm vào lọc luôn theo số đó
                targets: 2,
                responsivePriority: 3,
                render: function (data, type, full) {
                    return '<a href="index.php?menu=phones_sms&id=' + Number(full['phone_id']) +
                        '" class="text-heading fw-medium text-nowrap">' +
                        esc(full['number']) + '</a>';
                }
            },
            {
                // From — số người lạ, KHÔNG tin được, phải esc()
                targets: 3,
                render: function (data, type, full) {
                    return '<span class="text-nowrap">' + esc(full['from']) + '</span>';
                }
            },
            {
                // Message. max-width BẮT BUỘC: nội dung SMS là chuỗi dài không có chỗ ngắt,
                // table-layout:auto sẽ cho ô này chiếm hết chỗ và bóp các cột sau về 0px.
                targets: 4,
                render: function (data, type, full) {
                    const t = String(full['text'] ?? '');
                    // Chưa đọc thì in đậm như hộp thư — cho thấy trạng thái ngay ở chỗ mắt
                    // đang nhìn, không phải liếc sang cột State
                    const dam = full['status'] === 'pending' ? ' fw-medium text-heading' : '';
                    return '<div class="text-truncate' + dam + '" style="max-width:420px" title="' +
                        esc(t) + '">' + esc(t) + '</div>';
                }
            },
            {
                targets: 5,
                searchable: false,
                render: function (data, type, full) {
                    // Giá trị lạ (dữ liệu bẩn) thì stateObj[...] là undefined -> phải có
                    // nhánh dự phòng, không thì render ném lỗi và bảng chết giữa chừng.
                    const st = stateObj[full['status']]
                        || { title: full['status'] || '—', class: 'bg-label-secondary' };
                    return '<span class="badge ' + st.class + '">' + esc(st.title) + '</span>';
                }
            },
            {
                targets: 6,
                searchable: false,
                render: function (data, type, full) {
                    return '<span class="text-nowrap">' + esc(fmtSmsDate(full['date'])) + '</span>';
                }
            },
            {
                // -1 = cột CUỐI. Chỉ số tuyệt đối sẽ trỏ nhầm ngay khi thêm/bớt một cột.
                targets: -1,
                title: 'Actions',
                searchable: false,
                orderable: false,
                render: function (data, type, full) {
                    const id = Number(full['id']);
                    // Xem trọn tin: ai xem được trang là xem được, không gắn quyền riêng
                    const viewBtn = `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon view-sms" data-id="${id}" title="View message"><i class="icon-base ti tabler-eye icon-22px"></i></button>`;
                    const doc = full['status'] === 'viewed';
                    // Phong thư vẽ theo TRẠNG THÁI, không phải theo hành động: đã đọc = thư
                    // MỞ (và mờ đi), chưa đọc = thư ĐÓNG. Bản đầu vẽ theo hành động nên đọc
                    // xong phong thư lại đóng vào — nhìn y như thao tác không ăn.
                    const markBtn = full['can_edit']
                        ? `<button type="button" class="btn ${doc ? 'btn-text-secondary' : 'btn-text-primary'} rounded-pill waves-effect btn-icon mark-sms" data-id="${id}" data-status="${doc ? 'pending' : 'viewed'}" title="${doc ? 'Mark as unread' : 'Mark as read'}"><i class="icon-base ti ${doc ? 'tabler-mail-opened' : 'tabler-mail'} icon-22px"></i></button>`
                        : lockedBtn(doc ? 'tabler-mail-opened' : 'tabler-mail',
                                    'You do not have permission to change messages.');
                    const delBtn = full['can_delete']
                        ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-sms" data-id="${id}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                        : lockedBtn('tabler-trash', 'You do not have permission to delete messages.');
                    return `<div class="d-inline-block text-nowrap">${viewBtn}${markBtn}${delBtn}</div>`;
                }
            }
        ],
        select: {
            style: 'multi',
            selector: 'td:nth-child(2)'
        },
        order: [[6, 'desc']],
        // Trạng thái xem (lọc/sort/trang/số dòng/tìm kiếm) phải nằm trên URL như mọi bảng khác
        ...urlState.tableOptions(SMS_COLS),
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
                            placeholder: 'Search Message',
                            text: '_INPUT_'
                        }
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
            searchPlaceholder: 'Search Message',
            paginate: {
                next: '<i class="icon-base ti tabler-chevron-right scaleX-n1-rtl icon-18px"></i>',
                previous: '<i class="icon-base ti tabler-chevron-left scaleX-n1-rtl icon-18px"></i>',
                first: '<i class="icon-base ti tabler-chevrons-left scaleX-n1-rtl icon-18px"></i>',
                last: '<i class="icon-base ti tabler-chevrons-right scaleX-n1-rtl icon-18px"></i>'
            }
        },
        responsive: {
            details: {
                display: DataTable.Responsive.display.modal({
                    header: function (row) {
                        return 'Message from ' + row.data()['from'];
                    }
                }),
                type: 'column'
                // Không đặt renderer riêng: dưới DataTables 2.1.8 + Responsive 3.0.4 bản tự
                // viết của template trả về rỗng nên bấm '+' không mở ra gì.
            }
        }
    });

    setTimeout(() => {
        const elementsToModify = [
            { selector: '.dt-buttons', classToRemove: 'btn-group' },
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
        elementsToModify.forEach(({ selector, classToRemove, classToAdd }) => {
            document.querySelectorAll(selector).forEach(element => {
                if (classToRemove) {
                    classToRemove.split(' ').forEach(c => element.classList.remove(c));
                }
                if (classToAdd) {
                    classToAdd.split(' ').forEach(c => element.classList.add(c));
                }
            });
        });
        // Ô chọn số dòng/trang bọc select2 cho khớp các trang khác
        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
        // Mọi <select> = select2 (quy ước dự án). `#SmsPhone` đã bọc trong dungOLocSo()
        // vì nó cần cấu hình ajax dựng trước lần vẽ bảng đầu tiên.
        const $st = $('#SmsStatus');
        if ($st.length && !$st.hasClass('select2-hidden-accessible')) {
            $st.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
        urlState.bind(dtSms, SMS_COLS);
        dtSms.on('select deselect draw', smsBulkRefresh);
    }, 100);

    $('#SmsPhone, #SmsStatus').on('change', function () {
        dtSms.ajax.reload();
    });

    tuDongNap();
}

/* ---------------- Tự động nạp tin mới ---------------- */

// Tin nhắn về từ webhook Telnyx bất cứ lúc nào; ngồi ở bảng này mà phải F5 mới thấy thì
// coi như mất tác dụng theo dõi. Cứ 30 giây nạp lại một lượt.
const SMS_NHIP_NAP = 30000;

function tuDongNap() {
    // Quay lại tab thì nạp NGAY, đừng bắt chờ hết nhịp 30 giây — lúc vừa nhìn vào bảng là
    // lúc dữ liệu cũ nhất (nhịp đã bị bỏ qua suốt thời gian tab ẩn).
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && dtSms && !document.querySelector('.modal.show')
            && dtSms.rows({ selected: true }).count() === 0) {
            dtSms.ajax.reload(null, false);
        }
    });

    setInterval(function () {
        if (!dtSms) {
            return;
        }
        // Tab đang ẩn: không nạp. Server chỉ có ~955MB RAM, đừng bắt nó phục vụ những
        // cái tab không ai nhìn.
        if (document.hidden) {
            return;
        }
        // Đang mở modal (đọc tin / xác nhận xóa): nạp lại sẽ vẽ lại bảng dưới chân người
        // dùng, và danh sách ID chờ xóa trỏ vào dòng vừa biến mất.
        if (document.querySelector('.modal.show')) {
            return;
        }
        // Đang tick chọn dòng: reload xóa sạch lựa chọn, người dùng tick giữa chừng sẽ mất.
        if (dtSms.rows({ selected: true }).count() > 0) {
            return;
        }
        // Đang gõ tìm kiếm cũng để yên
        if (document.activeElement === document.querySelector('.dt-search input')) {
            return;
        }
        dtSms.ajax.reload(null, false);   // false = giữ nguyên trang đang xem
    }, SMS_NHIP_NAP);
}

/* ---------------- Xem trọn một tin ---------------- */

$(document).on('click', '.view-sms', function () {
    const id = Number($(this).data('id'));
    const row = dtSms.rows().data().toArray().find(r => Number(r.id) === id);
    if (!row) {
        return;
    }
    // .text() chứ không .html(): nội dung tin nhắn do người lạ soạn
    $('#smsViewTo').text(row.number || '');
    $('#smsViewFrom').text(row.from || '');
    $('#smsViewDate').text(fmtSmsDate(row.date));
    $('#smsViewText').text(row.text || '');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('smsViewModal')).show();
    // Mở ra xem thì coi như đã đọc — chỉ khi có quyền sửa, và chỉ với tin chưa đọc
    if (row.can_edit && row.status === 'pending') {
        smsPost('update-sms-status', [id], { status: 'viewed' }, res => {
            if (res?.status === 'success') {
                dtSms.ajax.reload(null, false);
            }
        });
    }
});

/* ---------------- Đổi trạng thái / xóa ---------------- */

let smsDeleteIds = null;

function smsSelected() {
    if (!dtSms) {
        return [];
    }
    return dtSms.rows({ selected: true }).data().toArray().map(r => Number(r.id));
}

function smsPost(action, ids, data, onDone) {
    return $.ajax({
        url: '../../ajax.php?action=' + action,
        type: 'POST',
        dataType: 'json',
        data: Object.assign({ csrf_token: window.csrfToken, ids: ids }, data || {})
    }).done(onDone).fail(() => onDone({ status: 'error', message: 'Server connection error.' }));
}

function smsBulkRefresh() {
    const n = smsSelected().length;
    $('#smsBulkCount').text(n);
    $('#smsBulkBar').toggleClass('d-none', n === 0);
}

// Đổi trạng thái: không hỏi lại vì đảo ngược được bằng đúng nút bên cạnh
$(document).on('click', '#smsBulkBar button[data-status]', function () {
    const $b = $(this).prop('disabled', true);
    smsPost('update-sms-status', smsSelected(), { status: $(this).data('status') }, res => {
        $b.prop('disabled', false);
        if (res?.status === 'success') {
            dtSms.ajax.reload(null, false);
        } else {
            window.alert(res?.message || 'Failed.');
        }
    });
});

$(document).on('click', '.mark-sms', function () {
    const $b = $(this).prop('disabled', true);
    smsPost('update-sms-status', [Number($(this).data('id'))],
        { status: $(this).data('status') }, res => {
            if (res?.status === 'success') {
                dtSms.ajax.reload(null, false);
            } else {
                $b.prop('disabled', false);
                window.alert(res?.message || 'Failed.');
            }
        });
});

function smsOpenDelete(ids, tieuDe) {
    smsDeleteIds = ids;
    $('#smsDeleteTitle').text(tieuDe);
    $('#smsDeleteCount').text(ids.length);
    $('#smsDeleteResult').addClass('d-none').empty();
    $('#smsDeleteConfirm').prop('disabled', false);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('smsDeleteModal')).show();
}

$(document).on('click', '.delete-sms', function () {
    smsOpenDelete([Number($(this).data('id'))], 'Delete message');
});

$(document).on('click', '#smsBulkDelete', function () {
    smsOpenDelete(smsSelected(), 'Delete messages');
});

$(document).on('click', '#smsDeleteConfirm', function () {
    const $b = $(this).prop('disabled', true);
    smsPost('delete-sms', smsDeleteIds || smsSelected(), {}, res => {
        if (res?.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('smsDeleteModal')).hide();
            smsDeleteIds = null;
            dtSms.ajax.reload(null, false);
            smsBulkRefresh();
        } else {
            $b.prop('disabled', false);
            $('#smsDeleteResult').removeClass('d-none')
                .html(`<div class="alert alert-danger mb-0">${esc(res?.message || 'Failed.')}</div>`);
        }
    });
});

document.addEventListener('DOMContentLoaded', function () {
    init();
});
