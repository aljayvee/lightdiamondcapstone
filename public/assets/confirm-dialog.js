/*
 * On-page confirmation for forms and submit buttons.
 *
 * Usage:  <form ... data-confirm="Approve this request?">
 *    or:  <button type="submit" data-confirm="Archive this record?">
 *
 * Replaces onsubmit/onclick="return confirm(...)". Browser pop-ups can be
 * blocked (embedded browsers, "prevent this page from creating additional
 * dialogs"), which made those buttons silently do nothing.
 *
 * Runs in the bubbling phase on document, so a form's own submit listeners
 * (e.g. inline-validate.js) run first; if one of them blocked the submit,
 * no confirmation is shown. On "Confirm" the form is re-submitted with the
 * same submit button, so its name/value still reach the server.
 *
 * Fires "confirm:cancel" on the form when the user cancels.
 */
(function () {
    'use strict';

    var backdrop, titleEl, bodyEl, okBtn, cancelBtn;
    var pending = null;      // { form, submitter }
    var lastFocus = null;
    var lastSubmitter = null; // fallback when SubmitEvent.submitter is unavailable

    function build() {
        if (backdrop) return;
        backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.id = 'appConfirm';
        backdrop.setAttribute('role', 'alertdialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-labelledby', 'appConfirmTitle');
        backdrop.setAttribute('aria-describedby', 'appConfirmBody');
        backdrop.innerHTML =
            '<div class="modal-box">' +
            '<p class="modal-title" id="appConfirmTitle">Please confirm</p>' +
            '<p class="modal-body" id="appConfirmBody" style="white-space:pre-line"></p>' +
            '<div class="modal-actions">' +
            '<button type="button" class="btn btn-secondary" data-role="cancel">Cancel</button>' +
            '<button type="button" class="btn btn-primary" data-role="ok">Confirm</button>' +
            '</div></div>';
        document.body.appendChild(backdrop);
        titleEl   = backdrop.querySelector('#appConfirmTitle');
        bodyEl    = backdrop.querySelector('#appConfirmBody');
        okBtn     = backdrop.querySelector('[data-role="ok"]');
        cancelBtn = backdrop.querySelector('[data-role="cancel"]');

        okBtn.addEventListener('click', function () { close(true); });
        cancelBtn.addEventListener('click', function () { close(false); });
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) close(false);
        });
        backdrop.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); close(false); }
            if (e.key === 'Tab') { // keep focus on the two buttons
                e.preventDefault();
                (document.activeElement === okBtn ? cancelBtn : okBtn).focus();
            }
        });
    }

    function open(message, okLabel, form, submitter) {
        build();
        pending = { form: form, submitter: submitter };
        lastFocus = document.activeElement;
        bodyEl.textContent = message;
        okBtn.textContent = okLabel || 'Confirm';
        backdrop.classList.add('show');
        okBtn.focus();
    }

    function close(confirmed) {
        if (!pending) return;
        var p = pending;
        pending = null;
        backdrop.classList.remove('show');

        if (!confirmed) {
            p.form.dispatchEvent(new CustomEvent('confirm:cancel', { bubbles: true }));
            if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
            return;
        }

        p.form.setAttribute('data-confirmed', '1');
        if (typeof p.form.requestSubmit === 'function') {
            // requestSubmit re-runs validation and submit listeners, and sends
            // the submitter's name/value like a real click.
            p.form.requestSubmit(p.submitter || undefined);
        } else {
            if (p.submitter && p.submitter.name) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = p.submitter.name;
                h.value = p.submitter.value;
                p.form.appendChild(h);
            }
            p.form.submit();
        }
    }

    // Remember which submit button was clicked (old browsers lack e.submitter).
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button, input[type=submit]') : null;
        if (b && b.form && b.type === 'submit') {
            lastSubmitter = b;
        }
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        var submitter = e.submitter || (lastSubmitter && lastSubmitter.form === form ? lastSubmitter : null);
        lastSubmitter = null;

        var message = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
        if (!message) return;

        if (form.getAttribute('data-confirmed') === '1') {
            form.removeAttribute('data-confirmed');
            return; // already confirmed: let it go
        }
        if (e.defaultPrevented) return; // blocked by validation or another handler

        e.preventDefault();
        var okLabel = (submitter && submitter.getAttribute('data-confirm-ok')) || form.getAttribute('data-confirm-ok');
        open(message, okLabel, form, submitter);
    });
})();
