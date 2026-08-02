/**
 * DoubleScale Website Tracking
 *
 * Tracks page visits and stores them for known/anonymous contacts.
 *
 * @since 1.0.0
 */
(function () {
    'use strict';

    const config = window.doublescale_tracking_config || {};

    const {
        rest_url,
        nonce,
        tracking_cookie,
        page_visits_cookie,
        is_logged_in,
        has_tracking_cookie,
        disable_tracking,
        can_store_page_visits
    } = config;

    // Don't track if disabled
    if (disable_tracking) {
        return;
    }

    const DURATION = {
        HOUR: 60 * 60 * 1000,
        DAY: 24 * 60 * 60 * 1000
    };

    /**
     * Set a cookie
     */
    const setCookie = (name, value, duration) => {
        const date = new Date();
        date.setTime(date.getTime() + duration);
        document.cookie = `${name}=${value};expires=${date.toUTCString()};path=${config.cookie_path};SameSite=Lax`;
    };

    /**
     * Get a cookie value
     */
    const getCookie = (name, defaultValue = null) => {
        const nameEq = `${name}=`;
        const cookies = document.cookie.split(';');
        for (let i = 0; i < cookies.length; i++) {
            let c = cookies[i].trim();
            if (c.indexOf(nameEq) === 0) {
                return c.substring(nameEq.length);
            }
        }
        return defaultValue;
    };

    /**
     * Get visited pages from cookie
     */
    const getVisitedPages = () => {
        const cookie = getCookie(page_visits_cookie);
        if (!cookie) return [];
        try {
            return JSON.parse(decodeURIComponent(cookie));
        } catch (e) {
            return [];
        }
    };

    /**
     * Store page visit in cookie for later association
     */
    const storePageVisitInCookie = (wasTracked = false) => {

        if (!can_store_page_visits) {
            return;
        }


        const url = new URL(window.location.href);
        let pages = getVisitedPages();

        if (!Array.isArray(pages)) {
            pages = [];
        }

        // Limit size
        if (pages.length >= 50) {
            pages.shift();
        }

        pages.push({
            path: url.pathname,
            timestamp: Math.floor(Date.now() / 1000),
            tracked: wasTracked ? 1 : 0
        });

        setCookie(
            page_visits_cookie,
            encodeURIComponent(JSON.stringify(pages)),
            DURATION.HOUR
        );
    };

    /**
     * Send page view to REST API
     */
    const sendPageView = async () => {
        try {
            const response = await fetch(`${rest_url}page-view`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({
                    url: window.location.href
                }),
                credentials: 'same-origin'
            });

            if (response.ok) {
                return;
            } else {
                storePageVisitInCookie(false);
            }
        } catch (error) {
            console.warn('DoubleScale tracking error:', error);
            storePageVisitInCookie(false);
        }
    };

    /**
     * Initialize tracking
     */
    const init = () => {
        if (is_logged_in || has_tracking_cookie) {
            sendPageView();
            return;
        }

        storePageVisitInCookie(false);
    };


    // Run tracking on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for external use
    window.DoubleScaleTracking = {
        init,
        sendPageView,
        getVisitedPages,
        getCookie,
        setCookie
    };

})();