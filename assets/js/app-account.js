/**
 * Page Account — hồ sơ của một người (`?menu=account`, `?menu=account&id=N`).
 *
 * Xem hồ sơ NGƯỜI KHÁC thì fragment không render form ghi nào, nên file này chỉ nạp dữ liệu
 * rồi dừng. Mọi endpoint ghi ở đây đều KHÔNG nhận `id`: đích duy nhất là người đang đăng nhập.
 */

'use strict';

// Escape mọi field free-text trước khi nhét vào HTML — username là dữ liệu người dùng nhập
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// avatar lưu dạng đường dẫn tương đối gốc app (uploads/avatars/...) — trang nằm sâu 2 cấp
function avatarUrl(path) {
    return '../../' + String(path ?? '').replace(/^\/+/, '');
}

const ACC_STATUS = { 1: 'Pending', 2: 'Active', 3: 'Inactive' };
const AVATAR_STATES = ['success', 'danger', 'warning', 'info', 'dark', 'primary', 'secondary'];

let accUser = null;   // bản ghi vừa nạp, dùng cho nút Reset

function accId() {
    const n = parseInt(new URLSearchParams(location.search).get('id') || '0', 10);
    return Number.isFinite(n) && n > 0 ? n : 0;
}

function post(action, data) {
    return $.ajax({
        url: '../../ajax.php?action=' + action,
        type: 'POST',
        dataType: 'json',
        data: Object.assign({ csrf_token: window.csrfToken }, data || {})
    });
}

function setAvatar(path, name) {
    const $img = $('#acc-avatar-preview');
    const $ini = $('#acc-avatar-initial');
    $('#acc-avatar').val(path || '');
    if (path) {
        $img.attr('src', avatarUrl(path)).removeClass('d-none');
        $ini.addClass('d-none');
    } else {
        $img.addClass('d-none').attr('src', '');
        const ch = String(name || '?').trim().charAt(0).toUpperCase() || '?';
        const tone = AVATAR_STATES[ch.charCodeAt(0) % AVATAR_STATES.length];
        $ini.removeClass('d-none')
            .attr('class', 'avatar-initial rounded bg-label-' + tone)
            .text(ch);
    }
    $('#acc-avatar-reset').toggleClass('d-none', !path);
}

