/**
 * Rich HTML compose editor for emails/logs.php compose modal only.
 * Syncs contenteditable → hidden input (name=body) for FormData POST.
 */
/* eslint-env browser */
/* global window, document */
(function () {
    var editor;
    var hidden;
    var toolbar;

    var MAX_INLINE_IMAGE_SOURCE_BYTES = 25 * 1024 * 1024;
    var INLINE_IMAGE_TARGET_BYTES = 700 * 1024;
    var INLINE_IMAGE_MAX_DIMENSION = 1200;
    var INLINE_IMAGE_SIZE_MSG = 'Image is too large to paste inline. Please attach it as a file.';
    var INLINE_IMAGE_TYPE_MSG = 'Unsupported image format. Use PNG, JPEG, or WEBP.';
    var ALLOWED_STYLE_PROPS = {
        'background': 1,
        'background-color': 1,
        'border': 1,
        'border-bottom': 1,
        'border-left': 1,
        'border-right': 1,
        'border-top': 1,
        'border-color': 1,
        'border-collapse': 1,
        'border-spacing': 1,
        'border-style': 1,
        'border-width': 1,
        'color': 1,
        'caption-side': 1,
        'font-family': 1,
        'font-size': 1,
        'font-style': 1,
        'font-weight': 1,
        'height': 1,
        'line-height': 1,
        'margin': 1,
        'margin-bottom': 1,
        'margin-left': 1,
        'margin-right': 1,
        'margin-top': 1,
        'padding': 1,
        'padding-bottom': 1,
        'padding-left': 1,
        'padding-right': 1,
        'padding-top': 1,
        'table-layout': 1,
        'text-align': 1,
        'text-decoration-color': 1,
        'text-decoration-style': 1,
        'text-decoration': 1,
        'vertical-align': 1,
        'white-space': 1,
        'width': 1,
        'max-width': 1,
        'min-width': 1
    };

    function syncToHidden() {
        if (!editor || !hidden) {
            return;
        }
        hidden.value = editor.innerHTML.trim() === '' || editor.innerHTML === '<br>' ? '' : editor.innerHTML;
    }

    function looksLikeHtmlFragment(s) {
        var t = String(s || '').trim();
        return t.length > 0 && /^</.test(t) && /<\/[a-z][\s\S]*>/i.test(t);
    }

    function setBody(content) {
        if (!editor) {
            return;
        }
        var raw = content != null ? String(content) : '';
        if (raw === '') {
            editor.innerHTML = '<p><br></p>';
            syncToHidden();
            return;
        }
        if (looksLikeHtmlFragment(raw)) {
            editor.innerHTML = raw;
        } else {
            var pre = document.createElement('pre');
            pre.style.whiteSpace = 'pre-wrap';
            pre.style.fontFamily = 'inherit';
            pre.style.margin = '0';
            pre.textContent = raw;
            editor.innerHTML = '';
            editor.appendChild(pre);
        }
        syncToHidden();
    }

    function getPlainTextForChecks() {
        if (!editor) {
            return (hidden && hidden.value) || '';
        }
        return editor.innerText || editor.textContent || '';
    }

    function isEditorEmptyHtml(html) {
        var trimmed = String(html || '').trim();
        return trimmed === '' || trimmed === '<br>' || trimmed === '<p><br></p>';
    }

    function buildTextBlock(text) {
        var block = document.createElement('div');
        block.setAttribute('data-crm-message-template', '1');
        block.style.cssText = 'margin:0 0 12px;';

        String(text || '').replace(/\r\n/g, '\n').split('\n').forEach(function (line, index) {
            if (index > 0) {
                block.appendChild(document.createElement('br'));
            }
            block.appendChild(document.createTextNode(line));
        });

        return block;
    }

    function exec(cmd, value) {
        if (!editor) {
            return;
        }
        editor.focus();
        try {
            document.execCommand(cmd, false, value);
        } catch (e) {
            /* ignore */
        }
        syncToHidden();
    }

    function bindToolbar() {
        if (!toolbar) {
            return;
        }
        toolbar.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-cmd]');
            if (!btn || !toolbar.contains(btn)) {
                return;
            }
            ev.preventDefault();
            var cmd = btn.getAttribute('data-cmd');
            if (cmd === 'createLink') {
                var url = window.prompt('Link URL (https://...)', 'https://');
                if (url) {
                    exec('createLink', url);
                }
                return;
            }
            if (cmd === 'removeFormat') {
                exec('removeFormat');
                exec('unlink');
                return;
            }
            if (cmd) {
                exec(cmd, null);
            }
        });
    }

    function extractPasteHtml(fragment) {
        if (!fragment) {
            return '';
        }
        var m = fragment.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
        if (m && m[1]) {
            return m[1];
        }
        return fragment;
    }

    function sanitizePastedHtml(fragment) {
        var wrapper = document.createElement('div');
        wrapper.innerHTML = extractPasteHtml(fragment);

        Array.prototype.forEach.call(wrapper.querySelectorAll('script,style,meta,link,title,xml,iframe,object,embed,svg,canvas,video,audio,source'), function (node) {
            node.remove();
        });

        Array.prototype.forEach.call(wrapper.querySelectorAll('*'), function (el) {
            if (el.tagName === 'IMG') {
                el.remove();
                return;
            }

            var styleText = el.getAttribute('style') || '';
            if (styleText) {
                var cleanStyle = sanitizeInlineStyle(styleText);
                if (cleanStyle) {
                    el.setAttribute('style', cleanStyle);
                } else {
                    el.removeAttribute('style');
                }
            }

            if (el.tagName === 'TABLE') {
                ensureTablePresentation(el);
            }

            Array.prototype.slice.call(el.attributes).forEach(function (attr) {
                var name = attr.name.toLowerCase();
                var value = attr.value;
                if (name.indexOf('on') === 0 || name === 'class' || name === 'xmlns') {
                    el.removeAttribute(attr.name);
                    return;
                }

                if (name === 'href') {
                    if (!/^(https?:|mailto:|\/|#)/i.test(value || '')) {
                        el.removeAttribute(attr.name);
                    }
                    return;
                }

                if (name === 'rowspan' || name === 'colspan') {
                    if (!/^\d+$/.test(value || '')) {
                        el.removeAttribute(attr.name);
                    }
                    return;
                }

                if (name === 'width' || name === 'height') {
                    if (!/^\d+(\.\d+)?(px|em|rem|%|pt)?$/i.test(value || '')) {
                        el.removeAttribute(attr.name);
                    }
                    return;
                }

                if (name === 'title' || name === 'target' || name === 'rel' || name === 'aria-label') {
                    return;
                }

                if (name === 'style') {
                    return;
                }

                if (name === 'cellpadding' || name === 'cellspacing' || name === 'border' || name === 'bgcolor' || name === 'align' || name === 'valign') {
                    el.removeAttribute(attr.name);
                    return;
                }

                if (!el.hasAttribute(attr.name)) {
                    return;
                }
            });
        });

        return wrapper.innerHTML.trim();
    }

    function ensureTablePresentation(table) {
        if (!table || !table.style) {
            return;
        }
        if (!table.style.borderCollapse) {
            table.style.borderCollapse = 'collapse';
        }
        if (!table.style.maxWidth) {
            table.style.maxWidth = '100%';
        }
        if (!table.style.width) {
            table.style.width = 'auto';
        }
    }

    function sanitizeInlineStyle(styleText) {
        var out = [];
        String(styleText || '').split(';').forEach(function (chunk) {
            var pair = chunk.split(':');
            if (pair.length < 2) {
                return;
            }
            var prop = pair.shift().trim().toLowerCase();
            var value = pair.join(':').trim();
            if (!ALLOWED_STYLE_PROPS[prop]) {
                return;
            }
            if (!value || /url\s*\(/i.test(value) || /expression\s*\(/i.test(value) || /javascript:/i.test(value) || /data:/i.test(value)) {
                return;
            }
            out.push(prop + ':' + value);
        });
        return out.join(';');
    }

    function isAllowedImageFile(file) {
        if (!file) {
            return false;
        }
        var type = (file.type || '').toLowerCase();
        if (type === 'image/png' || type === 'image/jpeg' || type === 'image/jpg' || type === 'image/webp') {
            return true;
        }
        var name = (file.name || '').toLowerCase();
        if (name) {
            var ext = name.split('.').pop();
            return ext === 'png' || ext === 'jpg' || ext === 'jpeg' || ext === 'webp';
        }
        return type.indexOf('image/') === 0;
    }

    function showInlineImageError(message) {
        window.alert(message || INLINE_IMAGE_SIZE_MSG);
    }

    function setEditorBusy(busy) {
        if (!editor) {
            return;
        }
        if (busy) {
            editor.classList.add('compose-editor--busy');
            editor.setAttribute('aria-busy', 'true');
        } else {
            editor.classList.remove('compose-editor--busy');
            editor.removeAttribute('aria-busy');
        }
    }

    function buildInlineImgHtml(dataUrl) {
        return (
            '<img src="' +
            String(dataUrl).replace(/"/g, '&quot;') +
            '" alt="" style="max-width:100%;height:auto;display:block;margin:6px 0;">'
        );
    }

    function insertImageDataUrl(dataUrl) {
        if (!editor) {
            return;
        }
        editor.focus();
        document.execCommand('insertHTML', false, buildInlineImgHtml(dataUrl));
        syncToHidden();
    }

    function dataUrlSize(dataUrl) {
        var raw = String(dataUrl || '');
        var comma = raw.indexOf(',');
        var b64 = comma >= 0 ? raw.slice(comma + 1) : raw;
        return Math.floor((b64.length * 3) / 4);
    }

    function compressImageDataUrl(file, dataUrl, done) {
        if (!file || !/^data:image\//i.test(String(dataUrl || ''))) {
            done(dataUrl);
            return;
        }

        var img = new Image();
        img.onload = function () {
            try {
                var scale = Math.min(1, INLINE_IMAGE_MAX_DIMENSION / Math.max(img.width || 1, img.height || 1));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round((img.width || 1) * scale));
                canvas.height = Math.max(1, Math.round((img.height || 1) * scale));
                var ctx = canvas.getContext('2d');
                if (!ctx) {
                    done(dataUrl);
                    return;
                }
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

                var best = dataUrl;
                [0.78, 0.68, 0.58, 0.48, 0.38].some(function (quality) {
                    var candidate = canvas.toDataURL('image/jpeg', quality);
                    if (candidate && dataUrlSize(candidate) < dataUrlSize(best)) {
                        best = candidate;
                    }
                    return dataUrlSize(best) <= INLINE_IMAGE_TARGET_BYTES;
                });
                done(best);
            } catch (err) {
                done(dataUrl);
            }
        };
        img.onerror = function () {
            done(dataUrl);
        };
        img.src = dataUrl;
    }

    function insertImageFromFile(file) {
        if (!editor || !file) {
            return;
        }
        if (!isAllowedImageFile(file)) {
            showInlineImageError(INLINE_IMAGE_TYPE_MSG);
            return;
        }
        if (file.size > MAX_INLINE_IMAGE_SOURCE_BYTES) {
            showInlineImageError(INLINE_IMAGE_SIZE_MSG);
            return;
        }

        setEditorBusy(true);
        var reader = new FileReader();
        reader.onload = function () {
            var src = reader.result;
            if (typeof src === 'string') {
                compressImageDataUrl(file, src, function (compressedSrc) {
                    setEditorBusy(false);
                    insertImageDataUrl(compressedSrc);
                });
            } else {
                setEditorBusy(false);
            }
        };
        reader.onerror = function () {
            setEditorBusy(false);
            showInlineImageError('Could not read image.');
        };
        reader.readAsDataURL(file);
    }

    function collectClipboardImageFiles(cd) {
        var out = [];
        if (!cd) {
            return out;
        }
        if (cd.files && cd.files.length) {
            for (var i = 0; i < cd.files.length; i++) {
                var cf = cd.files[i];
                if (cf && isAllowedImageFile(cf)) {
                    out.push(cf);
                }
            }
        }
        var items = cd.items;
        if (items && items.length) {
            for (var j = 0; j < items.length; j++) {
                if (!items[j].type || items[j].type.indexOf('image/') !== 0) {
                    continue;
                }
                var f = items[j].getAsFile();
                if (f && isAllowedImageFile(f)) {
                    var dup = false;
                    for (var k = 0; k < out.length; k++) {
                        if (out[k] === f || (out[k].size === f.size && out[k].name === f.name)) {
                            dup = true;
                            break;
                        }
                    }
                    if (!dup) {
                        out.push(f);
                    }
                }
            }
        }
        return out;
    }

    function onPaste(ev) {
        if (!editor) {
            return;
        }
        var cd = ev.clipboardData;
        if (!cd) {
            return;
        }

        var html = cd.getData('text/html');
        if (html && html.trim() !== '') {
            ev.preventDefault();
            var cleaned = sanitizePastedHtml(html);
            if (cleaned) {
                try {
                    document.execCommand('insertHTML', false, cleaned);
                } catch (e2) {
                    editor.focus();
                    document.execCommand('insertHTML', false, cleaned);
                }
                syncToHidden();
            }
            return;
        }

        var imageFiles = collectClipboardImageFiles(cd);
        if (imageFiles.length) {
            ev.preventDefault();
            imageFiles.forEach(function (file) {
                insertImageFromFile(file);
            });
        }
    }

    function onDrop(ev) {
        if (!editor) {
            return;
        }
        var dt = ev.dataTransfer;
        if (!dt || !dt.files || !dt.files.length) {
            return;
        }

        var imageFiles = [];
        for (var i = 0; i < dt.files.length; i++) {
            var f = dt.files[i];
            if (f && isAllowedImageFile(f)) {
                imageFiles.push(f);
            }
        }

        if (!imageFiles.length) {
            return;
        }

        ev.preventDefault();
        imageFiles.forEach(function (file) {
            insertImageFromFile(file);
        });
    }

    function init() {
        editor = document.getElementById('compose-body-html');
        hidden = document.getElementById('compose-body');
        toolbar = document.getElementById('compose-editor-toolbar');
        if (!editor || !hidden) {
            return;
        }

        if (hidden.value && editor.innerHTML.trim() === '') {
            setBody(hidden.value);
        } else if (editor.innerHTML.trim() === '') {
            editor.innerHTML = '<p><br></p>';
        }

        bindToolbar();

        ['input', 'blur'].forEach(function (evt) {
            editor.addEventListener(evt, syncToHidden);
        });
        editor.addEventListener('paste', onPaste);
        editor.addEventListener('dragover', function (e) {
            e.preventDefault();
        });
        editor.addEventListener('drop', onDrop);

        syncToHidden();

        if (window.composeEmailSignature && typeof window.composeEmailSignature.applyToComposeEditor === 'function') {
            window.composeEmailSignature.applyToComposeEditor();
        }
    }

    function setBodyWithQuote(userPlain, quotedPlain, kind) {
        if (!editor) {
            return;
        }
        kind = kind || 'reply';
        editor.innerHTML = '';

        var firstP = document.createElement('p');
        var up = String(userPlain || '').trim();
        if (up) {
            firstP.textContent = up;
        } else {
            firstP.innerHTML = '<br>';
        }
        editor.appendChild(firstP);

        var q = String(quotedPlain || '').replace(/\r\n/g, '\n');
        if (q.trim() === '') {
            syncToHidden();
            placeCaretInEditor(firstP);
            return;
        }

        var bq = document.createElement('blockquote');
        bq.setAttribute('data-compose-quote', '1');
        bq.style.cssText =
            'margin:0;padding:10px 12px 12px;border-left:3px solid #94a3b8;background:#f8fafc;color:#334155;font-size:13px;line-height:1.5;border-radius:0 6px 6px 0;';
        q.split('\n').forEach(function (line) {
            var div = document.createElement('div');
            div.textContent = line;
            div.style.wordBreak = 'break-word';
            bq.appendChild(div);
        });

        if (kind === 'reply') {
            var wrap = document.createElement('div');
            wrap.setAttribute('data-compose-quote-wrap', '1');
            wrap.setAttribute('contenteditable', 'false');
            wrap.className = 'compose-quote-wrap';

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'compose-quote-toggle';
            toggle.setAttribute('data-compose-quote-toggle', '1');
            toggle.textContent = 'Show previous conversation';
            toggle.setAttribute('aria-expanded', 'false');

            var panel = document.createElement('div');
            panel.className = 'compose-quote-panel';
            panel.setAttribute('data-compose-quote-panel', '1');
            panel.hidden = true;
            panel.appendChild(bq);

            toggle.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var open = panel.hidden;
                panel.hidden = !open;
                toggle.textContent = open ? 'Hide previous conversation' : 'Show previous conversation';
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                syncToHidden();
            });

            wrap.appendChild(toggle);
            wrap.appendChild(panel);
            editor.appendChild(wrap);
        } else {
            editor.appendChild(bq);
        }

        placeCaretInEditor(firstP);
        syncToHidden();
    }

    function prependBody(content) {
        if (!editor) {
            return;
        }

        var raw = content != null ? String(content) : '';
        if (raw.trim() === '') {
            return;
        }

        var block = buildTextBlock(raw);
        editor.focus();

        if (isEditorEmptyHtml(editor.innerHTML)) {
            editor.innerHTML = '';
            editor.appendChild(block);
        } else {
            editor.insertBefore(block, editor.firstChild);
        }

        syncToHidden();
    }

    function placeCaretInEditor(startNode) {
        if (!editor || !startNode) {
            return;
        }
        editor.focus();
        var sel = window.getSelection();
        var range = document.createRange();
        if (startNode.firstChild && startNode.firstChild.nodeType === 3) {
            var tn = startNode.firstChild;
            range.setStart(tn, tn.textContent.length);
            range.collapse(true);
        } else {
            range.setStart(startNode, 0);
            range.collapse(true);
        }
        sel.removeAllRanges();
        sel.addRange(range);
    }

    window.composeRichEditor = {
        setBody: setBody,
        setBodyWithQuote: setBodyWithQuote,
        prependBody: prependBody,
        syncToHidden: syncToHidden,
        getPlainTextForChecks: getPlainTextForChecks,
        focus: function () {
            if (editor) {
                editor.focus();
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
