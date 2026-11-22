/*
 * WBPMS shared UI behaviour, loaded once by layout.php for every signed-in page.
 *
 *  1. Tables   - long tables scroll inside their card instead of pushing the
 *                page sideways, long text is clamped (click to expand), column
 *                headers sort on click, and long tables are paginated.
 *  2. Filters  - GET filter forms submit by themselves (search after a short
 *                pause, selects / dates / checkboxes on change); the Filter /
 *                Apply buttons are hidden.
 *  3. Dates    - dates picked in a filter form are remembered per page and come
 *                back when the page is opened again; dates typed into a normal
 *                form survive a page reload until the form is submitted.
 *  4. Calendar - every <input type="date"> / <input type="month"> opens the WBPMS
 *                calendar instead of the browser's own picker. The input keeps
 *                its ISO value, so servers and other scripts see no difference.
 *
 * Opt-outs (put on the element or an ancestor):
 *   data-no-enhance            no table behaviour at all
 *   data-no-paginate           no client pagination (e.g. server-paginated)
 *   data-no-sort               no header sorting (on <table> or a <th>)
 *   data-auto-filter="off"     GET form keeps its manual submit button
 *   data-no-retain             date input is never remembered / restored
 *   data-native-picker         date input keeps the browser's picker
 *   data-sort-value="..."      on a <td>: value used for sorting
 */
