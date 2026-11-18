(function ($) {
    'use strict';

    $(function () {
        var destination = document.querySelector('[data-dg-order-tracking-slot]');
        var orderData = document.getElementById('woocommerce-order-data');
        if (!destination || !orderData) {
            return;
        }

        function moveTrackingControls() {
            var controls = orderData.querySelector('.digits-tracking-wrapper');
            if (!controls) {
                return false;
            }

            var section = controls.parentElement;
            destination.appendChild(controls);
            // Digits renders a standalone heading above its controls inside order data.
            if (section !== orderData && section.children.length === 1 && section.firstElementChild.matches('h2')) {
                section.remove();
            }
            return true;
        }

        if (moveTrackingControls()) {
            return;
        }

        // Digits can insert its controls after the editor's initial ready event.
        var observer = new MutationObserver(function () {
            if (moveTrackingControls()) {
                observer.disconnect();
            }
        });
        observer.observe(orderData, {childList: true, subtree: true});
        window.setTimeout(function () {
            observer.disconnect();
        }, 10000);
    });
})(jQuery);
