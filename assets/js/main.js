/**
 * FSUU Library Booking System — Main JavaScript
 */

'use strict';

/* ---------- Auto-dismiss alerts after 5 s ---------- */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.alert.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });
});

/* ---------- Confirm before destructive actions ---------- */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            const message = this.dataset.confirm || 'Are you sure?';
            if (!window.confirm(message)) {
                e.preventDefault();
            }
        });
    });
});

/* ---------- Booking: enforce end-time > start-time ---------- */
const startTimeInput = document.getElementById('start_time');
const endTimeInput   = document.getElementById('end_time');

if (startTimeInput && endTimeInput) {
    startTimeInput.addEventListener('change', () => {
        endTimeInput.min = startTimeInput.value;
        if (endTimeInput.value && endTimeInput.value <= startTimeInput.value) {
            endTimeInput.value = '';
        }
    });
}

/* ---------- Booking: prevent past dates ---------- */
const bookingDateInput = document.getElementById('booking_date');
if (bookingDateInput) {
    const today = new Date().toISOString().split('T')[0];
    bookingDateInput.min = today;
}
