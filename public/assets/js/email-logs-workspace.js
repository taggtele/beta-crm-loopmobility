/**
 * Email Logs split-pane workspace (emails/logs.php only).
 * Call window.initEmailLogsWorkspace(openComposeFn) after openCompose is defined.
 */
/* eslint-env browser */
/* global window, document, localStorage, console, ResizeObserver */
(function () {
    var emailLogsContext = {};

    function emailLogsGetItem(store, idx) {
        if (store == null || idx === '' || idx === null || typeof idx === 'undefined') {
            return null;
        }
        var key = String(idx);
        if (Array.isArray(store)) {
            return store[idx] || null;
        }
        if (typeof store === 'object') {
            return store[idx] || store[key] || null;
        }

        return null;
    }

    function emailLogsItemsList(store) {
        if (Array.isArray(store)) {
            return store;
        }
        if (store && typeof store === 'object') {
            return Object.keys(store).map(function (k) {
                return store[k];
            });
        }

        return [];
    }

    function emailLogsItemsCount(store) {
        return emailLogsItemsList(store).length;
    }

    function emailLogsLoadReadIds() {
        try {
            var raw = localStorage.getItem('email_logs_read_ids_v1');
            var parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function emailLogsPersistReadIds(ids) {
        try {
            localStorage.setItem('email_logs_read_ids_v1', JSON.stringify(ids.slice(-800)));
        } catch (e) {
            /* ignore quota / private mode */
        }
    }

    function emailLogsMarkReadId(id) {
        var ids = emailLogsLoadReadIds();
        if (ids.indexOf(id) === -1) {
            ids.push(id);
            emailLogsPersistReadIds(ids);
        }
    }

    function emailLogsIsUnread(id) {
        return emailLogsLoadReadIds().indexOf(id) === -1;
    }

    function emailLogsFirstEmailFromHeader(header) {
        if (!header) {
            return '';
        }
        var re = /([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g;
        var m = re.exec(String(header));
        return m ? m[1] : '';
    }

    function emailLogsSplitRecipients(headerVal) {
        if (!headerVal) {
            return [];
        }
        return String(headerVal)
            .split(/[,;]+/)
            .map(function (s) {
                return s.trim();
            })
            .filter(Boolean);
    }

    function emailLogsUniqueEmails(list, excludeLower) {
        var out = [];
        var seen = {};
        excludeLower = (excludeLower || '').toLowerCase();
        list.forEach(function (raw) {
            var addr = emailLogsFirstEmailFromHeader(raw) || (raw.indexOf('@') !== -1 ? raw.trim() : '');
            addr = addr.replace(/[<>]/g, '').trim();
            if (!addr || addr.indexOf('@') === -1) {
                return;
            }
            var low = addr.toLowerCase();
            if (low === excludeLower) {
                return;
            }
            if (seen[low]) {
                return;
            }
            seen[low] = true;
            out.push(addr);
        });
        return out;
    }

    function emailLogsStripRe(subject) {
        var s = String(subject || '');
        while (/^\s*re:\s*/i.test(s)) {
            s = s.replace(/^\s*re:\s*/i, '');
        }
        return s.trim();
    }

    function emailLogsStripFw(subject) {
        var s = String(subject || '');
        while (/^\s*(fw|fwd):\s*/i.test(s)) {
            s = s.replace(/^\s*(fw|fwd):\s*/i, '');
        }
        return s.trim();
    }

    function emailLogsQuotePlain(text) {
        var t = String(text || '').replace(/\r\n/g, '\n');
        return t
            .split('\n')
            .map(function (line) {
                return '> ' + line;
            })
            .join('\n');
    }

    function emailLogsEscHtmlText(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function emailLogsEscAttr(value) {
        return emailLogsEscHtmlText(value);
    }

    function emailLogsFormatFileSize(bytes) {
        var n = parseInt(bytes, 10) || 0;
        if (n < 1024) {
            return n + ' B';
        }
        if (n < 1024 * 1024) {
            return (n / 1024).toFixed(n >= 10240 ? 0 : 1) + ' KB';
        }

        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function emailLogsFetchAttachments(item) {
        if (!item || !item.attachments_lazy || !item.attachments_url) {
            return Promise.resolve(item && item.attachments ? item.attachments : []);
        }
        if (item.attachments && item.attachments.length) {
            return Promise.resolve(item.attachments);
        }
        var fetchUrl = emailLogsResolvePreviewAssetUrl(item.attachments_url);
        return fetch(fetchUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Attachments HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                var list = data && Array.isArray(data.attachments) ? data.attachments : [];
                item.attachments = list;
                item.attachments_lazy = 0;
                return list;
            })
            .catch(function () {
                return [];
            });
    }

    function emailLogsRenderAttachmentsPanel(item, containerEl) {
        if (!containerEl) {
            return;
        }
        var list = item && Array.isArray(item.attachments) ? item.attachments : [];
        if (!list.length) {
            containerEl.classList.add('is-hidden');
            containerEl.innerHTML = '';
            return;
        }

        containerEl.classList.remove('is-hidden');
        var itemsHtml = list
            .map(function (att) {
                var name = String(att.name || 'attachment');
                var url = String(att.url || '').trim();
                var sizeLabel = att.size ? emailLogsFormatFileSize(att.size) : '';
                var href = url ? emailLogsResolvePreviewAssetUrl(url) : '';
                var shortName = name;
                if (shortName.length > 42) {
                    shortName = shortName.slice(0, 39) + '…';
                }
                var label = emailLogsEscHtmlText(shortName);
                var titleAttr = emailLogsEscAttr(name);
                var nameHtml =
                    href !== ''
                        ? '<a class="elw-attach-chip-link" href="' +
                          emailLogsEscAttr(href) +
                          '" target="_blank" rel="noopener noreferrer" download="' +
                          titleAttr +
                          '" title="' +
                          titleAttr +
                          '">' +
                          label +
                          '</a>'
                        : '<span class="elw-attach-chip-link" title="' + titleAttr + '">' + label + '</span>';

                return (
                    '<li class="elw-attach-chip">' +
                    '<span class="elw-attach-chip-icon" aria-hidden="true"></span>' +
                    nameHtml +
                    (sizeLabel
                        ? '<span class="elw-attach-chip-size">' + emailLogsEscHtmlText(sizeLabel) + '</span>'
                        : '') +
                    '</li>'
                );
            })
            .join('');

        containerEl.innerHTML =
            '<div class="elw-attach-bar">' +
            '<span class="elw-attach-label">' +
            emailLogsEscHtmlText('Attachments (' + list.length + ')') +
            '</span>' +
            '<ul class="elw-attach-list">' +
            itemsHtml +
            '</ul></div>';
    }

    function emailLogsCcRawForItem(item) {
        if (!item) {
            return '';
        }
        if (item.direction === 'incoming') {
            return String(item.parsed_cc || '').trim();
        }

        return String(item.cc_email || item.parsed_cc || '').trim();
    }

    function emailLogsThreadSenderLabel(msg) {
        if (msg.direction === 'incoming') {
            return msg.from_email || msg.list_sender || 'Unknown sender';
        }
        var to = msg.to_email || '';
        return to ? 'To: ' + to : msg.list_sender || 'Outgoing';
    }

    function emailLogsItemPreviewUrl(item) {
        if (!item) {
            return '';
        }
        if (item.preview_body_url) {
            return item.preview_body_url;
        }
        if (!item.log_id || !item.direction) {
            return '';
        }
        var base = String(emailLogsContext.logsPageUrl || 'emails/logs.php');
        var join = base.indexOf('?') >= 0 ? '&' : '?';

        return emailLogsResolvePreviewAssetUrl(
            base +
                join +
                'action=preview_body&direction=' +
                encodeURIComponent(item.direction) +
                '&log_id=' +
                encodeURIComponent(String(item.log_id))
        );
    }

    function emailLogsPreviewHtmlIsUnsafe(html) {
        var h = String(html || '');
        if (!h) {
            return false;
        }
        if (/\/storage\/email_outbox_assets\/\d+\//i.test(h) && !/data:image\//i.test(h)) {
            return h.length > 120000;
        }

        return /data:image\//i.test(h) || (h.length > 120000 && /<img\b/i.test(h));
    }

    function emailLogsPreviewHasPermanentAssets(html) {
        return /\/storage\/email_(?:outbox|inbox)_assets\/\d+\//i.test(String(html || ''));
    }

    function emailLogsItemNeedsLazyPreview(item) {
        if (!item) {
            return false;
        }
        if (item.preview_lazy) {
            return true;
        }
        if (item.has_inline_images) {
            return true;
        }
        if ((parseInt(item.raw_message_bytes, 10) || 0) > 0) {
            var cached = (item.preview_html || '').trim();
            if (!emailLogsPreviewHasVisibleContent(cached)) {
                return true;
            }
        }
        var html = (item.preview_html || '').trim();
        if (html) {
            if (emailLogsPreviewHasPermanentAssets(html) && !/data:image\//i.test(html)) {
                return false;
            }
            if (/action=inline_img/i.test(html)) {
                return true;
            }
            if (!emailLogsPreviewHtmlIsUnsafe(html)) {
                return false;
            }

            return true;
        }

        return /<img\b/i.test(item.body_plain || '') || /data:image\//i.test(item.body_plain || '');
    }

    function emailLogsItemShouldFetchPreview(item, html) {
        if (!item) {
            return false;
        }
        html = (html || '').trim();
        if (!emailLogsItemPreviewUrl(item)) {
            return false;
        }
        if (!html) {
            return emailLogsItemNeedsLazyPreview(item);
        }

        return emailLogsPreviewHtmlIsUnsafe(html) || emailLogsItemNeedsLazyPreview(item);
    }

    function emailLogsPreviewHasVisibleContent(html) {
        var h = String(html || '').trim();
        if (!h) {
            return false;
        }
        if (/<img\b/i.test(h) || /action=inline_img/i.test(h) || /<[a-z][\s>]/i.test(h)) {
            return true;
        }

        return h.replace(/<[^>]+>/g, '').replace(/\s+/g, '').length > 0;
    }

    var emailLogsPreviewBlobUrls = [];

    function emailLogsRevokePreviewBlobs() {
        emailLogsPreviewBlobUrls.forEach(function (blobUrl) {
            try {
                URL.revokeObjectURL(blobUrl);
            } catch (revokeErr) {
                /* ignore */
            }
        });
        emailLogsPreviewBlobUrls = [];
    }

    function emailLogsResolvePreviewAssetUrl(src) {
        var raw = String(src || '').trim();
        if (!raw) {
            return '';
        }
        try {
            return new URL(raw, window.location.href).href;
        } catch (urlErr) {
            if (raw.charAt(0) === '/') {
                return window.location.origin + raw;
            }

            return raw;
        }
    }

    function emailLogsStripLazyImageHints(html) {
        return String(html || '')
            .replace(/\sloading\s*=\s*["']?\s*lazy\s*["']?/gi, '')
            .replace(/\sfetchpriority\s*=\s*["']?\s*low\s*["']?/gi, '');
    }

    function emailLogsHydratePreviewInlineImages(html) {
        var input = emailLogsStripLazyImageHints(html);
        if (!input || !/inline_img/i.test(input) || emailLogsPreviewHasPermanentAssets(input)) {
            return Promise.resolve(input);
        }

        var doc = new DOMParser().parseFromString(input, 'text/html');
        var imgs = doc.querySelectorAll('img[src]');
        var tasks = [];
        Array.prototype.forEach.call(imgs, function (img) {
            var src = img.getAttribute('src') || '';
            if (!/inline_img/i.test(src)) {
                return;
            }
            var absolute = emailLogsResolvePreviewAssetUrl(src);
            tasks.push(
                fetch(absolute, { credentials: 'same-origin' })
                    .then(function (res) {
                        if (!res.ok) {
                            throw new Error('Image HTTP ' + res.status);
                        }
                        return res.blob();
                    })
                    .then(function (blob) {
                        var blobUrl = URL.createObjectURL(blob);
                        emailLogsPreviewBlobUrls.push(blobUrl);
                        img.src = blobUrl;
                    })
                    .catch(function () {
                        img.setAttribute('alt', 'Image could not be loaded');
                    })
            );
        });
        if (!tasks.length) {
            return Promise.resolve(input);
        }
        return Promise.allSettled(tasks).then(function () {
            if (/<html[\s>]/i.test(input)) {
                return '<!DOCTYPE html>\n' + doc.documentElement.outerHTML;
            }

            return doc.body.innerHTML;
        });
    }

    function emailLogsFetchPreviewBody(url, signal) {
        var fetchUrl = emailLogsResolvePreviewAssetUrl(url);
        var fetchOpts = { credentials: 'same-origin', headers: { Accept: 'text/html' } };
        if (signal) {
            fetchOpts.signal = signal;
        }
        return fetch(fetchUrl, fetchOpts).then(function (res) {
            if (!res.ok) {
                throw new Error('Preview failed (' + res.status + ')');
            }
            return res.text().then(function (text) {
                var body = String(text || '').trim();
                if (!body || /^not found\.?$/i.test(body)) {
                    throw new Error('Preview response was empty');
                }
                if (emailLogsPreviewHtmlIsUnsafe(body) && !/inline_img/i.test(body)) {
                    console.warn('[Email logs] Preview contains large inline base64; attempting render anyway.');
                }

                return body;
            });
        });
    }

    function emailLogsResolveThreadPreviews(thread, signal) {
        return Promise.all(
            thread.map(function (msg) {
                var existing = (msg.preview_html || '').trim();
                if (existing && !emailLogsItemShouldFetchPreview(msg, existing)) {
                    return Promise.resolve(msg);
                }
                var previewUrl = emailLogsItemPreviewUrl(msg);
                if (!previewUrl) {
                    return Promise.resolve(msg);
                }
                return emailLogsFetchPreviewBody(previewUrl, signal)
                    .then(function (html) {
                        msg.preview_html = html;
                        return msg;
                    })
                    .catch(function (fetchErr) {
                        console.warn('[Email logs] Thread preview fetch failed', fetchErr);
                        return msg;
                    });
            })
        );
    }

    function emailLogsThreadMessageBodyHtml(msg) {
        var html = msg.preview_html || '';
        if (html) {
            return html;
        }
        return (
            '<pre class="email-logs-plain-body">' +
            emailLogsLinkifyEscaped(msg.body_plain || '') +
            '</pre>'
        );
    }

    function emailLogsBuildThreadIframeDoc(thread, selectedId) {
        var scrollRoot = 'd' + 'iv';
        var sections = thread
            .map(function (msg) {
                var msgId = String(msg.id || '').replace(/[^a-zA-Z0-9_-]/g, '_');
                var selectedClass = msg.id === selectedId ? ' is-selected' : '';
                var isSystem = !!msg.is_system_auto;
                var dirClass = isSystem
                    ? 'elw-thread-msg--system'
                    : msg.direction === 'outgoing'
                      ? 'elw-thread-msg--out'
                      : 'elw-thread-msg--in';
                var dirLabel = isSystem
                    ? 'System'
                    : msg.direction === 'outgoing'
                      ? 'Sent'
                      : 'Received';
                var subj = msg.subject
                    ? '<span class="elw-thread-msg__subject">' + emailLogsEscHtmlText(msg.subject) + '</span>'
                    : '';
                var ccRaw = emailLogsCcRawForItem(msg);
                var ccLine = '';
                if (ccRaw) {
                    ccLine =
                        '<span class="elw-thread-msg__cc" title="' +
                        emailLogsEscAttr(ccRaw) +
                        '">CC: ' +
                        emailLogsEscHtmlText(ccRaw) +
                        '</span>';
                }
                var bodyHtml = emailLogsThreadMessageBodyHtml(msg);
                var bodyBlock = isSystem
                    ? '<' +
                      scrollRoot +
                      ' class="elw-thread-msg__body elw-thread-msg__body--system">' +
                      '<p class="elw-system-notice">Automated notification (part of this reply thread)</p>' +
                      '<button type="button" class="elw-system-expand" data-elw-expand>Show message</button>' +
                      '<' +
                      scrollRoot +
                      ' class="elw-system-full">' +
                      bodyHtml +
                      '</' +
                      scrollRoot +
                      '>' +
                      '</' +
                      scrollRoot +
                      '>'
                    : '<' + scrollRoot + ' class="elw-thread-msg__body">' + bodyHtml + '</' + scrollRoot + '>';
                return (
                    '<section class="elw-thread-msg ' +
                    dirClass +
                    selectedClass +
                    '" id="elw-msg-' +
                    msgId +
                    '" data-msg-id="' +
                    emailLogsEscHtmlText(msg.id || '') +
                    '">' +
                    '<header class="elw-thread-msg__head">' +
                    '<span class="elw-thread-msg__from">' +
                    emailLogsEscHtmlText(emailLogsThreadSenderLabel(msg)) +
                    '</span>' +
                    subj +
                    ccLine +
                    '<span class="elw-thread-msg__badge">' +
                    dirLabel +
                    '</span>' +
                    '<time class="elw-thread-msg__time" datetime="' +
                    emailLogsEscHtmlText(msg.sort_at || '') +
                    '">' +
                    emailLogsEscHtmlText(msg.time_full || msg.time_short || '') +
                    '</time>' +
                    '</header>' +
                    bodyBlock +
                    '</section>'
                );
            })
            .join('');

        return emailLogsBuildIframeDoc(sections, {
            scrollPane: true,
            collapseQuotes: false,
            scrollToSelected: true,
        });
    }

    function emailLogsBuildIframeDoc(htmlFragment, opts) {
        opts = opts || {};
        var scrollPane = !!opts.scrollPane;
        var collapseQuotes = opts.collapseQuotes !== false;
        var scrollToSelected = !!opts.scrollToSelected;

        var enhance = '(function(){';
        if (collapseQuotes) {
            enhance +=
                'try{var bqs=document.querySelectorAll("blockquote");bqs.forEach(function(bq){' +
                'if(!bq||bq.dataset.elwQuoteHandled||bq.textContent.length<140)return;' +
                'bq.dataset.elwQuoteHandled="1";bq.style.display="none";' +
                'var btn=document.createElement("button");btn.type="button";btn.className="elw-quote-toggle";' +
                'btn.textContent="\u2026 Show trimmed content";' +
                'btn.addEventListener("click",function(){' +
                'var hidden=bq.style.display==="none";bq.style.display=hidden?"":"none";' +
                'btn.textContent=hidden?"\u2212 Hide trimmed content":"\u2026 Show trimmed content";' +
                'reportH();});' +
                'bq.parentNode.insertBefore(btn,bq);});}catch(e){}';
        }
        if (!scrollPane) {
            enhance +=
                'function reportH(){try{var h=Math.max(document.documentElement.scrollHeight,document.body.scrollHeight);' +
                'parent.postMessage({type:"email-logs-iframe-height",height:h},"*");}catch(e){}}' +
                'window.addEventListener("load",reportH);' +
                'try{new ResizeObserver(reportH).observe(document.body);}catch(e){}' +
                'document.querySelectorAll("img").forEach(function(im){' +
                'im.addEventListener("load",reportH);im.addEventListener("error",reportH);});' +
                'setTimeout(reportH,250);setTimeout(reportH,1200);setTimeout(reportH,3000);';
        }
        if (scrollToSelected) {
            enhance +=
                'function scrollSel(){try{var t=document.querySelector(".elw-thread-msg.is-selected");' +
                'if(t){t.scrollIntoView({block:"start",behavior:"auto"});}}catch(e){}}' +
                'window.addEventListener("load",scrollSel);setTimeout(scrollSel,120);setTimeout(scrollSel,600);';
        }
        if (scrollPane) {
            enhance +=
                'try{document.querySelectorAll("[data-elw-expand]").forEach(function(btn){' +
                'btn.addEventListener("click",function(){' +
                'var wrap=btn.closest(".elw-thread-msg__body--system");if(!wrap)return;' +
                'var full=wrap.querySelector(".elw-system-full");if(!full)return;' +
                'var open=full.classList.toggle("is-open");' +
                'btn.textContent=open?"Hide message":"Show message";});});}catch(e){}';
        }
        enhance += '})();';

        var styles =
            'html,body{margin:0;padding:0;background:#fff;}' +
            'body{font-family:"Segoe UI",Roboto,Helvetica,Arial,sans-serif;' +
            'font-size:15px;line-height:1.62;color:#0f172a;word-wrap:break-word;overflow-wrap:anywhere;}' +
            '.elw-email-doc,.elw-email-body{width:100%;max-width:100%;}' +
            '.elw-plain-body{white-space:normal;}' +
            '.elw-inline-asset{margin:12px 0;}' +
            '.elw-inline-asset img{max-width:100% !important;height:auto !important;border:0;display:block !important;' +
            'visibility:visible !important;opacity:1 !important;}' +
            'img{max-width:100% !important;height:auto !important;border:0;display:inline-block !important;' +
            'visibility:visible !important;opacity:1 !important;vertical-align:middle;}' +
            'img[loading]{loading:eager !important;}' +
            'table{max-width:100%;border-collapse:collapse;}' +
            '.elw-email-body table{width:auto;max-width:100%;}' +
            'td,th{vertical-align:top;}' +
            'a{color:#1d4ed8;text-decoration:underline;}' +
            'a:hover{text-decoration:none;}' +
            'pre{white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere;font-family:inherit;}' +
            'blockquote{margin:8px 0;padding:6px 12px;border-left:3px solid #cbd5e1;color:#475569;background:#f8fafc;}' +
            '.elw-quote-toggle{display:inline-block;margin:6px 0;padding:3px 12px;border:1px solid #cbd5e1;' +
            'border-radius:6px;background:#f1f5f9;color:#334155;font:inherit;font-size:12px;cursor:pointer;}' +
            '.elw-quote-toggle:hover{background:#e2e8f0;border-color:#94a3b8;}';

        if (scrollPane) {
            styles +=
                'html,body{height:100%;overflow:hidden;}' +
                '#elw-scroll-root{box-sizing:border-box;height:100%;overflow-y:auto;overflow-x:hidden;' +
                '-webkit-overflow-scrolling:touch;}' +
                '.elw-thread-msg{border-bottom:1px solid #e2e8f0;background:#fff;}' +
                '.elw-thread-msg.is-selected{box-shadow:inset 3px 0 0 #2563eb;background:#f8fafc;}' +
                '.elw-thread-msg__head{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:12px 18px;' +
                'background:#f1f5f9;border-bottom:1px solid #e2e8f0;font-size:13px;}' +
                '.elw-thread-msg__from{flex:1 1 auto;min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
                '.elw-thread-msg__subject{flex:1 1 100%;font-size:11px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
                '.elw-thread-msg__cc{flex:1 1 100%;font-size:11px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
                '.elw-thread-msg__badge{flex:0 0 auto;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;text-transform:uppercase;}' +
                '.elw-thread-msg--in .elw-thread-msg__badge{background:#e0f2fe;color:#0369a1;}' +
                '.elw-thread-msg--out .elw-thread-msg__badge{background:#ecfdf5;color:#047857;}' +
                '.elw-thread-msg--system .elw-thread-msg__head{background:#fffbeb;border-bottom-color:#fde68a;}' +
                '.elw-thread-msg--system .elw-thread-msg__badge{background:#fef3c7;color:#92400e;}' +
                '.elw-thread-msg__time{flex:0 0 auto;margin-left:auto;color:#64748b;font-size:11px;white-space:nowrap;}' +
                '.elw-thread-msg__body{padding:18px 22px 22px;}' +
                '.elw-thread-msg__body--system{padding:10px 14px 12px;background:#fffbeb;}' +
                '.elw-system-notice{margin:0 0 8px;font-size:12px;color:#92400e;font-weight:600;}' +
                '.elw-system-expand{display:inline-block;padding:3px 10px;border:1px solid #fcd34d;border-radius:6px;' +
                'background:#fff;color:#92400e;font:inherit;font-size:12px;cursor:pointer;}' +
                '.elw-system-full{display:none;margin-top:10px;padding-top:10px;border-top:1px dashed #fde68a;}' +
                '.elw-system-full.is-open{display:block;}';
        } else {
            styles += 'body{padding:20px 24px 32px;}';
        }

        var bodyInner = scrollPane
            ? '<div id="elw-scroll-root">' + (htmlFragment || '') + '</div>'
            : htmlFragment || '';

        var baseHref = String(emailLogsContext.publicBaseUrl || '').trim();
        if (baseHref && baseHref.slice(-1) !== '/') {
            baseHref += '/';
        }

        return (
            '<!DOCTYPE html><html><head><meta charset="utf-8">' +
            (baseHref ? '<base href="' + emailLogsEscAttr(baseHref) + '">' : '') +
            '<style>' +
            styles +
            '</style></head><body>' +
            bodyInner +
            '<script>' +
            enhance +
            '</' + 'script>' +
            '</body></html>'
        );
    }

    function emailLogsLinkifyEscaped(text) {
        var esc = String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        esc = esc.replace(/\b(https?:\/\/[^\s<]+)/g, function (match) {
            var trail = '';
            while (match.length > 0 && /[.,;:!?)\]}>]/.test(match.charAt(match.length - 1))) {
                trail = match.charAt(match.length - 1) + trail;
                match = match.slice(0, -1);
            }
            if (!match) {
                return trail;
            }
            return '<a href="' + match + '" target="_blank" rel="noopener noreferrer">' + match + '</a>' + trail;
        });

        esc = esc.replace(/\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/g, function (match) {
            return '<a href="mailto:' + match + '">' + match + '</a>';
        });

        return esc;
    }

    window.initEmailLogsWorkspace = function (openComposeFn) {
        if (typeof openComposeFn !== 'function') {
            openComposeFn = function () {};
        }

        var emailLogsItems = [];
        emailLogsContext = {};
        try {
            var elItems = document.getElementById('email-logs-items-json');
            var elCtx = document.getElementById('email-logs-context-json');
            if (elItems && elItems.textContent) {
                emailLogsItems = JSON.parse(elItems.textContent);
            }
            if (elCtx && elCtx.textContent) {
                emailLogsContext = JSON.parse(elCtx.textContent);
            }
        } catch (parseErr) {
            emailLogsItems = [];
            emailLogsContext = {};
        }

        var wsRoot = document.getElementById('email-logs-workspace');
        if (!wsRoot) {
            return;
        }

        var workspace = { selectedIndex: -1, selectedItem: null, previewFetchAbort: null };

        function emailLogsBeginPreviewFetch() {
            if (workspace.previewFetchAbort) {
                try {
                    workspace.previewFetchAbort.abort();
                } catch (abortErr) {
                    /* ignore */
                }
            }
            workspace.previewFetchAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;
            return workspace.previewFetchAbort;
        }
        var wsList = document.getElementById('email-logs-list-scroll');
        var rowEls = wsList ? wsList.querySelectorAll('.email-logs-row') : [];
        if (rowEls.length > 0 && emailLogsItemsCount(emailLogsItems) !== rowEls.length) {
            console.warn(
                '[Email logs] Message data (' +
                    emailLogsItemsCount(emailLogsItems) +
                    ') does not match list rows (' +
                    rowEls.length +
                    '). Selection index may be wrong.'
            );
        }
        var wsResizer = document.getElementById('email-logs-resizer');
        var wsPaneList = document.getElementById('email-logs-pane-list');
        var btnReply = document.getElementById('email-logs-btn-reply');
        var btnForward = document.getElementById('email-logs-btn-forward');
        var ph = document.getElementById('email-logs-preview-placeholder');
        var content = document.getElementById('email-logs-preview-content');
        var iframe = document.getElementById('email-logs-preview-iframe');
        var frameWrap = document.getElementById('email-logs-preview-frame-wrap');
        var plainEl = document.getElementById('email-logs-preview-plain');
        var conversationEl = document.getElementById('email-logs-conversation');
        var previewSingleEl = document.getElementById('email-logs-preview-single');
        var errEl = document.getElementById('email-logs-preview-error');
        var subjEl = document.getElementById('email-logs-preview-subject');
        var partEl = document.getElementById('email-logs-preview-participants');
        var metaStrip = document.getElementById('email-logs-preview-meta-strip');
        var previewChrome = document.getElementById('email-logs-preview-chrome');
        var previewDetails = document.getElementById('email-logs-preview-details');
        var toggleDetailsBtn = document.getElementById('email-logs-toggle-details');
        var collapsedLineEl = document.getElementById('email-logs-preview-collapsed-line');
        var previewTimeEl = document.getElementById('email-logs-preview-time');
        var bottomActions = document.getElementById('email-logs-preview-actions-bottom');
        var mapWrap = document.getElementById('email-logs-map-wrap');
        var toolbarMeta = document.getElementById('email-logs-preview-toolbar-meta');
        var previewBody = document.getElementById('email-logs-preview-body');
        var attachmentsEl = document.getElementById('email-logs-preview-attachments');
        var previewDetailsExpanded = true;

        try {
            var storedDetails = sessionStorage.getItem('email_logs_details_expanded');
            previewDetailsExpanded = storedDetails === null ? false : storedDetails !== '0';
        } catch (detailsStoreErr) {
            previewDetailsExpanded = false;
        }

        function emailLogsApplyPreviewDetailsExpanded() {
            if (!previewChrome || !previewDetails || !toggleDetailsBtn) {
                return;
            }
            previewChrome.classList.toggle('is-collapsed', !previewDetailsExpanded);
            previewDetails.classList.toggle('is-hidden', !previewDetailsExpanded);
            if (collapsedLineEl) {
                collapsedLineEl.classList.toggle('is-hidden', previewDetailsExpanded);
            }
            toggleDetailsBtn.classList.toggle('is-expanded', previewDetailsExpanded);
            toggleDetailsBtn.setAttribute('aria-expanded', previewDetailsExpanded ? 'true' : 'false');
            toggleDetailsBtn.setAttribute('title', previewDetailsExpanded ? 'Hide message details' : 'Show message details');
            var labelEl = toggleDetailsBtn.querySelector('.elw-details-toggle-text');
            if (labelEl) {
                labelEl.textContent = 'Details';
            }
        }

        emailLogsApplyPreviewDetailsExpanded();

        if (toggleDetailsBtn) {
            toggleDetailsBtn.addEventListener('click', function () {
                previewDetailsExpanded = !previewDetailsExpanded;
                try {
                    sessionStorage.setItem('email_logs_details_expanded', previewDetailsExpanded ? '1' : '0');
                } catch (detailsToggleErr) {
                    /* ignore */
                }
                emailLogsApplyPreviewDetailsExpanded();
            });
        }

        function emailLogsParseCcAddresses(raw) {
            return emailLogsSplitRecipients(raw).filter(function (addr) {
                return addr.indexOf('@') !== -1;
            });
        }

        function emailLogsUpdateCollapsedLine(item) {
            if (!collapsedLineEl || !item) {
                return;
            }
            var fromLabel = item.from_email || item.list_sender || '-';
            var subjLabel = item.subject || '(No subject)';
            var timeLabel = item.time_full || item.time_short || '';
            var ccRaw = emailLogsCcRawForItem(item);
            var ccCount = emailLogsParseCcAddresses(ccRaw).length;
            var ccList = emailLogsParseCcAddresses(ccRaw);
            var ccHint = '';
            if (ccList.length > 0) {
                var ccShown = ccList.slice(0, 2).join(', ');
                if (ccList.length > 2) {
                    ccShown += ' +' + (ccList.length - 2) + ' more';
                }
                ccHint =
                    ' · <span class="email-logs-collapsed-cc" title="' +
                    emailLogsEscAttr(ccRaw) +
                    '">CC: ' +
                    emailLogsEscHtmlText(ccShown) +
                    '</span>';
            }
            var toLabel =
                item.direction === 'incoming'
                    ? item.parsed_to || item.to_email || ''
                    : item.to_email || '';
            var toHint = toLabel
                ? ' · <span class="email-logs-collapsed-to">To: ' + emailLogsEscHtmlText(toLabel) + '</span>'
                : '';
            collapsedLineEl.innerHTML =
                '<strong>' +
                emailLogsEscHtmlText(fromLabel) +
                '</strong>' +
                toHint +
                ' · ' +
                emailLogsEscHtmlText(subjLabel) +
                ccHint +
                (timeLabel
                    ? ' · <span class="email-logs-collapsed-time">' + emailLogsEscHtmlText(timeLabel) + '</span>'
                    : '');
        }

        function emailLogsSyncCcToggleState(block, expanded) {
            if (!block) {
                return;
            }
            block.classList.toggle('is-expanded', expanded);
            var btn = block.querySelector('[data-elw-cc-toggle]');
            var count = block.querySelectorAll('.elw-cc-chip').length;
            if (btn) {
                btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                var lbl = btn.querySelector('[data-elw-cc-label]');
                if (lbl) {
                    lbl.textContent = expanded ? 'Collapse' : 'Show all (' + count + ')';
                }
            }
        }

        if (partEl && !partEl.dataset.elwCcBound) {
            partEl.dataset.elwCcBound = '1';
            partEl.addEventListener('click', function (ev) {
                var ccBtn = ev.target.closest('[data-elw-cc-toggle]');
                if (!ccBtn || !partEl.contains(ccBtn)) {
                    return;
                }
                ev.preventDefault();
                var block = ccBtn.closest('.elw-cc-block');
                if (!block) {
                    return;
                }
                emailLogsSyncCcToggleState(block, !block.classList.contains('is-expanded'));
            });
        }

        var ticketsViewBase = wsRoot.getAttribute('data-url-tickets-view') || '';
        var logsBase = wsRoot.getAttribute('data-url-logs') || '';

        // Listen for content-height reports from the sandboxed iframe and grow it to fit.
        // Keeps the reading pane scrollable as one continuous surface (Gmail-style),
        // instead of trapping a tall email inside a fixed-height iframe.
        if (!window.__emailLogsHeightListenerBound) {
            window.__emailLogsHeightListenerBound = true;
            window.addEventListener('message', function (ev) {
                if (!ev || !ev.data || typeof ev.data !== 'object') {
                    return;
                }
                if (ev.data.type !== 'email-logs-iframe-height') {
                    return;
                }
                var h = parseInt(ev.data.height, 10);
                if (!isFinite(h) || h <= 0) {
                    return;
                }
                var capped = Math.min(Math.max(h + 24, 120), 3200);
                var frames = document.querySelectorAll('.email-logs-preview-iframe');
                for (var fi = 0; fi < frames.length; fi++) {
                    if (frames[fi].getAttribute('data-elw-scroll-pane') === '1') {
                        continue;
                    }
                    if (frames[fi].contentWindow === ev.source) {
                        frames[fi].style.height = capped + 'px';
                        break;
                    }
                }
            });
        }

        function escAttr(s) {
            return String(s || '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function qsAppend(base, query) {
            if (!base) {
                return '';
            }
            return base + (base.indexOf('?') === -1 ? '?' : '&') + query;
        }

        function emailLogsHydrateUnreadUi() {
            if (!wsList) {
                return;
            }
            wsList.querySelectorAll('.email-logs-row').forEach(function (row) {
                var idx = parseInt(row.getAttribute('data-email-logs-index'), 10);
                var item = emailLogsGetItem(emailLogsItems, idx);
                if (!item) {
                    return;
                }
                row.classList.toggle('email-logs-row--unread', emailLogsIsUnread(item.id));
            });
        }

        function emailLogsSetToolbarEnabled(on) {
            [btnReply, btnForward].forEach(function (b) {
                if (b) {
                    b.disabled = !on;
                }
            });
            if (toolbarMeta) {
                toolbarMeta.textContent =
                    on && workspace.selectedItem
                        ? workspace.selectedItem.time_tooltip || workspace.selectedItem.time_full || ''
                        : '';
            }
        }

        function emailLogsCollectThread(item) {
            var key = String(item.thread_key || item.id || '');
            var ticketThread = key.indexOf('ticket-') === 0;
            var ticketId = parseInt(item.ticket_id, 10) || 0;
            var list = emailLogsItemsList(emailLogsItems).filter(function (m) {
                if (String(m.thread_key || m.id || '') === key) {
                    return true;
                }
                if (!ticketThread || ticketId <= 0) {
                    return false;
                }
                return (
                    (parseInt(m.ticket_id, 10) || 0) === ticketId &&
                    String(m.thread_key || '').indexOf('ticket-') === 0
                );
            });
            list.sort(function (a, b) {
                var ta = Date.parse(String(a.sort_at || '')) || 0;
                var tb = Date.parse(String(b.sort_at || '')) || 0;
                if (ta !== tb) {
                    return ta - tb;
                }
                return String(a.id || '').localeCompare(String(b.id || ''));
            });
            return list;
        }

        function emailLogsSetThreadPaneMode(on) {
            if (frameWrap) {
                frameWrap.classList.toggle('email-logs-preview-frame-wrap--thread', !!on);
            }
            if (iframe) {
                if (on) {
                    iframe.setAttribute('data-elw-scroll-pane', '1');
                    iframe.style.height = '100%';
                } else {
                    iframe.removeAttribute('data-elw-scroll-pane');
                    iframe.style.height = '';
                }
            }
        }

        function emailLogsRenderThreadInSingleIframe(thread, selectedId) {
            if (conversationEl) {
                conversationEl.classList.add('is-hidden');
                conversationEl.innerHTML = '';
            }
            if (previewSingleEl) {
                previewSingleEl.classList.remove('is-hidden');
            }
            emailLogsSetThreadPaneMode(true);

            var combinedHtml = '';
            try {
                combinedHtml = emailLogsBuildThreadIframeDoc(thread, selectedId);
            } catch (buildErr) {
                console.error('[Email logs] Thread iframe build error', buildErr);
            }
            var hasAnyBody = thread.some(function (m) {
                return (m.preview_html || '').trim() !== '' || (m.body_plain || '').trim() !== '';
            });

            if (iframe && frameWrap && plainEl) {
                if (hasAnyBody && combinedHtml) {
                    frameWrap.classList.remove('is-hidden');
                    iframe.classList.remove('is-hidden');
                    plainEl.classList.add('is-hidden');
                    plainEl.innerHTML = '';
                    emailLogsHydratePreviewInlineImages(combinedHtml)
                        .then(function (hydratedDoc) {
                            try {
                                iframe.srcdoc = hydratedDoc;
                            } catch (srcdocErr) {
                                console.error('[Email logs] Thread srcdoc error', srcdocErr);
                                plainEl.classList.remove('is-hidden');
                                plainEl.innerHTML =
                                    '<pre class="email-logs-plain-body">Could not load thread preview.</pre>';
                            }
                        })
                        .catch(function () {
                            plainEl.classList.remove('is-hidden');
                            plainEl.innerHTML =
                                '<pre class="email-logs-plain-body">Could not load thread preview.</pre>';
                        });
                } else {
                    frameWrap.classList.add('is-hidden');
                    iframe.classList.add('is-hidden');
                    iframe.removeAttribute('srcdoc');
                    plainEl.classList.remove('is-hidden');
                    plainEl.innerHTML = '<pre class="email-logs-plain-body">No message body available.</pre>';
                }
            }
        }

        function emailLogsShowPreviewLoading() {
            if (iframe && frameWrap && plainEl) {
                frameWrap.classList.add('is-hidden');
                iframe.classList.add('is-hidden');
                iframe.removeAttribute('srcdoc');
                plainEl.classList.remove('is-hidden');
                plainEl.innerHTML = '<p class="email-logs-plain-empty">Loading message…</p>';
            }
        }

        function emailLogsRenderSingleBodyHtml(html) {
            if (!iframe || !frameWrap || !plainEl) {
                return Promise.resolve();
            }
            if (!html) {
                frameWrap.classList.add('is-hidden');
                iframe.classList.add('is-hidden');
                iframe.removeAttribute('srcdoc');
                plainEl.classList.remove('is-hidden');
                plainEl.innerHTML = '<pre class="email-logs-plain-body">No message body available.</pre>';
                return Promise.resolve();
            }
            return emailLogsHydratePreviewInlineImages(html).then(function (hydrated) {
                frameWrap.classList.remove('is-hidden');
                iframe.classList.remove('is-hidden');
                plainEl.classList.add('is-hidden');
                plainEl.innerHTML = '';
                iframe.srcdoc = emailLogsBuildIframeDoc(hydrated, { collapseQuotes: true });
            });
        }

        function emailLogsFetchAndRenderSingleBody(item) {
            var previewUrl = emailLogsItemPreviewUrl(item);
            if (!previewUrl) {
                return Promise.reject(new Error('No preview URL'));
            }
            emailLogsShowPreviewLoading();
            var abortCtl = emailLogsBeginPreviewFetch();
            var signal = abortCtl ? abortCtl.signal : undefined;
            return emailLogsFetchPreviewBody(previewUrl, signal)
                .then(function (loaded) {
                    if (!workspace.selectedItem || workspace.selectedItem.id !== item.id) {
                        return;
                    }
                    item.preview_html = loaded;
                    return emailLogsRenderSingleBodyHtml(loaded);
                })
                .finally(function () {
                    emailLogsSetPreviewLoading(false);
                });
        }

        function emailLogsRenderSingleBody(item) {
            emailLogsSetThreadPaneMode(false);
            if (conversationEl) {
                conversationEl.classList.add('is-hidden');
                conversationEl.innerHTML = '';
            }
            if (previewSingleEl) {
                previewSingleEl.classList.remove('is-hidden');
            }
            var html = (item.preview_html || '').trim();
            if (emailLogsItemShouldFetchPreview(item, html)) {
                emailLogsFetchAndRenderSingleBody(item).catch(function () {
                    if (workspace.selectedItem && workspace.selectedItem.id === item.id && plainEl) {
                        plainEl.classList.remove('is-hidden');
                        plainEl.innerHTML =
                            '<pre class="email-logs-plain-body">Could not load message preview.</pre>';
                    }
                });
                return;
            }
            emailLogsRenderSingleBodyHtml(html || '')
                .then(function () {
                    emailLogsSetPreviewLoading(false);
                })
                .catch(function () {
                    return emailLogsFetchAndRenderSingleBody(item);
                })
                .catch(function () {
                    emailLogsSetPreviewLoading(false);
                    if (workspace.selectedItem && workspace.selectedItem.id === item.id && plainEl) {
                        plainEl.classList.remove('is-hidden');
                        plainEl.innerHTML =
                            '<pre class="email-logs-plain-body">Could not load message preview.</pre>';
                    }
                });
            if (html) {
                return;
            }
            emailLogsSetPreviewLoading(false);
            if (!html && plainEl) {
                plainEl.classList.remove('is-hidden');
                plainEl.innerHTML =
                    '<pre class="email-logs-plain-body">' +
                    emailLogsLinkifyEscaped(item.body_plain || '') +
                    '</pre>';
            }
        }

        function emailLogsRenderPreview(item) {
            if (!item) {
                return;
            }
            if (ph) {
                ph.classList.add('is-hidden');
            }
            if (content) {
                content.classList.remove('is-hidden');
            }
            if (subjEl) {
                subjEl.textContent = item.subject || '(No subject)';
                subjEl.setAttribute('title', item.subject || '');
            }
            if (previewTimeEl) {
                var timeVal = item.time_full || item.time_short || '';
                previewTimeEl.textContent = timeVal;
                previewTimeEl.setAttribute('datetime', item.sort_at || '');
                previewTimeEl.hidden = !timeVal;
            }
            emailLogsUpdateCollapsedLine(item);
            var thread = emailLogsCollectThread(item);
            if (partEl) {
                var threadCount = thread.length > 1 ? thread.length : parseInt(item.thread_count, 10) || 1;
                var threadLead =
                    threadCount > 1
                        ? '<p class="email-logs-thread-count">' +
                          threadCount +
                          ' messages — scroll below to read the thread</p>'
                        : '';
                function participantRow(label, value) {
                    return (
                        '<div class="elw-rec-row">' +
                        '<span class="elw-rec-label">' +
                        emailLogsEscHtmlText(label) +
                        '</span>' +
                        '<span class="elw-rec-value">' +
                        emailLogsEscHtmlText(value || '-') +
                        '</span></div>'
                    );
                }
                function buildCcParticipantBlock(ccRaw) {
                    var list = emailLogsParseCcAddresses(ccRaw);
                    if (!list.length) {
                        return '';
                    }
                    var chipsHtml = list
                        .map(function (addr) {
                            return (
                                '<span class="elw-cc-chip" title="' +
                                emailLogsEscAttr(addr) +
                                '">' +
                                emailLogsEscHtmlText(addr) +
                                '</span>'
                            );
                        })
                        .join('');
                    var needsExpand = list.length > 3;
                    var expandBtn = needsExpand
                        ? '<button type="button" class="elw-cc-expand-btn" data-elw-cc-toggle="1" aria-expanded="false">' +
                          '<span data-elw-cc-label>Show all (' +
                          list.length +
                          ')</span>' +
                          '<span class="elw-chevron elw-chevron--sm" aria-hidden="true"></span></button>'
                        : '<span class="elw-cc-count">' + list.length + '</span>';
                    return (
                        '<div class="elw-cc-block' +
                        (needsExpand ? '' : ' elw-cc-block--static') +
                        '">' +
                        '<div class="elw-cc-block-head">' +
                        '<span class="elw-rec-label">CC</span>' +
                        expandBtn +
                        '</div>' +
                        '<div class="elw-cc-chips-wrap" data-elw-cc-wrap="1">' +
                        '<div class="elw-cc-chips">' +
                        chipsHtml +
                        '</div></div></div>'
                    );
                }
                var rows = participantRow('From', item.from_email || item.list_sender || '-');
                if (item.direction === 'incoming') {
                    if (item.parsed_to) {
                        rows += participantRow('To', item.parsed_to);
                    }
                } else {
                    rows += participantRow('To', item.to_email || '-');
                }
                var ccRawHeader = emailLogsCcRawForItem(item);
                if (ccRawHeader) {
                    rows += buildCcParticipantBlock(ccRawHeader);
                }
                partEl.innerHTML =
                    threadLead + '<div class="elw-recipients">' + rows + '</div>';
                partEl.querySelectorAll('.elw-cc-block').forEach(function (block) {
                    if (!block.classList.contains('elw-cc-block--static')) {
                        emailLogsSyncCcToggleState(block, false);
                    }
                });
            }
            if (emailLogsParseCcAddresses(emailLogsCcRawForItem(item)).length > 0) {
                previewDetailsExpanded = true;
            }
            emailLogsApplyPreviewDetailsExpanded();
            if (metaStrip) {
                var cells = [
                    '<div><span>Customer</span><strong>' + escAttr(item.meta_customer || '-') + '</strong></div>',
                    '<div><span>Ticket status</span><strong>' + escAttr(item.meta_ticket_status || '-') + '</strong></div>',
                    '<div><span>Source</span><strong>' + escAttr(item.meta_source || '-') + '</strong></div>',
                ];
                if (item.direction === 'incoming') {
                    cells.push('<div><span>Assigned</span><strong>' + escAttr(item.meta_assignee || '-') + '</strong></div>');
                } else {
                    cells.push('<div><span>Created by</span><strong>' + escAttr(item.meta_creator || '-') + '</strong></div>');
                }
                cells.push('<div><span>External ID</span><strong>' + escAttr(item.external_ticket_id || '-') + '</strong></div>');
                if (item.has_attachment || (item.attachments && item.attachments.length)) {
                    var attachCount = item.attachments ? item.attachments.length : 1;
                    cells.push(
                        '<div class="email-logs-meta-attach"><span>Attachments</span><strong>' +
                        attachCount +
                        '</strong></div>'
                    );
                }
                metaStrip.innerHTML = cells.join('');
            }
            if (item.attachments_lazy && item.attachments_url) {
                emailLogsRenderAttachmentsPanel(item, attachmentsEl);
                emailLogsFetchAttachments(item).then(function (list) {
                    if (workspace.selectedItem && workspace.selectedItem.id === item.id) {
                        item.attachments = list;
                        item.has_attachment = list.length > 0 ? 1 : item.has_attachment;
                        emailLogsRenderAttachmentsPanel(item, attachmentsEl);
                    }
                });
            } else {
                emailLogsRenderAttachmentsPanel(item, attachmentsEl);
            }
            if (errEl) {
                errEl.classList.add('is-hidden');
                errEl.textContent = '';
                if (item.direction === 'outgoing' && item.error_message) {
                    errEl.textContent = String(item.error_message);
                    errEl.classList.remove('is-hidden');
                }
                if (
                    item.direction === 'incoming' &&
                    item.ignored_reason &&
                    (item.status_label === 'Ignored' || item.status_label === 'Unmapped' || item.status_label === 'Unknown')
                ) {
                    errEl.textContent = String(item.ignored_reason);
                    errEl.classList.remove('is-hidden');
                }
            }
            if (bottomActions) {
                var tid = item.ticket_id ? String(item.ticket_id) : '';
                var links = '';
                if (tid && ticketsViewBase) {
                    links +=
                        '<a class="btn btn-outline btn-sm" href="' +
                        qsAppend(ticketsViewBase, 'id=' + encodeURIComponent(tid)) +
                        '">Open ticket</a>';
                }
                if (logsBase) {
                    if (item.direction === 'incoming') {
                        links +=
                            '<a class="btn btn-secondary btn-sm" href="' +
                            qsAppend(logsBase, 'ticket_id=' + encodeURIComponent(tid || '0') + '&direction=incoming') +
                            '">More mail</a>';
                    } else {
                        links +=
                            '<a class="btn btn-secondary btn-sm" href="' +
                            qsAppend(logsBase, 'ticket_id=' + encodeURIComponent(tid || '0') + '&direction=outgoing') +
                            '">More mail</a>';
                    }
                }
                bottomActions.innerHTML = links;
            }
            if (mapWrap) {
                mapWrap.classList.add('is-hidden');
                mapWrap.innerHTML = '';
                if (item.is_unmapped && emailLogsContext.canManageEmailLogs !== false) {
                    mapWrap.classList.remove('is-hidden');
                    var mapUrl = emailLogsContext.mapFormActionUrl || qsAppend(logsBase, 'direction=incoming&status=unmapped');
                    mapWrap.innerHTML =
                        '<form method="POST" action="' +
                        mapUrl +
                        '" class="email-logs-map-form">' +
                        '<input type="hidden" name="action" value="map_unmapped_email">' +
                        '<input type="hidden" name="' +
                        (emailLogsContext.csrfFieldName || 'csrf_token') +
                        '" value="' +
                        (emailLogsContext.csrfToken || '') +
                        '">' +
                        '<input type="hidden" name="inbox_log_id" value="' +
                        item.log_id +
                        '">' +
                        '<label class="email-logs-map-label">Map to ticket ID</label>' +
                        '<div class="email-logs-map-row"><input type="number" name="map_ticket_id" min="1" required placeholder="Internal ticket #">' +
                        '<button type="submit" class="btn btn-primary btn-sm">Map email</button></div></form>';
                }
            }

            if (thread.length > 1) {
                var needsLazy = thread.some(function (m) {
                    var ph = (m.preview_html || '').trim();
                    return emailLogsItemShouldFetchPreview(m, ph);
                });
                if (needsLazy) {
                    emailLogsShowPreviewLoading();
                    var abortCtl = emailLogsBeginPreviewFetch();
                    var signal = abortCtl ? abortCtl.signal : undefined;
                    emailLogsResolveThreadPreviews(thread, signal)
                        .then(function (resolved) {
                            if (workspace.selectedItem && workspace.selectedItem.id === item.id) {
                                return emailLogsRenderThreadInSingleIframe(resolved, item.id);
                            }
                        })
                        .catch(function (threadErr) {
                            console.warn('[Email logs] Thread preview failed', threadErr);
                            if (workspace.selectedItem && workspace.selectedItem.id === item.id) {
                                return emailLogsRenderThreadInSingleIframe(thread, item.id);
                            }
                        })
                        .finally(function () {
                            emailLogsSetPreviewLoading(false);
                        });
                } else {
                    emailLogsRenderThreadInSingleIframe(thread, item.id);
                    emailLogsSetPreviewLoading(false);
                }
            } else {
                emailLogsRenderSingleBody(item);
            }
        }

        function emailLogsSetActiveDescendant(idx) {
            if (!wsList) {
                return;
            }
            if (typeof idx !== 'number' || idx < 0 || isNaN(idx)) {
                wsList.removeAttribute('aria-activedescendant');
                return;
            }
            var row = wsList.querySelector('.email-logs-row[data-email-logs-index="' + idx + '"]');
            if (row && row.id) {
                wsList.setAttribute('aria-activedescendant', row.id);
            } else {
                wsList.removeAttribute('aria-activedescendant');
            }
        }

        function emailLogsSetPreviewLoading(on) {
            var busy = !!on;
            if (content) {
                content.classList.toggle('email-logs-preview-content--loading', busy);
                content.setAttribute('aria-busy', busy ? 'true' : 'false');
            }
            if (previewBody) {
                previewBody.classList.toggle('is-loading', busy);
                previewBody.setAttribute('aria-busy', busy ? 'true' : 'false');
            }
        }

        function emailLogsSelectIndex(idx) {
            emailLogsRevokePreviewBlobs();
            var item = emailLogsGetItem(emailLogsItems, idx);
            workspace.selectedIndex = typeof idx === 'number' && !isNaN(idx) ? idx : -1;
            workspace.selectedItem = item || null;
            if (!wsList) {
                return;
            }
            wsList.querySelectorAll('.email-logs-row').forEach(function (row) {
                var ridx = parseInt(row.getAttribute('data-email-logs-index'), 10);
                var sel = item && ridx === idx;
                row.classList.toggle('is-active', !!sel);
                row.setAttribute('aria-selected', sel ? 'true' : 'false');
            });
            if (!item) {
                emailLogsSetPreviewLoading(false);
                if (attachmentsEl) {
                    emailLogsRenderAttachmentsPanel(null, attachmentsEl);
                }
                if (ph) {
                    ph.classList.remove('is-hidden');
                }
                if (content) {
                    content.classList.add('is-hidden');
                }
                emailLogsSetToolbarEnabled(false);
                emailLogsSetActiveDescendant(-1);
                return;
            }
            emailLogsSetPreviewLoading(true);
            try {
                emailLogsRenderPreview(item);
            } catch (errPreview) {
                console.error('[Email logs] Preview error', errPreview);
                emailLogsFetchAndRenderSingleBody(item).catch(function () {
                    if (plainEl) {
                        plainEl.classList.remove('is-hidden');
                        plainEl.innerHTML =
                            '<pre class="email-logs-plain-body">Could not load message preview.</pre>';
                    }
                });
            }
            emailLogsMarkReadId(item.id);
            emailLogsHydrateUnreadUi();
            emailLogsSetToolbarEnabled(true);
            emailLogsSetActiveDescendant(idx);
        }

        function emailLogsFindRowFromEvent(evTarget) {
            if (!evTarget) {
                return null;
            }
            if (typeof evTarget.closest === 'function') {
                var byClosest = evTarget.closest('.email-logs-row');
                if (byClosest && wsRoot.contains(byClosest)) {
                    return byClosest;
                }
            }
            var t = evTarget;
            while (t && t !== wsRoot) {
                if (t.classList && t.classList.contains('email-logs-row')) {
                    return t;
                }
                t = t.parentElement;
            }
            return null;
        }

        function emailLogsSyncRowFlagUi(rowEl, flagged) {
            if (!rowEl) {
                return;
            }
            var btn = rowEl.querySelector('.email-logs-flag-btn');
            rowEl.classList.toggle('email-logs-row--flagged', !!flagged);
            if (btn) {
                btn.classList.toggle('is-flagged', !!flagged);
                btn.setAttribute('aria-pressed', flagged ? 'true' : 'false');
                btn.setAttribute(
                    'aria-label',
                    flagged ? 'Remove important flag' : 'Mark as important'
                );
                btn.title = flagged ? 'Remove flag' : 'Mark as important';
            }
        }

        function emailLogsToggleFlag(flagBtn, rowEl) {
            if (!flagBtn || flagBtn.disabled) {
                return;
            }
            var direction = String(flagBtn.getAttribute('data-mail-direction') || '');
            var logId = parseInt(flagBtn.getAttribute('data-log-id'), 10) || 0;
            if (logId <= 0 || (direction !== 'incoming' && direction !== 'outgoing')) {
                return;
            }

            var toggleUrl = emailLogsContext.flagToggleUrl || '';
            var csrfName = emailLogsContext.csrfFieldName || 'csrf_token';
            var csrfToken = emailLogsContext.csrfToken || '';
            if (!toggleUrl || !csrfToken) {
                return;
            }

            flagBtn.disabled = true;
            var body = new URLSearchParams();
            body.set('action', 'toggle_email_flag');
            body.set(csrfName, csrfToken);
            body.set('mail_direction', direction);
            body.set('log_id', String(logId));

            fetch(toggleUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body.toString(),
                credentials: 'same-origin',
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error((data && data.message) || 'Flag update failed.');
                    }
                    var flagged = !!data.flagged;
                    if (rowEl) {
                        emailLogsSyncRowFlagUi(rowEl, flagged);
                    }
                    var idx = rowEl
                        ? parseInt(rowEl.getAttribute('data-email-logs-index'), 10)
                        : NaN;
                    var flaggedItem = emailLogsGetItem(emailLogsItems, idx);
                    if (!isNaN(idx) && flaggedItem) {
                        flaggedItem.is_flagged = flagged;
                    }
                })
                .catch(function (flagErr) {
                    console.error('[Email logs] Flag toggle failed', flagErr);
                })
                .finally(function () {
                    flagBtn.disabled = false;
                });
        }

        wsRoot.addEventListener('click', function (ev) {
            var flagBtn =
                ev.target && typeof ev.target.closest === 'function'
                    ? ev.target.closest('.email-logs-flag-btn')
                    : null;
            if (flagBtn && wsRoot.contains(flagBtn)) {
                ev.preventDefault();
                ev.stopPropagation();
                emailLogsToggleFlag(flagBtn, emailLogsFindRowFromEvent(flagBtn));
                return;
            }

            var row = emailLogsFindRowFromEvent(ev.target);
            if (!row) {
                return;
            }
            var idx = parseInt(row.getAttribute('data-email-logs-index'), 10);
            if (isNaN(idx)) {
                return;
            }
            emailLogsSelectIndex(idx);
        });

        if (wsList) {
            function emailLogsScrollListBehavior() {
                if (typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    return 'auto';
                }
                return 'smooth';
            }

            wsList.addEventListener('keydown', function (ev) {
                var rows = wsList.querySelectorAll('.email-logs-row');
                if (!rows.length) {
                    return;
                }
                var list = Array.prototype.slice.call(rows);
                var scrollOpt = { block: 'nearest', behavior: emailLogsScrollListBehavior() };

                if (ev.key === 'Home') {
                    ev.preventDefault();
                    var firstRow = list[0];
                    emailLogsSelectIndex(parseInt(firstRow.getAttribute('data-email-logs-index'), 10));
                    firstRow.scrollIntoView(scrollOpt);
                    return;
                }
                if (ev.key === 'End') {
                    ev.preventDefault();
                    var lastRow = list[list.length - 1];
                    emailLogsSelectIndex(parseInt(lastRow.getAttribute('data-email-logs-index'), 10));
                    lastRow.scrollIntoView(scrollOpt);
                    return;
                }
                if (ev.key === 'Enter') {
                    var activeEl = document.activeElement;
                    if (activeEl && activeEl.classList && activeEl.classList.contains('email-logs-row')) {
                        ev.preventDefault();
                        var enterIdx = parseInt(activeEl.getAttribute('data-email-logs-index'), 10);
                        if (!isNaN(enterIdx)) {
                            emailLogsSelectIndex(enterIdx);
                        }
                    }
                    return;
                }

                if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') {
                    return;
                }
                ev.preventDefault();
                var curIdx = workspace.selectedIndex;
                var pos = -1;
                for (var i = 0; i < list.length; i++) {
                    if (parseInt(list[i].getAttribute('data-email-logs-index'), 10) === curIdx) {
                        pos = i;
                        break;
                    }
                }
                if (pos < 0) {
                    pos = ev.key === 'ArrowDown' ? -1 : list.length;
                }
                var nextPos = ev.key === 'ArrowDown' ? Math.min(list.length - 1, pos + 1) : Math.max(0, pos - 1);
                var nextRow = list[nextPos];
                if (!nextRow) {
                    return;
                }
                var nextDataIdx = parseInt(nextRow.getAttribute('data-email-logs-index'), 10);
                emailLogsSelectIndex(nextDataIdx);
                nextRow.scrollIntoView(scrollOpt);
            });
        }

        var filterToggle = document.getElementById('email-logs-filter-toggle');
        var filterFields = document.getElementById('email-logs-filter-fields');
        if (filterToggle && filterFields) {
            filterToggle.addEventListener('click', function () {
                var collapsed = filterFields.classList.toggle('is-collapsed');
                filterToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                filterToggle.textContent = collapsed ? 'Show filters' : 'Hide filters';
            });
        }

        if (wsPaneList && wsResizer) {
            var dragState = null;
            var storedW = parseInt(localStorage.getItem('email_logs_list_width'), 10);
            if (!isNaN(storedW) && storedW >= 220 && storedW <= 460) {
                wsPaneList.style.width = storedW + 'px';
            } else {
                wsPaneList.style.width = '280px';
            }
            wsResizer.addEventListener('mousedown', function (e) {
                dragState = { startX: e.clientX, startW: wsPaneList.getBoundingClientRect().width };
                e.preventDefault();
            });
            document.addEventListener('mousemove', function (e) {
                if (!dragState) {
                    return;
                }
                var w = dragState.startW + (e.clientX - dragState.startX);
                w = Math.max(220, Math.min(460, w));
                wsPaneList.style.width = w + 'px';
            });
            document.addEventListener('mouseup', function () {
                if (!dragState) {
                    return;
                }
                dragState = null;
                try {
                    localStorage.setItem('email_logs_list_width', String(Math.round(wsPaneList.getBoundingClientRect().width)));
                } catch (e2) {
                    /* ignore */
                }
            });
            wsResizer.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
                    return;
                }
                e.preventDefault();
                var cur = wsPaneList.getBoundingClientRect().width;
                var step = 24;
                var w = e.key === 'ArrowRight' ? cur + step : cur - step;
                w = Math.max(220, Math.min(460, Math.round(w)));
                wsPaneList.style.width = w + 'px';
                try {
                    localStorage.setItem('email_logs_list_width', String(w));
                } catch (e3) {
                    /* ignore */
                }
            });
        }

        function emailLogsWrapMessageId(messageId) {
            var mid = String(messageId || '').trim();
            if (mid === '') {
                return '';
            }
            if (mid.charAt(0) === '<' && mid.charAt(mid.length - 1) === '>') {
                return mid;
            }
            return '<' + mid + '>';
        }

        function emailLogsReplyThreadHeaders(item) {
            var wrapped = emailLogsWrapMessageId(item && item.message_id);
            if (!wrapped) {
                return { composeInReplyTo: '', composeReferencesHeader: '' };
            }
            var refs = String((item && item.references_header) || '').trim();
            if (refs !== '') {
                refs = refs + ' ' + wrapped;
            } else {
                refs = wrapped;
            }
            return { composeInReplyTo: wrapped, composeReferencesHeader: refs };
        }

        function emailLogsOpenReply(mode) {
            var item = workspace.selectedItem;
            if (!item) {
                return;
            }
            if (mode !== 'reply' && mode !== 'forward') {
                return;
            }

            var threadHeaders =
                mode === 'reply' ? emailLogsReplyThreadHeaders(item) : { composeInReplyTo: '', composeReferencesHeader: '' };

            var maxQuote = 120000;
            function truncateForQuote(text) {
                var t = String(text || '');
                if (t.length <= maxQuote) {
                    return t;
                }
                return t.slice(0, maxQuote) + '\n\n… [Quoted message truncated]';
            }

            var bodyPlain = truncateForQuote(item.body_plain || '');

            if (mode === 'forward') {
                var baseSubj = emailLogsStripFw(emailLogsStripRe(item.subject || ''));
                var fwdQuoted =
                    '---------- Forwarded message ----------\n' +
                    'Subject: ' +
                    (item.subject || '') +
                    '\n' +
                    'Date: ' +
                    (item.time_full || '') +
                    '\n\n' +
                    emailLogsQuotePlain(bodyPlain);
                openComposeFn({
                    to: '',
                    cc: '',
                    subject: 'Fw: ' + (baseSubj || 'Message'),
                    body: '',
                    quotedPlain: fwdQuoted,
                    quoteKind: 'forward',
                    ticketId: item.ticket_id ? String(item.ticket_id) : '',
                    partyId: '',
                    composeInReplyTo: '',
                    composeReferencesHeader: '',
                });
                return;
            }

            if (item.direction === 'incoming') {
                var replyTo = emailLogsFirstEmailFromHeader(item.parsed_reply_to) || item.from_email || '';
                var baseSubjIn = emailLogsStripRe(item.subject || '');
                var subj = 'Re: ' + (baseSubjIn || 'Message');
                openComposeFn({
                    to: replyTo,
                    cc: '',
                    subject: subj,
                    body: '',
                    ticketId: item.ticket_id ? String(item.ticket_id) : '',
                    partyId: '',
                    composeInReplyTo: threadHeaders.composeInReplyTo,
                    composeReferencesHeader: threadHeaders.composeReferencesHeader,
                });
                return;
            }

            var toOut = item.to_email || '';
            var baseSubjOut = emailLogsStripRe(item.subject || '');
            var subjOut = 'Re: ' + (baseSubjOut || 'Message');
            openComposeFn({
                to: toOut,
                cc: '',
                subject: subjOut,
                body: '',
                ticketId: item.ticket_id ? String(item.ticket_id) : '',
                partyId: '',
                composeInReplyTo: threadHeaders.composeInReplyTo,
                composeReferencesHeader: threadHeaders.composeReferencesHeader,
            });
        }

        if (btnReply) {
            btnReply.addEventListener('click', function () {
                emailLogsOpenReply('reply');
            });
        }
        if (btnForward) {
            btnForward.addEventListener('click', function () {
                emailLogsOpenReply('forward');
            });
        }

        function emailLogsFindPoolIndexForOpenTarget(logId, direction) {
            var poolIdx = parseInt(String(emailLogsContext.openPoolIndex ?? '-1'), 10);
            if (!isNaN(poolIdx) && poolIdx >= 0 && emailLogsGetItem(emailLogsItems, poolIdx)) {
                var pooled = emailLogsGetItem(emailLogsItems, poolIdx);
                if (
                    pooled
                    && parseInt(String(pooled.log_id || '0'), 10) === logId
                    && String(pooled.direction || '').toLowerCase() === direction
                ) {
                    return poolIdx;
                }
            }

            var targetIdx = -1;
            if (emailLogsItems && typeof emailLogsItems === 'object') {
                Object.keys(emailLogsItems).forEach(function (key) {
                    if (targetIdx >= 0) {
                        return;
                    }
                    var item = emailLogsGetItem(emailLogsItems, key);
                    if (!item) {
                        return;
                    }
                    if (
                        parseInt(String(item.log_id || '0'), 10) === logId
                        && String(item.direction || '').toLowerCase() === direction
                    ) {
                        targetIdx = parseInt(key, 10);
                    }
                });
            }

            return !isNaN(targetIdx) && targetIdx >= 0 ? targetIdx : -1;
        }

        function emailLogsPersistDeepLinkState(logId, direction, poolIdx) {
            try {
                sessionStorage.setItem(
                    'email_logs_deep_link_v1',
                    JSON.stringify({
                        log_id: logId,
                        direction: direction,
                        pool_index: poolIdx,
                    })
                );
            } catch (persistErr) {
                /* ignore */
            }
        }

        function emailLogsOpenFromDeepLink(attempt) {
            attempt = attempt || 0;

            var logId = parseInt(String(emailLogsContext.openLogId || '0'), 10);
            var direction = String(emailLogsContext.openDirection || '').toLowerCase();
            if (!logId) {
                try {
                    var params = new URLSearchParams(window.location.search);
                    logId = parseInt(params.get('open_log_id') || '0', 10);
                    direction = String(params.get('open_direction') || direction || 'incoming').toLowerCase();
                } catch (deepLinkParamErr) {
                    return false;
                }
            }

            if (!logId && attempt === 0) {
                try {
                    var storedRaw = sessionStorage.getItem('email_logs_deep_link_v1');
                    if (storedRaw) {
                        var stored = JSON.parse(storedRaw);
                        logId = parseInt(String(stored.log_id || '0'), 10);
                        direction = String(stored.direction || 'incoming').toLowerCase();
                    }
                } catch (storedErr) {
                    /* ignore */
                }
            }

            if (!logId || (direction !== 'incoming' && direction !== 'outgoing')) {
                return false;
            }

            var targetIdx = emailLogsFindPoolIndexForOpenTarget(logId, direction);
            if (targetIdx < 0) {
                if (attempt < 8) {
                    window.setTimeout(function () {
                        emailLogsOpenFromDeepLink(attempt + 1);
                    }, 60);
                }
                return false;
            }

            emailLogsSelectIndex(targetIdx);
            emailLogsPersistDeepLinkState(logId, direction, targetIdx);

            if (wsList) {
                var row = wsList.querySelector('.email-logs-row[data-email-logs-index="' + targetIdx + '"]');
                if (row) {
                    row.scrollIntoView({ block: 'nearest', behavior: 'auto' });
                    try {
                        row.focus({ preventScroll: true });
                    } catch (focusErr) {
                        /* ignore */
                    }
                }
            }

            try {
                var cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('open_notification_id');
                window.history.replaceState(
                    {},
                    document.title,
                    cleanUrl.pathname + (cleanUrl.search ? cleanUrl.search : '') + cleanUrl.hash
                );
            } catch (urlCleanErr) {
                /* ignore */
            }

            return true;
        }

        window.setTimeout(function () {
            emailLogsOpenFromDeepLink(0);
        }, 0);
        emailLogsHydrateUnreadUi();
    };
})();
