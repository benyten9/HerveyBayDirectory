const ajaxUrl = window['doublescale_public_config'].ajaxUrl;

const form = document.querySelector('form#doublescale-unsubscribe-form');
if (form) {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        const formWrapper = form.parentElement;
        try {
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });
            const data = await response.json();
            const message = data.data.message;

            formWrapper.innerHTML = `<p>${message}</p>`;
        } catch (error) {
            formWrapper.innerHTML = `<p>Something went wrong. Please try again later.</p>`;
        }

    });
}