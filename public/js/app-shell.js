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

function toggleSidebar() {
    var sidebar = document.getElementById('mainSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!sidebar || !overlay) return;

    var isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('show', isOpen);
    document.body.style.overflow = isOpen ? 'hidden' : '';
}

function closeSidebar() {
    var sidebar = document.getElementById('mainSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!sidebar || !overlay) return;

    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
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
            if (window.innerWidth <= 992) {
                closeSidebar();
            }
        });
    });

    // If the window is resized back to desktop while the mobile
    // sidebar is open, reset it so it doesn't stay stuck open.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 992) {
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
