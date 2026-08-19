document.addEventListener('DOMContentLoaded', function () {
    // Password visibility toggle handler
    const passwordInputs = document.querySelectorAll('input[type="password"]');
    const toggleButtons = document.querySelectorAll('.of-password-toggle');

    toggleButtons.forEach(button => {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target') || 'password';
            const input = document.getElementById(targetId);
            if (!input) return;

            const isPassword = input.getAttribute('type') === 'password';
            const newType = isPassword ? 'text' : 'password';
            input.setAttribute('type', newType);

            // Also toggle confirm password if present and marked
            const confirmInput = document.getElementById('password_confirmation');
            if (this.getAttribute('data-target-both') === 'true' && confirmInput) {
                confirmInput.setAttribute('type', newType);
            }

            const showIcon = this.querySelector('.of-pw-show');
            const hideIcon = this.querySelector('.of-pw-hide');

            if (showIcon) showIcon.style.display = isPassword ? 'none' : 'block';
            if (hideIcon) hideIcon.style.display = isPassword ? 'block' : 'none';

            this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
    });
});
