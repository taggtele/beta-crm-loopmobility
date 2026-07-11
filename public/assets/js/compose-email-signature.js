/**
 * Outlook-style per-user email signature for emails/logs.php compose modal.
 * Loaded only when compose is available; signature HTML comes from page JSON.
 */
/* eslint-env browser */
/* global window, document */
(function () {
    var SIGNATURE_ATTR = 'data-crm-email-signature';
    var SIGNATURE_VALUE = '1';

    function isSignatureAlreadyInjected(emailBody) {
        var html = String(emailBody || '');
        return html.indexOf(SIGNATURE_ATTR + '="' + SIGNATURE_VALUE + '"') !== -1
            || html.indexOf(SIGNATURE_ATTR + "='" + SIGNATURE_VALUE + "'") !== -1;
    }

    function buildSignatureBlock(signatureHtml) {
        return (
            '<div ' +
            SIGNATURE_ATTR +
            '="' +
            SIGNATURE_VALUE +
            '" style="margin-top:16px;padding-top:8px;border-top:1px solid #e2e8f0;">' +
            String(signatureHtml || '') +
            '</div>'
        );
    }

    function appendSignatureToEmailBody(emailBody, signatureHtml) {
        if (!signatureHtml || !String(signatureHtml).trim()) {
            return emailBody;
        }
        if (isSignatureAlreadyInjected(emailBody)) {
            return emailBody;
        }
        var block = buildSignatureBlock(signatureHtml);
        var body = String(emailBody || '').trim();
        if (body === '' || body === '<p><br></p>' || body === '<br>') {
            return '<p><br></p>' + block;
        }
        return body + '<p><br></p>' + block;
    }

    function syncToHiddenIfAvailable() {
        if (window.composeRichEditor && typeof window.composeRichEditor.syncToHidden === 'function') {
            window.composeRichEditor.syncToHidden();
        }
    }

    function applyToComposeEditor(attempt) {
        var sig = window.composeUserSignature;
        if (!sig || !sig.html) {
            return;
        }

        var editor = document.getElementById('compose-body-html');
        if (!editor || !window.composeRichEditor || typeof window.composeRichEditor.setBody !== 'function') {
            if ((attempt || 0) < 50) {
                requestAnimationFrame(function () {
                    applyToComposeEditor((attempt || 0) + 1);
                });
            }
            return;
        }

        var currentHtml = editor.innerHTML;
        if (isSignatureAlreadyInjected(currentHtml)) {
            return;
        }

        var quoteWrap = editor.querySelector('[data-compose-quote-wrap]');
        var block = buildSignatureBlock(sig.html);
        if (quoteWrap) {
            quoteWrap.insertAdjacentHTML('beforebegin', block);
            syncToHiddenIfAvailable();
        } else {
            var nextHtml = appendSignatureToEmailBody(currentHtml, sig.html);
            window.composeRichEditor.setBody(nextHtml);
        }

        if (!isSignatureAlreadyInjected(editor.innerHTML) && (attempt || 0) < 50) {
            requestAnimationFrame(function () {
                applyToComposeEditor((attempt || 0) + 1);
            });
        }
    }

    window.composeEmailSignature = {
        isSignatureAlreadyInjected: isSignatureAlreadyInjected,
        appendSignatureToEmailBody: appendSignatureToEmailBody,
        applyToComposeEditor: applyToComposeEditor,
    };
})();
