/**
 * Page Phones — Numbers
 */

'use strict';

// Escape MỌI field free-text trước khi nhét vào HTML. Ở trang này nguy hiểm hơn các trang
// khác: `latest_sms_text` là nội dung TIN NHẮN ĐẾN, tức là do bất kỳ ai nhắn tới số đó soạn
// ra — nhét thẳng vào HTML là stored XSS mà kẻ tấn công không cần tài khoản nào.
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Nút LẺ ở cột Actions: thiếu quyền/chưa có chức năng thì KHÓA kèm lý do, không ẩn —
// ẩn làm các nút còn lại xô lệch giữa các dòng.
function lockedBtn(icon, why) {
    return `<button type="button" class="btn btn-text-secondary rounded-pill btn-icon" disabled` +
        ` title="${esc(why)}"><i class="icon-base ti ${icon} icon-22px"></i></button>`;
}

// Cột theo THỨ TỰ trong bảng, dùng để dịch giữa chỉ số cột và tên khóa trên URL.
// Cột nào không sort được thì để null.
const PHONE_COLS = [null, null, 'number', 'status', null, null, null, null];

let urlState = null;
let dtPhones = null;

async function init() {
    initTable();
}

// Datatable (js)
function initTable(){
    const statusObj = {
        active: { title: 'active', class: 'bg-label-success' },
        suspend: { title: 'suspend', class: 'bg-label-danger' }
    }

    // Đọc tham số URL TRƯỚC khi dựng bảng rồi nhét vào config, để bảng không phải vẽ hai lần
    urlState = dtUrlState({}, 10);

    // Variable declaration for table
    const dt_user_table = document.querySelector('.datatables-phones');
    // Users datatable
    if (dt_user_table) {
        const dt_user = dtPhones = new DataTable(dt_user_table, {
            serverSide: true,
            processing: true,
            ajax: {
                url: '../../ajax.php?action=get-phones-table',
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
                        // max-width BẮT BUỘC: nội dung SMS là chuỗi dài không có chỗ ngắt,
                        // mà table-layout:auto sẽ cho ô này chiếm hết chỗ và bóp 4 cột cuối
                        // (Carriers/Notices/Accounts/Actions) về 0px. `text-truncate` một
                        // mình không cứu được — nó cần một mốc bề rộng mới cắt được.
                        return '<div class="d-flex flex-column" style="max-width:260px">' +
                            '<a href="index.php?menu=phones_sms&id=' + Number(full['id']) +
                            '" class="text-heading text-truncate">' +
                            '<span class="fw-medium">' + esc(full['number']) + '</span>' +
                            '</a>' +
                            '<small class="text-truncate" title="' + esc(full['latest_sms_text']) +
                            '">' + esc(full['latest_sms_text']) + '</small>' +
                            '</div>';
                    }
                },
                {
                    // status
                    targets: 3,
                    orderable: true,
                    searchable: false,
                    render: function (data, type, full, meta) {
                        // Giá trị lạ (schema đổi, dữ liệu bẩn) thì statusObj[status] là
                        // undefined -> bản cũ ném lỗi và bảng chết giữa chừng.
                        const st = statusObj[full['status']]
                            || { title: full['status'] || '—', class: 'bg-label-secondary' };
                        return '<span class="badge ' + st.class + '">' + esc(st.title) + '</span>';
                    }
                },
                {
                    // Carrier
                    targets: 4,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, full, meta) {
                        return '<span>' + esc(full['carrier']) + '</span>';
                    }
                },
                {
                    // notice
                    targets: 5,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, full, meta) {
                        const n = Number(full['notice']?.['sms_count'] || 0);
                        // Bấm vào để mở tin nhắn của số này. Số 0 thì icon mờ và không kèm
                        // huy hiệu — trước đây luôn hiện "0" đỏ chóe trông như có việc gấp.
                        return '<button type="button" class="btn btn-text-secondary btn-icon' +
                            ' rounded-pill position-relative phone-notices" data-id="' +
                            Number(full['id']) + '" title="' +
                            (n ? n + ' pending message(s)' : 'View messages') + '">' +
                            '<i class="icon-base ti tabler-mail icon-22px' +
                            (n ? '' : ' text-body-secondary') + '"></i>' +
                            (n ? '<span class="position-absolute top-0 start-100 translate-middle' +
                                ' badge rounded-pill bg-danger" style="font-size:0.6rem">' + n +
                                '</span>' : '') +
                            '</button>';
                    }
                },
                {
                    // Account đang dùng số này. Quan hệ nhiều-nhiều nên là một danh sách;
                    // hiện tối đa 3 cái rồi gộp phần dư thành "+N" để không phá cột.
                    targets: 6,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, full, meta) {
                        const list = Array.isArray(full['accounts']) ? full['accounts'] : [];
                        if (!list.length) {
                            return '<span class="text-body-secondary">—</span>';
                        }
                        const hien = list.slice(0, 3).map(a =>
                            `<a href="index.php?menu=stores&id=${Number(a.id)}"` +
                            ` class="badge bg-label-primary text-decoration-none">${esc(a.label)}</a>`
                        ).join(' ');
                        const du = list.length - 3;
                        return '<div class="d-flex flex-wrap gap-1">' + hien +
                            (du > 0 ? `<span class="badge bg-label-secondary" title="${esc(
                                list.slice(3).map(a => a.label).join(', '))}">+${du}</span>` : '') +
                            '</div>';
                    }
                },
                {
                    // -1 = cột CUỐI. Dùng chỉ số tuyệt đối (7) sẽ trỏ nhầm ngay khi thêm
                    // hoặc bớt một cột — mọi trang chuẩn đều dùng -1.
                    targets: -1,
                    title: 'Actions',
                    searchable: false,
                    orderable: false,
                    render: (data, type, full, meta) => {
                        // Trước đây hai nút này luôn hiện nhưng KHÔNG nối vào đâu cả: Edit
                        // không có handler, Delete không có endpoint. Nút bấm được mà không
                        // xảy ra gì là tệ hơn nút khóa. Dựng từ cờ theo dòng như mọi trang.
                        const editBtn = full['can_edit']
                            ? `<button type="button" class="btn btn-text-secondary rounded-pill waves-effect btn-icon edit-phone" data-id="${Number(full['id'])}" title="Edit"><i class="icon-base ti tabler-edit icon-22px"></i></button>`
                            : lockedBtn('tabler-edit', 'Phone numbers come from Telnyx and cannot be edited here.');
                        const delBtn = full['can_delete']
                            ? `<button type="button" class="btn btn-text-danger rounded-pill waves-effect btn-icon delete-phone" data-id="${Number(full['id'])}" title="Delete"><i class="icon-base ti tabler-trash icon-22px"></i></button>`
                            : lockedBtn('tabler-trash', 'Releasing a number must be done in Telnyx — deleting it here would keep billing it.');
                        // KHÔNG có nút "View messages": chính số điện thoại ở cột Numbers đã
                        // là link sang trang SMS rồi, thêm nút nữa chỉ tốn chỗ cột Actions.
                        return `<div class="d-inline-block text-nowrap">${editBtn}${delBtn}</div>`;
                    }
                }
            ],
            select: {
                style: 'multi',
                selector: 'td:nth-child(2)'
            },
            order: [[2, 'desc']],
            // Trạng thái xem (sort/trang/số dòng/tìm kiếm) phải nằm trên URL như mọi bảng khác
            ...urlState.tableOptions(PHONE_COLS),
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
                    // Bỏ renderer tự viết của template: dưới DataTables 2.1.8 + Responsive 3.0.4 nó
                    // trả về rỗng, nên bấm '+' chỉ đánh dấu dòng mà không mở ra gì. Bản mặc
                    // định của Responsive chạy đúng và cũng đã tự bỏ qua cột không có tiêu đề.
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
        // Ô chọn số dòng/trang bọc select2 cho khớp các trang khác
        const $len = $('.dt-length select');
        if ($len.length && !$len.hasClass('select2-hidden-accessible')) {
            $len.closest('.dt-length').css('min-width', '7rem');
            $len.select2({ minimumResultsForSearch: Infinity, width: '100%' });
        }
        // Ô Status trong form Edit cũng phải là select2 cho khớp quy ước "mọi select =
        // select2"; trước đó chỉ ô Team được bọc nên hai ô cạnh nhau trông khác hẳn nhau.
        const $st = $('#phone-status');
        if ($st.length && !$st.hasClass('select2-hidden-accessible')) {
            $st.select2({ dropdownParent: $('#offcanvasPhone'),
                          minimumResultsForSearch: Infinity, width: '100%' });
        }
        urlState.bind(dtPhones, PHONE_COLS);
        dtPhones.on('select deselect draw', phonesBulkRefresh);
    }, 100);
}
/* ---------------- Tin nhắn của một số (cột Notices) ---------------- */

