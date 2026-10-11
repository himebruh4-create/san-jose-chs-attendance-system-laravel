/* =========================================================
   APP SHELL — shared by every signed-in page.
   The helpers below were the inline script of the native-PHP
   includes/sidebar.php; appUrl() and the fetch wrapper are new.
   ========================================================= */

/* Absolute URL of an application path, whether the app is served
   from the domain root or from a sub-folder (http://server/attendance). */
function appUrl(path) {
    var meta = document.querySelector('meta[name="app-base"]');
    var base = meta ? meta.content.replace(/\/+$/, '') : '';
    return base + '/' + String(path || '').replace(/^\/+/, '');
}

/* Every same-origin request carries the CSRF token and asks for JSON, so
   pages keep calling fetch() exactly as before and Laravel's CSRF
   protection still applies to every POST. */
(function () {
    var nativeFetch = window.fetch.bind(window);

    window.fetch = function (input, init) {
        init = init || {};

        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var sameOrigin = !/^https?:\/\//i.test(url) || url.indexOf(window.location.origin) === 0;

        if (sameOrigin) {
            var headers = new Headers(init.headers || (typeof input !== 'string' && input.headers) || {});
            var token = document.querySelector('meta[name="csrf-token"]');

            if (token && !headers.has('X-CSRF-TOKEN')) headers.set('X-CSRF-TOKEN', token.content);
            if (!headers.has('Accept')) headers.set('Accept', 'application/json');
            if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'XMLHttpRequest');

            init.headers = headers;
            if (!init.credentials) init.credentials = 'same-origin';
        }

        return nativeFetch(input, init);
    };
})();

/* =========================================================
   SHARED PRINT HELPER
   Prints HTML content from a hidden same-origin iframe instead
   of opening a new tab/window.
   ========================================================= */
function printHTMLDocument(htmlString) {
    var iframe = document.getElementById('__printFrame');
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.id = '__printFrame';
        iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
        document.body.appendChild(iframe);
    }

    // Some print content manages its own print timing (e.g. waiting
    // for badge photos/barcodes to finish loading via its own
    // `window.onload = ...`) — in that case let it call window.print()
    // itself instead of us triggering it immediately on iframe load.
    var managesOwnPrint = /window\.onload\s*=/i.test(htmlString);

    iframe.onload = managesOwnPrint ? null : function () {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    };

    var doc = iframe.contentWindow.document;
    doc.open();
    doc.write(htmlString.replace(/<body([^>]*)\sonload="[^"]*"/i, '<body$1'));
    doc.close();
}

/* =========================================================
   SHARED: MODAL BACKGROUND SCROLL LOCK
   Counter-based so that if a second modal opens while one is
   already open, closing the second one doesn't prematurely unlock
   the background while the first modal is still up.
   ========================================================= */
var __modalLockCount = 0;

function lockBodyScroll() {
    __modalLockCount++;
    document.body.classList.add('modal-open');
}

function unlockBodyScroll() {
    __modalLockCount = Math.max(0, __modalLockCount - 1);
    if (__modalLockCount === 0) {
        document.body.classList.remove('modal-open');
    }
}

/* Below 1024px the sidebar is a drawer behind the hamburger — the same
   breakpoint as app-shell.css. */
var SIDEBAR_DRAWER_QUERY = '(max-width: 1023.98px)';

function isSidebarDrawer() {
    return window.matchMedia(SIDEBAR_DRAWER_QUERY).matches;
}

function setHamburgerExpanded(isOpen) {
    var button = document.querySelector('.hamburger-btn');
    if (button) button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}

