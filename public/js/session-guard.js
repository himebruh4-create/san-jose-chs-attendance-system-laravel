/* =========================================================
   ONE DEVICE PER ACCOUNT (partials/session-guard)
   Every 10 seconds, and when the tab comes back into view, the page
   asks GET /session/status whether this browser is still the
   account's active session (App\Support\ActiveSessions). When another
   device signed in, the prompt opens with the server's countdown.
   It cannot be closed with Esc or a click outside:
     Stay logged in -> POST /session/stay
     Log out        -> the normal logout form
     no answer      -> POST /session/timeout
   The server enforces all of this on its own; this only shows it.
   ========================================================= */
(function () {
    var CHECK_EVERY_MS = 10000;

    var guard = document.getElementById('sessionGuard');
    if (!guard) return;

    var bodyText = document.getElementById('sessionGuardBody');
    var countdownText = document.getElementById('sessionGuardCountdown');
    var stayButton = document.getElementById('sessionGuardStay');
    var logoutButton = document.getElementById('sessionGuardLogout');
    // The page shown to a replaced session has nothing behind the prompt.
    var isReplacedPage = guard.dataset.checkNow === '1';

    var deadline = null;
    var ticker = null;
    var checking = false;
    var answering = false;

    function isOpen() {
        return !guard.hidden;
    }

    function goToLogin(url) {
        answering = true;
        window.location.href = url || appUrl('login');
    }

    /* Status 401: the server has signed this session out. */
    function followSignOut(response) {
        return response.json()
            .catch(function () { return {}; })
            .then(function (data) { goToLogin(data.redirect); });
    }

    function check() {
        if (checking || answering) return;
        checking = true;

        fetch(appUrl('session/status'), { cache: 'no-store' })
            .then(function (response) {
                if (response.status === 401) return followSignOut(response);
                if (!response.ok) return;

                return response.json().then(function (data) {
                    if (data.status === 'replaced') {
                        open(data);
                    } else if (data.status === 'active') {
                        close();
                    }
                });
            })
            .catch(function () {})
            .then(function () { checking = false; });
    }

    function open(data) {
        bodyText.textContent = data.message || '';
        deadline = Date.now() + Math.max(0, Number(data.seconds_left) || 0) * 1000;
        render();

        if (isOpen()) return;

        guard.hidden = false;
        lockBodyScroll();
        setButtonsDisabled(false);
        stayButton.focus();
        ticker = setInterval(render, 250);
    }

    function close() {
        if (!isOpen()) return;

        clearInterval(ticker);
        ticker = null;
        deadline = null;
        guard.hidden = true;
        unlockBodyScroll();

        if (isReplacedPage) window.location.reload();
    }

    function render() {
        if (deadline === null) return;

        var seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        countdownText.textContent = 'You will be logged out in ' + seconds + (seconds === 1 ? ' second.' : ' seconds.');

        if (seconds === 0) timeOut();
    }

    function setButtonsDisabled(disabled) {
        stayButton.disabled = disabled;
        logoutButton.disabled = disabled;
    }

    /* POST an answer; a 401 means this session is signed out. */
    function answer(path, onActive) {
        if (answering) return;
        answering = true;
        setButtonsDisabled(true);

        fetch(appUrl(path), { method: 'POST' })
            .then(function (response) {
                if (response.status === 401) return followSignOut(response);

                return response.json().then(function (data) {
                    answering = false;
                    if (data.status === 'active') {
                        onActive();
                    } else {
                        goToLogin();
                    }
                });
            })
            .catch(function () {
                answering = false;
                setButtonsDisabled(false);
            });
    }

    function stay() {
        answer('session/stay', close);
    }

    /* The countdown ended. Another tab may have chosen Stay logged in
       already, in which case the server answers "active". */
    function timeOut() {
        clearInterval(ticker);
        ticker = null;
        answer('session/timeout', close);
    }

    function logOut() {
        if (answering) return;
        answering = true;
        setButtonsDisabled(true);
        clearInterval(ticker);
        document.getElementById('sessionGuardLogoutForm').submit();
    }

    stayButton.addEventListener('click', stay);
    logoutButton.addEventListener('click', logOut);

    // Esc does not close it, and Tab stays on its two buttons. Capture
    // phase, so the page's own Esc handlers (other modals, the sidebar)
    // never see the key while the prompt is open.
    document.addEventListener('keydown', function (event) {
        if (!isOpen()) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
        } else if (event.key === 'Tab') {
            event.preventDefault();
            (document.activeElement === stayButton ? logoutButton : stayButton).focus();
        }
    }, true);

    // Other requests that find this session replaced (409) check at once.
    window.checkSessionNow = check;

    setInterval(check, CHECK_EVERY_MS);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') check();
    });

    if (isReplacedPage) check();
})();