function fillForm(u) {
    accUser = u;
    setAvatar(u.avatar, u.username);
    $('#acc-username').val(u.username).removeClass('is-invalid');
    $('#acc-email').val(u.email).removeClass('is-invalid');
    $('#acc-role').val(u.role_name || '—');
    $('#acc-team').val(u.team_name || '—');
    $('#acc-status').val(ACC_STATUS[u.status] || '—');
    $('#acc-date').val(u.date ? new Date(String(u.date).replace(' ', 'T')).toLocaleDateString('en-GB',
        { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
    // Lương chỉ có trong response khi được phép xem -> ô cũng chỉ render khi đó
    if ('wage' in u) {
        $('#acc-wage').val(u.wage);
        $('#acc-insurance').val(u.insurance);
    }
    if ('api_key' in u) {
        $('#acc-api-key').val(u.api_key);
        danhGiaKey(u.api_key);
    }
}

function toast(type, msg) {
    // Dùng lại lối báo của các trang khác: alert nổi ở đầu vùng nội dung, tự tắt
    $('.acc-flash').remove();
    const $a = $('<div class="alert alert-dismissible acc-flash" role="alert">')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .html(esc(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>');
    $('.tab-content').before($a);
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => $a.alert('close'), 6000);
}

async function loadProfile() {
    try {
        const res = await post('get-account', { id: accId() });
        if (res?.status !== 'success') {
            toast('error', res?.message || 'Failed to load this account.');
            return;
        }
        fillForm(res.user);
        if (res.user.is_me) {
            loadDevices();
        }
    } catch (e) {
        toast('error', 'Server connection error.');
    }
}

/* ---------------- Ảnh đại diện ---------------- */
// Dùng LẠI endpoint upload-user-avatar của menu Users (đã kiểm đuôi + MIME thật +
// getimagesize + chặn pixel-flood + vẽ lại 96x96). Không viết đường upload thứ hai.
$(document).on('change', '#acc-avatar-file', function () {
    const file = this.files && this.files[0];
    if (!file) {
        return;
    }
    const $hint = $('#acc-avatar-hint').removeClass('text-danger').text('Uploading…');
    const fd = new FormData();
    fd.append('file', file);
    fd.append('id', String(accUser ? accUser.id : 0));
    fd.append('csrf_token', window.csrfToken);
    fetch('../../ajax.php?action=upload-user-avatar', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res?.status === 'success') {
                setAvatar(res.avatar, $('#acc-username').val());
                $hint.text('Uploaded. Save changes to apply.');
            } else {
                $hint.addClass('text-danger').text(res?.message || 'Upload failed.');
            }
        })
        .catch(() => $hint.addClass('text-danger').text('Server connection error.'))
        .finally(() => { this.value = ''; });   // cho chọn lại đúng file vừa rồi
});

$(document).on('click', '#acc-avatar-reset', function () {
    setAvatar('', $('#acc-username').val());
    $('#acc-avatar-hint').removeClass('text-danger').text('Avatar removed. Save changes to apply.');
});

/* ---------------- Hồ sơ ---------------- */
$(document).on('click', '#accProfileReset', function () {
    if (accUser) {
        fillForm(accUser);
        $('#acc-avatar-hint').removeClass('text-danger').text('PNG or JPG, max 2 MB. Resized to 96×96.');
    }
});

$(document).on('click', '#accProfileSave', async function () {
    const $btn = $(this);
    const username = String($('#acc-username').val() || '').trim();
    const email = String($('#acc-email').val() || '').trim();
    let ok = true;
    $('#acc-username').toggleClass('is-invalid', !(ok = username !== '' && ok));
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    $('#acc-email').toggleClass('is-invalid', !emailOk);
    if (!username || !emailOk) {
        return;
    }
    $btn.prop('disabled', true);
    try {
        const res = await post('save-account', {
            username: username, email: email, avatar: $('#acc-avatar').val() || ''
        });
        toast(res?.status === 'success' ? 'success' : 'error',
            res?.message || 'Failed to save.');
        if (res?.status === 'success') {
            accUser = Object.assign({}, accUser,
                { username: username, email: email, avatar: $('#acc-avatar').val() || '' });
            // Navbar hiển thị tên lấy lúc render trang -> cập nhật cho khớp ngay
            $('.dropdown-user .fw-medium').first().text(username);
        }
    } catch (e) {
        toast('error', 'Server connection error.');
    } finally {
        $btn.prop('disabled', false);
    }
});

/* ---------------- Mật khẩu ---------------- */
$(document).on('click', '#accPasswordSave', async function () {
    const $btn = $(this);
    const cur = String($('#acc-cur-pass').val() || '');
    const p1 = String($('#acc-new-pass').val() || '');
    const p2 = String($('#acc-new-pass2').val() || '');
    $('#acc-cur-pass').toggleClass('is-invalid', cur === '');
    $('#acc-new-pass').toggleClass('is-invalid', p1.length < 8);
    $('#acc-new-pass2').toggleClass('is-invalid', p1 !== p2);
    if (cur === '' || p1.length < 8 || p1 !== p2) {
        return;
    }
    $btn.prop('disabled', true);
    try {
        const res = await post('change-account-password', {
            current_password: cur, new_password: p1, confirm_password: p2
        });
        toast(res?.status === 'success' ? 'success' : 'error', res?.message || 'Failed.');
        if (res?.status === 'success') {
            $('#acc-cur-pass, #acc-new-pass, #acc-new-pass2').val('');
            loadDevices();   // đổi mật khẩu đã thu hồi hết thiết bị -> bảng phải trống
        }
    } catch (e) {
        toast('error', 'Server connection error.');
    } finally {
        $btn.prop('disabled', false);
    }
});

/* ---------------- Thiết bị đã ghi nhớ ---------------- */
function fmtDate(s) {
    if (!s) {
        return '—';
    }
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? esc(s) : d.toLocaleString('en-GB',
        { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

async function loadDevices() {
    const $tb = $('#accDeviceRows');
    if (!$tb.length) {
        return;
    }
    try {
        const res = await post('get-account-devices');
        const list = res?.devices || [];
        if (!list.length) {
            $tb.html('<tr><td colspan="4" class="text-center text-body-secondary">'
                + 'No remembered devices.</td></tr>');
            return;
        }
        $tb.html(list.map(d => `<tr>
            <td><i class="icon-base ti tabler-device-desktop me-2"></i>
                <span class="fw-medium">${esc(d.tag)}</span>
                ${d.is_current ? '<span class="badge bg-label-primary ms-2">This device</span>' : ''}</td>
            <td>${esc(fmtDate(d.created_at))}</td>
            <td>${esc(fmtDate(d.expires_at))}</td>
            <td class="text-end"><button type="button"
                class="btn btn-text-danger rounded-pill btn-icon acc-revoke"
                data-id="${Number(d.id)}" title="Sign out this device">
                <i class="icon-base ti tabler-logout icon-22px"></i></button></td>
        </tr>`).join(''));
    } catch (e) {
        $tb.html('<tr><td colspan="4" class="text-center text-danger">Server connection error.</td></tr>');
    }
}

$(document).on('click', '.acc-revoke', async function () {
    const id = parseInt($(this).data('id'), 10);
    const res = await post('revoke-account-device', { id: id });
    toast(res?.status === 'success' ? 'success' : 'error', res?.message || 'Failed.');
    loadDevices();
});

$(document).on('click', '#accRevokeAll', async function () {
    const res = await post('revoke-account-devices-all');
    toast(res?.status === 'success' ? 'success' : 'error', res?.message || 'Failed.');
    loadDevices();
});

/* ---------------- API key ---------------- */
$(document).on('click', '#acc-key-toggle', function () {
    const $i = $('#acc-api-key');
    const hien = $i.attr('type') === 'password';
    $i.attr('type', hien ? 'text' : 'password');
    $(this).find('i').attr('class', hien ? 'icon-base ti tabler-eye-off' : 'icon-base ti tabler-eye');
});

$(document).on('click', '#acc-key-new', async function () {
    // Đổi key làm extension đang chạy mất hiệu lực -> phải hỏi trước, đây là thao tác
    // người dùng không lấy lại được (key cũ băm đâu mà khôi phục).
    if (!window.confirm('Generate a new API key? Your extension will stop working until you '
        + 'update it with the new key.')) {
        return;
    }
    const $btn = $(this).prop('disabled', true);
    try {
        const res = await post('regenerate-account-key');
        if (res?.status === 'success') {
            $('#acc-api-key').val(res.key).attr('type', 'text');
            $('#acc-key-toggle i').attr('class', 'icon-base ti tabler-eye-off');
            danhGiaKey(res.key);
        }
        toast(res?.status === 'success' ? 'success' : 'error', res?.message || 'Failed.');
    } catch (e) {
        toast('error', 'Server connection error.');
    } finally {
        $btn.prop('disabled', false);
    }
});

// Dữ liệu thật đang có key dài 3–4 ký tự và cả key rỗng — nói thẳng cho chủ tài khoản biết,
// vì check_authors_key() chỉ so key + email nên key ngắn là đoán được trong vài giây.
function danhGiaKey(key) {
    const $h = $('#acc-key-hint');
    if (!$h.length) {
        return;
    }
    const n = String(key || '').length;
    if (n === 0) {
        $h.addClass('text-danger')
          .text('You have no API key yet — generate one before using the extension.');
    } else if (n < 32) {
        $h.addClass('text-danger')
          .text('This key is only ' + n + ' characters and can be guessed. Generate a new one.');
    } else {
        $h.removeClass('text-danger').text('');
    }
}

$(document).on('click', '#acc-key-copy', function () {
    const v = String($('#acc-api-key').val() || '');
    if (!v) {
        return;
    }
    // navigator.clipboard chỉ có trên HTTPS; site chạy HTTPS nên đủ dùng, vẫn bắt lỗi
    (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject())
        .then(() => $('#acc-key-hint').removeClass('text-danger').text('Copied to clipboard.'))
        .catch(() => $('#acc-key-hint').addClass('text-danger').text('Could not copy — select and copy manually.'));
});

$(function () {
    loadProfile();
});