function toggleSidebar() {
    var sidebar = document.getElementById('mainSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!sidebar || !overlay) return;

    var isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('show', isOpen);
    document.body.style.overflow = isOpen ? 'hidden' : '';
    setHamburgerExpanded(isOpen);
}

function closeSidebar() {
    var sidebar = document.getElementById('mainSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!sidebar || !overlay) return;

    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
    setHamburgerExpanded(false);
}

/* =========================================================
   SHARED MESSAGE MODAL
   Use instead of the browser's alert(): showMessageModal(text) or
   showMessageModal(text, { title: 'Something Went Wrong', type: 'error' }).
   Self-contained (builds its own markup and styles) so it works on
   every page. Closes with OK, Escape, or a click outside the box.
   ========================================================= */
function showMessageModal(message, options) {
    options = options || {};
    var isError = options.type === 'error';

    var old = document.getElementById('appMessageModal');
    if (old) { old.remove(); unlockBodyScroll(); }

    var wrap = document.createElement('div');
    wrap.id = 'appMessageModal';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.55); display:flex; ' +
        'justify-content:center; align-items:center; z-index:100000; padding:20px;';

    var box = document.createElement('div');
    box.style.cssText = 'background:#fffdf8; width:420px; max-width:100%; border-radius:14px; padding:24px; ' +
        'box-shadow:0 10px 35px rgba(0,0,0,0.3); font-family:inherit;';

    var title = document.createElement('h3');
    title.textContent = options.title || (isError ? 'Something Went Wrong' : 'Notice');
    title.style.cssText = 'margin:0 0 10px; font-size:18px; color:' + (isError ? '#c62828' : '#14390f') + ';';

    var text = document.createElement('p');
    text.textContent = message || '';
    text.style.cssText = 'margin:0 0 20px; color:#5c5238; font-size:14px; line-height:1.5; white-space:pre-line;';

    var actions = document.createElement('div');
    actions.style.cssText = 'text-align:right;';

    var ok = document.createElement('button');
    ok.type = 'button';
    ok.textContent = 'OK';
    ok.style.cssText = 'border:none; padding:10px 24px; border-radius:8px; cursor:pointer; font-size:14px; ' +
        'font-weight:bold; color:#fff; background:#14390f;';

    function close() {
        document.removeEventListener('keydown', onKey);
        wrap.remove();
        unlockBodyScroll();
    }
    function onKey(event) {
        if (event.key === 'Escape' || event.key === 'Enter') { event.preventDefault(); close(); }
    }

    ok.addEventListener('click', close);
    wrap.addEventListener('click', function (event) { if (event.target === wrap) { close(); } });
    document.addEventListener('keydown', onKey);

    actions.appendChild(ok);
    box.appendChild(title);
    box.appendChild(text);
    box.appendChild(actions);
    wrap.appendChild(box);
    document.body.appendChild(wrap);
    lockBodyScroll();
    ok.focus();
}

/* Escapes text for safe insertion into innerHTML templates. */
function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

document.addEventListener('DOMContentLoaded', function () {
    // Auto-close the mobile sidebar when a nav link is tapped
    document.querySelectorAll('.sidebar-menu a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (isSidebarDrawer()) {
                closeSidebar();
            }
        });
    });

    // If the window is resized back to desktop while the mobile
    // sidebar is open, reset it so it doesn't stay stuck open.
    window.addEventListener('resize', function () {
        if (!isSidebarDrawer()) {
            closeSidebar();
        }
    });

    // Escape closes the open drawer, like tapping the overlay.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && document.querySelector('.sidebar.open')) {
            closeSidebar();
        }
    });

    // Logout is a POST (with CSRF) so another site cannot log people out.
    var logoutLink = document.getElementById('sidebarLogoutLink');
    if (logoutLink) {
        logoutLink.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('sidebarLogoutForm').submit();
        });
    }

    if (document.body.dataset.role === 'superadmin') {
        initPendingReviewBadge();
    }
});

/* =========================================================
   SHARED: AUTO-CAPITALIZE WORDS WHILE TYPING
   Opt-in only — add data-capitalize="words" to an <input>.
   ========================================================= */
document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('input[data-capitalize="words"]')) {
        return;
    }
    var start = input.selectionStart;
    var end = input.selectionEnd;
    input.value = input.value.replace(/(^|\s)\S/g, function (c) { return c.toUpperCase(); });
    if (start !== null && end !== null) {
        input.setSelectionRange(start, end);
    }
});

/* =========================================================
   SUPER ADMIN: PERSISTENT PENDING REVIEW BADGE
   Shows the number of unresolved Pending Review items (all review
   periods) for as long as any exist. The dashboard reminder can be
   dismissed, but this badge never is.
   ========================================================= */
function initPendingReviewBadge() {
    var badge = document.getElementById('sidebarPendingBadge');
    if (!badge) return;

    var CACHE_KEY = 'pendingReviewCount';
    var CACHE_MS = 60 * 1000;

    function paint(total) {
        total = Number(total) || 0;
        badge.textContent = total > 99 ? '99+' : total;
        badge.style.display = total > 0 ? 'inline-block' : 'none';
    }

    // Lets a page that just loaded/resolved items update the badge at once.
    window.setPendingReviewBadge = function (total) {
        paint(total);
        try {
            sessionStorage.setItem(CACHE_KEY, JSON.stringify({ total: Number(total) || 0, at: Date.now() }));
        } catch (e) {}
    };

    var cached = null;
    try { cached = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null'); } catch (e) {}

    if (cached && typeof cached.total === 'number') {
        paint(cached.total);
        if (Date.now() - cached.at < CACHE_MS) return;
    }

    fetch(appUrl('data/review-summary'))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) window.setPendingReviewBadge(data.total);
        })
        .catch(function () {});
}
