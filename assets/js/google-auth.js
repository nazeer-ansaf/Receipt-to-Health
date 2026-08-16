(function () {
    window.handleGoogleCredential = function (response) {
        const credential = response?.credential || '';
        const form = document.querySelector('#google-auth-form');
        const field = document.querySelector('#google-credential');

        if (!credential || !form || !field) return;

        field.value = credential;
        form.submit();
    };
})();