// Theo dõi CUỘN: tin nào lọt vào tầm nhìn trong modal thì mới coi là đã đọc. Mở modal ra
// không có nghĩa là đọc hết — danh sách dài hơn màn hình là chuyện thường.
let smsQuanSat = null;   // IntersectionObserver hiện hành
let smsChoGui = new Set();
let smsHenGui = null;

function smsHuyTheoDoi() {
    if (smsQuanSat) {
        smsQuanSat.disconnect();
        smsQuanSat = null;
    }
    clearTimeout(smsHenGui);
    smsChoGui.clear();
}

function smsGuiDanhDau(phoneId) {
    if (!smsChoGui.size) {
        return;
    }
    const ids = [...smsChoGui];
    smsChoGui.clear();
    $.post('../../ajax.php?action=mark-sms-read',
        { csrf_token: window.csrfToken, phone_id: phoneId, ids: ids }, null, 'json')
        .done(res => {
            if (res?.status !== 'success') {
                return;
            }
            // Chỉnh thẳng huy hiệu trên bảng thay vì nạp lại — nạp lại sẽ cuốn mất vị trí
            // cuộn và nhấp nháy trong lúc người dùng vẫn đang đọc.
            const $btn = $(`.phone-notices[data-id="${Number(phoneId)}"]`);
            const con = Number(res.unread || 0);
            $btn.find('.badge').remove();
            $btn.attr('title', con ? con + ' pending message(s)' : 'View messages');
            $btn.find('i').toggleClass('text-body-secondary', con === 0);
            if (con > 0) {
                $btn.append('<span class="position-absolute top-0 start-100 translate-middle'
                    + ' badge rounded-pill bg-danger" style="font-size:0.6rem">' + con + '</span>');
            }
        });
}