(function () {
    'use strict';

    var USER = (document.body && document.body.getAttribute('data-user-key')) || 'anon';
    var PREFIX = 'wbpms:' + USER + ':';

    function storeGet(area, key) {
        try {
            var raw = window[area].getItem(PREFIX + key);
            return raw === null ? null : JSON.parse(raw);
        } catch (e) { return null; }
    }
    function storeSet(area, key, value) {
        try { window[area].setItem(PREFIX + key, JSON.stringify(value)); } catch (e) { /* storage blocked */ }
    }
    function storeDel(area, key) {
        try { window[area].removeItem(PREFIX + key); } catch (e) { /* storage blocked */ }
    }

    function closestAttr(el, attr) {
        return !!(el && el.closest && el.closest('[' + attr + ']'));
    }

    function pathOf(url) {
        try { return new URL(url, window.location.href).pathname.replace(/\/+$/, '') || '/'; } catch (e) { return ''; }
    }
    var HERE = pathOf(window.location.href);

    // ---- Date helpers ---------------------------------------------------
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];
    var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function toIso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function toMonth(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
    function parseIso(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
        if (!m) return null;
        var d = new Date(+m[1], +m[2] - 1, +m[3]);
        return d.getMonth() === +m[2] - 1 ? d : null;
    }
    function parseMonth(s) {
        var m = /^(\d{4})-(\d{2})$/.exec(s || '');
        return m ? new Date(+m[1], +m[2] - 1, 1) : null;
    }
    function addDays(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
    function sameDay(a, b) { return !!a && !!b && a.getTime() === b.getTime(); }
    function shortUs(d) { return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + '/' + String(d.getFullYear()).slice(-2); }
    function today() {
        // The business runs on Manila time, whatever the device clock says.
        try {
            var s = new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
            }).format(new Date());
            var d = parseIso(s);
            if (d) return d;
        } catch (e) { /* old browser */ }
        var n = new Date();
        return new Date(n.getFullYear(), n.getMonth(), n.getDate());
    }
    // Payroll cut-off week runs Friday to Thursday.
    function cutoffStart(d) { return addDays(d, -((d.getDay() - 5 + 7) % 7)); }

    function isDateInput(el) {
        return el && el.tagName === 'INPUT' && (el.type === 'date' || el.type === 'month');
    }

    // Two date inputs in one form form a range when their names pair up:
    // date_from/date_to, period_start/period_end, start_date/end_date ...
    var PAIRS = [['from', 'to'], ['start', 'end'], ['begin', 'end']];
    function rangePartner(input) {
        if (!input.form || !input.name || input.type !== 'date') return null;
        var name = input.name;
        for (var i = 0; i < PAIRS.length; i++) {
            var a = PAIRS[i][0], b = PAIRS[i][1];
            var reA = new RegExp('(^|_)' + a + '(_|$)'), reB = new RegExp('(^|_)' + b + '(_|$)');
            var other = null, role = null;
            if (reA.test(name)) { other = name.replace(reA, '$1' + b + '$2'); role = 'start'; }
            else if (reB.test(name)) { other = name.replace(reB, '$1' + a + '$2'); role = 'end'; }
            if (other) {
                var el = input.form.elements.namedItem(other);
                if (el && el.tagName === 'INPUT' && el.type === 'date') return { el: el, role: role };
            }
        }
        return null;
    }

    // =====================================================================
    // 1. TABLES
    // =====================================================================
    var PAGE_SIZES = [10, 25, 50, 100];
    var DEFAULT_SIZE = 25;
    var collator = window.Intl && Intl.Collator ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' }) : null;

    function sortKey(td) {
        if (!td) return { t: 2, v: '' };
        var attr = td.getAttribute('data-sort-value');
        var text = (attr !== null ? attr : td.textContent).replace(/\s+/g, ' ').trim();
        if (text === '' || text === '—' || text === '-' || text === '–') return { t: 2, v: '' };
        var m = /^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})(?:\s+(\d{1,2}):(\d{2}))?/.exec(text);
        if (m) {
            var y = +m[3]; if (y < 100) y += 2000;
            return { t: 0, v: ((y * 100 + +m[1]) * 100 + +m[2]) * 10000 + (m[4] ? +m[4] * 100 + +m[5] : 0) };
        }
        m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(text);
        if (m) return { t: 0, v: ((+m[1] * 100 + +m[2]) * 100 + +m[3]) * 10000 + (m[4] ? +m[4] * 100 + +m[5] : 0) };
        m = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec(text);
        if (m) return { t: 0, v: +m[1] * 60 + +m[2] };
        var n = text.replace(/[₱$%,\s]/g, '').replace(/[−–]/g, '-');
        var neg = /^\(.*\)$/.test(n);
        if (neg) n = n.slice(1, -1);
        if (/^-?\d*\.?\d+$/.test(n)) return { t: 0, v: (neg ? -1 : 1) * parseFloat(n) };
        return { t: 1, v: text };
    }
    function compareKeys(a, b) {
        if (a.t !== b.t) return a.t - b.t;               // numbers, then text, blanks last
        if (a.t === 0) return a.v - b.v;
        if (a.t === 1) return collator ? collator.compare(a.v, b.v) : (a.v < b.v ? -1 : a.v > b.v ? 1 : 0);
        return 0;
    }

    function isPlaceholderRow(tr) {
        return tr.cells.length === 1 && tr.cells[0].colSpan > 1;
    }

    function tableShape(table) {
        var shape = { sortable: false, pageable: false, body: null };
        if (!table.tHead || !table.tHead.rows.length || table.tBodies.length !== 1) return shape;
        var body = table.tBodies[0];
        shape.body = body;
        var rows = Array.prototype.filter.call(body.rows, function (tr) { return !isPlaceholderRow(tr); });
        if (!rows.length) return shape;
        // Grouped rows (rowspan / colspan sub-headers / detail rows) must stay together.
        var grouped = body.querySelector('td[rowspan]:not([rowspan="1"]), th[rowspan]:not([rowspan="1"])') !== null
            || rows.some(function (tr) {
                return Array.prototype.some.call(tr.cells, function (c) { return c.colSpan > 1; });
            });
        // Rows with editable fields: hiding them would hide validation errors or
        // let "select all" act on rows the user cannot see.
        var editable = body.querySelector(
            'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea') !== null;
        var simpleHead = table.tHead.rows.length === 1
            && !Array.prototype.some.call(table.tHead.rows[0].cells, function (c) { return c.colSpan > 1; });
        shape.sortable = !grouped && simpleHead && !closestAttr(table, 'data-no-sort');
        shape.pageable = !grouped && !editable && !closestAttr(table, 'data-no-paginate');
        return shape;
    }

    function wrapForScroll(table) {
        var parent = table.parentElement;
        if (!parent) return null;
        var ox = window.getComputedStyle(parent).overflowX;
        var wrap = parent;
        if (ox !== 'auto' && ox !== 'scroll') {
            wrap = document.createElement('div');
            wrap.className = 'tbl-scroll';
            parent.insertBefore(wrap, table);
            wrap.appendChild(table);
        } else {
            wrap.classList.add('tbl-scroll-host');
        }
        function hint() {
            var more = wrap.scrollWidth - wrap.clientWidth > 2;
            wrap.classList.toggle('is-scrollable', more);
            wrap.classList.toggle('at-end', !more || wrap.scrollLeft + wrap.clientWidth >= wrap.scrollWidth - 2);
            wrap.classList.toggle('at-start', wrap.scrollLeft <= 2);
        }
        wrap.addEventListener('scroll', hint, { passive: true });
        window.addEventListener('resize', hint);
        hint();
        return { el: wrap, hint: hint };
    }

    function clampLongCells(body) {
        Array.prototype.forEach.call(body.rows, function (tr) {
            Array.prototype.forEach.call(tr.cells, function (td) {
                if (td.tagName !== 'TD' || td.colSpan > 1) return;
                var text = td.textContent.replace(/\s+/g, ' ').trim();
                if (text.length < 70) return;
                if (td.querySelector('a, button, form, input, select, textarea, table, details, ul, ol')) return;
                var box = document.createElement('div');
                box.className = 'cell-clamp';
                while (td.firstChild) box.appendChild(td.firstChild);
                td.appendChild(box);
                td.classList.add('has-clamp');
                if (box.scrollHeight > box.clientHeight + 1) {
                    box.classList.add('is-clamped');
                    box.title = 'Click to show the full text';
                    box.tabIndex = 0;
                    var toggle = function () {
                        var open = box.classList.toggle('is-open');
                        box.title = open ? 'Click to collapse' : 'Click to show the full text';
                    };
                    box.addEventListener('click', toggle);
                    box.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
                    });
                }
            });
        });
    }

    function enhanceTable(table, index) {
        if (table.getAttribute('data-ui-table') || closestAttr(table, 'data-no-enhance')) return;
        if (table.closest('.modal-backdrop, .dp, .notif-panel')) return;
        table.setAttribute('data-ui-table', '1');

        var scroll = wrapForScroll(table);
        var shape = tableShape(table);
        if (!shape.body) return;
        var body = shape.body;
        clampLongCells(body);
        if (scroll) scroll.hint();

        var stateKey = 'tbl:' + HERE + ':' + (table.id || index);
        var saved = storeGet('sessionStorage', stateKey) || {};
        var state = {
            page: saved.page || 1,
            size: storeGet('localStorage', 'pageSize') || DEFAULT_SIZE,
            col: typeof saved.col === 'number' ? saved.col : -1,
            dir: saved.dir === 'desc' ? 'desc' : 'asc'
        };
        if (PAGE_SIZES.indexOf(state.size) < 0) state.size = DEFAULT_SIZE;
        var busy = false;
        var pager = null, noMatch = null;
        var colCount = table.tHead.rows[0].cells.length;

        function dataRows() {
            return Array.prototype.filter.call(body.rows, function (tr) {
                return !isPlaceholderRow(tr) && !tr.classList.contains('tbl-nomatch');
            });
        }
        function visibleRows() {
            return dataRows().filter(function (tr) { return tr.style.display !== 'none' && !tr.hidden; });
        }
        function save() {
            storeSet('sessionStorage', stateKey, { page: state.page, col: state.col, dir: state.dir });
        }

        // ---- sorting ----
        var heads = [];
        if (shape.sortable) {
            heads = Array.prototype.slice.call(table.tHead.rows[0].cells);
            heads.forEach(function (th, col) {
                var label = th.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
                if (th.hasAttribute('data-no-sort') || label === '' || label === 'actions' || label === 'action'
                    || th.querySelector('input, select, button')) return;
                th.classList.add('is-sortable');
                th.tabIndex = 0;
                th.setAttribute('aria-sort', 'none');
                th.title = 'Sort by ' + th.textContent.replace(/\s+/g, ' ').trim();
                var go = function () {
                    if (state.col === col) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
                    else { state.col = col; state.dir = 'asc'; }
                    state.page = 1;
                    applySort();
                    render();
                    save();
                };
                th.addEventListener('click', go);
                th.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
                });
            });
        }
        var original = dataRows();
        function applySort() {
            heads.forEach(function (th, i) {
                if (!th.classList.contains('is-sortable')) return;
                th.setAttribute('aria-sort', i === state.col ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none');
            });
            var rows = state.col < 0 ? original.filter(function (tr) { return tr.parentNode === body; }) : dataRows();
            if (state.col >= 0) {
                var col = state.col, sign = state.dir === 'asc' ? 1 : -1;
                rows = rows.map(function (tr, i) { return { tr: tr, i: i, k: sortKey(tr.cells[col]) }; })
                    .sort(function (a, b) {
                        // Blanks stay at the bottom in both directions.
                        if (a.k.t === 2 || b.k.t === 2) return (a.k.t === 2) - (b.k.t === 2) || a.i - b.i;
                        return sign * compareKeys(a.k, b.k) || a.i - b.i;
                    })
                    .map(function (x) { return x.tr; });
            }
            busy = true;
            rows.forEach(function (tr) { body.appendChild(tr); });
            if (noMatch) body.appendChild(noMatch);
            busy = false;
        }

        // ---- pagination ----
        function render() {
            var all = dataRows();
            var vis = visibleRows();
            busy = true;
            if (all.length && !vis.length) {
                if (!noMatch) {
                    noMatch = document.createElement('tr');
                    noMatch.className = 'tbl-nomatch';
                    noMatch.innerHTML = '<td colspan="' + colCount + '">No matching records.</td>';
                }
                body.appendChild(noMatch);
            } else if (noMatch && noMatch.parentNode) {
                noMatch.parentNode.removeChild(noMatch);
            }
            busy = false;
            if (!shape.pageable) return;

            var pages = Math.max(1, Math.ceil(vis.length / state.size));
            if (state.page > pages) state.page = pages;
            if (state.page < 1) state.page = 1;
            var from = (state.page - 1) * state.size;
            all.forEach(function (tr) { tr.classList.remove('pg-out'); });
            vis.forEach(function (tr, i) { tr.classList.toggle('pg-out', i < from || i >= from + state.size); });

            var needPager = vis.length > PAGE_SIZES[0] || state.page > 1;
            if (!needPager) { if (pager) pager.hidden = true; return; }
            if (!pager) {
                pager = document.createElement('nav');
                pager.className = 'tbl-pager no-print';
                pager.setAttribute('aria-label', 'Table pages');
                var anchor = scroll ? scroll.el : table;
                anchor.parentNode.insertBefore(pager, anchor.nextSibling);
                pager.addEventListener('click', function (e) {
                    var b = e.target.closest('button[data-page]');
                    if (!b || b.disabled) return;
                    state.page = +b.getAttribute('data-page');
                    render();
                    save();
                    var top = (scroll ? scroll.el : table).getBoundingClientRect().top;
                    if (top < 0) (scroll ? scroll.el : table).scrollIntoView({ block: 'start', behavior: 'smooth' });
                });
                pager.addEventListener('change', function (e) {
                    if (!e.target.matches('select')) return;
                    var first = (state.page - 1) * state.size;
                    state.size = +e.target.value;
                    state.page = Math.floor(first / state.size) + 1;
                    storeSet('localStorage', 'pageSize', state.size);
                    render();
                    save();
                });
            }
            pager.hidden = false;
            var end = Math.min(from + state.size, vis.length);
            var nums = pageList(state.page, pages);
            var html = '<div class="tbl-pager-info">Showing <b>' + (vis.length ? from + 1 : 0) + '–' + end
                + '</b> of <b>' + vis.length + '</b>' + (vis.length !== all.length ? ' (filtered from ' + all.length + ')' : '') + '</div>';
            html += '<label class="tbl-pager-size">Rows per page <select aria-label="Rows per page">'
                + PAGE_SIZES.map(function (s) { return '<option' + (s === state.size ? ' selected' : '') + '>' + s + '</option>'; }).join('')
                + '</select></label>';
            html += '<div class="tbl-pager-nav">'
                + '<button type="button" data-page="' + (state.page - 1) + '"' + (state.page === 1 ? ' disabled' : '') + ' aria-label="Previous page">‹</button>';
            nums.forEach(function (n) {
                html += n === 0 ? '<span class="tbl-pager-gap">…</span>'
                    : '<button type="button" data-page="' + n + '"' + (n === state.page ? ' aria-current="page" class="is-current"' : '') + '>' + n + '</button>';
            });
            html += '<button type="button" data-page="' + (state.page + 1) + '"' + (state.page === pages ? ' disabled' : '') + ' aria-label="Next page">›</button></div>';
            pager.innerHTML = html;
            if (scroll) scroll.hint();
        }

        if (state.col >= 0 && heads[state.col] && heads[state.col].classList.contains('is-sortable')) applySort();
        else state.col = -1;
        render();

        // Re-paginate when another script filters (style.display) or replaces rows.
        if (window.MutationObserver) {
            var queued = false;
            new MutationObserver(function (list) {
                if (busy || queued) return;
                // Only rows matter: rows added/removed, or a row shown/hidden.
                var relevant = list.some(function (m) {
                    return m.type === 'childList' ? m.target === body : m.target.parentNode === body;
                });
                if (!relevant) return;
                queued = true;
                requestAnimationFrame(function () {
                    queued = false;
                    original = original.filter(function (tr) { return tr.parentNode === body; })
                        .concat(dataRows().filter(function (tr) { return original.indexOf(tr) < 0; }));
                    state.page = 1;
                    render();
                });
            }).observe(body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'hidden'] });
        }
    }

    function pageList(cur, total) {
        if (total <= 7) { var all = []; for (var i = 1; i <= total; i++) all.push(i); return all; }
        var out = [1];
        var lo = Math.max(2, cur - 1), hi = Math.min(total - 1, cur + 1);
        if (cur <= 3) { lo = 2; hi = 4; }
        if (cur >= total - 2) { lo = total - 3; hi = total - 1; }
        if (lo > 2) out.push(0);
        for (var p = lo; p <= hi; p++) out.push(p);
        if (hi < total - 1) out.push(0);
        out.push(total);
        return out;
    }

    // Local "search this table" boxes call filterRows(); keep it, but make it
    // match on full text (innerText is empty for rows on another page).
    window.filterRows = function (input, tableId) {
        var q = (input.value || '').toLowerCase().trim();
        var table = document.getElementById(tableId);
        if (!table) return;
        Array.prototype.forEach.call(table.tBodies, function (body) {
            Array.prototype.forEach.call(body.rows, function (tr) {
                if (isPlaceholderRow(tr) || tr.classList.contains('tbl-nomatch')) return;
                tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none';
            });
        });
    };

    // =====================================================================
    // 2 + 3. AUTO FILTERS AND DATE RETENTION
    // =====================================================================
    function serialize(form) {
        try { return new URLSearchParams(new FormData(form)).toString(); } catch (e) { return ''; }
    }
    function retainedFields(form) {
        return Array.prototype.filter.call(form.elements, function (el) {
            return el.name && !el.disabled && !closestAttr(el, 'data-no-retain')
                && (isDateInput(el) || (el.tagName === 'INPUT' && el.type === 'hidden' && el.name !== '_csrf'));
        });
    }
    function filterKey(form, i) { return 'filter:' + pathOf(form.action) + ':' + i; }

    function rememberFilterDates(form, i) {
        var fields = retainedFields(form);
        if (!fields.some(isDateInput)) return;
        var data = {};
        fields.forEach(function (el) { data[el.name] = el.value; });
        var anyDate = fields.some(function (el) { return isDateInput(el) && el.value !== ''; });
        if (anyDate) storeSet('localStorage', filterKey(form, i), data);
        else storeDel('localStorage', filterKey(form, i));
    }

    // Opened without a query string -> bring back the dates picked last time.
    function restoreFilterDates(form, i) {
        if (window.location.search !== '' || pathOf(form.action) !== HERE) return false;
        var saved = storeGet('localStorage', filterKey(form, i));
        if (!saved) return false;
        var changed = false;
        retainedFields(form).forEach(function (el) {
            if (Object.prototype.hasOwnProperty.call(saved, el.name) && saved[el.name] !== el.value) {
                el.value = saved[el.name];
                changed = true;
            }
        });
        if (!changed) return false;
        var q = serialize(form);
        if (q === '') return false;
        document.documentElement.classList.add('is-restoring');
        window.location.replace(form.action.split('?')[0] + '?' + q);
        return true;
    }

    function initAutoFilter(form, i) {
        if (form.getAttribute('data-auto-filter') === 'off' || form.closest('.modal-backdrop')) return;
        if (restoreFilterDates(form, i)) return;
        // Only dates the user chose (they are in the URL) are remembered; the
        // server's defaults (e.g. "this month") must not be frozen in place.
        if (window.location.search !== '' && pathOf(form.action) === HERE) rememberFilterDates(form, i);
        form.classList.add('is-auto-filter');
        form.setAttribute('data-auto-filter', 'on');

        Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'), function (b) {
            if (b.name) return;            // a named button carries a value; keep it
            b.classList.add('auto-hidden');
            b.tabIndex = -1;
            b.setAttribute('aria-hidden', 'true');
        });
        var status = document.createElement('span');
        status.className = 'auto-filter-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        form.appendChild(status);

        // "Clear" / "Reset" links also forget the remembered dates.
        var scope = form.closest('.card') || form.parentElement || form;
        Array.prototype.forEach.call(scope.querySelectorAll('a[href]'), function (a) {
            var u;
            try { u = new URL(a.href, window.location.href); } catch (e) { return; }
            if (u.search === '' && pathOf(u.href) === pathOf(form.action)) {
                a.addEventListener('click', function () { storeDel('localStorage', filterKey(form, i)); });
            }
        });

        var last = serialize(form);
        var timer = null;
        var holds = 0;      // >0 while a calendar of this form is open

        function rangeProblem() {
            var bad = null;
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!isDateInput(el)) return;
                var p = rangePartner(el);
                el.setCustomValidity('');
                if (p && p.role === 'end' && el.value && p.el.value && el.value < p.el.value) {
                    el.setCustomValidity('The end date must be on or after the start date.');
                    bad = el;
                }
            });
            return bad;
        }
        function submitNow() {
            clearTimeout(timer);
            if (holds > 0) return;
            var bad = rangeProblem();
            if (bad) { bad.reportValidity(); return; }
            if (!form.checkValidity()) return;
            var q = serialize(form);
            if (q === last) return;
            last = q;
            rememberFilterDates(form, i);
            var active = document.activeElement;
            if (active && active.form === form && active.name && /^(text|search|number)$/.test(active.type)) {
                storeSet('sessionStorage', 'refocus', {
                    path: HERE, name: active.name,
                    caret: typeof active.selectionStart === 'number' ? active.selectionStart : null
                });
            }
            form.classList.add('is-loading');
            form.setAttribute('aria-busy', 'true');
            status.textContent = 'Updating results…';
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        }
        function later(ms) { clearTimeout(timer); timer = setTimeout(submitNow, ms); }

        form.addEventListener('input', function (e) {
            var el = e.target;
            if (!el.name || closestAttr(el, 'data-no-auto') || e.isComposing) return;
            if (el.tagName === 'INPUT' && /^(text|search|number|email|tel)$/.test(el.type)) later(550);
        });
        form.addEventListener('change', function (e) {
            var el = e.target;
            if (!el.name || closestAttr(el, 'data-no-auto')) return;
            if (isDateInput(el)) {
                if (el.getAttribute('data-dp-picking') === '1') return;   // the calendar decides
                if (el.value && !/^(19|20)\d\d-/.test(el.value)) return;  // year still being typed
                // Typing: wait until the user pauses or leaves the field.
                later(document.activeElement === el ? 1200 : 80);
                return;
            }
            if (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'radio') later(0);
        });
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.form === form) {
                e.preventDefault();
                submitNow();
            }
        });
        form.addEventListener('search', function () { later(0); }, true);
        form.addEventListener('focusout', function (e) {
            if (!isDateInput(e.target) || !timer) return;
            var next = e.relatedTarget;
            if (next && (isDateInput(next) && next.form === form || next.closest && next.closest('.dp'))) return;
            later(60);
        });
        form.addEventListener('datepicker:open', function () { holds++; clearTimeout(timer); });
        form.addEventListener('datepicker:close', function () {
            holds = Math.max(0, holds - 1);
            later(holds ? 0 : 30);
        });
        // A filter can also be changed by a page script (tab buttons, cascades).
        form.addEventListener('submit', function () {
            rememberFilterDates(form, i);
            form.classList.add('is-loading');
        });
    }

    function restoreFocus() {
        var f = storeGet('sessionStorage', 'refocus');
        storeDel('sessionStorage', 'refocus');
        if (!f || f.path !== HERE) return;
        var el = document.querySelector('form[data-auto-filter="on"] [name="' + String(f.name).replace(/"/g, '') + '"]');
        if (!el) return;
        el.focus({ preventScroll: true });
        try {
            var pos = f.caret === null ? el.value.length : Math.min(f.caret, el.value.length);
            el.setSelectionRange(pos, pos);
        } catch (e) { /* number inputs have no caret */ }
    }

    // Dates in normal (POST) forms survive a reload until the form is sent.
    function initDraftDates(form) {
        if ((form.method || 'get').toLowerCase() !== 'post') return;
        var fields = Array.prototype.filter.call(form.elements, function (el) {
            // Disabled fields count too: many forms enable a date section only
            // after a type is chosen; the remembered date is waiting there.
            return isDateInput(el) && el.name && !el.readOnly && !closestAttr(el, 'data-no-retain');
        });
        if (!fields.length) return;
        var formIndex = Array.prototype.indexOf.call(document.forms, form);
        function key(el) { return 'draft:' + HERE + ':' + formIndex + ':' + el.name; }
        fields.forEach(function (el) {
            var saved = storeGet('sessionStorage', key(el));
            // Restore only when the server sent the same value as when the
            // draft began; anything else is newer data from the server.
            if (saved && saved.orig === el.defaultValue && saved.value !== el.value) {
                el.value = saved.value;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
            el.addEventListener('change', function () {
                if (el.value === el.defaultValue) storeDel('sessionStorage', key(el));
                else storeSet('sessionStorage', key(el), { orig: el.defaultValue, value: el.value });
            });
        });
        form.addEventListener('submit', function (e) {
            setTimeout(function () {
                if (e.defaultPrevented) return;
                fields.forEach(function (el) { storeDel('sessionStorage', key(el)); });
            }, 0);
        });
    }

    // =====================================================================
    // 4. CALENDAR
    // =====================================================================
    var dp = null;          // the single popover element
    var cur = null;         // { input, mode, view, cursor (Date), partner }

    var ICON_PREV = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var ICON_NEXT = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M9 18l6-6-6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function buildPopover() {
        if (dp) return dp;
        dp = document.createElement('div');
        dp.className = 'dp';
        dp.setAttribute('role', 'dialog');
        dp.setAttribute('aria-modal', 'false');
        dp.hidden = true;
        dp.addEventListener('mousedown', function (e) {
            // Keep focus where it is unless a day button is the target.
            if (!e.target.closest('button, select')) e.preventDefault();
        });
        dp.addEventListener('click', onPopoverClick);
        dp.addEventListener('keydown', onPopoverKey);
        dp.addEventListener('mouseover', function (e) {
            var b = e.target.closest('.dp-day');
            if (b && cur && cur.partner) paintRange(b.getAttribute('data-date'));
        });
        dp.addEventListener('mouseleave', function () { if (cur && cur.partner) paintRange(null); });
        document.body.appendChild(dp);
        document.addEventListener('mousedown', function (e) {
            if (!cur) return;
            if (dp.contains(e.target) || e.target === cur.input) return;
            close(false);
        });
        window.addEventListener('resize', function () { if (cur) place(); });
        window.addEventListener('scroll', function () { if (cur) place(); }, true);
        return dp;
    }

    function limits(input) {
        var min = input.type === 'month' ? parseMonth(input.min) : parseIso(input.min);
        var max = input.type === 'month' ? parseMonth(input.max) : parseIso(input.max);
        if (cur && cur.partner && cur.partner.role === 'end' && cur.partner.el.value) {
            var start = parseIso(cur.partner.el.value);
            if (start && (!min || start > min)) min = start;
        }
        return { min: min, max: max };
    }
    function outOfRange(d, lim, unit) {
        if (!d) return false;
        if (unit === 'month') {
            var a = d.getFullYear() * 12 + d.getMonth();
            return (lim.min && a < lim.min.getFullYear() * 12 + lim.min.getMonth())
                || (lim.max && a > lim.max.getFullYear() * 12 + lim.max.getMonth());
        }
        if (unit === 'year') {
            return (lim.min && d.getFullYear() < lim.min.getFullYear()) || (lim.max && d.getFullYear() > lim.max.getFullYear());
        }
        return (lim.min && d < lim.min) || (lim.max && d > lim.max);
    }

    function open(input) {
        if (input.disabled || input.readOnly) return;
        buildPopover();
        if (cur && cur.input === input) return;
        if (cur) close(false);
        var mode = input.type === 'month' ? 'month' : 'date';
        var value = mode === 'month' ? parseMonth(input.value) : parseIso(input.value);
        var partner = rangePartner(input);
        var focusDay = value || (partner && partner.el.value ? parseIso(partner.el.value) : null) || today();
        cur = {
            input: input, mode: mode, view: mode === 'month' ? 'months' : 'days',
            cursor: new Date(focusDay.getFullYear(), focusDay.getMonth(), focusDay.getDate()),
            partner: partner
        };
        var lbl = input.labels && input.labels[0] ? input.labels[0].textContent.replace(/[*\s]+$/g, '').trim() : '';
        dp.setAttribute('aria-label', lbl ? 'Choose ' + lbl.toLowerCase() : (mode === 'month' ? 'Choose a month' : 'Choose a date'));
        input.setAttribute('aria-expanded', 'true');
        dp.hidden = false;
        draw();
        place();
        input.dispatchEvent(new CustomEvent('datepicker:open', { bubbles: true }));
        requestAnimationFrame(function () {
            dp.classList.add('is-open');
            focusCursor();
        });
    }

    function close(returnFocus) {
        if (!cur) return;
        var input = cur.input;
        cur = null;
        dp.classList.remove('is-open');
        dp.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        if (returnFocus) input.focus({ preventScroll: true });
        input.dispatchEvent(new CustomEvent('datepicker:close', { bubbles: true }));
    }

    function place() {
        var r = cur.input.getBoundingClientRect();
        var w = dp.offsetWidth, h = dp.offsetHeight;
        var vw = document.documentElement.clientWidth, vh = window.innerHeight;
        var left = Math.min(Math.max(8, r.left), vw - w - 8);
        var top = r.bottom + 6;
        if (top + h > vh - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
        dp.style.left = left + 'px';
        dp.style.top = top + 'px';
        dp.classList.toggle('is-above', top < r.top);
    }

    function setValue(str) {
        var input = cur.input;
        var partner = cur.partner;
        input.setAttribute('data-dp-picking', '1');
        input.value = str;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.removeAttribute('data-dp-picking');
        // Picking a start date after the end date empties the end date.
        if (partner && partner.role === 'start' && str && partner.el.value && partner.el.value < str) {
            partner.el.setAttribute('data-dp-picking', '1');
            partner.el.value = '';
            partner.el.dispatchEvent(new Event('change', { bubbles: true }));
            partner.el.removeAttribute('data-dp-picking');
        }
        close(true);
        // Range: after the start date, go straight on to the end date.
        if (str && partner && partner.role === 'start' && !partner.el.value && !partner.el.disabled) {
            open(partner.el);
        }
    }

    function presetRange(start, end) {
        var input = cur.input, partner = cur.partner;
        var startEl = partner.role === 'start' ? input : partner.el;
        var endEl = partner.role === 'start' ? partner.el : input;
        [[startEl, toIso(start)], [endEl, toIso(end)]].forEach(function (pair) {
            pair[0].setAttribute('data-dp-picking', '1');
            pair[0].value = pair[1];
            pair[0].dispatchEvent(new Event('input', { bubbles: true }));
            pair[0].dispatchEvent(new Event('change', { bubbles: true }));
            pair[0].removeAttribute('data-dp-picking');
        });
        close(true);
    }

    function draw() {
        var c = cur, d = c.cursor, lim = limits(c.input), html = '';
        var t = today();
        var title, prevLabel, nextLabel;
        if (c.view === 'days') {
            title = MONTHS[d.getMonth()] + ' <span>' + d.getFullYear() + '</span>';
            prevLabel = 'Previous month'; nextLabel = 'Next month';
        } else if (c.view === 'months') {
            title = '<span>' + d.getFullYear() + '</span>';
            prevLabel = 'Previous year'; nextLabel = 'Next year';
        } else {
            var y0 = d.getFullYear() - (d.getFullYear() % 12);
            title = '<span>' + y0 + ' – ' + (y0 + 11) + '</span>';
            prevLabel = 'Earlier years'; nextLabel = 'Later years';
        }
        html += '<div class="dp-head">'
            + '<button type="button" class="dp-nav" data-act="prev" aria-label="' + prevLabel + '">' + ICON_PREV + '</button>'
            + '<button type="button" class="dp-title" data-act="zoom" aria-label="Change view">' + title + '</button>'
            + '<button type="button" class="dp-nav" data-act="next" aria-label="' + nextLabel + '">' + ICON_NEXT + '</button>'
            + '</div>';

        if (c.mode === 'date' && c.partner && c.view === 'days') {
            html += '<div class="dp-presets" role="group" aria-label="Quick ranges">'
                + '<button type="button" data-preset="cutoff">This cut-off</button>'
                + '<button type="button" data-preset="lastcutoff">Last cut-off</button>'
                + '<button type="button" data-preset="month">This month</button>'
                + '</div>';
        }

        if (c.view === 'days') {
            html += '<div class="dp-week" aria-hidden="true">';
            ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].forEach(function (w, i) {
                html += '<span' + (i === 6 ? ' class="is-rest"' : '') + '>' + w + '</span>';
            });
            html += '</div><div class="dp-grid dp-days" role="grid">';
            var first = new Date(d.getFullYear(), d.getMonth(), 1);
            var start = addDays(first, -first.getDay());
            var sel = parseIso(c.input.value);
            for (var i = 0; i < 42; i++) {
                var day = addDays(start, i);
                var cls = ['dp-day'];
                if (day.getMonth() !== d.getMonth()) cls.push('is-out');
                if (day.getDay() === 6) cls.push('is-rest');
                if (sameDay(day, t)) cls.push('is-today');
                if (sameDay(day, sel)) cls.push('is-selected');
                var off = outOfRange(day, lim, 'day');
                var label = DAYS[day.getDay()] + ', ' + MONTHS[day.getMonth()] + ' ' + day.getDate() + ', ' + day.getFullYear()
                    + (sameDay(day, t) ? ' (today)' : '') + (day.getDay() === 6 ? ' (rest day)' : '');
                html += '<button type="button" role="gridcell" class="' + cls.join(' ') + '" data-date="' + toIso(day) + '"'
                    + ' tabindex="-1" aria-label="' + label + '"' + (sameDay(day, sel) ? ' aria-selected="true"' : '')
                    + (off ? ' disabled' : '') + '>' + day.getDate() + '</button>';
            }
            html += '</div>';
        } else if (c.view === 'months') {
            html += '<div class="dp-grid dp-months">';
            var selM = c.mode === 'month' ? parseMonth(c.input.value) : parseIso(c.input.value);
            for (var m = 0; m < 12; m++) {
                var md = new Date(d.getFullYear(), m, 1);
                var mc = ['dp-cell'];
                if (t.getFullYear() === md.getFullYear() && t.getMonth() === m) mc.push('is-today');
                if (selM && selM.getFullYear() === md.getFullYear() && selM.getMonth() === m) mc.push('is-selected');
                html += '<button type="button" class="' + mc.join(' ') + '" data-month="' + m + '" tabindex="-1"'
                    + (outOfRange(md, lim, 'month') ? ' disabled' : '') + ' aria-label="' + MONTHS[m] + ' ' + md.getFullYear() + '">'
                    + MONTHS[m].slice(0, 3) + '</button>';
            }
            html += '</div>';
        } else {
            html += '<div class="dp-grid dp-years">';
            var ys = d.getFullYear() - (d.getFullYear() % 12);
            for (var y = ys; y < ys + 12; y++) {
                var yc = ['dp-cell'];
                if (y === t.getFullYear()) yc.push('is-today');
                var sv = c.input.value ? +c.input.value.slice(0, 4) : null;
                if (y === sv) yc.push('is-selected');
                html += '<button type="button" class="' + yc.join(' ') + '" data-year="' + y + '" tabindex="-1"'
                    + (outOfRange(new Date(y, 0, 1), lim, 'year') ? ' disabled' : '') + '>' + y + '</button>';
            }
            html += '</div>';
        }

        var readout;
        var v = c.mode === 'month' ? parseMonth(c.input.value) : parseIso(c.input.value);
        if (!v) readout = '<span class="dp-readout is-empty">No ' + (c.mode === 'month' ? 'month' : 'date') + ' selected</span>';
        else if (c.mode === 'month') readout = '<span class="dp-readout">' + MONTHS[v.getMonth()] + ' ' + v.getFullYear() + '</span>';
        else readout = '<span class="dp-readout"><b>' + DAYS[v.getDay()].slice(0, 3) + '</b> ' + shortUs(v) + '</span>';
        var canClear = !c.input.required;
        html += '<div class="dp-foot">' + readout + '<span class="dp-actions">'
            + (canClear ? '<button type="button" data-act="clear">Clear</button>' : '')
            + '<button type="button" data-act="today" class="dp-today"' + (outOfRange(t, lim, c.mode === 'month' ? 'month' : 'day') ? ' disabled' : '') + '>'
            + (c.mode === 'month' ? 'This month' : 'Today') + '</button></span></div>';
        if (c.mode === 'date' && c.view === 'days') {
            html += '<div class="dp-legend" aria-hidden="true"><span class="dp-key dp-key-today"></span>Today<span class="dp-key dp-key-rest"></span>Rest day (Sat)</div>';
        }
        dp.innerHTML = html;
        dp.setAttribute('data-view', c.view);
        if (c.partner) paintRange(null);
    }

    function paintRange(hoverIso) {
        if (!cur || !cur.partner || cur.view !== 'days') return;
        var mine = hoverIso || cur.input.value;
        var other = cur.partner.el.value;
        var a = cur.partner.role === 'start' ? mine : other;   // start
        var b = cur.partner.role === 'start' ? other : mine;   // end
        Array.prototype.forEach.call(dp.querySelectorAll('.dp-day'), function (btn) {
            var iso = btn.getAttribute('data-date');
            var on = a && b && a <= b && iso >= a && iso <= b;
            btn.classList.toggle('in-range', !!on);
            btn.classList.toggle('range-start', !!on && iso === a);
            btn.classList.toggle('range-end', !!on && iso === b);
            btn.classList.toggle('is-anchor', iso === other);
        });
    }

    function focusCursor() {
        if (!cur) return;
        var target;
        if (cur.view === 'days') target = dp.querySelector('.dp-day[data-date="' + toIso(cur.cursor) + '"]');
        else if (cur.view === 'months') target = dp.querySelector('[data-month="' + cur.cursor.getMonth() + '"]');
        else target = dp.querySelector('[data-year="' + cur.cursor.getFullYear() + '"]');
        if (target) { target.tabIndex = 0; target.focus({ preventScroll: true }); }
    }

    function shift(n) {
        var d = cur.cursor;
        if (cur.view === 'days') cur.cursor = new Date(d.getFullYear(), d.getMonth() + n, Math.min(d.getDate(), 28));
        else if (cur.view === 'months') cur.cursor = new Date(d.getFullYear() + n, d.getMonth(), 1);
        else cur.cursor = new Date(d.getFullYear() + 12 * n, d.getMonth(), 1);
        draw();
        focusCursor();
    }

    function onPopoverClick(e) {
        if (!cur) return;
        var b = e.target.closest('button');
        if (!b || b.disabled) return;
        var act = b.getAttribute('data-act');
        var t = today();
        if (act === 'prev') return shift(-1);
        if (act === 'next') return shift(1);
        if (act === 'zoom') {
            cur.view = cur.view === 'days' ? 'months' : (cur.view === 'months' ? 'years' : 'years');
            draw(); focusCursor(); return;
        }
        if (act === 'today') return setValue(cur.mode === 'month' ? toMonth(t) : toIso(t));
        if (act === 'clear') return setValue('');
        var preset = b.getAttribute('data-preset');
        if (preset) {
            var s;
            if (preset === 'cutoff') { s = cutoffStart(t); return presetRange(s, addDays(s, 6)); }
            if (preset === 'lastcutoff') { s = addDays(cutoffStart(t), -7); return presetRange(s, addDays(s, 6)); }
            if (preset === 'month') return presetRange(new Date(t.getFullYear(), t.getMonth(), 1), new Date(t.getFullYear(), t.getMonth() + 1, 0));
        }
        if (b.hasAttribute('data-date')) return setValue(b.getAttribute('data-date'));
        if (b.hasAttribute('data-month')) {
            var m = +b.getAttribute('data-month');
            if (cur.mode === 'month') return setValue(cur.cursor.getFullYear() + '-' + pad(m + 1));
            cur.cursor = new Date(cur.cursor.getFullYear(), m, 1);
            cur.view = 'days'; draw(); focusCursor(); return;
        }
        if (b.hasAttribute('data-year')) {
            cur.cursor = new Date(+b.getAttribute('data-year'), cur.cursor.getMonth(), 1);
            cur.view = 'months'; draw(); focusCursor();
        }
    }

    function onPopoverKey(e) {
        if (!cur) return;
        if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
        if (e.key === 'Tab') {
            // Keep Tab inside the calendar while it is open.
            var items = Array.prototype.filter.call(dp.querySelectorAll('button'), function (b) {
                return !b.disabled && (b.tabIndex === 0 || !b.matches('.dp-day, .dp-cell'));
            });
            var idx = items.indexOf(document.activeElement);
            if (idx < 0) return;
            e.preventDefault();
            items[(idx + (e.shiftKey ? -1 : 1) + items.length) % items.length].focus();
            return;
        }
        if (!e.target.matches('.dp-day, .dp-cell')) return;
        var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
        var d = cur.cursor;
        if (cur.view !== 'days' && step !== undefined) step = { '-1': -1, '1': 1, '-7': -3, '7': 3 }[String(step)];
        if (step !== undefined) {
            e.preventDefault();
            if (cur.view === 'days') cur.cursor = addDays(d, step);
            else if (cur.view === 'months') cur.cursor = new Date(d.getFullYear(), d.getMonth() + step, 1);
            else cur.cursor = new Date(d.getFullYear() + step, d.getMonth(), 1);
            var vm = cur.view === 'days' && cur.cursor.getMonth() !== d.getMonth();
            var vy = cur.view === 'months' && cur.cursor.getFullYear() !== d.getFullYear();
            var vd = cur.view === 'years' && Math.floor(cur.cursor.getFullYear() / 12) !== Math.floor(d.getFullYear() / 12);
            if (vm || vy || vd) draw();
            Array.prototype.forEach.call(dp.querySelectorAll('.dp-day, .dp-cell'), function (b) { b.tabIndex = -1; });
            focusCursor();
            if (cur.partner) paintRange(cur.view === 'days' ? toIso(cur.cursor) : null);
            return;
        }
        if (e.key === 'PageUp' || e.key === 'PageDown') { e.preventDefault(); shift(e.key === 'PageUp' ? -1 : 1); return; }
        if (e.key === 'Home' && cur.view === 'days') { e.preventDefault(); cur.cursor = addDays(d, -d.getDay()); focusCursor(); return; }
        if (e.key === 'End' && cur.view === 'days') { e.preventDefault(); cur.cursor = addDays(d, 6 - d.getDay()); focusCursor(); }
    }

    function enhanceDateInput(input) {
        if (input.getAttribute('data-dp') || closestAttr(input, 'data-native-picker')) return;
        input.setAttribute('data-dp', '1');
        input.classList.add('dp-input');
        input.setAttribute('aria-haspopup', 'dialog');
        input.setAttribute('aria-expanded', 'false');
        if (!input.title) input.title = input.type === 'month' ? 'Click to choose a month' : 'Click to open the calendar, or type the date';
        // Stops the browser's own picker; typing into the field still works.
        input.addEventListener('click', function (e) {
            if (input.disabled || input.readOnly) return;
            e.preventDefault();
            open(input);
        });
        input.addEventListener('keydown', function (e) {
            if ((e.altKey && e.key === 'ArrowDown') || e.key === 'F4' || (e.key === ' ' && input.type === 'month')) {
                e.preventDefault();
                open(input);
            } else if (e.key === 'Escape' && cur && cur.input === input) {
                close(true);
            }
        });
        input.addEventListener('input', function () {
            if (cur && cur.input === input) {
                var v = input.type === 'month' ? parseMonth(input.value) : parseIso(input.value);
                if (v) cur.cursor = v;
                draw();
            }
        });
    }

    // =====================================================================
    // 5. 24-HOUR TIME PICKER (HH:MM text fields stay text: the 24-hour rule)
    // =====================================================================
    var tp = null;          // popover
    var tpCur = null;       // { input, hour }
    var TIME_RE = /^([01]\d|2[0-3]):([0-5]\d)$/;

    function isTimeInput(el) {
        return el && el.tagName === 'INPUT' && el.type === 'text'
            && /2\[0-3\]\)?:\[0-5\]/.test(el.getAttribute('pattern') || '');
    }

    function tpBuild() {
        if (tp) return;
        tp = document.createElement('div');
        tp.className = 'tp';
        tp.hidden = true;
        tp.setAttribute('role', 'dialog');
        tp.setAttribute('aria-label', 'Choose a time (24-hour)');
        tp.addEventListener('mousedown', function (e) { e.preventDefault(); });
        tp.addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b || !tpCur) return;
            var input = tpCur.input;
            var m = TIME_RE.exec(input.value.trim());
            if (b.hasAttribute('data-hour')) {
                tpCur.hour = b.getAttribute('data-hour');
                tpSet(tpCur.hour + ':' + (m ? m[2] : '00'), false);
            } else if (b.hasAttribute('data-minute')) {
                var h = tpCur.hour || (m ? m[1] : '08');
                tpSet(h + ':' + b.getAttribute('data-minute'), true);
            } else if (b.getAttribute('data-act') === 'clear') {
                tpSet('', true);
            }
        });
        document.body.appendChild(tp);
        document.addEventListener('mousedown', function (e) {
            if (tpCur && !tp.contains(e.target) && e.target !== tpCur.input) tpClose();
        });
        window.addEventListener('resize', function () { if (tpCur) tpPlace(); });
        window.addEventListener('scroll', function () { if (tpCur) tpPlace(); }, true);
    }

    function tpSet(value, done) {
        var input = tpCur.input;
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        if (done) tpClose(); else tpDraw();
    }

    function tpDraw() {
        var m = TIME_RE.exec(tpCur.input.value.trim());
        var hour = tpCur.hour || (m ? m[1] : null);
        var minute = m ? m[2] : null;
        var html = '<div class="tp-head"><span>Hour</span><span class="tp-value">' + (m ? m[0] : '--:--') + '</span></div><div class="tp-grid tp-hours">';
        for (var h = 0; h < 24; h++) {
            var hh = pad(h);
            html += '<button type="button" data-hour="' + hh + '"' + (hh === hour ? ' class="is-selected"' : '')
                + (h < 6 || h >= 20 ? ' data-night' : '') + '>' + hh + '</button>';
        }
        html += '</div><div class="tp-head"><span>Minute</span></div><div class="tp-grid tp-minutes">';
        for (var mm = 0; mm < 60; mm += 5) {
            var ms = pad(mm);
            html += '<button type="button" data-minute="' + ms + '"' + (ms === minute ? ' class="is-selected"' : '') + '>:' + ms + '</button>';
        }
        html += '</div><div class="tp-foot"><span>Type any minute, e.g. 07:42</span>'
            + (tpCur.input.required ? '' : '<button type="button" data-act="clear">Clear</button>') + '</div>';
        tp.innerHTML = html;
    }

    function tpPlace() {
        var r = tpCur.input.getBoundingClientRect();
        var w = tp.offsetWidth, h = tp.offsetHeight;
        var vw = document.documentElement.clientWidth, vh = window.innerHeight;
        var left = Math.min(Math.max(8, r.left), vw - w - 8);
        var top = r.bottom + 6;
        if (top + h > vh - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
        tp.style.left = left + 'px';
        tp.style.top = top + 'px';
    }

    function tpOpen(input) {
        if (input.disabled || input.readOnly) return;
        tpBuild();
        if (tpCur && tpCur.input === input) return;
        tpCur = { input: input, hour: null };
        input.setAttribute('aria-expanded', 'true');
        tp.hidden = false;
        tpDraw();
        tpPlace();
    }

    function tpClose() {
        if (!tpCur) return;
        tpCur.input.setAttribute('aria-expanded', 'false');
        tpCur = null;
        tp.hidden = true;
    }

    function enhanceTimeInput(input) {
        if (input.getAttribute('data-tp') || closestAttr(input, 'data-native-picker')) return;
        input.setAttribute('data-tp', '1');
        input.classList.add('tp-input');
        input.setAttribute('aria-haspopup', 'dialog');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('autocomplete', 'off');
        if (!input.placeholder) input.placeholder = 'HH:MM';
        input.addEventListener('click', function () { tpOpen(input); });
        input.addEventListener('keydown', function (e) {
            if ((e.altKey && e.key === 'ArrowDown') || e.key === 'F4') { e.preventDefault(); tpOpen(input); }
            else if (e.key === 'Escape' || e.key === 'Tab' || e.key === 'Enter') tpClose();
        });
        input.addEventListener('input', function () {
            if (tpCur && tpCur.input === input) { tpCur.hour = null; tpDraw(); }
        });
        // "730" or "7:30" typed by hand becomes 07:30.
        input.addEventListener('blur', function () {
            var v = input.value.trim();
            var m = /^(\d{1,2})[:.]?(\d{2})$/.exec(v);
            if (m && +m[1] < 24 && +m[2] < 60) {
                var fixed = pad(+m[1]) + ':' + m[2];
                if (fixed !== v) {
                    input.value = fixed;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
    }

    // =====================================================================
    // 6. LIVE MINUTES PREVIEW (attendance Adjust / Manual entry, REQ019)
    // =====================================================================
    function initMinutesPreview(box) {
        var form = box.closest('form');
        if (!form) return;
        var tin = form.elements.namedItem('time_in');
        var tout = form.elements.namedItem('time_out');
        if (!tin || !tout) return;
        var timer = null, seq = 0;
        function set(name, text) {
            var el = box.querySelector('[data-preview="' + name + '"]');
            if (el) el.textContent = text;
        }
        function run() {
            var a = tin.value.trim(), b = tout.value.trim();
            if (!TIME_RE.test(a) || !TIME_RE.test(b)) {
                box.classList.remove('is-error');
                set('worked', '—'); set('late', '—'); set('undertime', '—');
                set('note', 'Enter both times as HH:MM to see the result.');
                return;
            }
            var my = ++seq;
            box.classList.add('is-loading');
            fetch(box.getAttribute('data-url') + '&time_in=' + encodeURIComponent(a) + '&time_out=' + encodeURIComponent(b),
                { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (my !== seq) return;
                    box.classList.remove('is-loading');
                    box.classList.toggle('is-error', !d.ok);
                    if (d.ok) {
                        set('worked', d.worked_label); set('late', d.late_label); set('undertime', d.undertime_label);
                        set('note', 'From the assigned schedule; saved values are calculated the same way.');
                    } else {
                        set('worked', '—'); set('late', '—'); set('undertime', '—');
                        set('note', d.message || 'Check the times.');
                    }
                })
                .catch(function () {
                    if (my !== seq) return;
                    box.classList.remove('is-loading');
                    set('note', 'Preview unavailable right now; the values are still calculated when you save.');
                });
        }
        function later() { clearTimeout(timer); timer = setTimeout(run, 300); }
        tin.addEventListener('input', later);
        tout.addEventListener('input', later);
        run();
    }

    // =====================================================================
    // INIT
    // =====================================================================
    function init() {
        var root = document.querySelector('.content') || document.body;
        Array.prototype.forEach.call(root.querySelectorAll('table'), enhanceTable);

        var getForms = Array.prototype.filter.call(document.querySelectorAll('.content form'), function (f) {
            return (f.getAttribute('method') || 'get').toLowerCase() === 'get';
        });
        getForms.forEach(function (f, i) { initAutoFilter(f, i); });
        restoreFocus();

        Array.prototype.forEach.call(document.forms, initDraftDates);
        Array.prototype.forEach.call(document.querySelectorAll('input[type="date"], input[type="month"]'), enhanceDateInput);
        Array.prototype.forEach.call(document.querySelectorAll('input[type="text"][pattern]'), function (el) {
            if (isTimeInput(el)) enhanceTimeInput(el);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-minutes-preview]'), initMinutesPreview);

        // Inputs and tables added later (modals, scripts) get the same treatment.
        if (window.MutationObserver) {
            var pending = false;
            new MutationObserver(function () {
                if (pending) return;
                pending = true;
                requestAnimationFrame(function () {
                    pending = false;
                    Array.prototype.forEach.call(document.querySelectorAll('input[type="date"]:not([data-dp]), input[type="month"]:not([data-dp])'), enhanceDateInput);
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    // Bring the page back to life when it is restored from the back/forward cache.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.documentElement.classList.remove('is-restoring');
        Array.prototype.forEach.call(document.querySelectorAll('form.is-loading'), function (f) {
            f.classList.remove('is-loading');
            f.removeAttribute('aria-busy');
            var s = f.querySelector('.auto-filter-status');
            if (s) s.textContent = '';
        });
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
