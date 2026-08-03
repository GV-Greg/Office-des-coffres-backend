import './bootstrap';

import Alpine from 'alpinejs';
import '@fortawesome/fontawesome-free/js/all.min';

window.Alpine = Alpine;

Alpine.start();

window.confirmDangerousAction = function (button, message, confirmButtonColor = '#16a34a') {
    Swal.fire({
        title: message,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: button.dataset.confirmText || 'OK',
        cancelButtonText: button.dataset.cancelText || 'Annuler',
        confirmButtonColor,
        cancelButtonColor: '#6b7280',
    }).then((result) => {
        if (result.isConfirmed) {
            button.closest('form').submit();
        }
    });
};