function smsTheoDoiCuon(phoneId) {
    smsHuyTheoDoi();
    const root = document.querySelector('#phoneSmsModal .modal-body');
    const chuaDoc = document.querySelectorAll('#phoneSmsRows tr.sms-unread');
    if (!root || !chuaDoc.length || !('IntersectionObserver' in window)) {
        return;
    }
    smsQuanSat = new IntersectionObserver(entries => {
        entries.forEach(e => {
            // Phải thấy quá nửa dòng mới tính là đã đọc, tránh dòng vừa ló ra đã bị đánh dấu
            if (e.isIntersecting && e.intersectionRatio >= 0.5) {
                smsChoGui.add(Number(e.target.dataset.smsId));
                e.target.classList.remove('sms-unread');
                smsQuanSat.unobserve(e.target);
            }
        });
        // Gom lại rồi gửi một lượt, không mỗi dòng một request
        clearTimeout(smsHenGui);
        smsHenGui = setTimeout(() => smsGuiDanhDau(phoneId), 500);
    }, { root: root, threshold: [0.5] });
    chuaDoc.forEach(tr => smsQuanSat.observe(tr));
}

// Đóng modal: gửi nốt phần còn treo rồi thôi theo dõi
$(document).on('hidden.bs.modal', '#phoneSmsModal', function () {
    const id = Number($(this).data('phone-id') || 0);
    clearTimeout(smsHenGui);
    smsGuiDanhDau(id);
    smsHuyTheoDoi();
});

