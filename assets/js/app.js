(() => {
    'use strict';

    const baseUrl = window.STOCKFLOW_BASE_URL || '';
    const $ = (selector, context = document) => context.querySelector(selector);
    const $$ = (selector, context = document) => [...context.querySelectorAll(selector)];
    let lastFocusedElement = null;
    const overlayStack = [];

    const registerOverlay = (element, close, trigger = document.activeElement) => {
        if (!(element instanceof HTMLElement) || typeof close !== 'function') return;
        const existingIndex = overlayStack.findIndex((entry) => entry.element === element);
        if (existingIndex >= 0) overlayStack.splice(existingIndex, 1);
        const restoreTarget = trigger instanceof HTMLElement ? trigger : null;
        overlayStack.push({ element, close, restoreTarget });
        lastFocusedElement = restoreTarget;
    };

    const unregisterOverlay = (element, shouldRestoreFocus = true) => {
        const index = overlayStack.findIndex((entry) => entry.element === element);
        if (index < 0) return;
        const wasTopmost = index === overlayStack.length - 1;
        const [{ restoreTarget }] = overlayStack.splice(index, 1);
        const nextTopmost = overlayStack[overlayStack.length - 1];
        lastFocusedElement = nextTopmost?.restoreTarget || null;
        if (shouldRestoreFocus && wasTopmost && restoreTarget instanceof HTMLElement && restoreTarget.isConnected) {
            restoreTarget.focus();
        }
    };

    const closeTopmostOverlay = () => {
        const topmost = overlayStack[overlayStack.length - 1];
        if (!topmost) return false;
        topmost.close();
        return true;
    };

    const escapeHtml = (value = '') => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const ageRestrictedPublicItemPattern = /(?:^|[^\p{L}\p{N}])(cerveza|vino|licor|whisky|vodka|ron|pisco|tabaco|cigarro|nicotina|vapeador|cannabis|marihuana)(?=$|[^\p{L}\p{N}])|bebida\s+energizante|energy\s+drink/iu;
    const isAgeRestrictedPublicItem = (item = {}) => ageRestrictedPublicItemPattern.test([
        item?.name || '',
        item?.category || '',
        item?.description || '',
    ].join(' ').normalize('NFD').replace(/[\u0300-\u036f]/g, ''));

    const showImageFallback = (failedImage) => {
        if (!(failedImage instanceof HTMLImageElement)) return;
        if (failedImage.matches('[data-product-image]')) {
            if (failedImage.dataset.placeholderApplied === 'true') return;
            failedImage.dataset.placeholderApplied = 'true';
            failedImage.hidden = false;
            failedImage.classList.add('is-placeholder');
            failedImage.src = `${baseUrl}/assets/img/product-placeholder.svg`;
            return;
        }
        if (!failedImage.matches('[data-image-fallback]')) return;
        failedImage.hidden = true;
        const fallback = failedImage.nextElementSibling;
        if (fallback?.classList.contains('media-fallback')) fallback.hidden = false;
    };

    document.addEventListener('error', (event) => {
        const failedImage = event.target;
        showImageFallback(failedImage);
    }, true);

    $$('img[data-image-fallback], img[data-product-image]').forEach((image) => {
        if (image.complete && image.naturalWidth === 0) showImageFallback(image);
    });

    const formatMoney = (value) => new Intl.NumberFormat('es-PE', {
        style: 'currency',
        currency: 'PEN',
        minimumFractionDigits: 2,
    }).format(Number(value) || 0).replace('PEN', 'S/');

    const modalOpen = (modal, trigger = document.activeElement) => {
        if (!modal) return;
        registerOverlay(modal, () => modalClose(modal), trigger);
        modal.hidden = false;
        document.body.classList.add('modal-open');
        requestAnimationFrame(() => modal.classList.add('is-open'));
        $('.modal-close, button, a, input', modal)?.focus();
    };

    const modalClose = (modal, shouldRestoreFocus = true) => {
        if (!modal) return;
        modal.dispatchEvent(new Event('overlayclose'));
        modal.classList.remove('is-open');
        unregisterOverlay(modal, shouldRestoreFocus);
        window.setTimeout(() => {
            modal.hidden = true;
            if (!$('.modal-backdrop.is-open, .yape-backdrop.is-open')) document.body.classList.remove('modal-open');
        }, 180);
    };

    $$('.modal-backdrop').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal || event.target.closest('[data-modal-close]')) modalClose(modal);
        });
    });

    const mobileMenuButton = $('[data-mobile-menu]');
    const mobileMenuPanel = $('[data-mobile-menu-panel]');
    const closeMobileMenu = (shouldRestoreFocus = true) => {
        if (!mobileMenuButton || !mobileMenuPanel) return;
        mobileMenuPanel.classList.remove('is-open');
        mobileMenuButton.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('mobile-menu-open');
        unregisterOverlay(mobileMenuPanel, shouldRestoreFocus);
    };

    mobileMenuButton?.addEventListener('click', () => {
        const willOpen = mobileMenuButton.getAttribute('aria-expanded') !== 'true';
        if (willOpen) {
            registerOverlay(mobileMenuPanel, () => closeMobileMenu(), mobileMenuButton);
            mobileMenuPanel?.classList.add('is-open');
            mobileMenuButton.setAttribute('aria-expanded', 'true');
            document.body.classList.add('mobile-menu-open');
            $('a', mobileMenuPanel)?.focus();
        } else {
            closeMobileMenu();
        }
    });
    mobileMenuPanel?.addEventListener('click', (event) => {
        if (event.target.closest('a')) closeMobileMenu(false);
    });
    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024 && mobileMenuButton?.getAttribute('aria-expanded') === 'true') closeMobileMenu(false);
    });

    const sidebar = $('#sidebar');
    const menuToggle = $('#menuToggle');
    const sidebarClose = $('#sidebarClose');
    const sidebarBackdrop = $('#sidebarBackdrop');

    const closeAdminSidebar = (shouldRestoreFocus = true) => {
        if (!sidebar) return;
        sidebar.classList.remove('is-open');
        if (sidebarBackdrop) sidebarBackdrop.hidden = true;
        document.body.classList.remove('admin-sidebar-open');
        unregisterOverlay(sidebar, shouldRestoreFocus);
    };

    const openAdminSidebar = () => {
        if (!sidebar || !menuToggle) return;
        registerOverlay(sidebar, () => closeAdminSidebar(), menuToggle);
        sidebar.classList.add('is-open');
        if (sidebarBackdrop) sidebarBackdrop.hidden = false;
        document.body.classList.add('admin-sidebar-open');
        sidebarClose?.focus();
    };

    menuToggle?.addEventListener('click', openAdminSidebar);
    sidebarClose?.addEventListener('click', () => closeAdminSidebar());
    sidebarBackdrop?.addEventListener('click', () => closeAdminSidebar());
    window.addEventListener('resize', () => {
        if (window.innerWidth > 980 && sidebar?.classList.contains('is-open')) closeAdminSidebar(false);
    });

    const adminGlobalSearch = $('#adminGlobalSearch');
    document.addEventListener('keydown', (event) => {
        const target = event.target;
        const isTyping = target instanceof HTMLInputElement
            || target instanceof HTMLTextAreaElement
            || target instanceof HTMLSelectElement
            || target?.isContentEditable;
        if (event.key === '/' && !isTyping && adminGlobalSearch) {
            event.preventDefault();
            adminGlobalSearch.focus();
        }
    });

    const adminProductSearchInputs = $$('[data-admin-product-search]');
    const adminProductIsPublic = (item) => Number(item?.active) === 1
        && Number(item?.sale_enabled) === 1
        && Number(item?.restricted) === 0
        && Number(item?.stock) > 0
        && Number(item?.price) > 0;
    const adminProductLabel = (item) => `${escapeHtml(item?.code || 'Sin código')} · ${escapeHtml(item?.name || 'Producto')} · stock ${Number(item?.stock) || 0}`;
    const adminProductSearchState = new WeakMap();

    const addComboProductRow = (input, item) => {
        const picker = $(input.dataset.productPicker);
        if (!picker || !item?.id) return;
        const id = String(Number(item.id));
        const existing = [...picker.querySelectorAll('[data-product-id]')].find((row) => row.dataset.productId === id);
        if (existing) {
            $('.picker-quantity', existing)?.focus();
            return;
        }
        const row = document.createElement('label');
        row.className = 'picker-product';
        row.dataset.productId = id;
        row.dataset.price = String(Number(item.price) || 0);
        row.dataset.stock = String(Number(item.stock) || 0);
        row.dataset.productName = String(item.name || 'Producto');
        row.innerHTML = `<input type="checkbox" data-combo-check data-product-id="${escapeHtml(id)}" checked>
            <span>•</span><div><strong>${escapeHtml(item.name || 'Producto')}</strong><small>${adminProductLabel(item)} · ${Number(item.price) > 0 ? formatMoney(item.price) : 'Sin precio de venta'}</small></div>
            <input class="picker-quantity" type="number" name="products[${escapeHtml(id)}]" value="1" min="0" max="100" inputmode="numeric" title="Unidades de este producto dentro de un combo" aria-label="Unidades de ${escapeHtml(item.name || 'producto')} dentro de un combo">
            ${Number(item.price) > 0 ? '' : `<span class="combo-item-price"><span>Precio de venta de ${escapeHtml(item.name || 'producto')}</span><input class="picker-sale-price" type="number" name="product_prices[${escapeHtml(id)}]" value="${Number(item.suggested_price) > 0 ? escapeHtml(Number(item.suggested_price).toFixed(2)) : ''}" min="0.01" max="999999.99" step="0.01" inputmode="decimal" placeholder="S/ 0.00" aria-label="Precio de venta de ${escapeHtml(item.name || 'producto')}"></span>`}`;
        row.classList.toggle('needs-price', Number(item.price) <= 0);
        picker.append(row);
        row.querySelector('[data-combo-check]')?.dispatchEvent(new Event('change', { bubbles: true }));
        $('.picker-quantity', row)?.focus();
    };

    const renderAdminProductResults = (input, items) => {
        const results = $(input.dataset.productResults);
        if (!results) return;
        results.replaceChildren();
        const comboStatus = input.dataset.productMode === 'combo' ? $('#comboAvailableStatus') : null;
        if (comboStatus) comboStatus.textContent = items.length
            ? `${items.length} productos activos con stock. Pulsa «Agregar»; podrás indicar el precio de los que aún no lo tienen.`
            : 'No hay productos activos con stock para esta búsqueda. Revisa el inventario o busca otro nombre.';
        if (!items.length) {
            results.innerHTML = '<small class="empty-mini">No hay productos disponibles. Activa un producto y registra su precio y stock en «Todos los productos».</small>';
            return;
        }
        items.forEach((item) => {
            const result = document.createElement('button');
            result.type = 'button';
            result.className = 'picker-product product-search-result';
            result.innerHTML = `<span>⌕</span><div><strong>${escapeHtml(item.name || 'Producto')}</strong><small>${adminProductLabel(item)} · ${Number(item.price) > 0 ? formatMoney(item.price) : 'Indicar precio al agregar'}</small></div>`;
            result.addEventListener('click', () => {
                const mode = input.dataset.productMode || 'featured';
                if (mode === 'combo') {
                    addComboProductRow(input, item);
                } else if (mode === 'movement') {
                    const select = $('#movementProduct');
                    if (select) {
                        let option = [...select.options].find((candidate) => candidate.value === String(Number(item.id)));
                        if (!option) {
                            option = document.createElement('option');
                            option.value = String(Number(item.id));
                            select.append(option);
                        }
                        option.textContent = `${item.code || 'Sin código'} · ${item.name || 'Producto'} (${Number(item.stock) || 0} und.)`;
                        Object.assign(option.dataset, {
                            stock: String(Number(item.stock) || 0),
                            cost: String(Number(item.cost_price) || 0),
                            price: String(Number(item.price) || 0),
                            packSize: String(Number(item.units_per_pack) || 1),
                            packPrice: String(Number(item.pack_price) || 0),
                        });
                        select.value = option.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else {
                    const target = $(input.dataset.productTarget);
                    if (target) target.value = String(Number(item.id));
                    const selected = $(input.dataset.productSelected);
                    if (selected) selected.textContent = `${item.code || 'Sin código'} · ${item.name || 'Producto'} · stock ${Number(item.stock) || 0}`;
                }
                input.value = '';
                results.replaceChildren();
                if (mode === 'combo') input.dispatchEvent(new Event('input', { bubbles: true }));
            });
            results.append(result);
        });
    };

    adminProductSearchInputs.forEach((input) => {
        const target = $(input.dataset.productTarget);
        const selected = $(input.dataset.productSelected);
        let timer = 0;
        let searchGeneration = 0;
        input.addEventListener('input', () => {
            searchGeneration += 1;
            const generation = searchGeneration;
            window.clearTimeout(timer);
            timer = 0;
            if (target) target.value = '';
            if (selected) selected.textContent = 'Buscando…';
            const state = adminProductSearchState.get(input);
            if (state?.abort) state.abort.abort();
            adminProductSearchState.set(input, { abort: null, generation });
            const query = input.value.trim().slice(0, 100);
            const results = $(input.dataset.productResults);
            if (query.length < 2) {
                if (!(input.dataset.productMode === 'combo' && query.length === 0)) {
                    if (results) results.replaceChildren();
                    if (selected) selected.textContent = 'Escribe al menos 2 caracteres para buscar.';
                    return;
                }
            }
            timer = window.setTimeout(async () => {
                const abort = new AbortController();
                adminProductSearchState.set(input, { abort, generation });
                try {
                    const endpoint = new URL(input.dataset.productSearchUrl, window.location.origin);
                    endpoint.searchParams.set('q', query);
                    if (input.dataset.productMode === 'combo') endpoint.searchParams.set('mode', 'combo');
                    const response = await fetch(endpoint.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: abort.signal });
                    if (!response.ok) throw new Error('search failed');
                    const payload = await response.json();
                    if (!payload || !Array.isArray(payload.items)) throw new Error('invalid search response');
                    if (generation !== searchGeneration) return;
                    let items = payload.items.filter((item) => item && Number(item.id) > 0);
                    if (input.dataset.productRequirePublic === '1' && input.dataset.productMode !== 'combo') items = items.filter(adminProductIsPublic);
                    renderAdminProductResults(input, items.slice(0, 20));
                } catch (error) {
                    if (error?.name === 'AbortError') return;
                    if (generation !== searchGeneration) return;
                    const results = $(input.dataset.productResults);
                    if (results) results.innerHTML = '<small class="empty-mini">No se pudo buscar productos.</small>';
                }
            }, 220);
        });
        if (input.dataset.productMode === 'combo') input.dispatchEvent(new Event('input'));
    });

    const svgNode = (name, attributes = {}) => {
        const node = document.createElementNS('http://www.w3.org/2000/svg', name);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
        return node;
    };

    $$('[data-dashboard-chart]').forEach((chart) => {
        let labels;
        let sales;
        let profits;
        try {
            labels = JSON.parse(chart.dataset.labels || '[]').map(String);
            sales = JSON.parse(chart.dataset.sales || '[]').map(Number);
            profits = JSON.parse(chart.dataset.profits || '[]').map(Number);
        } catch (error) {
            return;
        }
        if (!labels.length || labels.length !== sales.length || labels.length !== profits.length) return;

        const width = 800;
        const height = 250;
        const padding = { top: 14, right: 16, bottom: 30, left: 47 };
        const values = [...sales, ...profits].filter(Number.isFinite);
        const minimum = Math.min(0, ...values);
        const maximum = Math.max(1, ...values);
        const range = Math.max(1, maximum - minimum);
        const x = (index) => padding.left + (index / Math.max(1, labels.length - 1)) * (width - padding.left - padding.right);
        const y = (value) => padding.top + ((maximum - value) / range) * (height - padding.top - padding.bottom);
        const points = (series) => series.map((value, index) => `${x(index).toFixed(2)},${y(Number.isFinite(value) ? value : 0).toFixed(2)}`).join(' ');

        const svg = svgNode('svg', {
            class: 'dashboard-chart-svg',
            viewBox: `0 0 ${width} ${height}`,
            preserveAspectRatio: 'none',
            'aria-hidden': 'true',
            focusable: 'false',
        });
        const defs = svgNode('defs');
        const gradient = svgNode('linearGradient', { id: 'dashboardSalesArea', x1: '0', y1: '0', x2: '0', y2: '1' });
        gradient.append(svgNode('stop', { offset: '0%', 'stop-color': '#3b82f6', 'stop-opacity': '.7' }));
        gradient.append(svgNode('stop', { offset: '100%', 'stop-color': '#3b82f6', 'stop-opacity': '0' }));
        defs.append(gradient);
        svg.append(defs);

        for (let index = 0; index <= 4; index += 1) {
            const value = maximum - (range * index / 4);
            const lineY = y(value);
            svg.append(svgNode('line', { class: 'dashboard-chart-grid', x1: padding.left, y1: lineY, x2: width - padding.right, y2: lineY }));
            const axisLabel = svgNode('text', { class: 'dashboard-chart-axis', x: padding.left - 7, y: lineY + 3, 'text-anchor': 'end' });
            axisLabel.textContent = Intl.NumberFormat('es-PE', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
            svg.append(axisLabel);
        }

        const baseY = y(0);
        const salesPoints = points(sales);
        const areaPoints = `${x(0)},${baseY} ${salesPoints} ${x(labels.length - 1)},${baseY}`;
        svg.append(svgNode('polygon', { class: 'dashboard-chart-area', points: areaPoints }));
        svg.append(svgNode('polyline', { class: 'dashboard-chart-sales', points: salesPoints }));
        svg.append(svgNode('polyline', { class: 'dashboard-chart-profit', points: points(profits) }));

        const labelIndexes = [...new Set([0, 7, 14, 21, labels.length - 1].filter((index) => index < labels.length))];
        labelIndexes.forEach((index) => {
            const axisLabel = svgNode('text', { class: 'dashboard-chart-axis', x: x(index), y: height - 8, 'text-anchor': index === 0 ? 'start' : (index === labels.length - 1 ? 'end' : 'middle') });
            axisLabel.textContent = labels[index];
            svg.append(axisLabel);
            [['#2563eb', sales[index], 'Ventas'], ['#19b8a5', profits[index], 'Ganancia']].forEach(([color, value, name]) => {
                const dot = svgNode('circle', { class: 'dashboard-chart-dot', cx: x(index), cy: y(Number(value) || 0), r: 4, fill: color });
                const title = svgNode('title');
                title.textContent = `${labels[index]} · ${name}: ${formatMoney(value)}`;
                dot.append(title);
                svg.append(dot);
            });
        });

        chart.replaceChildren(svg);
    });

    const paymentAlertBadge = $('#paymentAlertBadge');
    const adminPaymentToast = $('#adminPaymentToast');
    let paymentPollingBusy = false;

    const playPaymentTone = () => {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            const context = new AudioContext();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.frequency.setValueAtTime(740, context.currentTime);
            oscillator.frequency.setValueAtTime(920, context.currentTime + .12);
            gain.gain.setValueAtTime(.055, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(.001, context.currentTime + .32);
            oscillator.connect(gain).connect(context.destination);
            oscillator.start();
            oscillator.stop(context.currentTime + .33);
            oscillator.addEventListener('ended', () => context.close());
        } catch (error) {
            // Algunos navegadores bloquean audio hasta la primera interacción.
        }
    };

    const showAdminPaymentToast = (order) => {
        if (!adminPaymentToast || !order) return;
        $('#adminPaymentToastTitle').textContent = order.order_code || 'Comprobante recibido';
        $('#adminPaymentToastText').textContent = `${formatMoney(order.total)} · pendiente de revisión`;
        adminPaymentToast.hidden = false;
        requestAnimationFrame(() => adminPaymentToast.classList.add('is-visible'));
        playPaymentTone();
        window.clearTimeout(showAdminPaymentToast.timer);
        showAdminPaymentToast.timer = window.setTimeout(() => {
            adminPaymentToast.classList.remove('is-visible');
            window.setTimeout(() => { adminPaymentToast.hidden = true; }, 220);
        }, 9000);
    };

    adminPaymentToast?.querySelector('button')?.addEventListener('click', () => {
        adminPaymentToast.classList.remove('is-visible');
        window.setTimeout(() => { adminPaymentToast.hidden = true; }, 220);
    });

    const checkYapeUpdates = async () => {
        if (!paymentAlertBadge || paymentPollingBusy || document.hidden) return;
        paymentPollingBusy = true;
        try {
            const response = await fetch(`${baseUrl}/api/admin_yape_updates.php`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) return;
            const result = await response.json();
            if (!result.success) return;
            const pendingCount = Number(result.pending_count || 0);
            paymentAlertBadge.textContent = pendingCount > 99 ? '99+' : String(pendingCount);
            paymentAlertBadge.hidden = pendingCount === 0;
            const currentId = Number(result.latest_id || 0);
            const storedId = Number(localStorage.getItem('stockflowLastYapeOrder') || 0);
            if (storedId > 0 && currentId > storedId && result.latest) showAdminPaymentToast(result.latest);
            if (currentId > storedId) localStorage.setItem('stockflowLastYapeOrder', String(currentId));
        } catch (error) {
            // El panel sigue funcionando aunque una consulta temporal falle.
        } finally {
            paymentPollingBusy = false;
        }
    };

    if (paymentAlertBadge) {
        checkYapeUpdates();
        window.setInterval(checkYapeUpdates, 7000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) checkYapeUpdates(); });
    }

    $$('[data-auto-dismiss]').forEach((alert) => {
        $('button', alert)?.addEventListener('click', () => alert.remove());
        window.setTimeout(() => {
            alert.classList.add('fade-out');
            window.setTimeout(() => alert.remove(), 250);
        }, 5000);
    });

    $$('[data-auto-submit]').forEach((control) => {
        control.addEventListener('change', () => control.form?.requestSubmit());
    });

    $$('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || '¿Deseas continuar?')) event.preventDefault();
        });
    });

    $$('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.passwordToggle);
            if (!input) return;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Ver' : 'Ocultar';
        });
    });

    const textarea = $('textarea[name="description"]');
    const characterCount = $('[data-char-count]');
    const updateCharacterCount = () => {
        if (textarea && characterCount) characterCount.textContent = String(textarea.value.length);
    };
    textarea?.addEventListener('input', updateCharacterCount);
    updateCharacterCount();

    const galleryInput = $('[data-gallery-input]');
    const galleryPreview = $('[data-gallery-preview]');
    const galleryCount = $('[data-gallery-count]');
    let galleryPreviewUrls = [];
    galleryInput?.addEventListener('change', () => {
        galleryPreviewUrls.forEach((previewUrl) => URL.revokeObjectURL(previewUrl));
        const selectedFiles = [...galleryInput.files];
        const hasTooMany = selectedFiles.length > 8;
        galleryInput.setCustomValidity(hasTooMany ? 'Selecciona como máximo 8 imágenes.' : '');
        if (galleryCount) {
            galleryCount.textContent = `${Math.min(selectedFiles.length, 8)} / 8`;
            galleryCount.classList.toggle('is-complete', selectedFiles.length > 0 && !hasTooMany);
            galleryCount.classList.toggle('is-error', hasTooMany);
        }
        galleryPreviewUrls = selectedFiles.slice(0, 8).map((file) => URL.createObjectURL(file));
        if (!galleryPreview) return;
        galleryPreview.hidden = galleryPreviewUrls.length === 0;
        galleryPreview.innerHTML = galleryPreviewUrls.map((previewUrl, index) => `
            <article><img src="${previewUrl}" alt="Nueva vista ${index + 1}"><span>Imagen ${index + 1}</span></article>
        `).join('');
        if (hasTooMany) galleryInput.reportValidity();
    });

    const productForm = $('[data-validate-product]');
    productForm?.addEventListener('submit', (event) => {
        let firstInvalid = null;
        $$('input, textarea', productForm).forEach((field) => {
            field.classList.toggle('is-invalid', !field.checkValidity());
            if (!field.checkValidity() && !firstInvalid) firstInvalid = field;
        });

        if (firstInvalid) {
            event.preventDefault();
            firstInvalid.focus();
            firstInvalid.reportValidity();
        }
    });

    const confirmModal = $('#confirmModal');
    const deleteForm = $('#deleteProductForm');
    const deleteProductId = $('#deleteProductId');
    const confirmDeleteButton = $('#confirmDeleteButton');

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-delete-product]');
        if (!button || !confirmModal) return;
        deleteProductId.value = button.dataset.deleteProduct;
        $('#confirmText').textContent = `Se eliminará “${button.dataset.deleteName}” de forma permanente.`;
        modalOpen(confirmModal);
    });

    confirmDeleteButton?.addEventListener('click', () => {
        confirmDeleteButton.disabled = true;
        confirmDeleteButton.textContent = 'Eliminando...';
        deleteForm?.submit();
    });

    const productModal = $('#productModal');
    const galleryThumbnails = $('#productGalleryThumbnails');
    const galleryDots = $('#productGalleryDots');
    const galleryPrevious = $('#productGalleryPrev');
    const galleryNext = $('#productGalleryNext');
    const galleryZoom = $('#productGalleryZoom');
    const galleryStage = $('.product-gallery-stage', productModal || document);
    const galleryShell = $('.product-gallery-shell', productModal || document);
    let modalGalleryImages = [];
    let modalGalleryIndex = 0;
    let modalGalleryProduct = null;
    let modalGalleryTimer = null;

    const stopProductGallery = () => {
        window.clearTimeout(modalGalleryTimer);
        modalGalleryTimer = null;
    };

    const scheduleProductGallery = () => {
        stopProductGallery();
        if (modalGalleryImages.length <= 1 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        modalGalleryTimer = window.setTimeout(() => {
            if (productModal?.classList.contains('is-open')) moveProductGallery(1, false);
        }, 4200);
    };

    const renderProductGallery = () => {
        if (!modalGalleryProduct) return;
        const image = modalGalleryImages[modalGalleryIndex] || '';
        const icon = modalGalleryProduct.icon || '▦';
        const visual = $('#productModalVisual');
        visual.innerHTML = image
            ? `<img src="${escapeHtml(image)}" alt="${escapeHtml(modalGalleryProduct.name)} · vista ${modalGalleryIndex + 1}" decoding="async" referrerpolicy="no-referrer" data-image-fallback><span class="media-fallback" hidden>${escapeHtml(icon)}</span>`
            : `<span class="media-fallback">${escapeHtml(icon)}</span><small>Este producto todavía no tiene una foto cargada.</small>`;

        if (galleryThumbnails) {
            galleryThumbnails.hidden = modalGalleryImages.length <= 1;
            galleryThumbnails.innerHTML = modalGalleryImages.map((galleryImage, index) => `
                <button class="${index === modalGalleryIndex ? 'is-active' : ''}" type="button" data-gallery-index="${index}" aria-label="Ver imagen ${index + 1}">
                    <img src="${escapeHtml(galleryImage)}" alt="" loading="lazy" referrerpolicy="no-referrer">
                </button>
            `).join('');
        }
        galleryShell?.classList.toggle('gallery-single', modalGalleryImages.length <= 1);
        if (galleryDots) {
            galleryDots.hidden = modalGalleryImages.length <= 1;
            galleryDots.innerHTML = modalGalleryImages.map((_, index) => `
                <button class="${index === modalGalleryIndex ? 'is-active' : ''}" type="button" data-gallery-index="${index}" aria-label="Ir a imagen ${index + 1}"></button>
            `).join('');
        }
        if (galleryPrevious) galleryPrevious.hidden = modalGalleryImages.length <= 1;
        if (galleryNext) galleryNext.hidden = modalGalleryImages.length <= 1;
        if (galleryZoom) galleryZoom.hidden = image === '';
    };

    const moveProductGallery = (direction) => {
        if (modalGalleryImages.length <= 1) return;
        modalGalleryIndex = (modalGalleryIndex + direction + modalGalleryImages.length) % modalGalleryImages.length;
        renderProductGallery();
        scheduleProductGallery();
    };

    const showProductDetail = (product) => {
        if (!productModal || !product) return;
        modalGalleryProduct = product;
        modalGalleryImages = [...new Set([
            ...(Array.isArray(product.images) ? product.images : []),
            product.image_url || '',
        ].filter(Boolean))].slice(0, 8);
        modalGalleryIndex = 0;
        productModal.classList.remove('is-gallery-expanded');
        if (galleryZoom) galleryZoom.textContent = '⌕ Ampliar';

        $('#productModalName').textContent = product.name || '';
        $('#productModalDescription').textContent = product.description || 'Conoce este producto de Devioz Store.';
        $('#productModalPrice').textContent = formatMoney(product.price);

        renderProductGallery();

        modalOpen(productModal);
        scheduleProductGallery();
    };

    productModal?.addEventListener('click', (event) => {
        if (event.target === productModal || event.target.closest('[data-modal-close]')) {
            stopProductGallery();
            return;
        }
        const galleryButton = event.target.closest('[data-gallery-index]');
        if (!galleryButton) return;
        modalGalleryIndex = Number(galleryButton.dataset.galleryIndex) || 0;
        renderProductGallery();
        scheduleProductGallery();
    });
    productModal?.addEventListener('overlayclose', stopProductGallery);
    galleryPrevious?.addEventListener('click', () => moveProductGallery(-1));
    galleryNext?.addEventListener('click', () => moveProductGallery(1));
    galleryZoom?.addEventListener('click', () => {
        if (!productModal) return;
        const expanded = productModal.classList.toggle('is-gallery-expanded');
        galleryZoom.textContent = expanded ? '↙ Volver' : '⌕ Ampliar';
    });
    let galleryTouchStart = null;
    galleryStage?.addEventListener('touchstart', (event) => {
        galleryTouchStart = event.changedTouches[0]?.clientX ?? null;
    }, { passive: true });
    galleryStage?.addEventListener('touchend', (event) => {
        if (galleryTouchStart === null) return;
        const distance = (event.changedTouches[0]?.clientX ?? galleryTouchStart) - galleryTouchStart;
        if (Math.abs(distance) > 45) moveProductGallery(distance > 0 ? -1 : 1);
        galleryTouchStart = null;
    }, { passive: true });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.product-detail-btn');
        if (!button) return;
        try {
            showProductDetail(JSON.parse(button.dataset.product));
        } catch (error) {
            console.error('No se pudo mostrar el producto.', error);
        }
    });

    const searchInput = $('#productSearch');
    const searchClear = $('#searchClear');
    const categoryFilter = $('#categoryFilter');
    const productSort = $('#productSort');
    const offerOnlyButton = $('#offerOnlyButton');
    const productGrid = $('#productGrid');
    const resultCount = $('#resultCount');
    const emptyState = $('#emptyState');
    let requestController = null;
    let searchTimer = null;

    const renderProducts = (products) => {
        if (!productGrid) return;

        productGrid.innerHTML = products.map((product) => {
            const visual = product.image_url
                ? `<div class="product-image-frame"><img src="${escapeHtml(product.image_url)}" alt="${escapeHtml(product.name)}" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-image-fallback><span class="media-fallback" hidden>${escapeHtml(product.icon || '▦')}</span></div>`
                : `<span class="product-symbol">${escapeHtml(product.icon || '▦')}</span>`;
            const productData = escapeHtml(JSON.stringify(product));
            const nutritionLabel = product.nutrition_status === 'con_dato' && product.kcal_serving !== null
                ? `${Number(product.kcal_serving).toFixed(0)} kcal`
                : (product.nutrition_status === 'no_aplica' ? 'No aplica' : 'Sin información');
            const action = product.purchasable === true
                ? `<button class="catalog-add-button" type="button" data-buy-now="1" data-list-product="${productData}"><span>🛒</span> Agregar al carrito</button>`
                : '<button class="catalog-add-button" type="button" disabled>Consultar disponibilidad</button>';

            return `
                <article class="product-card public-product-card">
                    <button class="product-visual catalog-product-visual product-detail-btn" type="button" data-product="${productData}" aria-label="Ver imágenes de ${escapeHtml(product.name)}">
                        ${visual}
                        ${Array.isArray(product.images) && product.images.length > 1 ? '<span class="gallery-hint">Ver galería</span>' : ''}
                    </button>
                    <div class="product-content">
                        <h3>${escapeHtml(product.name)}</h3>
                        <p>${escapeHtml(product.presentation || product.description || 'Conoce este producto de Devioz Store.')}</p>
                        <small class="nutrition-pill">${escapeHtml(nutritionLabel)}</small>
                        <div class="catalog-price"><span>${product.purchasable === true ? 'Precio Devioz' : 'Precio referencial'}</span><div><strong>${formatMoney(product.price)}</strong></div></div>
                        ${action}
                    </div>
                </article>`;
        }).join('');

        $('h3', emptyState).textContent = 'No encontramos productos';
        $('p', emptyState).textContent = 'Prueba con otro nombre o descripción.';
        emptyState.hidden = products.length > 0;
    };

    const searchProducts = async () => {
        if (!productGrid) return;

        requestController?.abort();
        requestController = new AbortController();
        productGrid.classList.add('is-loading');

        const parameters = new URLSearchParams({
            q: searchInput?.value.trim() || '',
            category: categoryFilter?.value || '',
            sort: productSort?.value || 'featured',
            offer: offerOnlyButton?.getAttribute('aria-pressed') === 'true' ? '1' : '0',
            per_page: '24',
        });
        if (searchClear) searchClear.hidden = !searchInput?.value;

        try {
            const response = await fetch(`${baseUrl}/api/productos.php?${parameters}`, {
                signal: requestController.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (!data || !Array.isArray(data.products)) throw new Error('Respuesta inválida');
            renderProducts(data.products || []);
            if (resultCount) resultCount.textContent = `${data.count} resultado${data.count === 1 ? '' : 's'}`;
        } catch (error) {
            if (error.name !== 'AbortError') {
                emptyState.hidden = false;
                $('h3', emptyState).textContent = 'No se pudo actualizar el catálogo';
                $('p', emptyState).textContent = 'Verifica que Apache y MySQL estén funcionando.';
            }
        } finally {
            productGrid.classList.remove('is-loading');
        }
    };

    searchInput?.addEventListener('input', () => {
        if (searchClear) searchClear.hidden = !searchInput.value;
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(searchProducts, 280);
    });
    searchClear?.addEventListener('click', () => {
        if (!searchInput) return;
        searchInput.value = '';
        searchClear.hidden = true;
        searchInput.focus();
        searchProducts();
    });
    const categoryPills = $$('[data-category-pick]');

    const activateCategory = (category, shouldScroll = false) => {
        if (!categoryFilter) return;
        categoryFilter.value = category;
        categoryPills.forEach((pill) => {
            pill.classList.toggle('is-active', pill.dataset.categoryPick === category);
        });
        searchProducts();
        if (shouldScroll) $('#catalogo')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    categoryPills.forEach((pill) => {
        pill.addEventListener('click', () => activateCategory(pill.dataset.categoryPick || '', true));
    });

    categoryFilter?.addEventListener('change', () => activateCategory(categoryFilter.value));
    productSort?.addEventListener('change', searchProducts);
    offerOnlyButton?.addEventListener('click', () => {
        const active = offerOnlyButton.getAttribute('aria-pressed') !== 'true';
        offerOnlyButton.setAttribute('aria-pressed', active ? 'true' : 'false');
        offerOnlyButton.classList.toggle('is-active', active);
        searchProducts();
    });

    $$('[data-go-category]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            activateCategory(link.dataset.goCategory || '', true);
        });
    });

    const listDrawer = $('#listDrawer');
    if (!listDrawer) $$('[data-list-open]').forEach(button => button.addEventListener('click', () => { window.location.href = `${baseUrl}/index.php#catalogo`; }));
    const listItems = $('#listItems');
    const listEmpty = $('#listEmpty');
    const listTotal = $('#listTotal');
    const listBadge = $('#listBadge');
    const clearListButton = $('#clearListButton');
    const yapeCheckoutButton = $('#yapeCheckoutButton');
    const yapeModal = $('#yapeModal');
    const yapeTotal = $('#yapeTotal');
    const yapePhone = $('#yapePhone');
    const copyYapePhone = $('#copyYapePhone');
    const confirmYapeOrder = $('#confirmYapeOrder');
    const checkoutCustomer = $('#checkoutCustomer');
    const checkoutPhone = $('#checkoutPhone');
    const checkoutCustomerFields = $('#checkoutCustomerFields');
    const checkoutPaymentMethod = $('#checkoutPaymentMethod');
    const checkoutDeliveryZone = $('#checkoutDeliveryZone');
    const checkoutShippingFields = $('#checkoutShippingFields');
    const checkoutAddress = $('#checkoutAddress');
    const checkoutReference = $('#checkoutReference');
    const checkoutCoupon = $('#checkoutCoupon');
    const applyCoupon = $('#applyCoupon');
    const checkoutQuoteStatus = $('#checkoutQuoteStatus');
    const cashPaymentFields = $('#cashPaymentFields');
    const cashPaysWith = $('#cashPaysWith');
    const cashPaysWithLabel = $('#cashPaysWithLabel');
    const cashExactButton = $('#cashExactButton');
    const cashChangeButton = $('#cashChangeButton');
    const cashExactSummary = $('#cashExactSummary');
    const cashChangeSummary = $('#cashChangeSummary');
    const yapeMethodFields = $('#yapeMethodFields');
    const yapePaymentPanel = $('#yapePaymentPanel');
    const listStorageKey = 'devioz-store-lista-v2';
    const aiConfirmationStorageKey = 'devioz-ai-confirmation-v1';
    let shoppingList = [];
    let cartIntentItems = [];
    let cartReconciliationQueue = Promise.resolve();
    let cartReconciliationRevision = 0;
    let checkoutQuote = null;

    try {
        const storedList = JSON.parse(window.localStorage.getItem(listStorageKey) || '[]');
        cartIntentItems = window.DeviozPublicCartState?.cartRequestItems(storedList) || [];
    } catch (error) {
        cartIntentItems = [];
    }

    const productKey = (product) => `${product.kind || 'product'}-${String(product.id || product.code || product.name)}-${product.presentation || 'unidad'}`;

    const saveShoppingList = () => {
        try {
            window.localStorage.setItem(listStorageKey, JSON.stringify(shoppingList));
        } catch (error) {
            console.warn('El navegador no permitió guardar la lista.', error);
        }
    };

    const revalidateShoppingList = (persist = true) => {
        const revision = ++cartReconciliationRevision;
        const state = window.DeviozPublicCartState;
        const requestItems = state?.cartRequestItems(cartIntentItems) || [];
        const finish = (items, verified) => {
            if (revision !== cartReconciliationRevision) return items;
            shoppingList = verified ? items : [];
            cartIntentItems = verified && state ? state.cartRequestItems(items) : [];
            if (persist) saveShoppingList();
            renderShoppingList();
            return shoppingList;
        };

        cartReconciliationQueue = cartReconciliationQueue.catch(() => undefined).then(async () => {
            if (!state) return finish([], false);
            if (!requestItems.length) return finish([], true);
            const csrfToken = String($('#yapeCsrfToken')?.value || '');
            if (csrfToken === '') return finish([], false);
            try {
                const response = await fetch(`${baseUrl}/api/cart_reconcile.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ csrf_token: csrfToken, items: requestItems }),
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error('Cart reconciliation failed.');
                return finish(state.reconcileVerifiedCart(result), true);
            } catch (error) {
                return finish([], false);
            }
        });
        return cartReconciliationQueue;
    };

    const renderShoppingList = () => {
        if (!listItems || !listEmpty || !listTotal || !listBadge) return;

        const totalUnits = shoppingList.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
        const totalPrice = shoppingList.reduce((sum, item) => sum + (Number(item.price) * Number(item.quantity || 0)), 0);
        if (checkoutQuote && Math.abs(Number(checkoutQuote.subtotal) - totalPrice) >= .01) {
            checkoutQuote = null;
            if (checkoutQuoteStatus) checkoutQuoteStatus.textContent = 'La lista cambió. Recalcula el total antes de confirmar.';
            if (yapeTotal) yapeTotal.textContent = formatMoney(totalPrice);
        }
        listBadge.textContent = String(totalUnits);
        listTotal.textContent = formatMoney(totalPrice);
        listEmpty.hidden = shoppingList.length > 0;
        listItems.hidden = shoppingList.length === 0;
        clearListButton.disabled = shoppingList.length === 0;
        if (yapeCheckoutButton) yapeCheckoutButton.disabled = shoppingList.length === 0;

        listItems.innerHTML = shoppingList.map((item) => {
            const key = escapeHtml(productKey(item));
            const visual = item.image_url
                ? `<img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-image-fallback><span class="media-fallback" hidden>${escapeHtml(item.icon || '🛍️')}</span>`
                : escapeHtml(item.icon || '🛍️');

            return `
                <article class="drawer-item">
                    <div class="drawer-item-visual">${visual}</div>
                    <div class="drawer-item-info">
                        <strong>${escapeHtml(item.name)}</strong>
                        <span>${formatMoney(item.price)} · ${escapeHtml(item.presentation_label || 'Unidad')}</span>
                        <small class="drawer-item-subtotal">Subtotal: ${formatMoney(Number(item.price) * Number(item.quantity))}</small>
                        <div class="drawer-quantity">
                            <button type="button" data-list-action="decrease" data-list-key="${key}" aria-label="${Number(item.quantity) === 1 ? 'Quitar producto' : 'Restar unidad'}">−</button>
                            <b>${Number(item.quantity)}</b>
                            <button type="button" data-list-action="increase" data-list-key="${key}" aria-label="Agregar unidad" ${Number(item.quantity) >= Number(item.stock) ? 'disabled' : ''}>＋</button>
                        </div>
                    </div>
                    <button class="drawer-remove" type="button" data-list-action="remove" data-list-key="${key}" aria-label="Quitar producto">×</button>
                </article>`;
        }).join('');
    };

    const openShoppingList = (trigger = document.activeElement) => {
        if (!listDrawer) return;
        if (overlayStack.some((entry) => entry.element === listDrawer)) return;
        registerOverlay(listDrawer, () => closeShoppingList(), trigger);
        listDrawer.hidden = false;
        document.body.classList.add('drawer-open');
        requestAnimationFrame(() => {
            listDrawer.classList.add('is-open');
            $('[data-list-close]', listDrawer)?.focus();
        });
    };

    const closeShoppingList = (shouldRestoreFocus = true) => {
        if (!listDrawer) return;
        listDrawer.classList.remove('is-open');
        unregisterOverlay(listDrawer, shouldRestoreFocus);
        window.setTimeout(() => {
            listDrawer.hidden = true;
            document.body.classList.remove('drawer-open');
        }, 250);
    };

    const addToShoppingList = (product, sourceButton, presentation = 'unidad') => {
        if (!product || typeof product !== 'object') return Promise.resolve(false);
        if (product.kind !== 'combo' && product.purchasable !== true) return Promise.resolve(false);
        if (product.restricted === true || isAgeRestrictedPublicItem(product)) return Promise.resolve(false);
        const request = window.DeviozPublicCartState?.cartRequestItems([{
            kind: product.kind === 'combo' ? 'combo' : 'product',
            id: product.id,
            quantity: 1,
            presentation,
        }])[0];
        if (!request) return Promise.resolve(false);
        const current = cartIntentItems.find((item) => item.type === request.type && item.id === request.id);
        const verified = shoppingList.find((item) => item.type === request.type && item.id === request.id);
        if ((current?.quantity || 0) >= (verified?.stock || 99)) return Promise.resolve(false);
        cartIntentItems = cartIntentItems.filter((item) => item.type !== request.type || item.id !== request.id);
        cartIntentItems.push({ ...request, quantity: (current?.quantity || 0) + 1 });

        return revalidateShoppingList().then((items) => {
            const added = items.some((item) => item.type === request.type && item.id === request.id);
            if (added && sourceButton) {
                const originalHtml = sourceButton.innerHTML;
                sourceButton.classList.add('is-added');
                sourceButton.innerHTML = '<span>✓</span>Agregado';
                window.setTimeout(() => {
                    sourceButton.classList.remove('is-added');
                    sourceButton.innerHTML = originalHtml;
                }, 950);
            }
            return added;
        });
    };

    const proposalReconciledItems = (proposal, items) => {
        const wanted = new Map(proposal.map((item) => [Number(item.id), Number(item.quantity)]));
        return items.filter((item) => wanted.has(Number(item.id))).map((item) => ({
            type: 'product',
            id: Number(item.id),
            quantity: Number(item.quantity),
        }));
    };
    const finalizeAiConfirmation = async (pending, reconciled) => {
        if (!pending?.token || !Array.isArray(pending.items) || !Array.isArray(reconciled)) throw new Error('La confirmación de la propuesta no es válida.');
        const response = await fetch(`${baseUrl}/api/recomendaciones.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action: 'finalize', confirmation: pending.token, items: pending.items, reconciled }),
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'No se pudo confirmar la propuesta.');
        localStorage.removeItem(aiConfirmationStorageKey);
    };
    const readPendingAiConfirmation = () => {
        let pending = null;
        try { pending = JSON.parse(localStorage.getItem(aiConfirmationStorageKey) || 'null'); } catch {}
        if (!pending?.token || !/^[a-f0-9]{48}$/.test(pending.token) || !Array.isArray(pending.items)
            || !Array.isArray(pending.cartItems) || pending.items.length < 1 || pending.items.length > 12
            || pending.cartItems.length !== pending.items.length) {
            if (localStorage.getItem(aiConfirmationStorageKey)) localStorage.removeItem(aiConfirmationStorageKey);
            return null;
        }
        return pending;
    };
    const finalizePendingAiConfirmation = async () => {
        const pending = readPendingAiConfirmation();
        if (!pending) return false;
        const state = window.DeviozPublicCartState;
        if (!state) return false;
        const previousIntent = cartIntentItems;
        const previousList = shoppingList;
        cartIntentItems = state.cartRequestItems(pending.cartItems);
        const items = await revalidateShoppingList(false);
        const reconciled = proposalReconciledItems(pending.items, items);
        if (reconciled.length !== pending.items.length || reconciled.some((item, index) => item.id !== pending.items[index].id || item.quantity !== pending.items[index].quantity)) {
            cartIntentItems = previousIntent;
            shoppingList = previousList;
            renderShoppingList();
            return false;
        }
        try {
            await finalizeAiConfirmation(pending, reconciled);
            saveShoppingList();
            return true;
        } catch (error) {
            cartIntentItems = previousIntent;
            shoppingList = previousList;
            renderShoppingList();
            if (error.message.includes('venció') || error.message.includes('alterada') || error.message.includes('cambió') || error.message.includes('confirmada')) localStorage.removeItem(aiConfirmationStorageKey);
            return false;
        }
    };
    const addRecommendationProposal = async (result) => {
        const state = window.DeviozPublicCartState;
        if (!state || !result?.confirmation || !Array.isArray(result.items)) throw new Error('La propuesta no está disponible para confirmar.');
        const proposal = result.items.map((item) => ({
            kind: 'product',
            id: Number(item.id),
            code: String(item.code || ''),
            name: String(item.name || ''),
            category: String(item.category || ''),
            price: Number(item.price),
            stock: Number(item.stock),
            image_url: String(item.image_url || ''),
            icon: '🛍️',
            presentation: 'unidad',
            presentation_label: 'Unidad',
            units_per_item: 1,
            quantity: Math.min(99, Math.max(1, Number(item.quantity) || 1)),
        }));
        const proposalItems = proposal.map((item) => ({ id: item.id, quantity: item.quantity, price: item.price }));
        if (proposalItems.length < 1 || proposalItems.length > 12 || !/^[a-f0-9]{48}$/.test(result.confirmation)) throw new Error('La propuesta no tiene una confirmación válida.');
        const previousIntent = cartIntentItems;
        const previousList = shoppingList;
        localStorage.setItem(aiConfirmationStorageKey, JSON.stringify({ token: result.confirmation, items: proposalItems, cartItems: proposal }));
        cartIntentItems = state.cartRequestItems(proposal);
        const reconciledList = await revalidateShoppingList(false);
        const reconciled = proposalReconciledItems(proposalItems, reconciledList);
        if (reconciled.length !== proposalItems.length || reconciled.some((item, index) => item.id !== proposalItems[index].id || item.quantity !== proposalItems[index].quantity)) {
            cartIntentItems = previousIntent;
            shoppingList = previousList;
            renderShoppingList();
            throw new Error('El inventario cambió antes de confirmar. Genera una nueva propuesta.');
        }
        try {
            await finalizeAiConfirmation({ token: result.confirmation, items: proposalItems }, reconciled);
            saveShoppingList();
        } catch (error) {
            cartIntentItems = previousIntent;
            shoppingList = previousList;
            renderShoppingList();
            throw error;
        }
    };

    $$('[data-list-open]').forEach((button) => button.addEventListener('click', () => openShoppingList(button)));
    $$('[data-list-close]').forEach((button) => button.addEventListener('click', () => closeShoppingList()));

    listDrawer?.addEventListener('click', (event) => {
        if (event.target === listDrawer) closeShoppingList();
    });

    document.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-list-product], [data-list-combo]');
        if (addButton && !addButton.disabled) {
            try {
                const rawData = addButton.dataset.listProduct || addButton.dataset.listCombo;
                addToShoppingList(JSON.parse(rawData), addButton, addButton.dataset.listPresentation || 'unidad')
                    .then((added) => {
                        if (added) {
                            openShoppingList(addButton);
                            return;
                        }
                        const originalHtml = addButton.innerHTML;
                        addButton.textContent = 'No disponible';
                        window.setTimeout(() => { addButton.innerHTML = originalHtml; }, 1800);
                    });
            } catch (error) {
                console.error('No se pudo agregar el producto o combo.', error);
            }
        }

        const actionButton = event.target.closest('[data-list-action]');
        if (!actionButton) return;
        const item = shoppingList.find((entry) => productKey(entry) === actionButton.dataset.listKey);
        if (!item) return;
        const type = item.type || (item.kind === 'combo' ? 'combo' : item.presentation === 'paquete' ? 'product_pack' : 'product');
        const intent = cartIntentItems.find((entry) => entry.type === type && entry.id === item.id);
        if (!intent) return;

        if (actionButton.dataset.listAction === 'increase') {
            if (intent.quantity >= Number(item.stock)) return;
            cartIntentItems = cartIntentItems.map((entry) => entry === intent ? { ...entry, quantity: entry.quantity + 1 } : entry);
        } else if (actionButton.dataset.listAction === 'decrease') {
            cartIntentItems = intent.quantity <= 1
                ? cartIntentItems.filter((entry) => entry !== intent)
                : cartIntentItems.map((entry) => entry === intent ? { ...entry, quantity: entry.quantity - 1 } : entry);
        } else if (actionButton.dataset.listAction === 'remove') {
            cartIntentItems = cartIntentItems.filter((entry) => entry !== intent);
        }
        revalidateShoppingList();
    });

    clearListButton?.addEventListener('click', () => {
        shoppingList = [];
        cartIntentItems = [];
        cartReconciliationRevision += 1;
        saveShoppingList();
        renderShoppingList();
    });

    const shoppingTotal = () => shoppingList.reduce(
        (sum, item) => sum + (Number(item.price) * Number(item.quantity || 0)),
        0
    );

    const closeYapeModal = () => {
        if (!yapeModal) return;
        yapeModal.classList.remove('is-open');
        unregisterOverlay(yapeModal);
        window.setTimeout(() => {
            yapeModal.hidden = true;
            if (!$('.modal-backdrop.is-open, .yape-backdrop.is-open')) document.body.classList.remove('modal-open');
        }, 220);
    };

    yapeCheckoutButton?.addEventListener('click', () => {
        if (!yapeModal || shoppingList.length === 0) return;
        if (overlayStack.some((entry) => entry.element === yapeModal)) return;
        registerOverlay(yapeModal, closeYapeModal, yapeCheckoutButton);
        checkoutQuote = null;
        if (yapeTotal) yapeTotal.textContent = formatMoney(shoppingTotal());
        if (checkoutPaymentMethod?.value === 'yape'
            && (yapeModal.dataset.enabled !== '1' || (!yapeModal.dataset.phone && yapeModal.dataset.hasQr !== '1'))) {
            checkoutPaymentMethod.value = 'efectivo';
        }
        if (yapeOrderAccess) yapeOrderAccess.hidden = true;
        if (yapeReceiptUpload) yapeReceiptUpload.hidden = false;
        if (confirmYapeOrder) {
            confirmYapeOrder.hidden = false;
            confirmYapeOrder.textContent = 'Confirmar pedido';
        }
        if (yapeHelpText) yapeHelpText.hidden = false;
        if (yapeInlineStatus) yapeInlineStatus.hidden = true;
        checkoutFlow?.restart();
        syncCheckoutShippingFields();
        updateCheckoutState();
        yapeModal.hidden = false;
        document.body.classList.add('modal-open');
        requestAnimationFrame(() => {
            yapeModal.classList.add('is-open');
            $('[data-yape-close]', yapeModal)?.focus();
        });
        refreshCheckoutQuote();
    });

    $$('[data-yape-close]').forEach((button) => button.addEventListener('click', closeYapeModal));
    yapeModal?.addEventListener('click', (event) => {
        if (event.target === yapeModal) closeYapeModal();
    });

    copyYapePhone?.addEventListener('click', async () => {
        const phone = yapeModal?.dataset.phone || '';
        if (!phone) return;
        try {
            await navigator.clipboard.writeText(phone);
            const original = copyYapePhone.textContent;
            copyYapePhone.textContent = '✓ Copiado';
            window.setTimeout(() => { copyYapePhone.textContent = original; }, 1200);
        } catch (error) {
            window.prompt('Copia el número de Yape:', phone);
        }
    });

    const yapeReceiptInput = $('#yapeReceipt');
    const yapeReceiptPreview = $('#yapeReceiptPreview');
    const yapeUploadPrompt = $('#yapeUploadPrompt');
    const yapeInlineStatus = $('#yapeInlineStatus');
    const yapeOrderAccess = $('#yapeOrderAccess');
    const yapeReceiptUpload = $('#yapeReceiptUpload');
    const yapeHelpText = $('#yapeHelpText');
    const cardMethodFields = $('#cardMethodFields');
    const checkoutBackStep = $('#checkoutBackStep');
    const checkoutNextStep = $('#checkoutNextStep');
    const digitalPaymentLabel = $('#digitalPaymentLabel');
    const digitalPaymentOwner = $('#digitalPaymentOwner');
    const culqiPayButton = $('#culqiPayButton');
    let optimizedYapeReceipt = null;
    let cashMode = 'exacto';
    let checkoutQuoteGeneration = 0;
    let cardChargeReady = false;
    let checkoutFlow = null;

    const quoteTotal = () => checkoutQuote ? Number(checkoutQuote.total) : shoppingTotal();

    const syncCheckoutShippingFields = () => {
        if (checkoutShippingFields) checkoutShippingFields.hidden = !checkoutDeliveryZone?.value;
    };
    syncCheckoutShippingFields();

    const syncCheckoutStepPanels = () => {
        if (!checkoutFlow || !yapeModal) return;
        const step = checkoutFlow.current();
        $$('[data-checkout-step]', yapeModal).forEach((panel) => {
            panel.hidden = panel.dataset.checkoutStep !== step;
        });
        $$('[data-checkout-progress]', yapeModal).forEach((item) => {
            if (item.dataset.checkoutProgress === step) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
        });
        if (checkoutBackStep) checkoutBackStep.hidden = checkoutFlow.index() === 0;
        if (checkoutNextStep) checkoutNextStep.hidden = step === 'confirmation';
        if (confirmYapeOrder) confirmYapeOrder.hidden = step !== 'confirmation';
        const heading = $(`[data-checkout-step="${step}"] h3`, yapeModal);
        if (heading) setTimeout(() => heading.focus(), 20);
        updateCheckoutState();
    };

    const refreshCheckoutQuote = async () => {
        const generation = ++checkoutQuoteGeneration;
        checkoutQuote = null;
        updateCheckoutState();
        const body = new FormData();
        body.append('csrf_token', String($('#yapeCsrfToken')?.value || ''));
        body.append('items', JSON.stringify(window.DeviozPublicCartState?.cartRequestItems(shoppingList) || []));
        body.append('delivery_zone_id', checkoutDeliveryZone?.value || '');
        body.append('coupon', checkoutCoupon?.value.trim() || '');
        if (checkoutQuoteStatus) checkoutQuoteStatus.textContent = 'Calculando total…';
        try {
            const response = await fetch(`${window.STOCKFLOW_BASE_URL || ''}/api/checkout_quote.php`, { method: 'POST', body });
            const result = await response.json();
            if (generation !== checkoutQuoteGeneration) return;
            if (!response.ok || !result.success) throw new Error(result.message || 'No se pudo calcular el total.');
            checkoutQuote = result;
            cardChargeReady = false;
            if (yapeTotal) yapeTotal.textContent = formatMoney(result.total);
            if (checkoutQuoteStatus) checkoutQuoteStatus.textContent = `Productos: ${formatMoney(result.subtotal)} · ${result.district === 'Recojo en tienda' ? 'Recojo en tienda' : `Envío: ${formatMoney(result.delivery_fee)}`}${result.discount > 0 ? ` · Descuento: -${formatMoney(result.discount)}` : ''}. ${result.message || ''}`;
        } catch (error) {
            if (generation !== checkoutQuoteGeneration) return;
            checkoutQuote = null;
            if (checkoutQuoteStatus) checkoutQuoteStatus.textContent = error.message || 'No se pudo calcular el total.';
        }
        updateCheckoutState();
    };

    const optimizeReceiptImage = async (file) => {
        if (!file?.type?.startsWith('image/')) return file;
        const bitmap = await createImageBitmap(file);
        const maximumSide = 1400;
        const scale = Math.min(1, maximumSide / Math.max(bitmap.width, bitmap.height));
        const width = Math.max(1, Math.round(bitmap.width * scale));
        const height = Math.max(1, Math.round(bitmap.height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d', { alpha: false });
        context.fillStyle = '#fff';
        context.fillRect(0, 0, width, height);
        context.drawImage(bitmap, 0, 0, width, height);
        bitmap.close();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', .82));
        if (!blob || blob.size >= file.size) return file;
        return new File([blob], 'comprobante-yape.jpg', { type: 'image/jpeg', lastModified: Date.now() });
    };

    const showYapeStatus = (message, type = 'success') => {
        if (!yapeInlineStatus) return;
        yapeInlineStatus.textContent = message;
        yapeInlineStatus.className = `yape-inline-status is-${type}`;
        yapeInlineStatus.hidden = false;
    };

    const updateCheckoutState = () => {
        const selectedMethod = checkoutPaymentMethod?.value || 'efectivo';
        const method = ['yape', 'plin', 'tarjeta'].includes(selectedMethod) ? selectedMethod : 'efectivo';
        const total = Math.round((quoteTotal() + Number.EPSILON) * 100) / 100;
        const customerValid = (checkoutCustomer?.value.trim().length || 0) >= 3;
        const phoneValid = (checkoutPhone?.value.replace(/\D/g, '').length || 0) >= 7;
        const zoneValid = Boolean(checkoutQuote);
        const pickup = checkoutQuote?.district === 'Recojo en tienda';
        const addressValid = pickup || (checkoutAddress?.value.trim().length || 0) >= 5;
        const yapeConfigured = yapeModal?.dataset.enabled === '1'
            && (Boolean(yapeModal.dataset.phone) || yapeModal.dataset.hasQr === '1');
        const plinConfigured = yapeModal?.dataset.plinEnabled === '1'
            && (Boolean(yapeModal.dataset.plinPhone) || yapeModal.dataset.plinHasQr === '1');
        const receiptReady = Boolean(optimizedYapeReceipt || yapeReceiptInput?.files?.[0]);
        let paymentValid = false;

        if (cashPaymentFields) cashPaymentFields.hidden = method !== 'efectivo';
        if (yapeMethodFields) yapeMethodFields.hidden = !['yape', 'plin'].includes(method);
        if (cardMethodFields) cardMethodFields.hidden = method !== 'tarjeta';
        if (yapePaymentPanel) yapePaymentPanel.hidden = !['yape', 'plin'].includes(method);
        yapeModal?.classList.toggle('is-cash', method === 'efectivo');
        if (method === 'yape' || method === 'plin') {
            const prefix = method === 'plin' ? 'plin' : '';
            const phone = method === 'plin' ? yapeModal?.dataset.plinPhone || '' : yapeModal?.dataset.phone || '';
            const owner = method === 'plin' ? yapeModal?.dataset.plinOwner || '' : yapeModal?.dataset.owner || '';
            if (digitalPaymentLabel) digitalPaymentLabel.textContent = phone ? `Numero ${method === 'plin' ? 'Plin' : 'Yape'}` : `Cuenta ${method === 'plin' ? 'Plin' : 'Yape'}`;
            if (yapePhone) yapePhone.textContent = phone || 'Paga escaneando el QR';
            if (digitalPaymentOwner) digitalPaymentOwner.textContent = owner;
            if (yapeUploadPrompt) yapeUploadPrompt.querySelector('b').textContent = `＋ Subir captura de ${method === 'plin' ? 'Plin' : 'Yape'}`;
        }

        if (method === 'efectivo') {
            const isExact = cashMode === 'exacto';
            if (cashPaysWithLabel) cashPaysWithLabel.hidden = isExact;
            cashExactButton?.classList.toggle('is-active', isExact);
            cashChangeButton?.classList.toggle('is-active', !isExact);
            if (cashExactSummary) {
                cashExactSummary.textContent = isExact
                    ? `Pago exacto: ${formatMoney(total)}.`
                    : 'Indica con cuánto pagarás para calcular el vuelto.';
            }

            if (isExact) {
                paymentValid = total > 0;
                cashPaysWith?.setCustomValidity('');
                if (cashChangeSummary) {
                    cashChangeSummary.className = 'cash-change-summary is-ready';
                    cashChangeSummary.textContent = 'Vuelto: S/ 0.00';
                }
            } else {
                const rawAmount = cashPaysWith?.value.trim() || '';
                const paysWith = Number(rawAmount);
                paymentValid = rawAmount !== '' && Number.isFinite(paysWith) && paysWith + 0.0001 >= total;
                cashPaysWith?.setCustomValidity(paymentValid || rawAmount === '' ? '' : 'El monto debe ser igual o mayor al total.');
                if (cashChangeSummary) {
                    cashChangeSummary.className = 'cash-change-summary';
                    if (rawAmount === '') {
                        cashChangeSummary.textContent = 'Ingresa el monto para calcular tu vuelto.';
                    } else if (!Number.isFinite(paysWith) || paysWith + 0.0001 < total) {
                        cashChangeSummary.textContent = `Falta ${formatMoney(Math.max(0, total - (Number.isFinite(paysWith) ? paysWith : 0)))} para completar el pago.`;
                        cashChangeSummary.classList.add('is-error');
                    } else {
                        cashChangeSummary.textContent = `Vuelto: ${formatMoney(Math.round((paysWith - total + Number.EPSILON) * 100) / 100)}`;
                        cashChangeSummary.classList.add('is-ready');
                    }
                }
            }
        } else if (method === 'tarjeta') {
            paymentValid = yapeModal?.dataset.cardEnabled === '1' && (cardChargeReady || total > 0);
        } else {
            paymentValid = (method === 'plin' ? plinConfigured : yapeConfigured) && receiptReady;
        }

        if (confirmYapeOrder) {
            confirmYapeOrder.disabled = shoppingList.length === 0 || !customerValid || !phoneValid || !zoneValid || !addressValid || !paymentValid;
        }
    };

    [cashExactButton, cashChangeButton].forEach((button) => button?.addEventListener('click', () => {
        cashMode = button.dataset.cashMode === 'vuelto' ? 'vuelto' : 'exacto';
        if (cashMode === 'exacto' && cashPaysWith) cashPaysWith.value = '';
        updateCheckoutState();
    }));

    [checkoutCustomer, checkoutPhone, checkoutAddress, cashPaysWith].forEach((field) => field?.addEventListener('input', updateCheckoutState));
    checkoutDeliveryZone?.addEventListener('change', () => {
        syncCheckoutShippingFields();
        refreshCheckoutQuote();
    });
    applyCoupon?.addEventListener('click', refreshCheckoutQuote);
    checkoutCoupon?.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); refreshCheckoutQuote(); } });
    checkoutPaymentMethod?.addEventListener('change', () => {
        const value = checkoutPaymentMethod.value;
        const invalidYape = value === 'yape' && (yapeModal?.dataset.enabled !== '1' || (!yapeModal.dataset.phone && yapeModal.dataset.hasQr !== '1'));
        const invalidPlin = value === 'plin' && (yapeModal?.dataset.plinEnabled !== '1' || (!yapeModal.dataset.plinPhone && yapeModal.dataset.plinHasQr !== '1'));
        const invalidCard = value === 'tarjeta' && yapeModal?.dataset.cardEnabled !== '1';
        if (invalidYape || invalidPlin || invalidCard) {
            checkoutPaymentMethod.value = 'efectivo';
            showYapeStatus('Ese metodo no esta configurado. Puedes completar el pedido en efectivo.', 'error');
        }
        optimizedYapeReceipt = null;
        if (yapeReceiptInput) yapeReceiptInput.value = '';
        if (yapeReceiptPreview) yapeReceiptPreview.hidden = true;
        cardChargeReady = false;
        updateCheckoutState();
    });

    if (window.DeviozCheckoutSteps && yapeModal) {
        checkoutFlow = window.DeviozCheckoutSteps.create(['review', 'delivery', 'payment', 'confirmation'], { onChange: syncCheckoutStepPanels });
        syncCheckoutStepPanels();
    }

    const validateCheckoutStep = (step) => {
        if (step === 'review') return shoppingList.length > 0;
        if (step === 'delivery') {
            const pickup = checkoutQuote?.district === 'Recojo en tienda';
            return Boolean(checkoutQuote) && (checkoutCustomer?.value.trim().length || 0) >= 3
                && (checkoutPhone?.value.replace(/\D/g, '').length || 0) >= 7
                && (pickup || (checkoutAddress?.value.trim().length || 0) >= 5);
        }
        if (step === 'payment') {
            updateCheckoutState();
            return confirmYapeOrder ? !confirmYapeOrder.disabled : true;
        }
        return true;
    };
    checkoutNextStep?.addEventListener('click', () => {
        if (!checkoutFlow) return;
        if (checkoutFlow.current() === 'review') refreshCheckoutQuote();
        if (!checkoutFlow.next(validateCheckoutStep)) showYapeStatus('Completa este paso antes de continuar.', 'error');
    });
    checkoutBackStep?.addEventListener('click', () => checkoutFlow?.back());

    culqiPayButton?.addEventListener('click', async () => {
        cardChargeReady = false;
        showYapeStatus('Para activar tarjeta coloca tus llaves de Culqi en el servidor. StockFlow no guarda tarjetas.', 'loading');
        updateCheckoutState();
    });

    yapeReceiptInput?.addEventListener('change', async () => {
        const file = yapeReceiptInput.files?.[0];
        optimizedYapeReceipt = null;
        if (!file || !yapeReceiptPreview) {
            updateCheckoutState();
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            yapeReceiptInput.value = '';
            updateCheckoutState();
            showYapeStatus('La imagen supera los 5 MB. Elige una captura más liviana.', 'error');
            return;
        }
        showYapeStatus('Preparando la captura para enviarla más rápido…', 'loading');
        try {
            optimizedYapeReceipt = await optimizeReceiptImage(file);
        } catch (error) {
            optimizedYapeReceipt = file;
        }
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            yapeReceiptPreview.src = String(reader.result || '');
            yapeReceiptPreview.hidden = false;
            if (yapeUploadPrompt) yapeUploadPrompt.hidden = true;
            if (confirmYapeOrder) confirmYapeOrder.textContent = 'Confirmar pedido';
            updateCheckoutState();
            showYapeStatus('Captura lista. Confirma para enviar tu pedido.', 'ready');
        });
        reader.readAsDataURL(optimizedYapeReceipt || file);
    });

    confirmYapeOrder?.addEventListener('click', async () => {
        const selectedMethod = checkoutPaymentMethod?.value || 'efectivo';
        const method = ['yape', 'plin', 'tarjeta'].includes(selectedMethod) ? selectedMethod : 'efectivo';
        const receipt = optimizedYapeReceipt || $('#yapeReceipt')?.files?.[0];
        const customer = checkoutCustomer?.value.trim() || '';
        const phone = checkoutPhone?.value.trim() || '';
        const total = Math.round((quoteTotal() + Number.EPSILON) * 100) / 100;
        const paysWith = method === 'efectivo' && cashMode === 'exacto'
            ? total
            : Number(cashPaysWith?.value || 0);

        if (method === 'efectivo' && (!Number.isFinite(paysWith) || paysWith + 0.0001 < total)) {
            showYapeStatus('El monto en efectivo debe ser igual o mayor al total.', 'error');
            updateCheckoutState();
            return;
        }
        if (['yape', 'plin'].includes(method) && !receipt) {
            showYapeStatus(`Primero sube la captura de tu pago con ${method === 'plin' ? 'Plin' : 'Yape'}.`, 'error');
            return;
        }
        if (['yape', 'plin'].includes(method) && receipt.size > 5 * 1024 * 1024) {
            showYapeStatus('La captura no puede superar los 5 MB.', 'error');
            return;
        }
        if (method === 'tarjeta' && yapeModal?.dataset.cardEnabled !== '1') {
            showYapeStatus('La tarjeta aun no esta configurada con Culqi.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('csrf_token', String($('#yapeCsrfToken')?.value || ''));
        formData.append('cliente', customer);
        formData.append('telefono', phone);
        formData.append('metodo_pago', method);
        formData.append('paga_con', method === 'efectivo' ? paysWith.toFixed(2) : '');
        formData.append('delivery_zone_id', checkoutDeliveryZone?.value || '');
        formData.append('delivery_address', checkoutAddress?.value.trim() || '');
        formData.append('delivery_reference', checkoutReference?.value.trim() || '');
        formData.append('coupon', checkoutCoupon?.value.trim() || '');
        formData.append('items', JSON.stringify(shoppingList.map((item) => ({
            kind: item.kind || 'product',
            producto_id: (item.kind || 'product') === 'product' ? item.id : undefined,
            id: item.id,
            cantidad: Number(item.quantity || 1),
            presentation: item.presentation || 'unidad'
        }))));
        if (['yape', 'plin'].includes(method)) formData.append('receipt', receipt);

        confirmYapeOrder.disabled = true;
        confirmYapeOrder.textContent = 'Enviando…';
        showYapeStatus('Estamos registrando tu pedido.', 'loading');
        let completed = false;
        try {
            const endpoint = method === 'tarjeta' ? 'api/culqi_charge.php' : 'api/yape_order.php';
            const response = await fetch(`${window.STOCKFLOW_BASE_URL || ''}/${endpoint}`, { method: 'POST', body: formData });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'No se pudo registrar el pedido.');
            completed = true;
            const paymentMessage = ['yape', 'plin'].includes(method)
                ? `Pedido ${result.order_code} recibido y pendiente de revisión.`
                : `Pedido ${result.order_code} registrado. Vuelto: ${formatMoney(result.vuelto || 0)}.`;
            showYapeStatus(`¡Listo! ${paymentMessage} ${result.dispatch_message || ''}`.trim(), 'success');
            $('#yapeOrderCode').textContent = result.order_code || '';
            $('#yapeTrackingPin').textContent = result.tracking_pin || '';
            const trackingLink = $('#yapeTrackingLink');
            if (trackingLink && result.tracking_url) trackingLink.href = result.tracking_url;
            if (yapeReceiptUpload) yapeReceiptUpload.hidden = true;
            if (confirmYapeOrder) confirmYapeOrder.hidden = true;
            if (yapeHelpText) yapeHelpText.hidden = true;
            if (yapeOrderAccess) yapeOrderAccess.hidden = false;
            shoppingList = [];
            cartIntentItems = [];
            cartReconciliationRevision += 1;
            saveShoppingList();
            renderShoppingList();
            if (yapeReceiptInput) yapeReceiptInput.value = '';
            optimizedYapeReceipt = null;
            if (yapeReceiptPreview) {
                yapeReceiptPreview.src = '';
                yapeReceiptPreview.hidden = true;
            }
            if (yapeUploadPrompt) yapeUploadPrompt.hidden = false;
        } catch (error) {
            showYapeStatus(error.message || 'No se pudo registrar el pedido. Inténtalo nuevamente.', 'error');
        } finally {
            if (!completed) {
                confirmYapeOrder.textContent = 'Confirmar pedido';
                updateCheckoutState();
            }
        }
    });

    $('#copyTrackingData')?.addEventListener('click', async () => {
        const code = $('#yapeOrderCode')?.textContent || '';
        const pin = $('#yapeTrackingPin')?.textContent || '';
        if (!code || !pin) return;
        const text = `Pedido: ${code}\nPIN: ${pin}`;
        try {
            await navigator.clipboard.writeText(text);
            $('#copyTrackingData').textContent = '✓ Datos copiados';
        } catch (error) {
            window.prompt('Copia tus datos de seguimiento:', text);
        }
    });

    finalizePendingAiConfirmation().then((completed) => { if (!completed) revalidateShoppingList(); });

    const comboDetailModal = $('#comboDetailModal');
    const comboDetailProducts = $('#comboDetailProducts');
    const comboDetailAdd = $('#comboDetailAdd');
    let selectedComboForDetail = null;

    document.addEventListener('click', (event) => {
        const detailButton = event.target.closest('[data-combo-details]');
        if (!detailButton || !comboDetailModal || !comboDetailProducts) return;
        try {
            const combo = JSON.parse(detailButton.dataset.comboDetails || '{}');
            selectedComboForDetail = combo;
            $('#comboDetailName').textContent = combo.name || 'Productos incluidos';
            $('#comboDetailDescription').textContent = combo.description || '';
            $('#comboDetailRegular').textContent = formatMoney(combo.regular_price);
            $('#comboDetailPrice').textContent = formatMoney(combo.price);
            $('#comboDetailSaving').textContent = `Ahorras ${formatMoney(combo.saving)}`;
            comboDetailProducts.innerHTML = (combo.products || []).map((product) => `
                <article class="combo-detail-product">
                    <div class="combo-detail-image">
                        ${product.image_url ? `<img src="${escapeHtml(product.image_url)}" alt="${escapeHtml(product.name)}" data-image-fallback><i class="media-fallback" hidden>▦</i>` : '<i class="media-fallback">▦</i>'}
                        <b>${Number(product.quantity)}×</b>
                    </div>
                    <div><strong>${escapeHtml(product.name)}</strong><small class="combo-description-label">Descripción breve</small><p>${escapeHtml(String(product.description || 'Producto incluido en este combo.').slice(0, 110))}</p><span>${Number(product.quantity)} unidad${Number(product.quantity) === 1 ? '' : 'es'} · ${formatMoney(product.price)} c/u</span></div>
                </article>`).join('') || '<p class="empty-mini">No hay productos configurados.</p>';
            comboDetailAdd.disabled = Number(combo.available || 0) <= 0;
            comboDetailAdd.textContent = comboDetailAdd.disabled ? 'Combo agotado' : `Agregar combo · ${formatMoney(combo.price)}`;
            modalOpen(comboDetailModal);
        } catch (error) {
            console.error('No se pudo mostrar el detalle del combo.', error);
        }
    });

    comboDetailAdd?.addEventListener('click', () => {
        if (!selectedComboForDetail || comboDetailAdd.disabled) return;
        const sourceButton = $$('[data-list-combo]').find((button) => {
            try { return Number(JSON.parse(button.dataset.listCombo || '{}').id) === Number(selectedComboForDetail.id); }
            catch (error) { return false; }
        });
        if (sourceButton) sourceButton.click();
        modalClose(comboDetailModal);
        window.setTimeout(openShoppingList, 220);
    });

    // Enlaces generados desde el centro de pagos del administrador.
    const purchaseParams = new URLSearchParams(window.location.search);
    const requestedProduct = Number(purchaseParams.get('buy_product') || 0);
    const requestedCombo = Number(purchaseParams.get('buy_combo') || 0);
    if (requestedProduct > 0 || requestedCombo > 0) {
        const requestedPresentation = purchaseParams.get('presentation') === 'paquete' ? 'paquete' : 'unidad';
        const requestedQuantity = Math.max(1, Math.min(99, Number(purchaseParams.get('quantity') || 1)));
        const catalogButton = $$('[data-list-product], [data-list-combo]').find((button) => {
            try {
                const data = JSON.parse(button.dataset.listProduct || button.dataset.listCombo || '{}');
                return requestedCombo > 0 ? data.kind === 'combo' && Number(data.id) === requestedCombo : data.kind !== 'combo' && Number(data.id) === requestedProduct;
            }
            catch (error) { return false; }
        });
        if (catalogButton) {
            try {
                const product = JSON.parse(catalogButton.dataset.listProduct || catalogButton.dataset.listCombo || '{}');
                (async () => {
                    let added = false;
                    for (let index = 0; index < requestedQuantity; index += 1) {
                        added = await addToShoppingList(product, null, requestedPresentation) || added;
                    }
                    if (added) openShoppingList(catalogButton);
                })();
                window.history.replaceState({}, '', `${window.location.pathname}${window.location.hash || ''}`);
            } catch (error) { console.error('No se pudo abrir el cobro generado.', error); }
        }
    }

    const paymentGenerator = $('[data-payment-generator]');
    if (paymentGenerator) {
        const productSelect = $('#paymentProduct');
        const presentationSelect = $('#paymentPresentation');
        const quantityInput = $('#paymentQuantity');
        const totalOutput = $('#paymentGeneratedTotal');
        const descriptionOutput = $('#paymentDescription');
        const availabilityOutput = $('#paymentAvailability');
        const linkOutput = $('#paymentGeneratedLink');
        const copyButton = $('#copyPaymentLink');
        const copyMessageButton = $('#copyPaymentMessage');
        const previewLink = $('#previewPaymentLink');
        let generatedMessage = '';
        const updatePaymentGenerator = () => {
            const option = productSelect?.selectedOptions?.[0];
            if (!option?.value) {
                if (linkOutput) linkOutput.value = '';
                if (copyButton) copyButton.disabled = true;
                if (copyMessageButton) copyMessageButton.disabled = true;
                if (previewLink) { previewLink.href = '#'; previewLink.setAttribute('aria-disabled', 'true'); }
                return;
            }
            const kind = option.dataset.kind === 'combo' ? 'combo' : 'product';
            const packSize = Math.max(1, Number(option.dataset.packSize || 1));
            const stock = Math.max(0, Number(option.dataset.stock || 0));
            const hasPack = kind === 'product' && Number(option.dataset.packPrice || 0) > 0 && packSize > 1;
            if (presentationSelect) {
                presentationSelect.options[1].disabled = !hasPack;
                if (!hasPack && presentationSelect.value === 'paquete') presentationSelect.value = 'unidad';
            }
            const presentation = presentationSelect?.value === 'paquete' && hasPack ? 'paquete' : 'unidad';
            const available = presentation === 'paquete' ? Math.floor(stock / packSize) : stock;
            const quantity = Math.max(1, Math.min(available || 1, Number(quantityInput?.value || 1)));
            if (quantityInput) { quantityInput.value = String(quantity); quantityInput.max = String(Math.max(1, available)); }
            const price = presentation === 'paquete' ? Number(option.dataset.packPrice || 0) : Number(option.dataset.price || 0);
            if (descriptionOutput) descriptionOutput.textContent = `${quantity} × ${option.dataset.name} · ${presentation === 'paquete' ? `Pack de ${packSize}` : 'Unidad'}`;
            if (availabilityOutput) availabilityOutput.textContent = `${available} ${presentation === 'paquete' ? 'packs' : 'unidades'} disponibles`;
            if (totalOutput) totalOutput.textContent = formatMoney(price * quantity);
            const link = new URL(paymentGenerator.dataset.catalogUrl || window.location.href, window.location.origin);
            link.searchParams.set(kind === 'combo' ? 'buy_combo' : 'buy_product', option.dataset.id || '');
            link.searchParams.set('presentation', presentation);
            link.searchParams.set('quantity', String(quantity));
            if (linkOutput) linkOutput.value = link.toString();
            if (copyButton) copyButton.disabled = available <= 0;
            generatedMessage = `Hola, puedes completar tu compra de ${quantity} × ${option.dataset.name} por ${formatMoney(price * quantity)} aquí: ${link}`;
            if (copyMessageButton) copyMessageButton.disabled = available <= 0;
            if (previewLink) { previewLink.href = link.toString(); previewLink.setAttribute('aria-disabled', available <= 0 ? 'true' : 'false'); }
        };
        productSelect?.addEventListener('change', updatePaymentGenerator);
        presentationSelect?.addEventListener('change', updatePaymentGenerator);
        quantityInput?.addEventListener('input', updatePaymentGenerator);
        copyButton?.addEventListener('click', async () => {
            if (!linkOutput?.value) return;
            try { await navigator.clipboard.writeText(linkOutput.value); copyButton.textContent = '✓ Copiado'; }
            catch (error) { linkOutput.select(); document.execCommand('copy'); copyButton.textContent = '✓ Copiado'; }
            window.setTimeout(() => { copyButton.textContent = 'Copiar enlace'; }, 1300);
        });
        copyMessageButton?.addEventListener('click', async () => {
            if (!generatedMessage) return;
            try { await navigator.clipboard.writeText(generatedMessage); copyMessageButton.textContent = '✓ Mensaje copiado'; }
            catch (error) { window.prompt('Copia este mensaje:', generatedMessage); }
            window.setTimeout(() => { copyMessageButton.textContent = 'Copiar mensaje para WhatsApp'; }, 1500);
        });
        updatePaymentGenerator();
    }

    const movementForm = $('[data-movement-form]');
    if (movementForm) {
        const productSelect = $('#movementProduct');
        const typeSelect = $('#movementType');
        const presentationSelect = $('#movementPresentation');
        const quantityInput = $('input[name="quantity"]', movementForm);
        const costInput = $('#movementCost');
        const saleInput = $('#movementSalePrice');
        const liveSummary = $('#movementLiveSummary');

        const updateMovement = (fillPrices = false) => {
            const option = productSelect?.selectedOptions[0];
            if (!option || !option.value) {
                liveSummary.textContent = 'Selecciona producto, tipo y cantidad para ver el cálculo.';
                return;
            }
            const stock = Number(option.dataset.stock) || 0;
            const unitCost = Number(option.dataset.cost) || 0;
            const unitPrice = Number(option.dataset.price) || 0;
            const packSize = Math.max(1, Number(option.dataset.packSize) || 1);
            const packPrice = Number(option.dataset.packPrice) || 0;
            const packOption = presentationSelect?.querySelector('option[value="paquete"]');
            if (packOption) packOption.disabled = packSize <= 1 || packPrice <= 0;
            if (presentationSelect?.value === 'paquete' && packOption?.disabled) presentationSelect.value = 'unidad';

            const isPack = presentationSelect?.value === 'paquete';
            const multiplier = isPack ? packSize : 1;
            const quantity = Math.max(1, Number(quantityInput?.value) || 1);
            const units = quantity * multiplier;
            const type = typeSelect?.value || 'entrada';
            const incoming = type === 'entrada' || type === 'ajuste_entrada';
            const purchase = type === 'entrada';
            const sale = type === 'salida';
            costInput.readOnly = !purchase;
            saleInput.readOnly = !sale;
            if (fillPrices) {
                if (purchase) costInput.value = (unitCost * multiplier).toFixed(2);
                if (sale) saleInput.value = (isPack ? packPrice : unitPrice).toFixed(2);
            }
            const resultStock = stock + (incoming ? units : -units);
            const expense = purchase ? (Number(costInput.value) || 0) * quantity : sale ? unitCost * units : 0;
            const income = sale ? (Number(saleInput.value) || 0) * quantity : 0;
            const profit = sale ? income - expense : 0;
            liveSummary.innerHTML = `<span>Stock: <strong>${stock} → ${resultStock}</strong> unidades</span><span>Gasto: <strong>${formatMoney(expense)}</strong></span><span>Ingreso: <strong>${formatMoney(income)}</strong></span><span>Ganancia: <strong>${formatMoney(profit)}</strong></span>`;
            liveSummary.classList.toggle('has-error', resultStock < 0);
        };

        productSelect?.addEventListener('change', () => updateMovement(true));
        typeSelect?.addEventListener('change', () => updateMovement(true));
        presentationSelect?.addEventListener('change', () => updateMovement(true));
        quantityInput?.addEventListener('input', () => updateMovement(false));
        costInput?.addEventListener('input', () => updateMovement(false));
        saleInput?.addEventListener('input', () => updateMovement(false));
        updateMovement(false);
    }

    const comboForm = $('[data-combo-form]');
    const comboStockInput = $('#comboStock');
    const comboPriceInput = $('#comboPrice', comboForm);
    const updateComboSummary = () => {
        if (!comboForm) return;
        const rows = $$('#comboProductPicker .picker-product', comboForm);
        const hasConfiguredStock = comboStockInput && comboStockInput.value.trim() !== '';
        const configuredStock = hasConfiguredStock ? Math.max(0, Math.floor(Number(comboStockInput.value) || 0)) : null;
        let units = 0;
        let regular = 0;
        let productCapacity = Infinity;
        let limitingProduct = '';
        rows.forEach((row) => {
            const quantity = Math.max(0, Number($('.picker-quantity', row)?.value) || 0);
            if (quantity <= 0) return;
            units += quantity;
            regular += quantity * (Number($('.picker-sale-price', row)?.value || row.dataset.price) || 0);
            const rowAvailable = Math.floor((Number(row.dataset.stock) || 0) / quantity);
            if (rowAvailable < productCapacity) {
                productCapacity = rowAvailable;
                limitingProduct = row.dataset.productName || 'un producto';
            }
        });
        productCapacity = Number.isFinite(productCapacity) ? Math.max(0, productCapacity) : 0;
        if (comboPriceInput && units >= 2 && regular > 0 && (comboPriceInput.value.trim() === '' || comboPriceInput.dataset.autoSuggested === '1')) {
            const suggestedCents = Math.min(Math.round(regular * 90), Math.round(regular * 100) - 1);
            if (suggestedCents > 0) {
                comboPriceInput.value = (suggestedCents / 100).toFixed(2);
                comboPriceInput.dataset.autoSuggested = '1';
            }
        }
        const price = Number(comboPriceInput?.value) || 0;
        const available = configuredStock === null ? productCapacity : Math.min(configuredStock, productCapacity);
        const saving = Math.max(0, regular - price);
        $('#comboLiveUnits').textContent = String(units);
        const selectedHeading = $('#comboSelectedHeading');
        const selectedCount = rows.filter((row) => Number($('.picker-quantity', row)?.value) > 0).length;
        if (selectedHeading) selectedHeading.textContent = `Productos agregados al combo (${selectedCount})`;
        const emptyHint = $('#comboPickerEmpty');
        if (emptyHint) emptyHint.hidden = selectedCount > 0;
        $('#comboLiveProductCapacity').textContent = String(productCapacity);
        $('#comboLiveConfiguredStock').textContent = configuredStock === null ? 'Automático' : String(configuredStock);
        $('#comboLiveAvailable').textContent = String(available);
        $('#comboLiveRegular').textContent = formatMoney(regular);
        $('#comboLiveSaving').textContent = formatMoney(saving);
        const message = $('#comboLiveMessage');
        let text = 'El combo está listo para guardar.';
        let hasError = false;
        if (units < 2) {
            text = 'Selecciona al menos dos productos o dos unidades.';
            hasError = true;
        } else if (rows.some((row) => Number($('.picker-quantity', row)?.value) > 0 && Number($('.picker-sale-price', row)?.value || row.dataset.price) <= 0)) {
            text = 'Indica el precio de venta de los productos que aún no lo tienen.';
            hasError = true;
        } else if (price <= 0) {
            text = 'Ingresa el precio especial del combo.';
            hasError = true;
        } else if (price >= regular) {
            text = 'El precio especial debe ser menor al precio normal.';
            hasError = true;
        } else if (productCapacity <= 0) {
            text = `${limitingProduct} no tiene stock suficiente para armar este combo. Reduce la cantidad o repón stock.`;
        } else if (configuredStock === 0) {
            text = 'El stock del combo está en 0. Usa el botón ＋ o calcula según productos.';
        } else if (configuredStock !== null && configuredStock > productCapacity) {
            text = `Configuraste ${configuredStock}, pero los productos solo permiten vender ${productCapacity} combo${productCapacity === 1 ? '' : 's'}.`;
        }
        message.textContent = text;
        message.classList.toggle('has-error', hasError);
    };

    $$('[data-combo-check]').forEach((checkbox) => {
        const row = checkbox.closest('.picker-product');
        const quantity = $('.picker-quantity', row);
        const syncFromCheck = () => {
            if (!quantity) return;
            if (checkbox.checked && Number(quantity.value) <= 0) quantity.value = '1';
            if (!checkbox.checked) quantity.value = '0';
            row.classList.toggle('is-selected', checkbox.checked);
        };
        checkbox.addEventListener('change', syncFromCheck);
        quantity?.addEventListener('input', () => {
            checkbox.checked = Number(quantity.value) > 0;
            row.classList.toggle('is-selected', checkbox.checked);
        });
        syncFromCheck();
    });
    comboForm?.addEventListener('input', (event) => {
        if (event.target === comboPriceInput) delete comboPriceInput.dataset.autoSuggested;
        updateComboSummary();
    });
    comboForm?.addEventListener('change', updateComboSummary);
    comboForm?.addEventListener('change', (event) => {
        const checkbox = event.target.closest?.('[data-combo-check]');
        if (!checkbox) return;
        const row = checkbox.closest('.picker-product');
        const quantity = $('.picker-quantity', row);
        if (!row || !quantity) return;
        if (checkbox.checked && Number(quantity.value) <= 0) quantity.value = '1';
        if (!checkbox.checked) quantity.value = '0';
        row.classList.toggle('is-selected', checkbox.checked);
        updateComboSummary();
    });
    comboForm?.addEventListener('input', (event) => {
        const quantity = event.target.closest?.('.picker-quantity');
        if (!quantity) return;
        const checkbox = quantity.closest('.picker-product')?.querySelector('[data-combo-check]');
        if (checkbox) checkbox.checked = Number(quantity.value) > 0;
        quantity.closest('.picker-product')?.classList.toggle('is-selected', Number(quantity.value) > 0);
        updateComboSummary();
    });
    $$('[data-combo-stock-step]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!comboStockInput) return;
            const step = Number(button.dataset.comboStockStep) || 0;
            const current = comboStockInput.value.trim() === '' ? 0 : Math.max(0, Math.floor(Number(comboStockInput.value) || 0));
            comboStockInput.value = String(Math.max(0, current + step));
            updateComboSummary();
        });
    });
    $('#comboStockAuto')?.addEventListener('click', () => {
        if (!comboStockInput) return;
        comboStockInput.value = '';
        updateComboSummary();
    });
    updateComboSummary();

    const mobileFilterButton = $('[data-mobile-filters]');
    const mobileFilterPanel = $('[data-filter-panel]');
    mobileFilterButton?.addEventListener('click', () => {
        const open = mobileFilterPanel?.classList.toggle('is-open') || false;
        mobileFilterButton.setAttribute('aria-expanded', String(open));
    });

    $$('[data-confirm-convert]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('¿Agregar este producto externo al inventario Devioz?')) event.preventDefault();
        });
    });

    const smartStoreSearch = $('#smartStoreSearch');
    const smartStoreSearchForm = smartStoreSearch?.closest('form');
    const budgetIntentPattern = /\b(?:presupuesto|por\s+s\/?|con\s+s\/?|hasta\s+s\/?|máximo|maximo|menos\s+de)\b/i;
    const runSmartStoreSearch = () => {
        if (!smartStoreSearch) return;
        const query = smartStoreSearch.value.trim();
        if (query === '') {
            smartStoreSearch.focus();
            return;
        }
        if (budgetIntentPattern.test(query) && aiShopPanel && aiShopTrigger) {
            const assistantQuery = $('textarea[name="query"]', aiShopPanel);
            const assistantBudget = $('input[name="budget"]', aiShopPanel);
            if (assistantQuery) assistantQuery.value = query;
            const amount = query.match(/(?:s\/?\s*)?(\d+(?:[.,]\d{1,2})?)/i)?.[1];
            if (assistantBudget && amount) assistantBudget.value = amount.replace(',', '.');
            toggleAiShop(true, smartStoreSearch);
            return;
        }
        window.location.assign(`${baseUrl}/catalogo_completo.php?q=${encodeURIComponent(query)}`);
    };
    smartStoreSearchForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        runSmartStoreSearch();
    });

    const aiShopTrigger = $('#aiShopTrigger');
    const aiShopPanel = $('#aiShopPanel');
    const aiShopResult = $('#aiShopResult');
    const toggleAiShop = (open, trigger = document.activeElement) => {
        if (!aiShopPanel || !aiShopTrigger) return;
        if (open && aiShopPanel.hidden) registerOverlay(aiShopPanel, () => toggleAiShop(false), trigger);
        aiShopPanel.hidden = !open;
        aiShopTrigger.setAttribute('aria-expanded', String(open));
        if (open) requestAnimationFrame(() => $('textarea, input, button', aiShopPanel)?.focus());
        else unregisterOverlay(aiShopPanel);
    };
    aiShopTrigger?.addEventListener('click',()=>toggleAiShop(aiShopPanel?.hidden, aiShopTrigger));
    $('#aiShopClose')?.addEventListener('click',()=>toggleAiShop(false));
    $$('[data-open-assistant]').forEach((button) => button.addEventListener('click', () => toggleAiShop(true, button)));
    $('#aiShopForm')?.addEventListener('submit',async(event)=>{
        event.preventDefault();
        const form=event.currentTarget; const button=$('button[type="submit"]',form);
        button.disabled=true; aiShopResult.innerHTML='<p class="ai-shop-note">Buscando opciones reales del inventario…</p>';
        try{
            const data=Object.fromEntries(new FormData(form));
            const response=await fetch(`${baseUrl}/api/recomendaciones.php`,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(data)});
            const result=await response.json();
            if(!response.ok||!result.success) throw new Error(result.message||'No se pudo preparar la propuesta.');
            if(!result.items.length){aiShopResult.innerHTML='<p class="ai-shop-note">No encontré productos disponibles para esa solicitud.</p>';return;}
            const rows=result.items.map((item)=>`<article><div><strong>${escapeHtml(item.name)}</strong><small>${item.quantity} × ${formatMoney(item.price)}</small></div><b>${formatMoney(item.subtotal)}</b></article>`).join('');
            aiShopResult.innerHTML=`${rows}<div class="ai-shop-total"><span>Total</span><strong>${formatMoney(result.total)}</strong></div><p class="ai-shop-note">${result.provider==='ollama'?'Propuesta ordenada por Ollama.':'Ollama no respondió; se usó el respaldo automático.'}</p><button class="btn btn-primary" type="button" id="confirmAiProposal">Confirmar y agregar</button>`;
            $('#confirmAiProposal')?.addEventListener('click',async(confirmEvent)=>{
                if(!window.confirm('¿Confirmas agregar esta propuesta a Mi lista?'))return;
                const confirmButton=confirmEvent.currentTarget;confirmButton.disabled=true;
                try{
                    await addRecommendationProposal(result);
                    confirmButton.textContent='Agregado al carrito';
                }catch(error){confirmButton.disabled=false;aiShopResult.insertAdjacentHTML('beforeend',`<p class="ai-shop-note">${escapeHtml(error.message)}</p>`);}
            },{once:true});
        }catch(error){aiShopResult.innerHTML=`<p class="ai-shop-note">${escapeHtml(error.message)}</p>`;}finally{button.disabled=false;}
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && closeTopmostOverlay()) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        if (productModal?.classList.contains('is-open') && event.key === 'ArrowLeft') moveProductGallery(-1);
        if (productModal?.classList.contains('is-open') && event.key === 'ArrowRight') moveProductGallery(1);
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && searchInput) {
            event.preventDefault();
            searchInput.focus();
        }
    });
})();
