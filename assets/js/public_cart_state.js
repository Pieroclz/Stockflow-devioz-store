(() => {
    'use strict';

    const allowedTypes = new Set(['product', 'product_pack', 'combo']);
    const positiveInteger = (value, maximum = Number.MAX_SAFE_INTEGER) => Number.isInteger(value) && value >= 1 && value <= maximum;

    const cartRequestItems = (items) => {
        if (!Array.isArray(items)) return [];
        const normalized = new Map();
        items.slice(0, 40).forEach((item) => {
            if (!item || typeof item !== 'object') return;
            const type = item.type || (item.kind === 'combo' ? 'combo' : item.presentation === 'paquete' ? 'product_pack' : 'product');
            const id = Number(item.id);
            const quantity = Number(item.quantity);
            if (!allowedTypes.has(type) || !positiveInteger(id) || !positiveInteger(quantity, 99)) return;
            const key = `${type}:${id}`;
            const current = normalized.get(key);
            const nextQuantity = (current?.quantity || 0) + quantity;
            if (nextQuantity > 99) {
                normalized.delete(key);
                return;
            }
            normalized.set(key, { type, id, quantity: nextQuantity });
        });
        return [...normalized.values()];
    };

    const canonicalItem = (item) => {
        if (!item || typeof item !== 'object') return null;
        const type = item.type;
        const id = Number(item.id);
        const quantity = Number(item.quantity);
        const price = Number(item.price);
        const stock = Number(item.stock);
        if (!allowedTypes.has(type) || !positiveInteger(id) || !positiveInteger(quantity, 99)
            || !Number.isFinite(price) || price <= 0 || !Number.isInteger(stock) || stock < quantity
            || item.restricted !== false) return null;
        return {
            type,
            kind: type === 'combo' ? 'combo' : 'product',
            id,
            quantity,
            code: String(item.code || ''),
            name: String(item.name || ''),
            category: String(item.category || ''),
            description: String(item.description || ''),
            price,
            stock,
            image_url: String(item.image_url || ''),
            icon: String(item.icon || ''),
            presentation: String(item.presentation || 'unidad'),
            presentation_label: String(item.presentation_label || 'Unidad'),
            units_per_item: Math.max(1, Number(item.units_per_item) || 1),
            restricted: false,
        };
    };

    const reconcileVerifiedCart = (response) => {
        if (!response || response.success !== true || !Array.isArray(response.items)) return [];
        return response.items.map(canonicalItem).filter(Boolean);
    };

    const api = { cartRequestItems, reconcileVerifiedCart };
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (typeof window !== 'undefined') window.DeviozPublicCartState = api;
})();