function fmtSmsDate(s) {
    if (!s) {
        return '—';
    }
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? esc(s) : d.toLocaleString('en-GB',
        { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

$(document).on('click', '.phone-notices', function () {
    const id = Number($(this).data('id'));
    $('#phoneSmsNumber').text('');
    $('#phoneSmsRows').empty();
    $('#phoneSmsWrap').addClass('d-none');
    $('#phoneSmsLoading').removeClass('d-none');
    $('#phoneSmsOpenPage').addClass('d-none').attr('href', 'index.php?menu=phones_sms&id=' + id);
    $('#phoneSmsModal').data('phone-id', id);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('phoneSmsModal')).show();

    $.post('../../ajax.php?action=get-phone-messages',
        { csrf_token: window.csrfToken, id: id }, null, 'json')
        .done(res => {
            $('#phoneSmsLoading').addClass('d-none');
            if (res?.status !== 'success') {
                $('#phoneSmsRows').html(`<tr><td colspan="3" class="text-danger">${
                    esc(res?.message || 'Failed to load messages.')}</td></tr>`);
                $('#phoneSmsWrap').removeClass('d-none');
                return;
            }
            $('#phoneSmsNumber').text(res.number || '');
            $('#phoneSmsOpenPage').removeClass('d-none');
            const list = res.messages || [];
            $('#phoneSmsRows').html(list.length
                ? list.map(m => `<tr data-sms-id="${Number(m.id)}"${
                    m.status === 'pending' ? ' class="table-warning sms-unread"' : ''}>
                    <td class="text-nowrap">${esc(m.from)}</td>
                    <td>${esc(m.text)}</td>
                    <td class="text-nowrap">${esc(fmtSmsDate(m.date))}</td></tr>`).join('')
                : '<tr><td colspan="3" class="text-center text-body-secondary">No messages yet.</td></tr>');
            $('#phoneSmsWrap').removeClass('d-none');
            smsTheoDoiCuon(id);
        })
        .fail(() => {
            $('#phoneSmsLoading').addClass('d-none');
            $('#phoneSmsRows').html('<tr><td colspan="3" class="text-danger">Server connection error.</td></tr>');
            $('#phoneSmsWrap').removeClass('d-none');
        });
});

/* ---------------- Sửa MỘT số ---------------- */

// Danh sách team cho ô chọn (chỉ admin mới có ô này). Nạp một lần rồi dùng lại.
let phoneTeams = null;

async function loadPhoneTeams() {
    if (phoneTeams || !$('#phone-team').length) {
        return;
    }
    const res = await $.post('../../ajax.php?action=get-teams-table',
        { csrf_token: window.csrfToken, start: 0, length: 500, draw: 1 }, null, 'json')
        .catch(() => null);
    phoneTeams = (res && Array.isArray(res.data)) ? res.data : [];
    const $sel = $('#phone-team').empty();
    phoneTeams.forEach(t => $sel.append(new Option(t.name, t.id, false, false)));
    if (!$sel.hasClass('select2-hidden-accessible')) {
        $sel.select2({ dropdownParent: $('#offcanvasPhone'), width: '100%' });
    }
}

$(document).on('click', '.edit-phone', async function () {
    const id = Number($(this).data('id'));
    const row = dtPhones.rows().data().toArray().find(r => Number(r.id) === id);
    if (!row) {
        return;
    }
    await loadPhoneTeams();
    $('#phone-id').val(id);
    $('#phone-number').val(row.number);
    $('#phone-carrier').val(row.carrier || '');
    $('#phone-status').val(row.status).trigger('change.select2');
    if ($('#phone-team').length) {
        $('#phone-team').val(String(row.team_id || '')).trigger('change.select2');
    }
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasPhone')).show();
});

$(document).on('click', '#phoneSubmit', function () {
    const $btn = $(this).prop('disabled', true);
    const data = { csrf_token: window.csrfToken, id: $('#phone-id').val(),
        status: $('#phone-status').val() };
    if ($('#phone-team').length) {
        data.team_id = $('#phone-team').val();
    }
    $.post('../../ajax.php?action=save-phone', data, null, 'json')
        .done(res => {
            if (res?.status === 'success') {
                bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasPhone')).hide();
                dtPhones.ajax.reload(null, false);
            } else {
                window.alert(res?.message || 'Failed to save.');
            }
        })
        .fail(() => window.alert('Server connection error.'))
        .always(() => $btn.prop('disabled', false));
});

/* ---------------- Thao tác hàng loạt ---------------- */

// ID các dòng đang được tick. Cột checkbox dùng DataTables Select nên đọc từ API của nó.
// ID sẽ bị xóa: bình thường là các dòng đang tick, nhưng khi bấm Delete ở MỘT dòng thì
// chỉ đúng dòng đó — dùng chung một modal xác nhận cho cả hai đường.
let phonesDeleteIds = null;

function phonesSelected() {
    if (!dtPhones) {
        return [];
    }
    return dtPhones.rows({ selected: true }).data().toArray().map(r => Number(r.id));
}

function phonesOpenDelete(ids, tieuDe) {
    phonesDeleteIds = ids;
    $('#phonesDeleteTitle').text(tieuDe);
    $('#phonesDeleteCount').text(ids.length);
    $('#phonesDeleteResult').addClass('d-none').empty();
    $('#phonesDeleteConfirm').prop('disabled', false);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('phonesDeleteModal')).show();
}

$(document).on('click', '.delete-phone', function () {
    phonesOpenDelete([Number($(this).data('id'))], 'Delete phone number');
});

function phonesBulkRefresh() {
    const n = phonesSelected().length;
    $('#phonesBulkCount').text(n);
    $('#phonesBulkBar').toggleClass('d-none', n === 0);
}

function phonesBulkPost(action, data, onDone) {
    return $.ajax({
        url: '../../ajax.php?action=' + action,
        type: 'POST',
        dataType: 'json',
        data: Object.assign({ csrf_token: window.csrfToken,
            ids: phonesDeleteIds || phonesSelected() }, data || {})
    }).done(onDone).fail(() => onDone({ status: 'error', message: 'Server connection error.' }));
}

// Đổi trạng thái: không hỏi lại vì đảo ngược được bằng đúng nút bên cạnh
$(document).on('click', '#phonesBulkBar button[data-status]', function () {
    const $b = $(this).prop('disabled', true);
    phonesDeleteIds = null;   // thao tác này luôn theo các dòng đang tick
    phonesBulkPost('update-phones-status', { status: $(this).data('status') }, res => {
        $b.prop('disabled', false);
        if (res?.status === 'success') {
            dtPhones.ajax.reload(null, false);
        } else {
            window.alert(res?.message || 'Failed.');
        }
    });
});

// Xóa: hỏi lại trong modal của app, KHÔNG dùng window.confirm (trình duyệt chặn được nó)
$(document).on('click', '#phonesBulkDelete', function () {
    phonesOpenDelete(phonesSelected(), 'Delete phone numbers');
});

$(document).on('click', '#phonesDeleteConfirm', function () {
    const $b = $(this).prop('disabled', true);
    phonesBulkPost('delete-phones', {}, res => {
        if (res?.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('phonesDeleteModal')).hide();
            phonesDeleteIds = null;
            dtPhones.ajax.reload(null, false);
            phonesBulkRefresh();
        } else {
            $b.prop('disabled', false);
            $('#phonesDeleteResult').removeClass('d-none')
                .html(`<div class="alert alert-danger mb-0">${esc(res?.message || 'Failed.')}</div>`);
        }
    });
});

document.addEventListener('DOMContentLoaded', function (e) {
    init();
});
