(function (window) {
    'use strict';

    function createCheckoutSteps(names, options) {
        var index = 0;
        var onChange = options && typeof options.onChange === 'function' ? options.onChange : function () {};
        function emit() {
            onChange({ step: names[index], index: index });
            if (typeof window.CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('checkout:stepchange', { detail: { step: names[index], index: index } }));
            }
        }
        return {
            current: function () { return names[index]; },
            index: function () { return index; },
            go: function (target) {
                var next = typeof target === 'number' ? target : names.indexOf(target);
                if (next < 0 || next >= names.length) return false;
                index = next;
                emit();
                return true;
            },
            next: function (validate) {
                if (typeof validate === 'function' && !validate(names[index], index)) return false;
                if (index >= names.length - 1) return false;
                index += 1;
                emit();
                return true;
            },
            back: function () {
                if (index <= 0) return false;
                index -= 1;
                emit();
                return true;
            },
            restart: function () {
                index = 0;
                emit();
                return true;
            }
        };
    }

    window.DeviozCheckoutSteps = { create: createCheckoutSteps };
})(window);
