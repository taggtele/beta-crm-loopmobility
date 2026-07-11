/* eslint-env browser */
/* global window, document, fetch, FormData */
(function () {
    var config = window.messageTemplatesConfig || {};
    var apiUrl = config.apiUrl || '';
    var csrfToken = config.csrfToken || '';
    var templates = [];
    var templatesLoaded = false;
    var templatesLoading = false;
    var loadPromise = null;
    var composeMenuOpen = null;

    function hasComposeEditor() {
        return !!document.getElementById('compose-body-html');
    }

    function getTemplateManagerNodes() {
        var manager = document.querySelector('[data-message-templates-manager]');
        if (!manager) {
            return null;
        }

        return {
            manager: manager,
            form: manager.querySelector('[data-message-templates-form]'),
            list: manager.querySelector('[data-message-templates-list]'),
            status: manager.querySelector('[data-message-templates-status]'),
            submit: manager.querySelector('[data-message-templates-submit]'),
            cancel: manager.querySelector('[data-message-templates-cancel]'),
            id: manager.querySelector('[data-message-template-id]'),
            title: manager.querySelector('[data-message-template-title]'),
            content: manager.querySelector('[data-message-template-content]'),
        };
    }

    function getComposePickers() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-message-template-picker]'));
    }

    function setStatus(nodes, message, type) {
        if (!nodes || !nodes.status) {
            return;
        }
        nodes.status.textContent = message || '';
        nodes.status.className = 'profile-template-status' + (type ? ' is-' + type : '');
    }

    function apiRequest(action, data) {
        if (!apiUrl) {
            return Promise.reject(new Error('Template API is not configured.'));
        }

        var formData = new FormData();
        formData.append('action', action);
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }
        if (data) {
            Object.keys(data).forEach(function (key) {
                if (data[key] !== undefined && data[key] !== null) {
                    formData.append(key, data[key]);
                }
            });
        }

        return fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json || json.ok === false) {
                    var error = (json && json.error) ? json.error : 'Request failed.';
                    throw new Error(error);
                }
                return json;
            });
        });
    }

    function fetchTemplates(force) {
        if (!apiUrl) {
            return Promise.resolve([]);
        }

        if (templatesLoaded && !force) {
            return Promise.resolve(templates);
        }

        if (templatesLoading && loadPromise) {
            return loadPromise;
        }

        templatesLoading = true;
        loadPromise = fetch(apiUrl + '?action=list', {
            credentials: 'same-origin',
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json || json.ok === false) {
                    var error = (json && json.error) ? json.error : 'Unable to load templates.';
                    throw new Error(error);
                }
                templates = Array.isArray(json.templates) ? json.templates : [];
                templatesLoaded = true;
                templatesLoading = false;
                renderAll();
                return templates;
            });
        }).catch(function (error) {
            templates = [];
            templatesLoaded = false;
            templatesLoading = false;
            renderAll(error ? error.message : 'Unable to load templates.');
            return [];
        });

        return loadPromise;
    }

    function templateSnippet(content) {
        var text = String(content || '').replace(/\r\n/g, '\n').trim();
        if (!text) {
            return '';
        }
        text = text.replace(/\n+/g, ' ');
        return text.length > 100 ? text.slice(0, 100) + '...' : text;
    }

    function renderProfileManager(errorMessage) {
        var nodes = getTemplateManagerNodes();
        if (!nodes || !nodes.list) {
            return;
        }

        var list = nodes.list;
        list.innerHTML = '';

        if (errorMessage) {
            list.innerHTML = '<div class="profile-template-item"><div class="profile-template-item__preview">' + escapeHtml(errorMessage) + '</div></div>';
            setStatus(nodes, errorMessage, 'error');
            return;
        }

        if (templatesLoading && !templates.length) {
            list.innerHTML = '<div class="profile-template-item"><div class="profile-template-item__preview">Loading templates...</div></div>';
            setStatus(nodes, 'Loading templates...');
            return;
        }

        if (!templates.length) {
            list.innerHTML = '<div class="profile-template-item"><div class="profile-template-item__preview">No message templates saved yet.</div></div>';
            setStatus(nodes, 'Create a template to reuse it in compose windows.');
            return;
        }

        templates.forEach(function (template) {
            var item = document.createElement('div');
            item.className = 'profile-template-item';
            item.setAttribute('data-template-id', String(template.id || ''));

            var header = document.createElement('div');
            header.className = 'profile-template-item__header';

            var titleWrap = document.createElement('div');
            var title = document.createElement('h4');
            title.className = 'profile-template-item__title';
            title.textContent = template.title || 'Untitled template';
            titleWrap.appendChild(title);

            var meta = document.createElement('div');
            meta.className = 'profile-template-item__meta';
            meta.textContent = template.updated_at ? ('Updated ' + String(template.updated_at)) : '';
            titleWrap.appendChild(meta);

            var actions = document.createElement('div');
            actions.className = 'profile-template-actions';

            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'btn btn-secondary btn-sm';
            editBtn.textContent = 'Edit';
            editBtn.addEventListener('click', function () {
                fillTemplateForm(template);
            });

            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'btn btn-outline btn-sm';
            deleteBtn.textContent = 'Delete';
            deleteBtn.addEventListener('click', function () {
                deleteTemplate(template.id);
            });

            actions.appendChild(editBtn);
            actions.appendChild(deleteBtn);

            header.appendChild(titleWrap);
            header.appendChild(actions);

            var preview = document.createElement('div');
            preview.className = 'profile-template-item__preview';
            preview.textContent = template.content || '';

            item.appendChild(header);
            item.appendChild(preview);
            list.appendChild(item);
        });

        setStatus(nodes, templates.length + ' template' + (templates.length === 1 ? '' : 's') + ' saved.');
    }

    function clearTemplateForm() {
        var nodes = getTemplateManagerNodes();
        if (!nodes || !nodes.form) {
            return;
        }

        if (nodes.id) {
            nodes.id.value = '';
        }
        if (nodes.title) {
            nodes.title.value = '';
        }
        if (nodes.content) {
            nodes.content.value = '';
        }
        if (nodes.submit) {
            nodes.submit.textContent = 'Save Template';
        }
        setStatus(nodes, '');
    }

    function fillTemplateForm(template) {
        var nodes = getTemplateManagerNodes();
        if (!nodes || !nodes.form || !template) {
            return;
        }

        if (nodes.id) {
            nodes.id.value = String(template.id || '');
        }
        if (nodes.title) {
            nodes.title.value = template.title || '';
        }
        if (nodes.content) {
            nodes.content.value = template.content || '';
        }
        if (nodes.submit) {
            nodes.submit.textContent = 'Update Template';
        }
        setStatus(nodes, 'Editing template "' + (template.title || 'Untitled') + '".');
        nodes.title.focus();
    }

    function deleteTemplate(templateId) {
        if (!templateId) {
            return;
        }
        if (!window.confirm('Delete this message template?')) {
            return;
        }

        apiRequest('delete', { template_id: templateId }).then(function () {
            clearTemplateForm();
            return fetchTemplates(true);
        }).catch(function (error) {
            var nodes = getTemplateManagerNodes();
            setStatus(nodes, error.message || 'Could not delete template.', 'error');
        });
    }

    function saveTemplate(form) {
        var nodes = getTemplateManagerNodes();
        if (!form) {
            return;
        }

        var formData = new FormData(form);
        formData.set('action', 'save');
        if (csrfToken) {
            formData.set('csrf_token', csrfToken);
        }

        var payload = {
            template_id: formData.get('template_id') || '',
            title: formData.get('title') || '',
            content: formData.get('content') || '',
        };

        apiRequest('save', payload).then(function () {
            form.reset();
            if (nodes && nodes.id) {
                nodes.id.value = '';
            }
            if (nodes && nodes.submit) {
                nodes.submit.textContent = 'Save Template';
            }
            setStatus(nodes, 'Template saved.', 'success');
            return fetchTemplates(true);
        }).catch(function (error) {
            setStatus(nodes, error.message || 'Could not save template.', 'error');
        });
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function insertTemplate(template) {
        var content = template && template.content ? String(template.content) : '';
        if (!content.trim()) {
            return;
        }

        if (window.composeRichEditor && typeof window.composeRichEditor.prependBody === 'function') {
            window.composeRichEditor.prependBody(content);
            if (typeof window.composeRichEditor.focus === 'function') {
                window.composeRichEditor.focus();
            }
            return;
        }

        var hiddenBody = document.getElementById('compose-body');
        if (hiddenBody) {
            var existing = String(hiddenBody.value || '');
            hiddenBody.value = content + (existing ? '\n\n' + existing : '');
        }
    }

    function renderComposePickers() {
        var pickers = getComposePickers();
        if (!pickers.length) {
            return;
        }

        pickers.forEach(function (picker) {
            var toggle = picker.querySelector('[data-message-template-toggle]');
            var menu = picker.querySelector('[data-message-template-menu]');
            if (!toggle || !menu) {
                return;
            }

            if (picker.getAttribute('data-message-template-bound') !== '1') {
                toggle.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (composeMenuOpen && composeMenuOpen !== menu) {
                        composeMenuOpen.hidden = true;
                    }
                    menu.hidden = !menu.hidden;
                    composeMenuOpen = menu.hidden ? null : menu;
                });
                picker.setAttribute('data-message-template-bound', '1');
            }

            menu.innerHTML = '';

            if (templatesLoading && !templates.length) {
                menu.appendChild(buildComposeMenuItem('Loading templates...', '', true));
                return;
            }

            if (!templates.length) {
                menu.appendChild(buildComposeMenuItem('No templates saved', 'Save one in your profile to reuse it here.', true));
                return;
            }

            templates.forEach(function (template) {
                var item = buildComposeMenuItem(template.title || 'Untitled template', templateSnippet(template.content || ''));
                item.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    insertTemplate(template);
                    menu.hidden = true;
                    composeMenuOpen = null;
                });
                menu.appendChild(item);
            });
        });
    }

    function buildComposeMenuItem(title, preview, disabled) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'compose-template-menu__item';
        if (disabled) {
            button.disabled = true;
        }

        var titleEl = document.createElement('span');
        titleEl.className = 'compose-template-menu__title';
        titleEl.textContent = title;

        var previewEl = document.createElement('span');
        previewEl.className = 'compose-template-menu__preview';
        previewEl.textContent = preview || '';

        button.appendChild(titleEl);
        if (preview) {
            button.appendChild(previewEl);
        }

        return button;
    }

    function renderAll(errorMessage) {
        renderProfileManager(errorMessage);
        renderComposePickers();
    }

    function closeOpenComposeMenu(event) {
        if (!composeMenuOpen) {
            return;
        }

        var target = event.target;
        if (composeMenuOpen.contains(target) || (target && target.closest && target.closest('[data-message-template-picker]'))) {
            return;
        }

        composeMenuOpen.hidden = true;
        composeMenuOpen = null;
    }

    function initProfileForm() {
        var nodes = getTemplateManagerNodes();
        if (!nodes || !nodes.form || nodes.form.getAttribute('data-message-templates-bound') === '1') {
            return;
        }

        nodes.form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (nodes.form.checkValidity && !nodes.form.checkValidity()) {
                if (typeof nodes.form.reportValidity === 'function') {
                    nodes.form.reportValidity();
                }
                return;
            }
            saveTemplate(nodes.form);
        });

        if (nodes.cancel) {
            nodes.cancel.addEventListener('click', function () {
                clearTemplateForm();
            });
        }

        nodes.form.setAttribute('data-message-templates-bound', '1');
    }

    function init() {
        if (!apiUrl) {
            return;
        }

        initProfileForm();
        fetchTemplates(true);

        document.addEventListener('click', closeOpenComposeMenu);
    }

    window.messageTemplates = {
        refresh: function () {
            return fetchTemplates(true);
        },
        insert: insertTemplate,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
