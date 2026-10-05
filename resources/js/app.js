(() => {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const JSON_HIGHLIGHT_MAX_LENGTH = 262144;
    const JSON_HIGHLIGHT_MAX_TOKENS = 12000;

    class BindingError extends Error {
        constructor(error) {
            super(error?.message || 'The PHP operation failed.');
            this.name = 'BindingError';
            this.payload = error || {type: 'application_error', message: this.message, details: {}};
        }
    }

    const state = {
        data: {
            collections: [],
            savedRequests: [],
            history: [],
            environments: [],
            activeEnvironmentId: null,
            databases: [],
            activeDatabaseId: null,
            databaseSwitchingAllowed: true,
            managedByEnvironment: false,
            limits: {responseBytes: 2097152, uploadBytes: 26214400},
        },
        currentSavedId: null,
        currentName: 'Untitled request',
        bodyType: 'none',
        dirty: false,
        applying: false,
        sending: false,
        exportingCurl: false,
        databaseSwitching: false,
        pendingBindings: 0,
        response: null,
        responseBodyView: 'pretty',
        previewObjectUrl: null,
        entitySubmit: null,
        editingEnvironmentId: null,
        confirmResolve: null,
        jsonHighlightFrame: null,
        jsonEditorResizeObserver: null,
        variableSuggestionItems: [],
        activeVariableSuggestion: -1,
        variableCompletion: null,
        variableSuggestionInput: null,
    };

    const elements = {};

    const defaultRequest = () => ({
        method: 'GET',
        url: '',
        query: [],
        headers: [],
        body: {type: 'none', content: '', fields: []},
        auth: {type: 'none'},
        timeout: 30,
    });

    function cacheElements() {
        Object.assign(elements, {
            appShell: $('#appShell'),
            bootOverlay: $('#bootOverlay'),
            sidebar: $('#sidebar'),
            sidebarBackdrop: $('#sidebarBackdrop'),
            sidebarResizer: $('#sidebarResizer'),
            mobileMenuButton: $('#mobileMenuButton'),
            collectionsTree: $('#collectionsTree'),
            collectionSearch: $('#collectionSearch'),
            historyList: $('#historyList'),
            environmentSelect: $('#environmentSelect'),
            environmentDot: $('#environmentDot'),
            databasePicker: $('#databasePicker'),
            databaseSelect: $('#databaseSelect'),
            manageDatabasesButton: $('#manageDatabasesButton'),
            requestPane: $('.request-pane'),
            requestForm: $('#requestForm'),
            requestTitle: $('#requestTitle'),
            dirtyDot: $('#dirtyDot'),
            methodSelect: $('#methodSelect'),
            methodWrap: $('.method-select-wrap'),
            urlField: $('#urlField'),
            urlInput: $('#urlInput'),
            variableSuggestions: $('#variableSuggestions'),
            timeoutInput: $('#timeoutInput'),
            sendButton: $('#sendButton'),
            exportCurlButton: $('#exportCurlButton'),
            curlModal: $('#curlModal'),
            curlCommand: $('#curlCommand'),
            copyCurlButton: $('#copyCurlButton'),
            paramsEditor: $('#paramsEditor'),
            headersEditor: $('#headersEditor'),
            formEditor: $('#formEditor'),
            multipartEditor: $('#multipartEditor'),
            paramsCount: $('#paramsCount'),
            headersCount: $('#headersCount'),
            bodyDot: $('#bodyDot'),
            authType: $('#authType'),
            bearerToken: $('#bearerToken'),
            basicUsername: $('#basicUsername'),
            basicPassword: $('#basicPassword'),
            apiKeyName: $('#apiKeyName'),
            apiKeyValue: $('#apiKeyValue'),
            apiKeyLocation: $('#apiKeyLocation'),
            jsonBodyHighlight: $('#jsonBodyHighlight'),
            jsonBodyInput: $('#jsonBodyInput'),
            rawBodyInput: $('#rawBodyInput'),
            responseEmpty: $('#responseEmpty'),
            responseLoading: $('#responseLoading'),
            responseError: $('#responseError'),
            responseErrorType: $('#responseErrorType'),
            responseErrorMessage: $('#responseErrorMessage'),
            responseErrorDetails: $('#responseErrorDetails'),
            responseStats: $('#responseStats'),
            responseStatus: $('#responseStatus'),
            responseTime: $('#responseTime'),
            responseSize: $('#responseSize'),
            truncatedPill: $('#truncatedPill'),
            responseControls: $('#responseControls'),
            responseBodyPanel: $('#responseBodyPanel'),
            responseHeadersPanel: $('#responseHeadersPanel'),
            responsePreviewPanel: $('#responsePreviewPanel'),
            responseBody: $('#responseBody'),
            responseHeaders: $('#responseHeaders'),
            responsePreview: $('#responsePreview'),
            responseHeaderCount: $('#responseHeaderCount'),
            responseEncoding: $('#responseEncoding'),
            deleteRequestButton: $('#deleteRequestButton'),
            entityModal: $('#entityModal'),
            entityModalForm: $('#entityModalForm'),
            entityModalTitle: $('#entityModalTitle'),
            entityModalEyebrow: $('#entityModalEyebrow'),
            entityModalSubmit: $('#entityModalSubmit'),
            entityNameLabel: $('#entityNameLabel'),
            entityNameInput: $('#entityNameInput'),
            entityDescriptionField: $('#entityDescriptionField'),
            entityDescriptionInput: $('#entityDescriptionInput'),
            saveModal: $('#saveModal'),
            saveModalForm: $('#saveModalForm'),
            saveRequestName: $('#saveRequestName'),
            saveCollectionSelect: $('#saveCollectionSelect'),
            saveFolderSelect: $('#saveFolderSelect'),
            environmentModal: $('#environmentModal'),
            databaseModal: $('#databaseModal'),
            openExistingDatabaseButton: $('#openExistingDatabaseButton'),
            createDatabaseButton: $('#createDatabaseButton'),
            environmentList: $('#environmentList'),
            environmentForm: $('#environmentForm'),
            environmentNameInput: $('#environmentNameInput'),
            environmentVariables: $('#environmentVariables'),
            deleteEnvironmentButton: $('#deleteEnvironmentButton'),
            confirmModal: $('#confirmModal'),
            confirmTitle: $('#confirmTitle'),
            confirmMessage: $('#confirmMessage'),
            confirmAccept: $('#confirmAccept'),
            confirmCancel: $('#confirmCancel'),
            toastRegion: $('#toastRegion'),
        });
    }

    async function waitForBindings() {
        for (let attempt = 0; attempt < 160; attempt += 1) {
            if (typeof window.api?.bootstrap === 'function') {
                return;
            }

            await new Promise((resolve) => window.setTimeout(resolve, 50));
        }

        throw new Error('Boson Function Bindings did not become available. Run the UI with php index.php.');
    }

    function bindingAt(path) {
        return path.split('.').reduce((context, segment) => context?.[segment], window);
    }

    async function invoke(path, ...args) {
        const binding = bindingAt(path);

        if (typeof binding !== 'function') {
            throw new Error(`Boson binding "${path}" is not available.`);
        }

        state.pendingBindings += 1;

        try {
            const result = await binding(...args);

            if (!result || result.ok !== true) {
                throw new BindingError(result?.error);
            }

            return result.data;
        } finally {
            state.pendingBindings = Math.max(0, state.pendingBindings - 1);
        }
    }

    async function refreshWorkspace() {
        applyWorkspaceData(await invoke('api.bootstrap'));
    }

    function applyWorkspaceData(data) {
        state.data = {...state.data, ...(data && typeof data === 'object' ? data : {})};
        renderDatabaseSelect();
        renderCollections();
        renderHistory();
        renderEnvironmentSelect();
        updateRequestHeading();
        if (state.variableSuggestionInput) renderVariableSuggestions();
    }

    function attachEvents() {
        $$('.sidebar-tab').forEach((button) => {
            button.addEventListener('click', () => switchSidebarTab(button.dataset.sidebarTab));
        });

        $$('.editor-tab').forEach((button) => {
            button.addEventListener('click', () => switchRequestTab(button.dataset.requestTab));
        });

        $$('[data-response-tab]').forEach((button) => {
            button.addEventListener('click', () => switchResponseTab(button.dataset.responseTab));
        });

        $$('[data-body-view]').forEach((button) => {
            button.addEventListener('click', () => {
                state.responseBodyView = button.dataset.bodyView;
                $$('[data-body-view]').forEach((item) => item.classList.toggle('is-active', item === button));
                renderResponseBody();
            });
        });

        $$('[data-body-type]').forEach((button) => {
            button.addEventListener('click', () => {
                setBodyType(button.dataset.bodyType);
                markDirty();
            });
        });

        $$('[data-add-row]').forEach((button) => {
            button.addEventListener('click', () => {
                addRowFor(button.dataset.addRow);
                markDirty();
            });
        });

        $$('[data-close-modal]').forEach((button) => {
            button.addEventListener('click', () => closeModal(button.dataset.closeModal));
        });

        [elements.entityModal, elements.saveModal, elements.environmentModal, elements.databaseModal, elements.curlModal].forEach((modal) => {
            modal.addEventListener('mousedown', (event) => {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            });
        });

        elements.requestPane.addEventListener('input', markDirty);
        elements.requestPane.addEventListener('change', () => {
            markDirty();
            updateEditorCounts();
        });
        elements.requestForm.addEventListener('submit', sendRequest);
        elements.exportCurlButton.addEventListener('click', exportCurl);
        elements.copyCurlButton.addEventListener('click', copyCurlCommand);
        elements.methodSelect.addEventListener('change', () => {
            elements.methodWrap.dataset.method = elements.methodSelect.value;
        });
        ['input', 'focusin', 'click'].forEach((type) => {
            elements.requestPane.addEventListener(type, (event) => {
                if (event.target.matches('[data-variable-input]')) renderVariableSuggestions();
            });
        });
        elements.requestPane.addEventListener('keydown', handleVariableSuggestionKeydown);
        elements.requestPane.addEventListener('keyup', (event) => {
            if (
                event.target.matches('[data-variable-input]')
                && !['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(event.key)
            ) {
                renderVariableSuggestions();
            }
        });
        elements.requestPane.addEventListener('focusout', (event) => {
            if (event.target === state.variableSuggestionInput) hideVariableSuggestions();
        });
        elements.variableSuggestions.addEventListener('mousedown', (event) => event.preventDefault());
        elements.variableSuggestions.addEventListener('click', handleVariableSuggestionClick);
        window.addEventListener('resize', positionVariableSuggestions);
        window.addEventListener('scroll', (event) => {
            if (!(event.target instanceof Node) || !elements.variableSuggestions.contains(event.target)) {
                positionVariableSuggestions();
            }
        }, true);
        elements.authType.addEventListener('change', renderAuthSection);
        elements.collectionSearch.addEventListener('input', renderCollections);
        elements.collectionsTree.addEventListener('click', handleTreeClick);
        elements.historyList.addEventListener('click', handleHistoryClick);
        elements.environmentSelect.addEventListener('change', activateSelectedEnvironment);
        elements.databaseSelect.addEventListener('change', switchSelectedDatabase);
        elements.saveCollectionSelect.addEventListener('change', populateFolderSelect);
        elements.entityModalForm.addEventListener('submit', submitEntityModal);
        elements.entityNameInput.addEventListener('input', () => elements.entityNameInput.setCustomValidity(''));
        elements.saveModalForm.addEventListener('submit', submitSaveModal);
        elements.environmentForm.addEventListener('submit', saveEnvironment);
        elements.environmentList.addEventListener('click', handleEnvironmentListClick);
        elements.environmentVariables.addEventListener('change', handleEnvironmentVariableChange);
        elements.environmentVariables.addEventListener('click', handleEnvironmentVariableClick);
        elements.jsonBodyInput.addEventListener('input', scheduleRequestJsonHighlight);
        elements.jsonBodyInput.addEventListener('scroll', syncRequestJsonHighlightScroll);

        if (typeof ResizeObserver === 'function') {
            state.jsonEditorResizeObserver = new ResizeObserver(syncRequestJsonHighlightScroll);
            state.jsonEditorResizeObserver.observe(elements.jsonBodyInput);
        }

        $('#addCollectionButton').addEventListener('click', openCreateCollectionModal);
        $('#clearHistoryButton').addEventListener('click', clearHistory);
        $('#newRequestButton').addEventListener('click', newRequest);
        $('#saveRequestButton').addEventListener('click', saveCurrentRequest);
        $('#saveAsRequestButton').addEventListener('click', () => openSaveModal(true));
        elements.deleteRequestButton.addEventListener('click', deleteCurrentRequest);
        $('#manageEnvironmentsButton').addEventListener('click', openEnvironmentModal);
        elements.manageDatabasesButton.addEventListener('click', openDatabaseModal);
        elements.openExistingDatabaseButton.addEventListener('click', () => chooseDatabase('api.database.open'));
        elements.createDatabaseButton.addEventListener('click', openCreateDatabaseModal);
        $('#newEnvironmentButton').addEventListener('click', () => {
            editEnvironment(null);
            window.setTimeout(() => elements.environmentNameInput.select(), 0);
        });
        $('#addEnvironmentVariable').addEventListener('click', () => addEnvironmentVariableRow());
        elements.deleteEnvironmentButton.addEventListener('click', deleteEditingEnvironment);
        $('#formatJsonButton').addEventListener('click', formatRequestJson);
        $('#copyResponseButton').addEventListener('click', copyResponseBody);
        elements.mobileMenuButton.addEventListener('click', () => elements.appShell.classList.toggle('sidebar-open'));
        elements.sidebarBackdrop.addEventListener('click', closeMobileSidebar);
        elements.confirmCancel.addEventListener('click', () => resolveConfirmation(false));
        elements.confirmAccept.addEventListener('click', () => resolveConfirmation(true));
        elements.confirmModal.addEventListener('mousedown', (event) => {
            if (event.target === elements.confirmModal) {
                resolveConfirmation(false);
            }
        });
        window.addEventListener('beforeunload', clearResponsePreview, {once: true});

        setupSidebarResize();
        setupKeyboardShortcuts();
    }

    function switchSidebarTab(tab) {
        $$('.sidebar-tab').forEach((button) => button.classList.toggle('is-active', button.dataset.sidebarTab === tab));
        $$('[data-sidebar-panel]').forEach((panel) => panel.classList.toggle('is-active', panel.dataset.sidebarPanel === tab));
    }

    function switchRequestTab(tab) {
        hideVariableSuggestions();
        $$('.editor-tab').forEach((button) => button.classList.toggle('is-active', button.dataset.requestTab === tab));
        $$('[data-request-panel]').forEach((panel) => panel.classList.toggle('is-active', panel.dataset.requestPanel === tab));
    }

    function switchResponseTab(tab) {
        $$('[data-response-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.responseTab === tab));
        elements.responseBodyPanel.classList.toggle('is-hidden', tab !== 'body');
        elements.responseHeadersPanel.classList.toggle('is-hidden', tab !== 'headers');
        elements.responsePreviewPanel.classList.toggle('is-hidden', tab !== 'preview');
    }

    function setBodyType(type) {
        const allowed = ['none', 'json', 'raw', 'form', 'multipart'];
        state.bodyType = allowed.includes(type) ? type : 'none';
        $$('[data-body-type]').forEach((button) => button.classList.toggle('is-active', button.dataset.bodyType === state.bodyType));
        $$('[data-body-panel]').forEach((panel) => panel.classList.toggle('is-active', panel.dataset.bodyPanel === state.bodyType));
        updateEditorCounts();
    }

    function renderAuthSection() {
        const type = elements.authType.value;
        $$('[data-auth-section]').forEach((section) => section.classList.toggle('is-active', section.dataset.authSection === type));
    }

    function addRowFor(type) {
        const editors = {
            params: elements.paramsEditor,
            headers: elements.headersEditor,
            form: elements.formEditor,
        };

        if (type === 'multipart') {
            addMultipartRow();
        } else if (editors[type]) {
            addKeyValueRow(editors[type]);
        }

        updateEditorCounts();
    }

    function addKeyValueRow(container, data = {}) {
        const row = $('#keyValueRowTemplate').content.firstElementChild.cloneNode(true);
        $('.row-enabled', row).checked = data.enabled !== false;
        $('.row-key', row).value = data.key ?? '';
        $('.row-value', row).value = data.value ?? '';
        if (container === elements.headersEditor) {
            $$('.row-key, .row-value', row).forEach((input) => {
                input.dataset.variableInput = '';
                input.setAttribute('role', 'combobox');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-expanded', 'false');
                input.setAttribute('aria-controls', 'variableSuggestions');
            });
        }
        $('.row-delete', row).addEventListener('click', () => {
            if (row.contains(state.variableSuggestionInput)) hideVariableSuggestions();
            row.remove();
            markDirty();
            updateEditorCounts();
        });
        container.append(row);

        return row;
    }

    function hasKeyValueContent(row) {
        return row && typeof row === 'object' && (
            String(row.key ?? '').trim() !== ''
            || String(row.value ?? '') !== ''
        );
    }

    function renderKeyValueRows(container, rows) {
        container.replaceChildren();
        const values = Array.isArray(rows) ? rows.filter(hasKeyValueContent) : [];
        values.forEach((row) => addKeyValueRow(container, row));
    }

    function addMultipartRow(data = {}) {
        const row = $('#multipartRowTemplate').content.firstElementChild.cloneNode(true);
        const kind = $('.multipart-kind', row);
        const filePicker = $('.file-picker', row);
        const textValue = $('.multipart-text-value', row);
        const fileInput = $('.multipart-file-input', row);
        const fileLabel = $('.file-picker-label', row);
        $('.row-enabled', row).checked = data.enabled !== false;
        $('.row-key', row).value = data.key ?? '';
        kind.value = data.type === 'file' ? 'file' : 'text';
        textValue.value = data.value ?? '';

        if (data.file && typeof data.file === 'object') {
            row._fileData = {...data.file};
            fileLabel.textContent = `${data.file.name || 'File'}${data.file.missing ? ' · choose again' : ''}`;
        }

        const updateKind = () => {
            const isFile = kind.value === 'file';
            textValue.classList.toggle('is-hidden', isFile);
            filePicker.classList.toggle('is-hidden', !isFile);
        };
        kind.addEventListener('change', updateKind);
        fileInput.addEventListener('change', async () => {
            const file = fileInput.files?.[0];

            if (!file) {
                return;
            }

            if (file.size > state.data.limits.uploadBytes) {
                fileInput.value = '';
                toast(`File is larger than the ${formatBytes(state.data.limits.uploadBytes)} upload limit.`, true);
                return;
            }

            fileLabel.textContent = 'Reading file…';

            try {
                row._fileData = {
                    name: file.name,
                    type: file.type || 'application/octet-stream',
                    size: file.size,
                    contentBase64: arrayBufferToBase64(await file.arrayBuffer()),
                };
                fileLabel.textContent = `${file.name} · ${formatBytes(file.size)}`;
                markDirty();
            } catch (error) {
                row._fileData = null;
                fileLabel.textContent = 'Choose file';
                toast(error.message || 'Unable to read the selected file.', true);
            }
        });
        $('.row-delete', row).addEventListener('click', () => {
            row.remove();
            markDirty();
            updateEditorCounts();
        });
        updateKind();
        elements.multipartEditor.append(row);

        return row;
    }

    function renderMultipartRows(rows) {
        elements.multipartEditor.replaceChildren();
        const values = Array.isArray(rows) ? rows.filter((row) => (
            hasKeyValueContent(row)
            || (row?.file && typeof row.file === 'object')
        )) : [];
        values.forEach(addMultipartRow);
    }

    function arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        const chunkSize = 0x8000;
        let binary = '';

        for (let offset = 0; offset < bytes.length; offset += chunkSize) {
            binary += String.fromCharCode(...bytes.subarray(offset, Math.min(offset + chunkSize, bytes.length)));
        }

        return btoa(binary);
    }

    function collectRows(container) {
        return $$('.kv-row', container).map((row) => ({
            enabled: $('.row-enabled', row).checked,
            key: $('.row-key', row).value,
            value: $('.row-value', row).value,
        })).filter(hasKeyValueContent);
    }

    function collectMultipartRows() {
        return $$('.multipart-row', elements.multipartEditor).map((row) => {
            const type = $('.multipart-kind', row).value;

            return {
                enabled: $('.row-enabled', row).checked,
                key: $('.row-key', row).value,
                type,
                value: type === 'text' ? $('.row-value', row).value : '',
                ...(type === 'file' && row._fileData ? {file: {...row._fileData}} : {}),
            };
        }).filter((row) => hasKeyValueContent(row) || row.file);
    }

    function collectAuth() {
        const type = elements.authType.value;

        if (type === 'bearer') {
            return {type, token: elements.bearerToken.value};
        }

        if (type === 'basic') {
            return {type, username: elements.basicUsername.value, password: elements.basicPassword.value};
        }

        if (type === 'api_key') {
            return {
                type,
                key: elements.apiKeyName.value,
                value: elements.apiKeyValue.value,
                location: elements.apiKeyLocation.value,
            };
        }

        return {type: 'none'};
    }

    function collectBody() {
        if (state.bodyType === 'json') {
            return {type: 'json', content: elements.jsonBodyInput.value, fields: []};
        }

        if (state.bodyType === 'raw') {
            return {type: 'raw', content: elements.rawBodyInput.value, fields: []};
        }

        if (state.bodyType === 'form') {
            return {type: 'form', content: '', fields: collectRows(elements.formEditor)};
        }

        if (state.bodyType === 'multipart') {
            return {type: 'multipart', content: '', fields: collectMultipartRows()};
        }

        return {type: 'none', content: '', fields: []};
    }

    function collectRequest() {
        return {
            method: elements.methodSelect.value,
            url: elements.urlInput.value.trim(),
            query: collectRows(elements.paramsEditor),
            headers: collectRows(elements.headersEditor),
            body: collectBody(),
            auth: collectAuth(),
            timeout: Number(elements.timeoutInput.value || 30),
        };
    }

    function applyRequest(request, options = {}) {
        const normalized = request && typeof request === 'object' ? request : defaultRequest();
        const body = normalized.body && typeof normalized.body === 'object' ? normalized.body : {type: 'none'};
        const auth = normalized.auth && typeof normalized.auth === 'object' ? normalized.auth : {type: 'none'};
        state.applying = true;
        state.currentSavedId = options.savedId ?? null;
        state.currentName = options.name || 'Untitled request';
        elements.methodSelect.value = normalized.method || 'GET';
        elements.methodWrap.dataset.method = elements.methodSelect.value;
        elements.urlInput.value = normalized.url || '';
        hideVariableSuggestions();
        elements.timeoutInput.value = normalized.timeout || 30;
        renderKeyValueRows(elements.paramsEditor, normalized.query || normalized.params || []);
        renderKeyValueRows(elements.headersEditor, normalized.headers || []);
        elements.jsonBodyInput.value = body.type === 'json' ? body.content || '' : '';
        elements.rawBodyInput.value = body.type === 'raw' ? body.content || '' : '';
        renderKeyValueRows(elements.formEditor, body.type === 'form' ? body.fields || [] : []);
        renderMultipartRows(body.type === 'multipart' ? body.fields || [] : []);
        setBodyType(body.type || 'none');
        renderRequestJsonHighlight();
        elements.authType.value = ['none', 'bearer', 'basic', 'api_key'].includes(auth.type) ? auth.type : 'none';
        elements.bearerToken.value = auth.token || '';
        elements.basicUsername.value = auth.username || '';
        elements.basicPassword.value = auth.password || '';
        elements.apiKeyName.value = auth.key || '';
        elements.apiKeyValue.value = auth.value || '';
        elements.apiKeyLocation.value = auth.location === 'query' ? 'query' : 'header';
        renderAuthSection();
        state.applying = false;
        setDirty(false);
        updateRequestHeading();
        updateEditorCounts();

        if (options.clearResponse !== false) {
            showResponseState('empty');
        }
    }

    function markDirty() {
        if (!state.applying) {
            setDirty(true);
        }
    }

    function setDirty(dirty) {
        state.dirty = Boolean(dirty);
        elements.dirtyDot.classList.toggle('is-visible', state.dirty);
    }

    function updateRequestHeading() {
        elements.requestTitle.textContent = state.currentName;
        elements.deleteRequestButton.classList.toggle('is-hidden', state.currentSavedId === null);
        $$('.request-tree-item', elements.collectionsTree).forEach((item) => {
            item.classList.toggle('is-active', Number(item.dataset.requestId) === state.currentSavedId);
        });
    }

    function updateEditorCounts() {
        const countRows = (container) => $$('.kv-row', container).filter((row) => (
            $('.row-enabled', row).checked && $('.row-key', row).value.trim() !== ''
        )).length;
        elements.paramsCount.textContent = String(countRows(elements.paramsEditor));
        elements.headersCount.textContent = String(countRows(elements.headersEditor));
        const hasBody = state.bodyType !== 'none' && (
            (state.bodyType === 'json' && elements.jsonBodyInput.value.trim() !== '')
            || (state.bodyType === 'raw' && elements.rawBodyInput.value !== '')
            || (state.bodyType === 'form' && countRows(elements.formEditor) > 0)
            || (state.bodyType === 'multipart' && countRows(elements.multipartEditor) > 0)
        );
        elements.bodyDot.classList.toggle('is-visible', hasBody);
    }

    function activeEnvironment() {
        if (state.data.activeEnvironmentId === null) {
            return null;
        }

        return state.data.environments.find(
            (environment) => Number(environment.id) === Number(state.data.activeEnvironmentId),
        ) || null;
    }

    function variableCompletionAtCursor(input) {
        const cursor = input.selectionStart;

        if (!Number.isInteger(cursor)) {
            return null;
        }

        const value = input.value;
        const beforeCursor = value.slice(0, cursor);
        const start = beforeCursor.lastIndexOf('{{');

        if (start < 0 || beforeCursor.lastIndexOf('}}') > start) {
            return null;
        }

        const query = beforeCursor.slice(start + 2);

        if (!/^[A-Za-z0-9_.-]*$/.test(query)) {
            return null;
        }

        const afterCursor = value.slice(cursor);
        const closingOffset = afterCursor.indexOf('}}');
        let end = cursor;

        if (
            closingOffset >= 0
            && /^[A-Za-z0-9_.-]*$/.test(afterCursor.slice(0, closingOffset))
        ) {
            end = cursor + closingOffset + 2;
        }

        return {start, end, query};
    }

    function currentEnvironmentVariables(environment) {
        return (environment?.variables || [])
            .filter((variable) => variable.enabled !== false && variable.enabled !== 0 && String(variable.name || '').trim() !== '')
            .map((variable) => ({
                name: String(variable.name).trim(),
                secret: Boolean(variable.is_secret),
            }));
    }

    function matchingEnvironmentVariables(variables, query) {
        const needle = query.toLocaleLowerCase();

        return variables
            .filter((variable) => variable.name.toLocaleLowerCase().includes(needle))
            .sort((left, right) => {
                const leftStarts = left.name.toLocaleLowerCase().startsWith(needle);
                const rightStarts = right.name.toLocaleLowerCase().startsWith(needle);

                if (leftStarts !== rightStarts) {
                    return leftStarts ? -1 : 1;
                }

                return left.name.localeCompare(right.name);
            })
            .slice(0, 12);
    }

    function renderVariableSuggestions() {
        const input = document.activeElement;
        if (!input?.matches('[data-variable-input]')) {
            hideVariableSuggestions();
            return;
        }

        const completion = variableCompletionAtCursor(input);

        if (!completion) {
            hideVariableSuggestions();
            return;
        }

        if (state.variableSuggestionInput !== input) hideVariableSuggestions();
        state.variableSuggestionInput = input;
        const environment = activeEnvironment();
        const variables = currentEnvironmentVariables(environment);
        const matches = matchingEnvironmentVariables(variables, completion.query);
        const heading = document.createElement('div');
        heading.className = 'variable-suggestions-heading';
        heading.textContent = environment ? `Variables · ${environment.name}` : 'Environment variables';
        elements.variableSuggestions.replaceChildren(heading);
        state.variableCompletion = completion;
        state.variableSuggestionItems = matches;
        state.activeVariableSuggestion = matches.length ? 0 : -1;

        if (!environment || !variables.length || !matches.length) {
            const empty = document.createElement('div');
            empty.className = 'variable-suggestions-empty';
            empty.setAttribute('role', 'status');
            empty.textContent = !environment
                ? 'No active environment.'
                : (!variables.length ? 'No enabled variables.' : 'No matching variables.');
            elements.variableSuggestions.append(empty);
        } else {
            matches.forEach((variable, index) => {
                const option = document.createElement('div');
                option.id = `variable-suggestion-${index}`;
                option.className = `variable-suggestion${index === 0 ? ' is-active' : ''}`;
                option.dataset.variableSuggestion = String(index);
                option.dataset.variableName = variable.name;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', index === 0 ? 'true' : 'false');

                const name = document.createElement('code');
                name.textContent = `{{${variable.name}}}`;
                const meta = document.createElement('span');
                meta.className = 'variable-suggestion-meta';
                meta.textContent = variable.secret ? 'Secret' : 'Variable';
                option.append(name, meta);
                elements.variableSuggestions.append(option);
            });
        }

        elements.variableSuggestions.classList.remove('is-hidden');
        input.setAttribute('aria-expanded', 'true');

        if (matches.length) {
            input.setAttribute('aria-activedescendant', 'variable-suggestion-0');
        } else {
            input.removeAttribute('aria-activedescendant');
        }
        positionVariableSuggestions();
    }

    function hideVariableSuggestions() {
        elements.variableSuggestions.classList.add('is-hidden');
        elements.variableSuggestions.replaceChildren();
        state.variableSuggestionInput?.setAttribute('aria-expanded', 'false');
        state.variableSuggestionInput?.removeAttribute('aria-activedescendant');
        state.variableSuggestionInput = null;
        state.variableSuggestionItems = [];
        state.activeVariableSuggestion = -1;
        state.variableCompletion = null;
    }

    function positionVariableSuggestions() {
        const input = state.variableSuggestionInput;
        const popup = elements.variableSuggestions;
        if (!input || popup.classList.contains('is-hidden')) return;

        const rect = input.getBoundingClientRect();
        const editor = input.closest('.request-editor')?.getBoundingClientRect();
        if (
            !input.isConnected || !rect.width || !rect.height
            || rect.bottom <= 0 || rect.top >= window.innerHeight
            || (editor && (rect.bottom <= editor.top || rect.top >= editor.bottom))
        ) {
            hideVariableSuggestions();
            return;
        }

        const margin = 8;
        const gap = 6;
        const width = Math.min(Math.max(rect.width, 240), window.innerWidth - margin * 2);
        popup.style.width = `${width}px`;
        popup.style.left = `${Math.max(margin, Math.min(rect.left, window.innerWidth - width - margin))}px`;
        const below = window.innerHeight - rect.bottom - gap - margin;
        const above = rect.top - gap - margin;
        const placeAbove = below < Math.min(230, popup.scrollHeight) && above > below;
        popup.style.maxHeight = `${Math.max(0, Math.min(230, placeAbove ? above : below))}px`;
        popup.style.top = `${placeAbove ? rect.top - gap - popup.offsetHeight : rect.bottom + gap}px`;
    }

    function setActiveVariableSuggestion(index) {
        const count = state.variableSuggestionItems.length;

        if (!count) return;
        state.activeVariableSuggestion = (index + count) % count;
        const options = $$('[data-variable-suggestion]', elements.variableSuggestions);

        options.forEach((option, optionIndex) => {
            const active = optionIndex === state.activeVariableSuggestion;
            option.classList.toggle('is-active', active);
            option.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        const active = options[state.activeVariableSuggestion];
        state.variableSuggestionInput.setAttribute('aria-activedescendant', active.id);
        active.scrollIntoView({block: 'nearest'});
    }

    function insertVariableSuggestion(variableName) {
        const completion = state.variableCompletion;
        const input = state.variableSuggestionInput;

        if (!completion || !input || !variableName) return;
        input.setRangeText(
            `{{${variableName}}}`,
            completion.start,
            completion.end,
            'end',
        );
        hideVariableSuggestions();
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.focus();
    }

    function handleVariableSuggestionKeydown(event) {
        if (
            event.target !== state.variableSuggestionInput
            || elements.variableSuggestions.classList.contains('is-hidden')
            || event.isComposing
        ) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            hideVariableSuggestions();
            return;
        }

        if (!state.variableSuggestionItems.length) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            event.stopPropagation();
            setActiveVariableSuggestion(
                state.activeVariableSuggestion + (event.key === 'ArrowDown' ? 1 : -1),
            );
            return;
        }

        if (
            (event.key === 'Enter' || event.key === 'Tab')
            && !event.ctrlKey
            && !event.metaKey
            && !event.altKey
        ) {
            event.preventDefault();
            event.stopPropagation();
            insertVariableSuggestion(
                state.variableSuggestionItems[state.activeVariableSuggestion]?.name,
            );
        }
    }

    function handleVariableSuggestionClick(event) {
        const option = event.target.closest('[data-variable-name]');

        if (option) {
            insertVariableSuggestion(option.dataset.variableName);
        }
    }

    function renderCollections() {
        const search = elements.collectionSearch.value.trim().toLowerCase();
        const html = state.data.collections.map((collection) => renderCollection(collection, search)).filter(Boolean).join('');
        elements.collectionsTree.innerHTML = html || `
            <div class="tree-empty">
                <strong>${search ? 'No matching requests' : 'No collections yet'}</strong>
                <p>${search ? 'Try a different search phrase.' : 'Create a collection to organize reusable requests.'}</p>
            </div>`;
        updateRequestHeading();
    }

    function renderCollection(collection, search) {
        const collectionRequests = state.data.savedRequests.filter((request) => request.collection_id === collection.id);
        const collectionMatches = !search || collection.name.toLowerCase().includes(search);
        const roots = (collection.folders || []).filter((folder) => folder.parent_id === null);
        const rootRequests = collectionRequests.filter((request) => request.folder_id === null && requestMatches(request, search, collectionMatches));
        const folderHtml = roots.map((folder) => renderFolder(folder, collection, collectionRequests, search, collectionMatches, new Set())).filter(Boolean).join('');

        if (search && !collectionMatches && !rootRequests.length && !folderHtml) {
            return '';
        }

        const requestsHtml = rootRequests.map(renderRequestTreeItem).join('');

        return `
            <details class="collection-node" open>
                <summary class="tree-node">
                    ${chevronIcon()}
                    ${collectionIcon()}
                    <span class="tree-label" title="${escapeHtml(collection.name)}">${escapeHtml(collection.name)}</span>
                    <span class="tree-actions">
                        <button class="tree-action" type="button" data-tree-action="add-folder" data-collection-id="${collection.id}" title="Add folder">+</button>
                        <button class="tree-action" type="button" data-tree-action="edit-collection" data-collection-id="${collection.id}" title="Edit collection">⋯</button>
                    </span>
                </summary>
                <div class="tree-children">${requestsHtml}${folderHtml}</div>
            </details>`;
    }

    function renderFolder(folder, collection, requests, search, parentMatches, visited) {
        if (visited.has(folder.id)) {
            return '';
        }

        const nextVisited = new Set(visited);
        nextVisited.add(folder.id);
        const folderMatches = parentMatches || !search || folder.name.toLowerCase().includes(search);
        const ownRequests = requests.filter((request) => request.folder_id === folder.id && requestMatches(request, search, folderMatches));
        const children = (collection.folders || []).filter((candidate) => candidate.parent_id === folder.id);
        const childHtml = children.map((child) => renderFolder(child, collection, requests, search, folderMatches, nextVisited)).filter(Boolean).join('');

        if (search && !folderMatches && !ownRequests.length && !childHtml) {
            return '';
        }

        return `
            <details class="folder-node" open>
                <summary class="tree-node">
                    ${chevronIcon()}
                    ${folderIcon()}
                    <span class="tree-label" title="${escapeHtml(folder.name)}">${escapeHtml(folder.name)}</span>
                    <span class="tree-actions">
                        <button class="tree-action" type="button" data-tree-action="add-subfolder" data-collection-id="${collection.id}" data-folder-id="${folder.id}" title="Add nested folder">+</button>
                        <button class="tree-action" type="button" data-tree-action="edit-folder" data-folder-id="${folder.id}" title="Edit folder">⋯</button>
                    </span>
                </summary>
                <div class="tree-children">${ownRequests.map(renderRequestTreeItem).join('')}${childHtml}</div>
            </details>`;
    }

    function requestMatches(request, search, parentMatches) {
        return parentMatches || !search || request.name.toLowerCase().includes(search)
            || request.url.toLowerCase().includes(search)
            || request.method.toLowerCase().includes(search);
    }

    function renderRequestTreeItem(request) {
        return `
            <div class="request-tree-item${request.id === state.currentSavedId ? ' is-active' : ''}" data-request-id="${request.id}" title="${escapeHtml(request.url)}">
                <span class="method-chip method-${escapeHtml(request.method)}">${escapeHtml(request.method)}</span>
                <span class="request-tree-name">${escapeHtml(request.name)}</span>
                <button class="request-tree-action" type="button" data-tree-action="rename-request" data-request-id="${request.id}" title="Rename request" aria-label="Rename ${escapeHtml(request.name)}">⋯</button>
            </div>`;
    }

    function chevronIcon() {
        return '<svg class="chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>';
    }

    function collectionIcon() {
        return '<svg class="node-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h5"/></svg>';
    }

    function folderIcon() {
        return '<svg class="node-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5h7l2-2h3.5A2.5 2.5 0 0 1 18 8v.5h1A2 2 0 0 1 21 11l-1.3 7H4.3L3 10V7.5Z"/></svg>';
    }

    async function handleTreeClick(event) {
        const actionButton = event.target.closest('[data-tree-action]');

        if (actionButton) {
            event.preventDefault();
            event.stopPropagation();
            await handleTreeAction(actionButton);
            return;
        }

        const requestItem = event.target.closest('[data-request-id]');

        if (requestItem) {
            loadSavedRequest(Number(requestItem.dataset.requestId));
        }
    }

    async function handleTreeAction(button) {
        const action = button.dataset.treeAction;
        const collectionId = Number(button.dataset.collectionId);
        const folderId = Number(button.dataset.folderId);

        if (action === 'rename-request') {
            const requestId = Number(button.dataset.requestId);
            const saved = state.data.savedRequests.find((request) => request.id === requestId);

            if (!saved) return;
            openEntityModal({
                eyebrow: 'Request',
                title: 'Rename request',
                submitLabel: 'Save changes',
                nameLabel: 'Request name',
                name: saved.name,
                maxNameLength: 160,
                showDescription: false,
                onSubmit: async ({name}) => {
                    const renamed = await invoke('api.request.rename', {id: saved.id, name});

                    if (state.currentSavedId === saved.id) {
                        state.currentName = renamed.name;
                    }

                    await refreshWorkspace();
                    toast('Request renamed.');
                },
            });
            return;
        }

        if (action === 'add-folder' || action === 'add-subfolder') {
            openEntityModal({
                eyebrow: 'Collection',
                title: action === 'add-subfolder' ? 'New nested folder' : 'New folder',
                submitLabel: 'Create folder',
                showDescription: false,
                onSubmit: async ({name}) => {
                    await invoke('api.folder.create', {
                        collectionId,
                        parentId: action === 'add-subfolder' ? folderId : null,
                        name,
                    });
                    await refreshWorkspace();
                    toast('Folder created.');
                },
            });
            return;
        }

        if (action === 'edit-collection') {
            const collection = state.data.collections.find((item) => item.id === collectionId);

            if (!collection) return;
            openEntityModal({
                eyebrow: 'Collection',
                title: 'Edit collection',
                submitLabel: 'Save changes',
                name: collection.name,
                description: collection.description,
                showDescription: true,
                onSubmit: async (values) => {
                    await invoke('api.collection.update', {id: collection.id, ...values});
                    await refreshWorkspace();
                    toast('Collection updated.');
                },
                onDelete: async () => deleteCollection(collection),
            });
            return;
        }

        if (action === 'edit-folder') {
            const folder = state.data.collections.flatMap((item) => item.folders || []).find((item) => item.id === folderId);

            if (!folder) return;
            openEntityModal({
                eyebrow: 'Folder',
                title: 'Edit folder',
                submitLabel: 'Save changes',
                name: folder.name,
                showDescription: false,
                onSubmit: async ({name}) => {
                    await invoke('api.folder.update', {id: folder.id, name});
                    await refreshWorkspace();
                    toast('Folder updated.');
                },
                onDelete: async () => deleteFolder(folder),
            });
        }
    }

    function loadSavedRequest(id) {
        const saved = state.data.savedRequests.find((request) => request.id === id);

        if (!saved) {
            return;
        }

        applyRequest(saved.request, {savedId: saved.id, name: saved.name});
        closeMobileSidebar();
    }

    function renderHistory() {
        if (!state.data.history.length) {
            elements.historyList.innerHTML = '<div class="history-empty"><strong>No requests yet</strong><p>Sent requests will be kept locally in SQLite.</p></div>';
            return;
        }

        let lastDay = '';
        const parts = [];

        state.data.history.forEach((entry) => {
            const day = historyDay(entry.created_at);

            if (day !== lastDay) {
                parts.push(`<div class="history-day">${escapeHtml(day)}</div>`);
                lastDay = day;
            }

            const status = entry.status_code ?? (entry.error_type ? 'ERR' : '—');
            const time = entry.duration_ms === null ? '' : `${formatDuration(entry.duration_ms)} · `;
            parts.push(`
                <div class="history-item" data-history-id="${entry.id}" title="${escapeHtml(entry.url)}">
                    <span class="method-chip method-${escapeHtml(entry.method)}">${escapeHtml(entry.method)}</span>
                    <span class="history-url">${escapeHtml(entry.url)}</span>
                    <span class="history-meta">${time}${escapeHtml(formatClock(entry.created_at))}</span>
                    <span class="history-status${entry.error_type ? ' is-error' : ''}">${escapeHtml(String(status))}</span>
                </div>`);
        });
        elements.historyList.innerHTML = parts.join('');
    }

    function handleHistoryClick(event) {
        const item = event.target.closest('[data-history-id]');

        if (!item) return;
        const entry = state.data.history.find((history) => history.id === Number(item.dataset.historyId));

        if (!entry) return;
        applyRequest(entry.request, {
            name: `History · ${formatClock(entry.created_at)}`,
            savedId: null,
        });
        closeMobileSidebar();
    }

    function historyDate(value) {
        return new Date(String(value).replace(' ', 'T') + 'Z');
    }

    function historyDay(value) {
        const date = historyDate(value);
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const target = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const days = Math.round((today - target) / 86400000);

        if (days === 0) return 'Today';
        if (days === 1) return 'Yesterday';

        return new Intl.DateTimeFormat(undefined, {month: 'short', day: 'numeric'}).format(date);
    }

    function formatClock(value) {
        const date = historyDate(value);

        if (Number.isNaN(date.getTime())) return '';
        return new Intl.DateTimeFormat(undefined, {hour: '2-digit', minute: '2-digit'}).format(date);
    }

    function renderDatabaseSelect() {
        const databases = Array.isArray(state.data.databases) ? state.data.databases : [];
        const availableDatabases = databases.filter((database) => database.available);
        const filenameCounts = databases.reduce((counts, database) => {
            const key = String(database.name || '').toLocaleLowerCase();
            counts.set(key, (counts.get(key) || 0) + 1);
            return counts;
        }, new Map());
        elements.databaseSelect.replaceChildren();

        databases.forEach((database) => {
            const option = document.createElement('option');
            const filename = String(database.name || 'database.sqlite');
            const pathParts = String(database.directory || '').split(/[\\/]/).filter(Boolean);
            const parent = pathParts.at(-1);
            const duplicatePrefix = filenameCounts.get(filename.toLocaleLowerCase()) > 1 && parent
                ? `${parent} / `
                : '';
            option.value = database.id;
            option.textContent = `${duplicatePrefix}${filename}${database.available ? '' : ' · unavailable'}`;
            option.title = database.pathname || database.directory;
            option.selected = database.id === state.data.activeDatabaseId;
            option.disabled = !database.available;
            elements.databaseSelect.append(option);
        });

        if (!databases.length) {
            const option = document.createElement('option');
            option.textContent = 'No database';
            elements.databaseSelect.append(option);
        }

        const allowed = state.data.databaseSwitchingAllowed !== false;
        elements.databaseSelect.disabled = state.databaseSwitching || !allowed || availableDatabases.length < 2;
        elements.manageDatabasesButton.disabled = state.databaseSwitching || !allowed;
        elements.databasePicker.classList.toggle('is-locked', !allowed);
        elements.databasePicker.title = state.data.managedByEnvironment
            ? 'Database path is controlled by RELAYDECK_STORAGE_DIR.'
            : (databases.find((database) => database.active)?.pathname || 'RelayDeck databases');
    }

    async function switchSelectedDatabase() {
        if (state.databaseSwitching) return;

        const databaseId = elements.databaseSelect.value;
        const previousId = state.data.activeDatabaseId;

        if (!databaseId || databaseId === previousId) return;

        if (!await confirmDatabaseChange()) {
            elements.databaseSelect.value = previousId || '';
            return;
        }

        setDatabaseBusy(true);
        let backendSwitched = false;
        let uiSynchronized = false;

        try {
            const workspaceData = await invoke('api.database.switch', databaseId);
            backendSwitched = true;
            resetAfterDatabaseChange(workspaceData);
            uiSynchronized = true;
            toast('Active database changed.');
        } catch (error) {
            if (backendSwitched) {
                showDatabaseSynchronizationFailure(error);
                return;
            }

            elements.databaseSelect.value = previousId || '';
            showOperationError(error);
        } finally {
            if (!backendSwitched || uiSynchronized) {
                setDatabaseBusy(false);
                renderDatabaseSelect();
            }
        }
    }

    async function chooseDatabase(binding, ...args) {
        if (state.databaseSwitching) return false;
        if (!await confirmDatabaseChange()) return false;
        setDatabaseBusy(true);
        let backendSwitched = false;
        let uiSynchronized = false;

        try {
            const result = await invoke(binding, ...args);

            if (result === null) return false;
            backendSwitched = true;
            closeModal('databaseModal');
            resetAfterDatabaseChange(result);
            uiSynchronized = true;
            toast(binding.endsWith('.create') ? 'New database created.' : 'Database opened.');
            return true;
        } catch (error) {
            if (backendSwitched) {
                showDatabaseSynchronizationFailure(error);
                return true;
            }

            showOperationError(error);
            return false;
        } finally {
            if (!backendSwitched || uiSynchronized) {
                setDatabaseBusy(false);
                renderDatabaseSelect();
            }
        }
    }

    async function confirmDatabaseChange() {
        if (databaseOperationIsBlocked()) {
            toast('Wait for the current operation to finish before changing database.', true);
            return false;
        }

        if (state.dirty && !await confirmAction(
            'Discard unsaved changes?',
            'Changing database resets the current request and response.',
            'Change database',
        )) {
            return false;
        }

        if (databaseOperationIsBlocked()) {
            toast('Wait for the current operation to finish before changing database.', true);
            return false;
        }

        return true;
    }

    function databaseOperationIsBlocked() {
        return state.sending || state.pendingBindings > 0;
    }

    function setDatabaseBusy(busy) {
        state.databaseSwitching = Boolean(busy);
        elements.appShell.classList.toggle('is-database-switching', state.databaseSwitching);
        elements.appShell.setAttribute('aria-busy', state.databaseSwitching ? 'true' : 'false');
        elements.databaseSelect.disabled = busy;
        elements.manageDatabasesButton.disabled = busy;
        elements.openExistingDatabaseButton.disabled = busy;
        elements.createDatabaseButton.disabled = busy;

        if (busy) {
            $('strong', elements.bootOverlay).textContent = 'Switching database';
            $('p', elements.bootOverlay).textContent = 'Opening SQLite and loading workspace data…';
        }
    }

    function resetAfterDatabaseChange(workspaceData) {
        $$('.modal-backdrop:not(.is-hidden)').forEach((modal) => closeModal(modal.id));
        state.response = null;
        state.currentSavedId = null;
        state.currentName = 'Untitled request';
        state.editingEnvironmentId = null;
        state.entitySubmit = null;
        elements.collectionSearch.value = '';
        elements.responseBody.textContent = '';
        elements.responseHeaders.replaceChildren();
        elements.responseErrorType.textContent = '';
        elements.responseErrorMessage.textContent = '';
        elements.responseErrorDetails.textContent = '';
        elements.responseStatus.textContent = '';
        elements.responseTime.textContent = '';
        elements.responseSize.textContent = '';
        elements.responseHeaderCount.textContent = '0';
        elements.responseEncoding.textContent = '';
        applyWorkspaceData(workspaceData);
        applyRequest(defaultRequest(), {name: 'Untitled request'});
        switchSidebarTab('collections');
        closeMobileSidebar();
        window.setTimeout(() => elements.urlInput.focus(), 0);
    }

    function showDatabaseSynchronizationFailure(error) {
        const message = error?.message || 'The new database opened, but the workspace could not be rendered.';
        $('strong', elements.bootOverlay).textContent = 'Database changed';
        $('p', elements.bootOverlay).textContent = `${message} Restart RelayDeck to reload the workspace.`;
        elements.appShell.classList.add('is-database-switching');
        elements.appShell.setAttribute('aria-busy', 'true');
    }

    function renderEnvironmentSelect() {
        const options = state.data.environments.map((environment) => (
            `<option value="${environment.id}"${environment.id === state.data.activeEnvironmentId ? ' selected' : ''}>${escapeHtml(environment.name)}</option>`
        ));
        elements.environmentSelect.innerHTML = options.length ? options.join('') : '<option value="">No environment</option>';
        elements.environmentSelect.disabled = !options.length;
        elements.environmentDot.classList.toggle('is-active', state.data.activeEnvironmentId !== null);
    }

    async function activateSelectedEnvironment() {
        const id = Number(elements.environmentSelect.value);

        if (!id) return;

        try {
            await invoke('api.environment.activate', id);
            await refreshWorkspace();
            toast('Active environment changed.');
        } catch (error) {
            showOperationError(error);
        }
    }

    async function sendRequest(event) {
        event?.preventDefault();

        if (state.sending || state.databaseSwitching) return;
        const request = collectRequest();

        if (!request.url) {
            switchRequestTab('params');
            elements.urlInput.focus();
            toast('Enter a request URL.', true);
            return;
        }

        state.sending = true;
        elements.sendButton.disabled = true;
        elements.sendButton.classList.add('is-loading');
        showResponseState('loading');

        try {
            const response = await invoke('api.send', request);
            showResponse(response);
        } catch (error) {
            showResponseError(error instanceof BindingError ? error.payload : {
                type: 'application_error',
                message: error.message || 'Unable to send the request.',
                details: {},
            });
        } finally {
            state.sending = false;
            elements.sendButton.disabled = false;
            elements.sendButton.classList.remove('is-loading');

            try {
                await refreshWorkspace();
            } catch (error) {
                toast('The request finished, but history could not be refreshed.', true);
            }
        }
    }

    async function exportCurl() {
        if (state.exportingCurl || state.databaseSwitching) return;
        const request = collectRequest();

        if (!request.url) {
            elements.urlInput.focus();
            toast('Enter a request URL.', true);
            return;
        }

        state.exportingCurl = true;
        elements.exportCurlButton.disabled = true;
        elements.exportCurlButton.setAttribute('aria-busy', 'true');

        try {
            const result = await invoke('api.request.curl', request);

            if (typeof result?.command !== 'string' || !result.command) {
                throw new Error('Unable to generate the cURL command.');
            }

            elements.curlCommand.value = result.command;
            openModal('curlModal');
            elements.curlCommand.focus();
            elements.curlCommand.select();
        } catch (error) {
            showOperationError(error);
        } finally {
            state.exportingCurl = false;
            elements.exportCurlButton.disabled = false;
            elements.exportCurlButton.removeAttribute('aria-busy');
        }
    }

    async function copyCurlCommand() {
        const command = elements.curlCommand.value;
        if (!command || elements.copyCurlButton.disabled) return;
        elements.copyCurlButton.disabled = true;

        try {
            let copied = false;

            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(command);
                    copied = true;
                } catch (_error) {
                    // The WebView may support copying only through the selected text.
                }
            }

            if (!copied) {
                elements.curlCommand.focus();
                elements.curlCommand.select();
                copied = document.execCommand('copy');
            }

            if (!copied) {
                throw new Error('Clipboard access is unavailable.');
            }

            toast('cURL command copied.');
        } catch (_error) {
            toast('Unable to copy automatically. Select the command and press Ctrl/Cmd+C.', true);
        } finally {
            elements.copyCurlButton.disabled = false;
        }
    }

    function showResponse(response) {
        state.response = response;
        state.responseBodyView = 'pretty';
        showResponseState('body');
        elements.responseStats.classList.remove('is-hidden');
        elements.responseControls.classList.remove('is-hidden');
        elements.responseStatus.textContent = String(response.statusCode);
        elements.responseStatus.className = `status-pill ${statusClass(response.statusCode)}`;
        elements.responseTime.textContent = formatDuration(response.durationMs);
        elements.responseSize.textContent = `${response.truncated ? '≥ ' : ''}${formatBytes(response.sizeBytes)}`;
        elements.truncatedPill.classList.toggle('is-hidden', !response.truncated);
        const headerCount = Object.values(response.headers || {}).reduce((total, values) => total + values.length, 0);
        elements.responseHeaderCount.textContent = String(headerCount);
        elements.responseEncoding.textContent = response.bodyEncoding === 'base64'
            ? 'binary · base64 display'
            : response.truncated
                ? `first ${formatBytes(response.displayedSizeBytes)}`
                : 'UTF-8';
        $$('[data-body-view]').forEach((button) => button.classList.toggle('is-active', button.dataset.bodyView === 'pretty'));
        renderResponseBody();
        renderResponseHeaders(response.headers || {});
        renderResponsePreview();
        switchResponseTab('body');
    }

    function showResponseError(error) {
        clearResponsePreview();
        state.response = null;
        showResponseState('error');
        const labels = {
            timeout: 'Request timeout',
            ssl_error: 'SSL verification error',
            dns_error: 'DNS error',
            connection_refused: 'Connection refused',
            connection_error: 'Connection error',
            invalid_url: 'Invalid URL',
            unresolved_variable: 'Variable error',
            invalid_json: 'Invalid JSON',
            missing_upload: 'File required',
        };
        elements.responseErrorType.textContent = labels[error.type] || 'Request error';
        elements.responseErrorMessage.textContent = error.message || 'The request could not be completed.';
        elements.responseErrorDetails.textContent = error.details?.transport_message || '';
    }

    function showResponseState(name) {
        elements.responseEmpty.classList.toggle('is-hidden', name !== 'empty');
        elements.responseLoading.classList.toggle('is-hidden', name !== 'loading');
        elements.responseError.classList.toggle('is-hidden', name !== 'error');
        elements.responseBodyPanel.classList.toggle('is-hidden', name !== 'body');
        elements.responseHeadersPanel.classList.add('is-hidden');
        elements.responsePreviewPanel.classList.add('is-hidden');

        if (!['body', 'headers', 'preview'].includes(name)) {
            clearResponsePreview();
            elements.responseStats.classList.add('is-hidden');
            elements.responseControls.classList.add('is-hidden');
        }
    }

    function renderResponseBody() {
        if (!state.response) {
            elements.responseBody.textContent = '';
            return;
        }

        let output = state.response.body || '';
        let isJson = /^[a-z0-9!#$&^_.+-]+\/(?:[a-z0-9!#$&^_.+-]+\+)?json$/i.test(
            responseContentType(state.response.headers || {})
        );

        if (state.response.bodyEncoding === 'base64') {
            output = `Binary response (base64):\n\n${output}`;
            isJson = false;
        } else if (state.responseBodyView === 'pretty' && !state.response.truncated) {
            try {
                output = JSON.stringify(JSON.parse(output), null, 2);
                isJson = true;
            } catch (_error) {
                // Non-JSON bodies are already the clearest pretty representation.
            }
        } else if (!state.response.truncated && !isJson) {
            try {
                JSON.parse(output);
                isJson = true;
            } catch (_error) {
                // A raw non-JSON response should remain unhighlighted.
            }
        }

        if (isJson) {
            renderJsonHighlight(elements.responseBody, output);
        } else {
            elements.responseBody.textContent = output;
        }
    }

    function scheduleRequestJsonHighlight() {
        if (state.jsonHighlightFrame !== null) {
            return;
        }

        state.jsonHighlightFrame = window.requestAnimationFrame(() => {
            state.jsonHighlightFrame = null;
            renderRequestJsonHighlight();
        });
    }

    function renderRequestJsonHighlight() {
        if (state.jsonHighlightFrame !== null) {
            window.cancelAnimationFrame(state.jsonHighlightFrame);
            state.jsonHighlightFrame = null;
        }

        const value = elements.jsonBodyInput.value;
        renderJsonHighlight(elements.jsonBodyHighlight, value.endsWith('\n') ? `${value} ` : value);
        syncRequestJsonHighlightScroll();
    }

    function syncRequestJsonHighlightScroll() {
        const scrollbarWidth = Math.max(0, elements.jsonBodyInput.offsetWidth - elements.jsonBodyInput.clientWidth);
        const scrollbarHeight = Math.max(0, elements.jsonBodyInput.offsetHeight - elements.jsonBodyInput.clientHeight);
        elements.jsonBodyHighlight.style.right = `${scrollbarWidth}px`;
        elements.jsonBodyHighlight.style.bottom = `${scrollbarHeight}px`;
        elements.jsonBodyHighlight.scrollTop = elements.jsonBodyInput.scrollTop;
        elements.jsonBodyHighlight.scrollLeft = elements.jsonBodyInput.scrollLeft;
    }

    function renderJsonHighlight(target, source) {
        const text = String(source ?? '');

        if (text.length > JSON_HIGHLIGHT_MAX_LENGTH) {
            target.textContent = text;
            return;
        }

        const tokenPattern = /"(?:\\[\s\S]|[^"\\])*"|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|\b(?:true|false|null)\b/g;
        let tokenCount = 0;
        let match;

        while ((match = tokenPattern.exec(text)) !== null) {
            tokenCount += 1;

            if (tokenCount > JSON_HIGHLIGHT_MAX_TOKENS) {
                target.textContent = text;
                return;
            }
        }

        tokenPattern.lastIndex = 0;
        const fragment = document.createDocumentFragment();
        let cursor = 0;

        while ((match = tokenPattern.exec(text)) !== null) {
            if (match.index > cursor) {
                fragment.append(document.createTextNode(text.slice(cursor, match.index)));
            }

            const token = match[0];
            const span = document.createElement('span');

            if (token.startsWith('"')) {
                span.className = /^\s*:/.test(text.slice(tokenPattern.lastIndex)) ? 'json-key' : 'json-string';
            } else if (token === 'true' || token === 'false') {
                span.className = 'json-boolean';
            } else if (token === 'null') {
                span.className = 'json-null';
            } else {
                span.className = 'json-number';
            }

            span.textContent = token;
            fragment.append(span);
            cursor = tokenPattern.lastIndex;
        }

        if (cursor < text.length) {
            fragment.append(document.createTextNode(text.slice(cursor)));
        }

        target.replaceChildren(fragment);
    }

    function renderResponseHeaders(headers) {
        const rows = [];

        Object.entries(headers).forEach(([name, values]) => {
            values.forEach((value) => {
                rows.push(`<div class="response-header-row"><span>${escapeHtml(name)}</span><span>${escapeHtml(value)}</span></div>`);
            });
        });
        elements.responseHeaders.innerHTML = rows.join('') || '<div class="history-empty"><strong>No response headers</strong></div>';
    }

    function renderResponsePreview() {
        clearResponsePreview();

        const response = state.response;

        if (!response) {
            renderPreviewNotice('Nothing to preview', 'Send a request to create a response preview.');
            return;
        }

        const body = String(response.body || '');

        if (!body) {
            renderPreviewNotice('Empty response', 'The response body contains no previewable content.');
            return;
        }

        if (response.truncated) {
            renderPreviewNotice(
                'Preview unavailable',
                'The response exceeded the display limit, so a partial file or document is not rendered.'
            );
            return;
        }

        const contentType = responseContentType(response.headers || {});
        const textBody = response.bodyEncoding === 'base64' ? '' : body;
        const trimmedBody = textBody.trimStart();
        const isSvg = contentType === 'image/svg+xml' || /^<svg(?:\s|>)/i.test(trimmedBody);
        const isHtml = ['text/html', 'application/xhtml+xml'].includes(contentType)
            || /^<(?:!doctype\s+html|html|head|body)(?:\s|>)/i.test(trimmedBody);

        if (isSvg && textBody) {
            renderSandboxedDocument(textBody, true);
            return;
        }

        if (isHtml && textBody) {
            renderSandboxedDocument(textBody, false);
            return;
        }

        if (contentType.startsWith('image/')) {
            renderImagePreview(response, contentType);
            return;
        }

        if (contentType === 'application/pdf') {
            renderPdfPreview(response, contentType);
            return;
        }

        renderPreviewNotice(
            'No visual preview',
            'Preview supports HTML, SVG, common image formats and PDF. Use Body for this response.'
        );
    }

    function clearResponsePreview() {
        if (state.previewObjectUrl) {
            URL.revokeObjectURL(state.previewObjectUrl);
            state.previewObjectUrl = null;
        }

        if (elements.responsePreview) {
            elements.responsePreview.replaceChildren();
        }
    }

    function responseContentType(headers) {
        for (const [name, values] of Object.entries(headers)) {
            if (name.toLowerCase() !== 'content-type') {
                continue;
            }

            const value = Array.isArray(values) ? values[0] : values;
            return String(value || '').split(';', 1)[0].trim().toLowerCase();
        }

        return '';
    }

    function renderSandboxedDocument(source, svgOnly) {
        const markup = svgOnly
            ? `<!doctype html><html><head></head><body class="relaydeck-svg-preview">${source}</body></html>`
            : source;
        const previewDocument = new DOMParser().parseFromString(markup, 'text/html');

        previewDocument.querySelectorAll('script, iframe, frame, frameset, object, embed, applet, portal, base, link, meta[http-equiv]')
            .forEach((node) => node.remove());

        previewDocument.querySelectorAll('*').forEach((node) => {
            Array.from(node.attributes).forEach((attribute) => {
                const name = attribute.name.toLowerCase();

                if (name.startsWith('on') || ['srcdoc', 'srcset'].includes(name)) {
                    node.removeAttribute(attribute.name);
                    return;
                }

                if (['src', 'poster'].includes(name) && !isAllowedEmbeddedUrl(attribute.value)) {
                    node.removeAttribute(attribute.name);
                    return;
                }

                if (['href', 'xlink:href'].includes(name) && !attribute.value.trim().startsWith('#')) {
                    node.removeAttribute(attribute.name);
                    return;
                }

                if (['action', 'formaction'].includes(name)) {
                    node.removeAttribute(attribute.name);
                }
            });
        });

        const policy = previewDocument.createElement('meta');
        policy.setAttribute('http-equiv', 'Content-Security-Policy');
        policy.setAttribute(
            'content',
            "default-src 'none'; img-src data:; media-src data:; font-src data:; style-src 'unsafe-inline'; "
            + "script-src 'none'; connect-src 'none'; frame-src 'none'; object-src 'none'; form-action 'none'; base-uri 'none'"
        );
        previewDocument.head.prepend(policy);

        const previewStyle = previewDocument.createElement('style');
        previewStyle.textContent = [
            'html, body { min-height: 100%; }',
            'body { margin: 0; overflow-wrap: anywhere; }',
            'img, svg, video, canvas { max-width: 100%; }',
            '.relaydeck-svg-preview { display: grid; box-sizing: border-box; place-items: center; padding: 16px; }',
            '.relaydeck-svg-preview > svg { max-height: calc(100vh - 32px); }',
        ].join('\n');
        previewDocument.head.append(previewStyle);

        const frame = document.createElement('iframe');
        frame.className = 'preview-frame';
        frame.title = svgOnly ? 'SVG response preview' : 'HTML response preview';
        frame.setAttribute('sandbox', '');
        frame.referrerPolicy = 'no-referrer';
        frame.srcdoc = `<!doctype html>\n${previewDocument.documentElement.outerHTML}`;
        elements.responsePreview.append(frame);
    }

    function isAllowedEmbeddedUrl(value) {
        return /^data:(?:image|audio|video)\/[a-z0-9.+-]+(?:;[^,]*)?,/i.test(value.trim());
    }

    function renderImagePreview(response, contentType) {
        try {
            const image = document.createElement('img');
            const objectUrl = createResponseObjectUrl(response, contentType);
            image.className = 'response-preview-image';
            image.alt = 'Response image preview';
            image.src = objectUrl;

            const canvas = document.createElement('div');
            canvas.className = 'response-preview-canvas';
            canvas.append(image);
            elements.responsePreview.append(canvas);

            image.addEventListener('error', () => {
                if (state.previewObjectUrl !== objectUrl) {
                    return;
                }

                clearResponsePreview();
                renderPreviewNotice('Image preview failed', 'The response could not be decoded as an image.');
            }, {once: true});
        } catch (_error) {
            renderPreviewNotice('Image preview failed', 'The response contains invalid binary data.');
        }
    }

    function renderPdfPreview(response, contentType) {
        try {
            const frame = document.createElement('iframe');
            frame.className = 'preview-frame preview-pdf';
            frame.title = 'PDF response preview';
            frame.setAttribute('sandbox', '');
            frame.referrerPolicy = 'no-referrer';
            frame.src = createResponseObjectUrl(response, contentType);
            elements.responsePreview.append(frame);
        } catch (_error) {
            renderPreviewNotice('PDF preview failed', 'The response contains invalid binary data.');
        }
    }

    function createResponseObjectUrl(response, contentType) {
        const blob = response.bodyEncoding === 'base64'
            ? base64ToBlob(response.body || '', contentType)
            : new Blob([response.body || ''], {type: contentType});

        state.previewObjectUrl = URL.createObjectURL(blob);
        return state.previewObjectUrl;
    }

    function base64ToBlob(value, contentType) {
        const binary = window.atob(value);
        const bytes = new Uint8Array(binary.length);

        for (let index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }

        return new Blob([bytes], {type: contentType});
    }

    function renderPreviewNotice(title, message) {
        const notice = document.createElement('div');
        notice.className = 'response-preview-empty';

        const icon = document.createElement('span');
        icon.className = 'response-preview-icon';
        icon.textContent = '</>';

        const heading = document.createElement('strong');
        heading.textContent = title;

        const description = document.createElement('p');
        description.textContent = message;

        notice.append(icon, heading, description);
        elements.responsePreview.append(notice);
    }

    function statusClass(status) {
        if (status >= 200 && status < 400) return 'status-success';
        if (status >= 400 && status < 500) return 'status-warning';
        return 'status-error';
    }

    function formatDuration(milliseconds) {
        const value = Number(milliseconds || 0);
        return value >= 1000 ? `${(value / 1000).toFixed(2)} s` : `${Math.round(value)} ms`;
    }

    function formatBytes(bytes) {
        const value = Number(bytes || 0);

        if (value < 1024) return `${value} B`;
        if (value < 1048576) return `${(value / 1024).toFixed(value < 10240 ? 1 : 0)} KB`;
        return `${(value / 1048576).toFixed(1)} MB`;
    }

    async function newRequest() {
        if (state.dirty && !await confirmAction('Discard unsaved changes?', 'The current request has changes that have not been saved.', 'Discard')) {
            return;
        }

        applyRequest(defaultRequest(), {name: 'Untitled request'});
        elements.urlInput.focus();
    }

    async function saveCurrentRequest() {
        const saved = state.data.savedRequests.find((request) => request.id === state.currentSavedId);

        if (!saved) {
            openSaveModal(false);
            return;
        }

        try {
            await invoke('api.request.save', {
                id: saved.id,
                name: saved.name,
                collectionId: saved.collection_id,
                folderId: saved.folder_id,
                request: collectRequest(),
            });
            await refreshWorkspace();
            setDirty(false);
            toast('Request saved.');
        } catch (error) {
            showOperationError(error);
        }
    }

    function openSaveModal(saveAs) {
        if (!state.data.collections.length) {
            toast('Create a collection before saving a request.', true);
            openCreateCollectionModal();
            return;
        }

        const current = state.data.savedRequests.find((request) => request.id === state.currentSavedId);
        elements.saveRequestName.value = saveAs && current ? `${current.name} copy` : (current?.name || suggestedRequestName());
        elements.saveCollectionSelect.innerHTML = state.data.collections.map((collection) => (
            `<option value="${collection.id}"${collection.id === current?.collection_id ? ' selected' : ''}>${escapeHtml(collection.name)}</option>`
        )).join('');
        populateFolderSelect(current?.folder_id ?? null);
        openModal('saveModal');
        window.setTimeout(() => elements.saveRequestName.select(), 0);
    }

    function suggestedRequestName() {
        try {
            const url = new URL(elements.urlInput.value.replace(/\{\{[^}]+}}/g, 'environment.local'));
            const segment = url.pathname.split('/').filter(Boolean).pop();
            return `${elements.methodSelect.value} ${segment || url.hostname}`;
        } catch (_error) {
            return 'New request';
        }
    }

    function populateFolderSelect(selectedId = null) {
        const collection = state.data.collections.find((item) => item.id === Number(elements.saveCollectionSelect.value));
        const folders = collection?.folders || [];
        elements.saveFolderSelect.innerHTML = '<option value="">Collection root</option>' + folders.map((folder) => (
            `<option value="${folder.id}"${folder.id === selectedId ? ' selected' : ''}>${escapeHtml(folderPath(folder, folders))}</option>`
        )).join('');
    }

    function folderPath(folder, folders) {
        const names = [folder.name];
        const seen = new Set([folder.id]);
        let parent = folders.find((item) => item.id === folder.parent_id);

        while (parent && !seen.has(parent.id)) {
            seen.add(parent.id);
            names.unshift(parent.name);
            parent = folders.find((item) => item.id === parent.parent_id);
        }

        return names.join(' / ');
    }

    async function submitSaveModal(event) {
        event.preventDefault();
        const submit = $('button[type="submit"]', elements.saveModalForm);
        submit.disabled = true;

        try {
            const saved = await invoke('api.request.save', {
                name: elements.saveRequestName.value,
                collectionId: Number(elements.saveCollectionSelect.value),
                folderId: elements.saveFolderSelect.value ? Number(elements.saveFolderSelect.value) : null,
                request: collectRequest(),
            });
            state.currentSavedId = saved.id;
            state.currentName = saved.name;
            await refreshWorkspace();
            setDirty(false);
            closeModal('saveModal');
            toast('Request saved to collection.');
        } catch (error) {
            showOperationError(error);
        } finally {
            submit.disabled = false;
        }
    }

    async function deleteCurrentRequest() {
        const saved = state.data.savedRequests.find((request) => request.id === state.currentSavedId);

        if (!saved || !await confirmAction('Delete saved request?', `“${saved.name}” will be removed from its collection.`)) {
            return;
        }

        try {
            await invoke('api.request.delete', saved.id);
            await refreshWorkspace();
            applyRequest(defaultRequest(), {name: 'Untitled request'});
            toast('Saved request deleted.');
        } catch (error) {
            showOperationError(error);
        }
    }

    function openCreateCollectionModal() {
        openEntityModal({
            eyebrow: 'Workspace',
            title: 'New collection',
            submitLabel: 'Create collection',
            showDescription: true,
            onSubmit: async (values) => {
                await invoke('api.collection.create', values);
                await refreshWorkspace();
                toast('Collection created.');
            },
        });
    }

    function openEntityModal(options) {
        state.entitySubmit = options;
        elements.entityModalEyebrow.textContent = options.eyebrow || 'Workspace';
        elements.entityModalTitle.textContent = options.title;
        elements.entityModalSubmit.textContent = options.submitLabel || 'Save';
        elements.entityNameLabel.textContent = options.nameLabel || 'Name';
        elements.entityNameInput.value = options.name || '';
        elements.entityNameInput.maxLength = options.maxNameLength || 160;
        elements.entityNameInput.placeholder = options.namePlaceholder || '';
        elements.entityNameInput.setCustomValidity('');
        elements.entityDescriptionInput.value = options.description || '';
        elements.entityDescriptionField.classList.toggle('is-hidden', options.showDescription === false);

        let deleteButton = $('#entityDeleteButton');

        if (deleteButton) deleteButton.remove();
        if (typeof options.onDelete === 'function') {
            deleteButton = document.createElement('button');
            deleteButton.id = 'entityDeleteButton';
            deleteButton.type = 'button';
            deleteButton.className = 'ghost-button danger-text';
            deleteButton.textContent = 'Delete';
            deleteButton.addEventListener('click', async () => {
                closeModal('entityModal');
                await options.onDelete();
            });
            $('.modal-actions', elements.entityModalForm).prepend(deleteButton);
        }

        openModal('entityModal');
        window.setTimeout(() => elements.entityNameInput.select(), 0);
    }

    async function submitEntityModal(event) {
        event.preventDefault();

        if (!state.entitySubmit?.onSubmit) return;
        elements.entityModalSubmit.disabled = true;

        try {
            const submitted = await state.entitySubmit.onSubmit({
                name: elements.entityNameInput.value,
                description: elements.entityDescriptionInput.value,
            });

            if (submitted !== false) {
                closeModal('entityModal');
            }
        } catch (error) {
            showOperationError(error);
        } finally {
            elements.entityModalSubmit.disabled = false;
        }
    }

    async function deleteCollection(collection) {
        if (!await confirmAction('Delete collection?', `“${collection.name}”, its folders and saved requests will be deleted.`)) {
            return;
        }

        try {
            await invoke('api.collection.delete', collection.id);

            if (state.data.savedRequests.some((request) => request.collection_id === collection.id && request.id === state.currentSavedId)) {
                applyRequest(defaultRequest(), {name: 'Untitled request'});
            }

            await refreshWorkspace();
            toast('Collection deleted.');
        } catch (error) {
            showOperationError(error);
        }
    }

    async function deleteFolder(folder) {
        if (!await confirmAction('Delete folder?', 'Saved requests will move to the collection root. Nested folders will also move to the root.')) {
            return;
        }

        try {
            await invoke('api.folder.delete', folder.id);
            await refreshWorkspace();
            toast('Folder deleted.');
        } catch (error) {
            showOperationError(error);
        }
    }

    async function clearHistory() {
        if (!state.data.history.length || !await confirmAction('Clear request history?', 'All locally stored history entries will be removed.', 'Clear')) {
            return;
        }

        try {
            await invoke('api.history.clear');
            await refreshWorkspace();
            toast('History cleared.');
        } catch (error) {
            showOperationError(error);
        }
    }

    function openEnvironmentModal() {
        renderEnvironmentList();
        editEnvironment(state.data.activeEnvironmentId ?? state.data.environments[0]?.id ?? null);
        openModal('environmentModal');
    }

    function renderEnvironmentList() {
        elements.environmentList.innerHTML = state.data.environments.map((environment) => `
            <button class="environment-list-item${environment.id === state.editingEnvironmentId ? ' is-active' : ''}" type="button" data-environment-id="${environment.id}">
                <span class="environment-dot${environment.is_active ? ' is-active' : ''}"></span>
                <span class="environment-list-name">${escapeHtml(environment.name)}</span>
            </button>`).join('') || '<div class="history-empty"><p>No environments</p></div>';
    }

    function handleEnvironmentListClick(event) {
        const item = event.target.closest('[data-environment-id]');
        if (item) editEnvironment(Number(item.dataset.environmentId));
    }

    function editEnvironment(id) {
        state.editingEnvironmentId = id;
        const environment = state.data.environments.find((item) => item.id === id);
        elements.environmentNameInput.value = environment?.name || 'New environment';
        elements.environmentVariables.replaceChildren();
        const variables = environment?.variables?.length ? environment.variables : [{}];
        variables.forEach(addEnvironmentVariableRow);
        elements.deleteEnvironmentButton.classList.toggle('is-hidden', !environment);
        $('#environmentFormEyebrow').textContent = environment?.is_active ? 'Active environment' : (environment ? 'Edit environment' : 'New environment');
        renderEnvironmentList();
    }

    function addEnvironmentVariableRow(variable = {}) {
        const row = $('#environmentVariableTemplate').content.firstElementChild.cloneNode(true);
        $('.env-enabled', row).checked = variable.enabled !== false;
        $('.env-name', row).value = variable.name || '';
        $('.env-value', row).value = variable.value || '';
        $('.env-secret', row).checked = Boolean(variable.is_secret);
        updateSecretRow(row);
        $('.row-delete', row).addEventListener('click', () => {
            row.remove();
            if (!elements.environmentVariables.children.length) addEnvironmentVariableRow();
        });
        elements.environmentVariables.append(row);
        return row;
    }

    function handleEnvironmentVariableChange(event) {
        if (event.target.classList.contains('env-secret')) {
            updateSecretRow(event.target.closest('.environment-variable-row'));
        }
    }

    function handleEnvironmentVariableClick(event) {
        const toggle = event.target.closest('.secret-toggle');

        if (!toggle) return;
        const input = $('.env-value', toggle.closest('.environment-variable-row'));
        input.type = input.type === 'password' ? 'text' : 'password';
        toggle.classList.toggle('is-revealed', input.type === 'text');
    }

    function updateSecretRow(row) {
        const secret = $('.env-secret', row).checked;
        const value = $('.env-value', row);
        const toggle = $('.secret-toggle', row);
        value.type = secret ? 'password' : 'text';
        toggle.classList.toggle('is-hidden', !secret);
        toggle.classList.remove('is-revealed');
    }

    async function saveEnvironment(event) {
        event.preventDefault();
        const wasNew = state.editingEnvironmentId === null;
        const submit = $('button[type="submit"]', elements.environmentForm);
        submit.disabled = true;
        const variables = $$('.environment-variable-row', elements.environmentVariables).map((row) => ({
            enabled: $('.env-enabled', row).checked,
            name: $('.env-name', row).value,
            value: $('.env-value', row).value,
            is_secret: $('.env-secret', row).checked,
        }));

        try {
            const saved = await invoke('api.environment.save', {
                id: state.editingEnvironmentId,
                name: elements.environmentNameInput.value,
                variables,
            });

            if (wasNew) {
                await invoke('api.environment.activate', saved.id);
            }

            await refreshWorkspace();
            editEnvironment(saved.id);
            toast('Environment saved.');
        } catch (error) {
            showOperationError(error);
        } finally {
            submit.disabled = false;
        }
    }

    async function deleteEditingEnvironment() {
        const environment = state.data.environments.find((item) => item.id === state.editingEnvironmentId);

        if (!environment || !await confirmAction('Delete environment?', `“${environment.name}” and its variables will be removed.`)) {
            return;
        }

        try {
            await invoke('api.environment.delete', environment.id);
            await refreshWorkspace();
            editEnvironment(state.data.activeEnvironmentId ?? state.data.environments[0]?.id ?? null);
            toast('Environment deleted.');
        } catch (error) {
            showOperationError(error);
        }
    }

    function formatRequestJson() {
        try {
            elements.jsonBodyInput.value = JSON.stringify(JSON.parse(elements.jsonBodyInput.value || '{}'), null, 2);
            renderRequestJsonHighlight();
            markDirty();
            toast('JSON formatted.');
        } catch (error) {
            toast(`Invalid JSON: ${error.message}`, true);
        }
    }

    async function copyResponseBody() {
        if (!state.response) return;
        const text = elements.responseBody.textContent;

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.append(textarea);
                textarea.select();
                document.execCommand('copy');
                textarea.remove();
            }
            toast('Response body copied.');
        } catch (_error) {
            toast('Clipboard access is unavailable in this WebView.', true);
        }
    }

    function openModal(id) {
        hideVariableSuggestions();
        document.getElementById(id)?.classList.remove('is-hidden');
    }

    function openDatabaseModal() {
        if (state.databaseSwitching) return;
        openModal('databaseModal');
        window.setTimeout(() => elements.openExistingDatabaseButton.focus(), 0);
    }

    function openCreateDatabaseModal() {
        if (state.databaseSwitching) return;

        closeModal('databaseModal');
        openEntityModal({
            eyebrow: 'Database',
            title: 'Create new database',
            submitLabel: 'Choose folder',
            showDescription: false,
            maxNameLength: 120,
            nameLabel: 'Database filename',
            namePlaceholder: 'my-database.sqlite',
            onSubmit: async ({name}) => {
                const databaseFilename = name.trim();

                if (!databaseFilename) {
                    elements.entityNameInput.setCustomValidity('Enter a database filename.');
                    elements.entityNameInput.reportValidity();
                    return false;
                }

                return chooseDatabase('api.database.create', databaseFilename);
            },
        });
    }

    function closeModal(id) {
        document.getElementById(id)?.classList.add('is-hidden');

        if (id === 'databaseModal' && !state.databaseSwitching) {
            elements.manageDatabasesButton.focus();
        }

        if (id === 'curlModal') {
            elements.exportCurlButton.focus();
        }
    }

    function confirmAction(title, message, acceptLabel = 'Delete') {
        elements.confirmTitle.textContent = title;
        elements.confirmMessage.textContent = message;
        elements.confirmAccept.textContent = acceptLabel;
        openModal('confirmModal');

        return new Promise((resolve) => {
            state.confirmResolve = resolve;
        });
    }

    function resolveConfirmation(accepted) {
        closeModal('confirmModal');
        const resolve = state.confirmResolve;
        state.confirmResolve = null;
        resolve?.(accepted);
    }

    function setupSidebarResize() {
        elements.sidebarResizer.addEventListener('pointerdown', (event) => {
            if (window.innerWidth <= 740) return;
            document.body.classList.add('is-resizing');
            elements.sidebarResizer.setPointerCapture(event.pointerId);
        });
        elements.sidebarResizer.addEventListener('pointermove', (event) => {
            if (!document.body.classList.contains('is-resizing')) return;
            const max = Math.min(480, window.innerWidth - 380);
            const width = Math.max(220, Math.min(max, event.clientX));
            document.documentElement.style.setProperty('--sidebar-width', `${width}px`);
        });
        const stop = () => document.body.classList.remove('is-resizing');
        elements.sidebarResizer.addEventListener('pointerup', stop);
        elements.sidebarResizer.addEventListener('pointercancel', stop);
    }

    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', (event) => {
            const command = event.ctrlKey || event.metaKey;

            if (state.databaseSwitching) {
                event.preventDefault();
                return;
            }

            if (event.key === 'Escape') {
                $$('.modal-backdrop:not(.is-hidden)').forEach((modal) => {
                    if (modal.id === 'confirmModal') resolveConfirmation(false);
                    else closeModal(modal.id);
                });
                closeMobileSidebar();
                return;
            }

            if (!command) return;
            if (event.key === 'Enter') {
                event.preventDefault();
                sendRequest();
            } else if (event.key.toLowerCase() === 's') {
                event.preventDefault();
                saveCurrentRequest();
            } else if (event.key.toLowerCase() === 'l') {
                event.preventDefault();
                elements.urlInput.focus();
                elements.urlInput.select();
            }
        });
    }

    function closeMobileSidebar() {
        elements.appShell.classList.remove('sidebar-open');
    }

    function showOperationError(error) {
        const message = error instanceof BindingError ? error.payload.message : (error.message || 'Operation failed.');
        toast(message, true);
    }

    function toast(message, isError = false) {
        const item = document.createElement('div');
        item.className = `toast${isError ? ' is-error' : ''}`;
        item.textContent = message;
        elements.toastRegion.append(item);
        window.setTimeout(() => item.remove(), 3800);
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;',
        })[character]);
    }

    async function init() {
        cacheElements();
        attachEvents();
        renderKeyValueRows(elements.paramsEditor, []);
        renderKeyValueRows(elements.headersEditor, []);
        renderKeyValueRows(elements.formEditor, []);
        renderMultipartRows([]);

        try {
            await waitForBindings();
            await refreshWorkspace();
            applyRequest(defaultRequest(), {name: 'Untitled request'});
            $('#uploadLimitText').textContent = `Files are held in memory for the current request (total limit ${formatBytes(state.data.limits.uploadBytes)}).`;
            elements.appShell.classList.remove('is-booting');
            window.setTimeout(() => elements.urlInput.focus(), 100);
        } catch (error) {
            const card = $('.boot-card', elements.bootOverlay);
            $('.loader-line', card)?.remove();
            $('strong', card).textContent = 'Unable to start RelayDeck';
            $('p', card).textContent = error.message || 'Initialization failed.';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();
