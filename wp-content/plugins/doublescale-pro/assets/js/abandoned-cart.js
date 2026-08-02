class CheckoutFormHandler {
	constructor() {
		this.abandonedCart =
			window?.doublescale_abandoned_cart ||
			window?.ds_abandoned_cart ||
			{};
		this.checkoutForm = null;
		this.fieldsValues = {};
		this.previousValues = {};
		this.saveTimeout = null;
		this.debounceDelay = 800;
		this.isLoading = false;
		this.retryCount = 0;
		this.maxRetries = 10;

		this.checkoutFields = {
			billing_email: ['#billing_email', '#email', 'input[name="billing_email"]', 'input[name="email"]', 'input[type="email"]'],
			billing_first_name: ['#billing_first_name', '#billing-first_name', 'input[name="billing_first_name"]'],
			billing_last_name: ['#billing_last_name', '#billing-last_name', 'input[name="billing_last_name"]'],
			billing_phone: [
				'#billing_phone',
				'#billing-phone',
				'input[name="billing_phone"]',
				'input[name="billing-phone"]',
				'.wc-block-components-address-form__billing .wc-block-components-phone-number input',
				'.wc-block-components-address-form--billing .wc-block-components-phone-number input',
				'.wc-block-components-address-form__billing input[type="tel"]',
			],
			billing_country: ['#billing_country', '#billing-country', 'select[name="billing_country"]'],
			billing_address_1: ['#billing_address_1', '#billing-address_1', 'input[name="billing_address_1"]'],
			billing_address_2: ['#billing_address_2', '#billing-address_2', 'input[name="billing_address_2"]'],
			billing_city: ['#billing_city', '#billing-city', 'input[name="billing_city"]'],
			billing_state: ['#billing_state', '#billing-state', 'select[name="billing_state"]'],
			billing_postcode: ['#billing_postcode', '#billing-postcode', 'input[name="billing_postcode"]'],
			shipping_first_name: ['#shipping_first_name', '#shipping-first_name', 'input[name="shipping_first_name"]'],
			shipping_last_name: ['#shipping_last_name', '#shipping-last_name', 'input[name="shipping_last_name"]'],
			shipping_phone: [
				'#shipping_phone',
				'#shipping-phone',
				'input[name="shipping_phone"]',
				'input[name="shipping-phone"]',
				'.wc-block-components-address-form__shipping .wc-block-components-phone-number input',
				'.wc-block-components-address-form--shipping .wc-block-components-phone-number input',
				'.wc-block-components-address-form__shipping input[type="tel"]',
			],
			shipping_country: ['#shipping_country', '#shipping-country', 'select[name="shipping_country"]'],
			shipping_address_1: ['#shipping_address_1', '#shipping-address_1', 'input[name="shipping_address_1"]'],
			shipping_address_2: ['#shipping_address_2', '#shipping-address_2', 'input[name="shipping_address_2"]'],
			shipping_city: ['#shipping_city', '#shipping-city', 'input[name="shipping_city"]'],
			shipping_state: ['#shipping_state', '#shipping-state', 'select[name="shipping_state"]'],
			shipping_postcode: ['#shipping_postcode', '#shipping-postcode', 'input[name="shipping_postcode"]'],
		};

		this.init();
	}

	init() {
		this.checkoutForm = this.findCheckoutForm();
		if (this.checkoutForm) {
			this.attachEventListeners();
			this.attachOptOutHandler();
			this.maybeAddGDPRConsent();
		} else if (this.retryCount < this.maxRetries) {
			this.retryCount++;
			setTimeout(() => this.init(), 500);
		} else {
			console.error('Checkout form not found after ' + this.maxRetries + ' retries');
		}
	}

	findCheckoutForm() {
		const form =
			document.querySelector('form.checkout') ||
			document.querySelector('form.wc-block-checkout__form') ||
			document.querySelector('.woocommerce-checkout form') ||
			document.querySelector('form[name="checkout"]');
		this.isBlockCheckout = form?.classList.contains('wc-block-checkout__form') || false;
		return form;
	}

	maybeAddGDPRConsent() {
		if (!this.abandonedCart.gdpr_compliance) return;
		if (
			document.querySelector('.doublescale-gdpr-message') ||
			document.querySelector('.ds-gdpr-message')
		) {
			return;
		}

		const gdprMessageEl = document.createElement('div');
		gdprMessageEl.className = 'doublescale-gdpr-message';
		gdprMessageEl.style.marginTop = '10px';
		gdprMessageEl.style.fontSize = 'small';
		gdprMessageEl.innerHTML = `<p>${this.abandonedCart.gdpr_message}</p>`;

		const emailField = document.querySelector('#billing_email_field, .wc-block-components-address-form__email');
		if (emailField) emailField.insertAdjacentElement('afterend', gdprMessageEl);
	}

	attachOptOutHandler() {
		document.addEventListener('click', (e) => {
			const el = e.target.closest(
				'a.doublescale-abandoned-cart-opt-out, a#doublescale-opt-out, a#ds-opt-out'
			);
			if (!el) return;
			e.preventDefault();
			const { ajax_url, nonce } = this.abandonedCart;
			if (!ajax_url || !nonce) return;
			fetch(ajax_url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({
					action: 'doublescale_opt_out_abandoned_cart',
					nonce,
				}),
			})
				.then((res) => res.json())
				.then((data) => {
					if (data.success && data.data?.message) {
						window.alert(data.data.message);
					}
				})
				.catch(() => {});
		});
	}

	attachEventListeners() {
		const attach = () => {
			Object.keys(this.checkoutFields).forEach((key) => {
				for (const selector of this.checkoutFields[key]) {
					const input = document.querySelector(selector);
					if (input && !input.dataset.doublescaleAbandonedCartListener) {
						input.dataset.doublescaleAbandonedCartListener = 'true';
						input.dataset.doublescaleAbandonedCartFieldKey = key;
						const eventType =
							input.type === 'checkbox' || input.type === 'radio' || input.tagName === 'SELECT'
								? 'change'
								: 'input';
						input.addEventListener(eventType, this.debouncedChangeHandler.bind(this));
						if (eventType === 'input') input.addEventListener('blur', this.debouncedChangeHandler.bind(this));
						break;
					}
				}
			});
		};

		attach();

		if (this.isBlockCheckout && this.checkoutForm) {
			const observer = new MutationObserver(attach);
			observer.observe(this.checkoutForm, { childList: true, subtree: true });
		}
	}

	debouncedChangeHandler(e) {
		const input = e.target;
		const key = input.dataset.doublescaleAbandonedCartFieldKey;
		const value = input.value;

		this.fieldsValues[key] = value;

		if (this.previousValues[key] === value) return;

		clearTimeout(this.saveTimeout);
		this.saveTimeout = setTimeout(() => this.saveAbandonedCart(), this.debounceDelay);
	}

	isEmailFilled() {
		return this.fieldsValues.billing_email && this.fieldsValues.billing_email.trim() !== '';
	}

	collectCheckoutFields() {
		Object.keys(this.checkoutFields).forEach((key) => {
			for (const selector of this.checkoutFields[key]) {
				const input = document.querySelector(selector);
				if (input && 'value' in input) {
					const value = String(input.value || '').trim();
					if (value !== '') {
						this.fieldsValues[key] = input.value;
					}
					break;
				}
			}
		});
	}

	saveAbandonedCart() {
		this.collectCheckoutFields();
		if (!this.isEmailFilled() || this.isLoading) return;

		this.isLoading = true;

		const { ajax_url, nonce } = this.abandonedCart;
		fetch(ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'doublescale_save_abandoned_cart',
				nonce,
				fields: JSON.stringify(this.fieldsValues),
			}),
		})
			.then((res) => res.json())
			.then((data) => {
				console.log('Abandoned cart saved:', data);
				this.previousValues = { ...this.fieldsValues };
				this.isLoading = false;
			})
			.catch((err) => {
				console.error('Error saving abandoned cart:', err);
				this.isLoading = false;
			});
	}
}

window.addEventListener('DOMContentLoaded', () => new CheckoutFormHandler());
