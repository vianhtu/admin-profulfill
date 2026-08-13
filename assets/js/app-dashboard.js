/**
 * Trang Dashboards.
 *
 * Fragment PHP chỉ dựng khung rỗng theo quyền; file này gọi `dashboard-stats`
 * một lần rồi điền số. Khối nào server không trả về nghĩa là người xem không có
 * quyền — im lặng bỏ qua, đừng dựng bù bằng JS.
 */
'use strict';

(function () {
    const $ = (id) => document.getElementById(id);
    const num = (n) => Number(n || 0).toLocaleString('vi-VN');
    const money = (n) => Number(n || 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 }) + ' $';

    /** Chuỗi từ server không bao giờ được nối thẳng vào innerHTML. */
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    const STATUS_LABEL = {
        pending: 'Chờ xử lý', schedule: 'Đã lên lịch', listed: 'Đã đăng',
        inactive: 'Ngừng', unshipped: 'Chờ gửi', shipped: 'Đã gửi',
        delivered: 'Đã giao', cancel: 'Huỷ',
    };
    const label = (s) => STATUS_LABEL[String(s).toLowerCase()] || s;

    const PILL = { crit: 'bg-label-danger', warn: 'bg-label-warning', info: 'bg-label-info', ok: 'bg-label-success' };

    /** "3 ngày trước", "hôm nay 15:09" — dễ đọc hơn nhiều so với ngày trần. */
    function humanDate(value) {
        if (!value) return { text: 'chưa bao giờ', days: Infinity };
        const then = new Date(value.replace(' ', 'T'));
        if (isNaN(then)) return { text: value, days: Infinity };
        const days = Math.floor((Date.now() - then.getTime()) / 86400000);
        if (days <= 0) return { text: 'hôm nay ' + then.toTimeString().slice(0, 5), days: 0 };
        if (days === 1) return { text: 'hôm qua', days: 1 };
        if (days < 30) return { text: days + ' ngày trước', days };
        if (days < 365) return { text: Math.floor(days / 30) + ' tháng trước', days };
        return { text: then.toLocaleDateString('vi-VN'), days };
    }

    function delta(el, now, before, suffix) {
        if (!el) return;
        if (!before) {
            el.textContent = before === 0 && now > 0 ? `▲ ${num(now)} (${suffix}: 0)` : suffix;
            el.className = before === 0 && now > 0 ? 'text-success' : 'text-muted';
            return;
        }
        const pct = Math.round(((now - before) / before) * 100);
        el.textContent = `${pct >= 0 ? '▲' : '▼'} ${Math.abs(pct)}% ${suffix}`;
        el.className = pct >= 0 ? 'text-success' : 'text-danger';
    }

    function renderKpis(p) {
        if (!p) return;
        if ($('kpi-today')) $('kpi-today').textContent = num(p.today);
        if ($('kpi-d7')) $('kpi-d7').textContent = num(p.d7);
        if ($('kpi-d30')) $('kpi-d30').textContent = num(p.d30);
        if ($('kpi-total')) $('kpi-total').textContent = num(p.total);
        delta($('kpi-today-delta'), p.today, p.yesterday, 'so với hôm qua');
        delta($('kpi-d7-delta'), p.d7, p.d7_prev, 'so với 7 ngày trước');
    }

    function renderDaily(series) {
        const host = $('chart-daily');
        if (!host || typeof ApexCharts === 'undefined' || !series) return;

        new ApexCharts(host, {
            chart: {
                type: 'bar', height: 260, parentHeightOffset: 0, toolbar: { show: false },
                events: {
                    // Mỗi cột dẫn thẳng sang Products đã lọc sẵn ngày đó.
                    dataPointSelection: (e, ctx, cfg) => {
                        const day = series[cfg.dataPointIndex]?.date;
                        if (day) window.location.href = `?menu=products&ProductFrom=${day}&ProductTo=${day}`;
                    },
                },
            },
            series: [{ name: 'Sản phẩm', data: series.map((d) => d.value) }],
            plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
            dataLabels: { enabled: false },
            grid: { strokeDashArray: 4, padding: { top: -10 } },
            xaxis: {
                categories: series.map((d) => d.date.slice(8) + '/' + d.date.slice(5, 7)),
                axisTicks: { show: false }, axisBorder: { show: false },
            },
            yaxis: { labels: { formatter: (v) => num(Math.round(v)) } },
            tooltip: { y: { formatter: (v) => num(v) + ' sản phẩm' } },
            states: { hover: { filter: { type: 'darken' } } },
        }).render();
    }

    function renderPipeline(pipeline) {
        const host = $('chart-pipeline');
        if (!host || typeof ApexCharts === 'undefined' || !pipeline || !pipeline.length) return;

        new ApexCharts(host, {
            chart: { type: 'donut', height: 260 },
            series: pipeline.map((p) => p.count),
            labels: pipeline.map((p) => label(p.status)),
            legend: { position: 'bottom' },
            dataLabels: { enabled: false },
            tooltip: { y: { formatter: (v) => num(v) + ' sản phẩm' } },
            plotOptions: {
                pie: { donut: { labels: { show: true,
                    total: { show: true, label: 'Tổng', formatter: (w) => num(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } },
            },
        }).render();
    }

    function renderAlerts(alerts) {
        const host = $('dash-alerts');
        if (!host) return;
        if (!alerts || !alerts.length) {
            host.innerHTML = '<li class="text-success">Không có gì phải xử lý.</li>';
            return;
        }
        host.innerHTML = alerts.map((a) => {
            const badge = `<span class="badge ${PILL[a.level] || PILL.info} me-2">${num(a.count)}</span>`;
            const text = esc(a.text);
            return `<li class="mb-2 d-flex align-items-start">${badge}<span>${
                a.link ? `<a href="${esc(a.link)}" class="text-body">${text}</a>` : text
            }</span></li>`;
        }).join('');
    }

    function renderOrders(orders) {
        const host = $('dash-orders');
        if (!host) return;
        const rows = orders?.by_status || [];
        if (!rows.length) {
            host.innerHTML = '<tr><td colspan="3" class="text-muted">Chưa có đơn nào.</td></tr>';
            return;
        }
        host.innerHTML = rows.map((r) => `
            <tr>
              <td><span class="badge ${r.status === 'unshipped' ? PILL.warn : PILL.info}">${esc(label(r.status))}</span></td>
              <td class="text-end">${num(r.count)}</td>
              <td class="text-end">${money(r.value)}</td>
            </tr>`).join('');
    }

    function renderMembers(members) {
        const host = $('dash-members');
        if (!host) return;
        if (!members || !members.length) {
            host.innerHTML = '<tr><td colspan="8" class="text-muted">Không có thành viên nào.</td></tr>';
            return;
        }
        host.innerHTML = members.map((m) => {
            const last = humanDate(m.last_at);
            let state = `<span class="badge ${PILL.ok}">đang làm</span>`;
            if (last.days === Infinity) state = `<span class="badge ${PILL.info}">chưa nhập gì</span>`;
            else if (last.days > 30) state = `<span class="badge ${PILL.crit}">ngừng ${last.text}</span>`;
            else if (last.days > 7) state = `<span class="badge ${PILL.warn}">${last.text}</span>`;

            return `<tr>
              <td><a href="?menu=account&id=${m.id}" class="text-body fw-medium">${esc(m.username)}</a></td>
              <td class="text-muted">${esc(m.role_name)}</td>
              <td class="text-end">${num(m.today)}</td>
              <td class="text-end">${num(m.d7)}</td>
              <td class="text-end">${num(m.d30)}</td>
              <td class="text-end">${num(m.total)}</td>
              <td class="text-muted">${esc(last.text)}</td>
              <td>${state}</td>
            </tr>`;
        }).join('');
    }

    function renderTeams(teams) {
        const host = $('dash-teams');
        if (!host || !teams) return;
        host.innerHTML = teams.map((t) => `
            <tr>
              <td><a href="?menu=users&UserTeam=${t.id}" class="text-body fw-medium">${esc(t.name)}</a></td>
              <td class="text-end">${num(t.members)}</td>
              <td class="text-end">${num(t.products)}</td>
              <td>${t.products === 0
                  ? `<span class="badge ${PILL.crit}">chưa dùng</span>`
                  : `<span class="badge ${PILL.ok}">đang chạy</span>`}</td>
            </tr>`).join('');
    }

    function renderSecurity(sec) {
        const host = $('dash-security');
        if (!host || !sec) return;
        const rows = [
            ['Tài khoản đã khoá nhưng còn key API', sec.inactive_with_key, true],
            ['Key API ngắn hơn 32 ký tự', sec.weak_keys, true],
            ['Lỗi PHP trong 24 giờ', sec.php_errors_24h, true],
            ['Lượt đọc credential account (24 giờ)', sec.credential_reads_24h, false],
            ['Team đang có key API', sec.teams_with_key, false],
        ];
        host.innerHTML = rows.map(([text, value, mustBeZero]) => {
            const bad = mustBeZero && Number(value) > 0;
            return `<tr>
              <td>${esc(text)}</td>
              <td class="text-end"><span class="badge ${bad ? PILL.crit : PILL.ok}">${num(value)}</span></td>
            </tr>`;
        }).join('');
    }

    function renderScopeNote(scope) {
        const note = $('dash-scope-note');
        if (!note || !scope) return;
        const where = {
            admin: 'Bạn đang xem số liệu của toàn hệ thống.',
            manager: `Bạn đang xem số liệu của team ${scope.team_name || ''}.`.trim(),
        }[scope.level] || 'Bạn đang xem số liệu của riêng mình.';
        note.textContent = where;
    }

    async function load() {
        try {
            const body = new URLSearchParams({ action: 'dashboard-stats' });
            const res = await fetch('../../ajax.php?action=dashboard-stats', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            });
            const json = await res.json();
            if (json.status !== 'success') {
                $('dash-scope-note').textContent = json.message || 'Không tải được số liệu.';
                return;
            }
            const d = json.data;
            renderScopeNote(d.scope);
            renderKpis(d.products);
            renderDaily(d.products?.series);
            renderPipeline(d.products?.pipeline);
            renderAlerts(d.alerts);
            renderOrders(d.orders);
            renderMembers(d.members);
            renderTeams(d.teams);
            renderSecurity(d.security);
        } catch (error) {
            console.error('[dashboard]', error);
            const note = $('dash-scope-note');
            if (note) note.textContent = 'Không tải được số liệu: ' + error.message;
        }
    }

    document.addEventListener('DOMContentLoaded', load);
})();
