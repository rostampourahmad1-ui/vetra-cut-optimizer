/* VETRA Cut Optimizer 2.1 — admin/front-end SPA */
/* global VCO */
(function () {
    'use strict';

    var SIZES = VCO.sizes || [8, 10, 12, 14, 16, 18, 20, 22, 25, 28, 32];
    var GRADES = VCO.grades || ['AII', 'AIII'];
    var state = {
        projects: [], pid: 0, project: null, cuts: [], inventory: [], autoInventory: [],
        cfg: { min_reusable_m: 0.5, scrap_ratio: 0.3, ton_price: 0 },
        result: null, tab: 'cuts', dirty: false
    };

    function el(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function num(v, d) { var n = parseFloat(v); return isNaN(n) ? (d || 0) : n; }
    function fa(n, d) { return Number(n || 0).toLocaleString('fa-IR', { maximumFractionDigits: d === undefined ? 0 : d }); }
    function kgm(size) { return Math.round(size * size / 162 * 10000) / 10000; }

    function api(method, path, body) {
        var opt = { method: method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': VCO.nonce, 'X-VCO-Access': VCO.accessToken || '' } };
        if (body !== undefined) { opt.body = JSON.stringify(body); }
        return fetch(VCO.restUrl + path, opt).then(function (r) {
            if (!r.ok) { return r.json().then(function (j) { throw new Error(j && j.message ? j.message : 'خطای سرور (' + r.status + ')'); }); }
            return r.json();
        });
    }

    function toast(message, type) {
        var t = document.createElement('div');
        t.className = 'vco-toast ' + (type === 'err' ? 'error' : 'success');
        t.textContent = message; document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3500);
    }

    function boot() {
        renderShell();
        api('GET', '/projects').then(function (list) {
            state.projects = list || [];
            renderToolbar();
            var host = document.querySelector('.vco-front-wrap[data-initial-project]');
            var initial = host ? parseInt(host.getAttribute('data-initial-project'), 10) : 0;
            if (initial && state.projects.some(function (p) { return Number(p.id) === initial; })) { loadProject(initial); }
            else if (state.projects.length) { loadProject(state.projects[0].id); }
            else { render(); }
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    function renderShell() {
        el('vco-root').innerHTML = '<div class="vco-app">' +
            '<div class="vco-header vco-no-print"><div class="vco-brand">' +
            '<div class="vco-logo">✂️</div><div><h1>وترا کات <small>Vetra RebarCut v' + esc(VCO.version) + '</small></h1>' +
            '<div class="vco-brand-sub">بهینه‌ساز برش میلگرد — انبار، خرید، نقشه گرافیکی و چاپ لیبل</div></div></div>' +
            '<div class="vco-toolbar" id="vco-toolbar"></div></div>' +
            '<div class="vco-card"><div class="vco-tabs vco-no-print" id="vco-tabs"></div><div id="vco-print-area"></div></div></div>';
    }

    function renderToolbar() {
        var opts = state.projects.map(function (p) { return '<option value="' + p.id + '"' + (p.id == state.pid ? ' selected' : '') + '>#' + p.id + ' — ' + esc(p.name) + '</option>'; }).join('');
        el('vco-toolbar').innerHTML = '<select id="vco-project" class="vco-btn">' + opts + '</select>' +
            '<button class="btn vco-btn" id="vco-new">+ پروژه جدید</button>' +
            '<button class="btn vco-btn-green" id="vco-optimize" ' + (state.pid ? '' : 'disabled') + '>⚡ محاسبه و بهینه‌سازی</button>';
        if (el('vco-project')) { el('vco-project').onchange = function () { loadProject(parseInt(this.value, 10)); }; }
        if (el('vco-new')) { el('vco-new').onclick = createProject; }
        if (el('vco-optimize')) { el('vco-optimize').onclick = runOptimize; }
    }

    function createProject() {
        var name = window.prompt('نام پروژه:', 'پروژه ' + new Date().toLocaleDateString('fa-IR'));
        if (name === null) { return; }
        api('POST', '/projects', { name: name }).then(function (p) {
            state.projects.unshift(p); toast('پروژه ساخته شد'); loadProject(p.id);
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    function loadProject(id, keepResult) {
        api('GET', '/projects/' + id).then(function (p) {
            state.pid = Number(p.id); state.project = p;
            state.cuts = (p.cuts || []).map(mapCut);
            state.inventory = (p.inventory || []).filter(function (r) { return r.location !== VCO.offcutTag; }).map(mapInv);
            state.autoInventory = (p.inventory || []).filter(function (r) { return r.location === VCO.offcutTag; }).map(mapInv);
            state.cfg = normalizeCfg(p.price_config); state.dirty = false;
            if (!keepResult) { state.result = null; state.tab = 'cuts'; }
            renderToolbar(); render();
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    function mapCut(c) { return { size: parseInt(c.size, 10) || 12, grade: GRADES.indexOf(c.grade) > -1 ? c.grade : 'AII', length: num(c.length), quantity: parseInt(c.quantity, 10) || 1, label: c.label || '', note: c.note || '' }; }
    function mapInv(c) { return { size: parseInt(c.size, 10) || 12, grade: GRADES.indexOf(c.grade) > -1 ? c.grade : 'AII', bar_length: num(c.bar_length), quantity: parseInt(c.quantity, 10) || 1, location: c.location || '' }; }
    function normalizeCfg(cfg) { cfg = cfg || {}; return { min_reusable_m: num(cfg.min_reusable_m, 0.5), scrap_ratio: num(cfg.scrap_ratio, 0.3), ton_price: num(cfg.ton_price !== undefined ? cfg.ton_price : cfg.default_price, 0) }; }

    var TABS = [{ id: 'cuts', name: '✂️ لیست برش' }, { id: 'inv', name: '🏗️ انبار' }, { id: 'settings', name: '⚙️ تنظیمات' }, { id: 'report', name: '📊 نقشه و گزارش' }, { id: 'labels', name: '🏷️ چاپ لیبل' }];

    function render() {
        if (!state.pid) { el('vco-tabs').innerHTML = ''; el('vco-print-area').innerHTML = '<div class="vco-empty">پروژه‌ای انتخاب نشده است.</div>'; return; }
        el('vco-tabs').innerHTML = TABS.map(function (t) { return '<div class="vco-tab' + (state.tab === t.id ? ' active' : '') + '" data-tab="' + t.id + '">' + t.name + '</div>'; }).join('');
        Array.prototype.forEach.call(el('vco-tabs').querySelectorAll('.vco-tab'), function (tab) { tab.onclick = function () { state.tab = this.dataset.tab; render(); }; });
        var area = el('vco-print-area');
        if (state.tab === 'cuts') { renderCuts(area); } else if (state.tab === 'inv') { renderInventory(area); } else if (state.tab === 'settings') { renderSettings(area); } else if (state.tab === 'labels') { renderLabels(area); } else { renderReport(area); }
    }
    function saveBar(actions) { return '<div class="vco-actions-row vco-no-print">' + actions + '</div>'; }

    function renderCuts(area) {
        if (!state.cuts.length) { state.cuts.push({ size: 12, grade: 'AII', length: 0, quantity: 0, label: '', note: '' }); }
        var rows = state.cuts.map(function (c, i) { return '<tr><td class="vco-num">' + fa(i + 1) + '</td><td>' + select('size', i, SIZES, c.size, 'Ø') + '</td><td>' + select('grade', i, GRADES, c.grade) + '</td>' +
            '<td><input type="number" step="0.01" min="0" data-f="length" data-i="' + i + '" value="' + c.length + '"></td><td><input type="number" step="1" min="0" data-f="quantity" data-i="' + i + '" value="' + c.quantity + '"></td>' +
            '<td><input type="text" data-f="label" data-i="' + i + '" value="' + esc(c.label) + '"></td><td><input type="text" data-f="note" data-i="' + i + '" value="' + esc(c.note) + '"></td>' +
            '<td style="white-space:nowrap"><button class="btn vco-btn-outline vco-btn-sm" data-dup="' + i + '">⧉</button> <button class="btn vco-btn-red vco-btn-sm" data-del="' + i + '">✕</button></td></tr>'; }).join('');
        area.innerHTML = '<h3>لیست برش — گروه‌بندی خودکار سایز × گرید</h3>' + saveBar('<button class="btn vco-btn" id="vco-addrow">+ ردیف</button><button class="btn vco-btn-outline" id="vco-paste">📋 کپی از Excel</button><button class="btn vco-btn-outline" id="vco-clearrows">پاک کردن</button><button class="btn vco-btn-green" id="vco-savecuts">💾 ذخیره لیست</button>') +
            '<div class="vco-table-wrap"><table class="vco-table"><thead><tr><th>ردیف</th><th>سایز *</th><th>نوع *</th><th>طول (m) *</th><th>تعداد *</th><th>لیبل</th><th>توضیحات</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<p class="vco-hint">ترتیب ستون‌های Excel: سایز، نوع، طول، تعداد، لیبل، توضیحات. ستون شکل حذف شده است.</p>';
        bindTable(area, state.cuts);
        el('vco-addrow').onclick = function () { state.cuts.push({ size: 12, grade: 'AII', length: 0, quantity: 1, label: '', note: '' }); state.dirty = true; render(); };
        el('vco-clearrows').onclick = function () { state.cuts = []; render(); };
        el('vco-savecuts').onclick = function () { saveCuts().then(function (r) { toast('لیست برش ذخیره شد (' + r.saved + ' ردیف)'); }); };
        el('vco-paste').onclick = function () { openPasteModal('cuts'); };
    }

    function select(field, i, options, selected, prefix) { return '<select data-f="' + field + '" data-i="' + i + '">' + options.map(function (o) { return '<option value="' + o + '"' + (String(o) === String(selected) ? ' selected' : '') + '>' + (prefix ? prefix + o : o) + '</option>'; }).join('') + '</select>'; }
    function bindTable(area, arr) {
        function update(e) { var t = e.target; if (!t.dataset || t.dataset.f === undefined) { return; } var i = parseInt(t.dataset.i, 10), f = t.dataset.f, v = t.value; arr[i][f] = (f === 'length' || f === 'quantity' || f === 'bar_length') ? num(v) : v; if (f === 'size') { arr[i][f] = parseInt(v, 10); } state.dirty = true; }
        area.oninput = update; area.onchange = update;
        area.onclick = function (e) { var t = e.target; if (!t.dataset) { return; } if (t.dataset.dup !== undefined) { var d = parseInt(t.dataset.dup, 10); arr.splice(d + 1, 0, JSON.parse(JSON.stringify(arr[d]))); state.dirty = true; render(); } else if (t.dataset.del !== undefined) { arr.splice(parseInt(t.dataset.del, 10), 1); state.dirty = true; render(); } };
    }

    function openPasteModal(target) {
        var back = document.createElement('div'); back.className = 'vco-modal-back';
        back.innerHTML = '<div class="vco-modal"><h3>درون‌ریزی از Excel</h3><div class="vco-hint">' + (target === 'cuts' ? 'ستون‌ها: سایز | نوع | طول | تعداد | لیبل | توضیحات' : 'ستون‌ها: سایز | نوع | طول شاخه | تعداد | محل') + '</div><textarea id="vco-paste-data" dir="rtl"></textarea><div class="vco-actions-row"><button class="btn" id="vco-paste-cancel">انصراف</button><button class="btn vco-btn" id="vco-paste-ok">افزودن</button></div></div>';
        document.body.appendChild(back); el('vco-paste-cancel').onclick = function () { back.remove(); }; back.onclick = function (e) { if (e.target === back) { back.remove(); } };
        el('vco-paste-ok').onclick = function () { var rows = parseTable(el('vco-paste-data').value, target); if (!rows.length) { toast('ردیف معتبر پیدا نشد', 'err'); return; } if (target === 'cuts') { state.cuts = state.cuts.concat(rows); } else { state.inventory = state.inventory.concat(rows); } state.dirty = true; back.remove(); render(); toast(rows.length + ' ردیف اضافه شد'); };
    }
    function normGrade(g) { g = String(g || '').toUpperCase().trim(); return (g === 'A3' || g === 'III' || g === 'AIII' || g === '3') ? 'AIII' : 'AII'; }
    function parseTable(text, target) {
        var out = []; text.split(/\r?\n/).forEach(function (line) { line = line.trim(); if (!line) { return; } var c = line.split(/\t|;|،| {2,}/).map(function (x) { return x.trim(); }); var size = parseInt(c[0], 10); if (isNaN(size) || c.length < 4) { return; }
            if (target === 'cuts') { out.push({ size: size, grade: normGrade(c[1]), length: num(c[2]), quantity: parseInt(c[3], 10) || 1, label: c[4] || '', note: c[5] || '' }); }
            else { out.push({ size: size, grade: normGrade(c[1]), bar_length: num(c[2]), quantity: parseInt(c[3], 10) || 1, location: c[4] || '' }); }
        }); return out;
    }

    function renderInventory(area) {
        var rows = state.inventory.map(function (c, i) { return '<tr><td class="vco-num">' + fa(i + 1) + '</td><td>' + select('size', i, SIZES, c.size, 'Ø') + '</td><td>' + select('grade', i, GRADES, c.grade) + '</td><td><input type="number" step="0.01" min="0" data-f="bar_length" data-i="' + i + '" value="' + c.bar_length + '"></td><td><input type="number" step="1" min="0" data-f="quantity" data-i="' + i + '" value="' + c.quantity + '"></td><td><input type="text" data-f="location" data-i="' + i + '" value="' + esc(c.location) + '"></td><td><button class="btn vco-btn-red vco-btn-sm" data-del="' + i + '">✕</button></td></tr>'; }).join('');
        var auto = state.autoInventory.length ? '<h4>تکه‌های قابل استفاده افزوده‌شده خودکار</h4><div class="vco-auto-offcuts">' + state.autoInventory.map(function (c) { return '<span>Ø' + c.size + ' ' + c.grade + ' — ' + fa(c.bar_length, 2) + 'm × ' + fa(c.quantity) + '</span>'; }).join('') + '</div>' : '<div class="vco-hint">پس از محاسبه، ته‌مانده‌های بزرگ‌تر از حد مجاز به‌صورت خودکار در این بخش ثبت می‌شوند.</div>';
        area.innerHTML = '<h3>انبار — موجودی همیشه در محاسبه مصرف می‌شود</h3>' + saveBar('<button class="btn vco-btn" id="vco-addinv">+ ردیف</button><button class="btn vco-btn-outline" id="vco-paste-inv">📋 کپی از Excel</button><button class="btn vco-btn-green" id="vco-saveinv">💾 ذخیره موجودی</button>') + '<div class="vco-table-wrap"><table class="vco-table"><thead><tr><th>ردیف</th><th>سایز *</th><th>نوع *</th><th>طول شاخه (m) *</th><th>تعداد *</th><th>محل/توضیح</th><th></th></tr></thead><tbody>' + (rows || '<tr><td colspan="7" class="vco-empty">موجودی دستی ثبت نشده</td></tr>') + '</tbody></table></div>' + auto;
        bindTable(area, state.inventory); el('vco-addinv').onclick = function () { state.inventory.push({ size: 12, grade: 'AII', bar_length: 6, quantity: 1, location: '' }); state.dirty = true; render(); }; el('vco-paste-inv').onclick = function () { openPasteModal('inventory'); }; el('vco-saveinv').onclick = function () { saveInventory().then(function (r) { toast('موجودی ذخیره شد (' + r.saved + ' ردیف)'); }); };
    }

    function renderSettings(area) {
        var p = state.project, stockSel = [6, 12].indexOf(num(p.stock_length)) > -1 ? String(p.stock_length) : 'custom';
        area.innerHTML = '<h3>تنظیمات محاسبه</h3><div class="vco-info">موجودی انبار در هر محاسبه به‌صورت خودکار اولویت دارد. قیمت زیر یک‌بار برای کل پروژه استفاده می‌شود.</div><div class="vco-form-grid">' +
            field('طول شاخه استاندارد', '<select id="vco-stock-opt"><option value="6">۶ متر</option><option value="12">۱۲ متر</option><option value="custom">سفارشی</option></select>') + field('طول سفارشی (m)', '<input type="number" step="0.1" min="1" id="vco-stock-custom" value="' + p.stock_length + '">') +
            field('ضخامت تیغه Kerf (mm)', '<input type="number" step="0.1" min="0" max="20" id="vco-kerf" value="' + num(p.kerf) + '">') + field('حداقل ته‌مانده قابل استفاده (m)', '<input type="number" step="0.05" min="0" id="vco-minrem" value="' + state.cfg.min_reusable_m + '">') +
            field('نسبت ارزش ضایعات (۰ تا ۱)', '<input type="number" step="0.05" min="0" max="1" id="vco-scrap" value="' + state.cfg.scrap_ratio + '">') + field('قیمت پیش‌فرض هر تن (ریال)', '<input type="number" step="1000" min="0" id="vco-price" value="' + state.cfg.ton_price + '">') + '</div>' + saveBar('<button class="btn vco-btn-green" id="vco-savesettings">💾 ذخیره تنظیمات</button>');
        el('vco-stock-opt').value = stockSel; el('vco-stock-custom').disabled = stockSel !== 'custom'; el('vco-stock-opt').onchange = function () { el('vco-stock-custom').disabled = this.value !== 'custom'; };
        el('vco-savesettings').onclick = function () { var body = { stock_length: el('vco-stock-opt').value === 'custom' ? num(el('vco-stock-custom').value, 12) : num(el('vco-stock-opt').value), kerf: num(el('vco-kerf').value), config: { min_reusable_m: num(el('vco-minrem').value, 0.5), scrap_ratio: num(el('vco-scrap').value, 0.3), ton_price: num(el('vco-price').value) } }; api('PUT', '/projects/' + state.pid + '/settings', body).then(function () { state.project.stock_length = body.stock_length; state.project.kerf = body.kerf; state.cfg = normalizeCfg(body.config); toast('تنظیمات ذخیره شد'); }).catch(function (e) { toast(e.message, 'err'); }); };
    }
    function field(label, input, hint) { return '<div class="vco-field"><label>' + label + '</label>' + input + (hint ? '<div class="vco-hint">' + hint + '</div>' : '') + '</div>'; }

    function saveCuts() { return api('PUT', '/projects/' + state.pid + '/cuts', state.cuts.filter(function (c) { return c.length > 0 && c.quantity > 0; })); }
    function saveInventory() { return api('PUT', '/projects/' + state.pid + '/inventory', state.inventory.filter(function (c) { return c.bar_length > 0 && c.quantity > 0; })); }
    function runOptimize() {
        if (!state.cuts.some(function (c) { return c.length > 0 && c.quantity > 0; })) { toast('لیست برش خالی است', 'err'); return; }
        toast('در حال ذخیره و محاسبه…');
        Promise.all([saveCuts(), saveInventory()]).then(function () { return api('POST', '/projects/' + state.pid + '/optimize', {}); }).then(function (report) {
            state.result = report; state.tab = 'report'; state.dirty = false;
            return new Promise(function (resolve) { loadProjectAfterOptimize(resolve); });
        }).then(function () { render(); toast('محاسبه انجام شد ✓'); }).catch(function (e) { toast(e.message, 'err'); });
    }
    function loadProjectAfterOptimize(done) {
        api('GET', '/projects/' + state.pid).then(function (p) { state.project = p; state.inventory = (p.inventory || []).filter(function (r) { return r.location !== VCO.offcutTag; }).map(mapInv); state.autoInventory = (p.inventory || []).filter(function (r) { return r.location === VCO.offcutTag; }).map(mapInv); renderToolbar(); done(); }).catch(function () { done(); });
    }

    function renderReport(area) {
        if (!state.result) { area.innerHTML = '<div class="vco-empty">ابتدا محاسبه را اجرا کنید.</div>'; return; }
        var r = state.result, t = r.totals;
        var html = '<div class="vco-no-print vco-actions-row"><button class="btn vco-btn" id="vco-print">🖨 چاپ گرافیک / PDF</button><button class="btn vco-btn-outline" id="vco-labels">🏷 چاپ لیبل‌ها</button><a class="btn vco-btn-outline" href="' + csvLink() + '">⬇ Excel (CSV)</a><button class="btn vco-btn-outline" id="vco-json">⬇ JSON</button></div>';
        if (r.offcuts_auto && r.offcuts_added) { html += '<div class="vco-success-note">' + fa(r.offcuts_added) + ' نوع ته‌مانده قابل استفاده به‌صورت خودکار به انبار افزوده شد.</div>'; }
        html += '<div class="vco-stats">' + stat('شاخه نو مورد نیاز', fa(t.new_bars) + ' شاخه') + stat('مصرف از انبار', fa(t.inventory_len, 2) + ' m / ' + fa(t.inventory_bars) + ' شاخه') + stat('کل ضایعات', fa(t.waste_length, 2) + ' m', 'warn') + stat('درصد ضایعات', fa(t.waste_percent, 1) + '٪', t.waste_percent > 10 ? 'warn' : 'good') + stat('ته‌مانده قابل استفاده', fa(t.reusable_left, 2) + ' m', 'good') + stat('وزن مفید', fa(t.weight_useful, 0) + ' kg') + stat('وزن خرید', fa(t.weight_purchase, 0) + ' kg') + stat('هزینه خرید', fa(t.cost, 0) + ' ریال') + stat('ارزش ضایعات', fa(t.scrap_value, 0) + ' ریال') + stat('صرفه‌جویی', fa(t.saving, 0) + ' ریال', 'good') + '</div>';
        html += '<h4>📦 لیست خرید پس از کسر موجودی</h4><div class="vco-table-wrap"><table class="vco-table"><thead><tr><th>سایز</th><th>نوع</th><th>طول شاخه</th><th>تعداد</th><th>وزن (kg)</th><th>هزینه (ریال)</th></tr></thead><tbody>' + (r.purchase_list.length ? r.purchase_list.map(function (p) { return '<tr><td>Ø' + p.size + '</td><td>' + p.grade + '</td><td>' + fa(p.bar_length, 1) + 'm</td><td>' + fa(p.qty_bars) + '</td><td>' + fa(p.weight_kg, 1) + '</td><td>' + fa(p.cost) + '</td></tr>'; }).join('') : '<tr><td colspan="6" class="vco-empty">نیازی به خرید شاخه نو نیست ✓</td></tr>') + '</tbody></table></div>';
        html += '<h4>🗺 نقشه گرافیکی برش</h4><div class="vco-legend"><span><i style="background:#2e9e4f"></i>برش مفید</span><span><i style="background:#e6b800"></i>ته‌مانده قابل استفاده</span><span><i style="background:#d64545"></i>ضایعات</span></div>';
        r.groups.forEach(function (g) { var s = g.stat; html += '<div class="vco-group-block"><div class="vco-group-title">Ø' + s.size + ' — ' + s.grade + '</div><div class="vco-group-meta">قطعات: ' + fa(s.pieces_count) + ' | نو: ' + fa(s.new_bars) + ' | انبار: ' + fa(s.inventory_bars) + ' (' + fa(s.inventory_len, 1) + 'm) | ضایعات: ' + fa(s.waste_length, 2) + 'm</div>';
            if (s.errors && s.errors.length) { html += '<div class="vco-errors">⚠ ' + s.errors.map(esc).join('<br>⚠ ') + '</div>'; }
            g.bars.forEach(function (b, bi) { var cap = b.type === 'new' ? 'شاخه نو ' + fa(b.length, 1) + 'm — شماره ' + fa(bi + 1) : 'از انبار ' + fa(b.length, 1) + 'm' + (b.location ? ' | ' + esc(b.location) : ''); html += '<div class="vco-bar-row"><div class="vco-bar-caption">' + cap + ' — Ø' + s.size + ' ' + s.grade + '</div><div class="vco-bar">' + barSegments(b, s.size, s.grade) + '</div></div>'; });
            html += '</div>'; });
        area.innerHTML = html; el('vco-print').onclick = function () { window.print(); }; el('vco-labels').onclick = function () { state.tab = 'labels'; render(); }; el('vco-json').onclick = function () { downloadFile('vetra-cut-report-' + state.pid + '.json', JSON.stringify(r, null, 2), 'application/json'); };
    }
    function stat(label, value, kind) { return '<div class="vco-stat ' + (kind || '') + '"><b>' + esc(value) + '</b><span>' + label + '</span></div>'; }
    function barSegments(b, size, grade) { var html = '', L = b.length; b.pieces.forEach(function (p) { var w = p.length / L * 100, tip = fa(p.length, 2) + 'm' + (p.label ? ' — ' + p.label : '') + ' — Ø' + size + ' ' + grade; html += '<div class="vco-seg vco-seg-piece" style="width:' + w + '%" title="' + esc(tip) + '">' + (w > 7 ? fa(p.length, 2) + 'm' : '') + (w > 13 && p.label ? ' ' + esc(p.label) : '') + '</div>'; }); if (b.reusable > 0.001) { html += '<div class="vco-seg vco-seg-reusable" style="width:' + b.reusable / L * 100 + '%">ته‌مانده ' + fa(b.reusable, 1) + '</div>'; } if (b.waste > 0.001) { html += '<div class="vco-seg vco-seg-waste" style="width:' + b.waste / L * 100 + '%">ضایعات</div>'; } return html; }

    function labelItems(expand) {
        var items = [], r = state.result;
        if (r && r.groups) { r.groups.forEach(function (g) { g.bars.forEach(function (b, bi) { b.pieces.forEach(function (p) { items.push({ size: g.stat.size, grade: g.stat.grade, length: p.length, label: p.label || ('قطعه Ø' + g.stat.size), source: b.type === 'new' ? 'شاخه نو' : 'انبار', bar: bi + 1 }); }); }); }); }
        if (!items.length) { state.cuts.forEach(function (c) { for (var i = 0; i < c.quantity; i++) { items.push({ size: c.size, grade: c.grade, length: c.length, label: c.label || ('قطعه Ø' + c.size), source: 'لیست برش', bar: '-' }); } }); }
        if (expand) { return items.slice(0, 2000); }
        var map = {}, out = []; items.forEach(function (x) { var k = x.size + '|' + x.grade + '|' + x.length + '|' + x.label; if (!map[k]) { map[k] = { size: x.size, grade: x.grade, length: x.length, label: x.label, source: x.source, bar: x.bar, count: 0 }; out.push(map[k]); } map[k].count++; }); return out;
    }
    function renderLabels(area) { var items = labelItems(false); area.innerHTML = '<div class="vco-label-head"><h3>لیبل قطعات</h3><p>برای لیبل‌پرینتر یا پرینتر معمولی، اندازه کاغذ/لیبل را در تنظیمات چاپگر انتخاب کنید.</p></div>' + saveBar('<label class="vco-check"><input type="checkbox" id="vco-label-expand"> چاپ یک لیبل برای هر قطعه</label><button class="btn vco-btn" id="vco-print-labels">🖨 چاپ لیبل‌ها</button>') + '<div class="vco-label-preview">' + items.map(function (x) { return '<div class="vco-label-card"><strong>Ø' + x.size + ' ' + x.grade + '</strong><b>' + fa(x.length, 2) + ' m</b><span>' + esc(x.label) + '</span><small>' + esc(x.source) + ' | تعداد: ' + fa(x.count) + '</small></div>'; }).join('') + '</div>';
        el('vco-print-labels').onclick = function () { printLabels(el('vco-label-expand').checked); };
    }
    function printLabels(expand) { var items = labelItems(expand); if (!items.length) { toast('لیبلی برای چاپ وجود ندارد', 'err'); return; } var w = window.open('', '_blank'); if (!w) { toast('پنجره چاپ توسط مرورگر مسدود شد', 'err'); return; } var title = state.project ? state.project.name : 'VETRA Cut Optimizer'; w.document.write('<!doctype html><html dir="rtl"><head><meta charset="utf-8"><title>لیبل - ' + esc(title) + '</title><style>@page{margin:5mm}*{box-sizing:border-box}body{font-family:Tahoma,Arial;direction:rtl;margin:0}.sheet{display:grid;grid-template-columns:repeat(2,90mm);gap:4mm}.label{width:90mm;min-height:42mm;border:1px solid #111;padding:4mm;display:flex;flex-direction:column;justify-content:space-between;page-break-inside:avoid}.project{font-size:10pt}.group{font-size:14pt;font-weight:bold}.length{font-size:20pt;font-weight:bold;direction:ltr;text-align:right}.code{font-size:13pt;overflow-wrap:anywhere}.meta{font-size:9pt;color:#444}</style></head><body><div class="sheet">'); items.forEach(function (x, i) { w.document.write('<div class="label"><span class="project">' + esc(title) + '</span><span class="group">Ø' + x.size + ' ' + x.grade + '</span><span class="length">' + Number(x.length).toFixed(2) + ' m</span><span class="code">' + esc(x.label) + '</span><span class="meta">' + esc(x.source) + ' | شاخه: ' + esc(x.bar) + ' | #' + (i + 1) + '</span></div>'); }); w.document.write('</div></body></html>'); w.document.close(); w.focus(); setTimeout(function () { w.print(); }, 350); }

    function csvLink() { return VCO.csvUrl + '?action=vco_export_csv&project=' + state.pid + '&_wpnonce=' + VCO.csvNonce + (VCO.accessToken ? '&vco_access=' + encodeURIComponent(VCO.accessToken) : ''); }
    function downloadFile(name, content, mime) { var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + content], { type: mime })); a.download = name; a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000); }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
