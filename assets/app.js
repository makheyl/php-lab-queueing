// assets/app.js
//
// Shared behavior for the staff screens (index.php, claiming.php,
// extraction.php). A plain script, no modules or build step: pages load it
// with a normal <script src> tag and call LabUI.* from their own inline
// scripts.
//
//   - LabUI.startPolling(): the queue_status.php poller. It reloads the page
//     when the queue changes, but never while someone is typing or a pop-up
//     is open (that would throw their work away). It reloads as soon as they
//     are done. It also keeps polling after a failed request.
//   - Pop-ups: .modal-overlay elements opened with [data-open-dialog="id"] or
//     LabUI.openDialog(id). They close on [data-close-dialog], Escape, or a
//     click on the dark background.
//   - Keyboard shortcuts: any visible, enabled button with data-key="N" is
//     clicked when N is pressed. Ignored while typing or while a pop-up is open.
//   - Messages marked data-autohide fade out after a few seconds.
(function() {
    function isTextField(el) {
        if (!el) return false;
        const tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    function openOverlay() {
        return document.querySelector('.modal-overlay.open');
    }

    // Reloading now would lose something the person is in the middle of.
    function isBusy() {
        if (openOverlay()) return true;
        const el = document.activeElement;
        return isTextField(el) && typeof el.value === 'string' && el.value.trim() !== '';
    }

    function startPolling(options) {
        const interval = (options && options.interval) || 3000;
        const holdWhile = (options && options.holdWhile) || function() { return false; };
        let last = null;
        let reloadPending = false;

        async function tick() {
            try {
                const res = await fetch('queue_status.php', { cache: 'no-store' });
                if (res.ok) {
                    const text = JSON.stringify(await res.json());
                    if (last === null) {
                        last = text;
                    } else if (text !== last) {
                        reloadPending = true;
                    }
                }
            } catch (e) {}
            if (reloadPending && !isBusy() && !holdWhile()) {
                location.reload();
                return;
            }
            setTimeout(tick, interval);
        }
        tick();
    }

    function openDialog(id) {
        const overlay = document.getElementById(id);
        if (!overlay) return;
        overlay.classList.add('open');
        const target = overlay.querySelector('[autofocus]') || overlay.querySelector('input:not([type=hidden]), textarea, .btn');
        if (target) target.focus();
    }

    function closeDialog(overlay) {
        if (overlay) overlay.classList.remove('open');
    }

    document.addEventListener('click', function(e) {
        const opener = e.target.closest('[data-open-dialog]');
        if (opener && !opener.disabled) {
            openDialog(opener.getAttribute('data-open-dialog'));
            return;
        }
        if (e.target.closest('[data-close-dialog]')) {
            closeDialog(e.target.closest('.modal-overlay'));
            return;
        }
        if (e.target.classList && e.target.classList.contains('modal-overlay')) {
            closeDialog(e.target);
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDialog(openOverlay());
            return;
        }
        if (e.altKey || e.ctrlKey || e.metaKey) return;
        if (isTextField(document.activeElement) || openOverlay()) return;
        const key = e.key.length === 1 ? e.key.toUpperCase() : '';
        if (!/^[A-Z]$/.test(key)) return;
        const btn = document.querySelector('[data-key="' + key + '"]');
        if (btn && !btn.disabled && btn.offsetParent !== null) {
            e.preventDefault();
            btn.click();
        }
    });

    // data-autohide="10000" keeps a message up longer than the default 6 s.
    function autohide(el) {
        const ms = parseInt(el.getAttribute('data-autohide'), 10) || 6000;
        setTimeout(function() {
            el.classList.add('fading');
            setTimeout(function() { el.remove(); }, 700);
        }, ms);
    }

    function fadeMessages() {
        document.querySelectorAll('[data-autohide]').forEach(autohide);
    }

    // Pop-up notification in the top-right corner (see .toast in theme.css).
    // kind: 'success' (default) or 'warning'.
    function toast(title, message, kind, ms) {
        let stack = document.querySelector('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        const el = document.createElement('div');
        el.className = 'toast' + (kind === 'warning' ? ' toast-warning' : '');
        el.setAttribute('role', 'status');
        el.setAttribute('data-autohide', String(ms || 6000));
        const icon = document.createElement('span');
        icon.className = 'toast-icon';
        icon.textContent = kind === 'warning' ? '!' : '✓';
        const body = document.createElement('div');
        const strong = document.createElement('span');
        strong.className = 'toast-title';
        strong.textContent = title;
        body.appendChild(strong);
        if (message) body.appendChild(document.createTextNode(message));
        el.appendChild(icon);
        el.appendChild(body);
        stack.appendChild(el);
        autohide(el);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fadeMessages);
    } else {
        fadeMessages();
    }

    window.LabUI = { startPolling: startPolling, openDialog: openDialog, closeDialog: closeDialog, isBusy: isBusy, toast: toast };
})();
