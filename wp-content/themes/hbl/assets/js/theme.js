
(function ($) {
    'use strict';

    var HBL_SAVED_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-right:8px"><path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var HBL_GRAVATAR_FALLBACK = 'https://www.gravatar.com/avatar/?d=mp&s=150';

    $(document).ready(function () {

        const $body = $('body');
        const $window = $(window);
        const $document = $(document);
        const $htmlBody = $('html, body');


        function smoothScrollTo($target, offset = 100, duration = 800) {
            if (!$target || !$target.length) return false;

            $htmlBody.animate({
                scrollTop: $target.offset().top - offset
            }, duration);
            return true;
        }

        function initializeSwiper($slider, config) {
            if (!$slider || !$slider.length) return null;
            if (typeof Swiper === 'undefined') {
                return null;
            }

            try {
                return new Swiper($slider[0], config);
            } catch (error) {
                return null;
            }
        }

        function debounce(func, wait = 250) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }


        if (typeof bootstrap !== 'undefined') {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
        }

        $('a[href*="#"]:not([href="#"]):not(.hbl-page-number):not(.hbl-page-btn):not(.hbl-prev-btn):not(.hbl-next-btn)').click(function () {
            if ($(this).closest('.hbl-pagination').length > 0) {
                return;
            }

            if (location.pathname.replace(/^\//, '') == this.pathname.replace(/^\//, '') && location.hostname == this.hostname) {
                var target = $(this.hash);
                target = target.length ? target : $('[name=' + this.hash.slice(1) + ']');
                if (smoothScrollTo(target, 100, 1000)) {
                    return false;
                }
            }
        });

        var currentUrl = window.location.href;
        $('.navbar-nav .nav-link').each(function () {
            var href = $(this).attr('href');
            if (currentUrl.indexOf(href) > -1 && href !== '/') {
                $(this).addClass('active');
            }
        });

        var header = $('.site-header');
        var headerHeight = header.outerHeight();

        $(window).scroll(function () {
            if ($(window).scrollTop() > headerHeight) {
                header.addClass('scrolled');
            } else {
                header.removeClass('scrolled');
            }
        });

        function updateHeaderOffset() {
            var $fixedHeader = $('header, #masthead, [data-elementor-type="header"]').filter(function () {
                var $el = $(this);
                var position = $el.css('position');
                return (position === 'fixed' || position === 'sticky') && $el.offset().top <= 0;
            }).first();

            var offset = $fixedHeader.length ? $fixedHeader.outerHeight() : 0;
            document.documentElement.style.setProperty('--hbl-header-offset', offset + 'px');
        }

        updateHeaderOffset();
        $window.on('resize', debounce(updateHeaderOffset, 200));
        setTimeout(updateHeaderOffset, 1000);

        if ($('#back-to-top').length) {
            var scrollTrigger = 300;

            $(window).scroll(function () {
                if ($(window).scrollTop() > scrollTrigger) {
                    $('#back-to-top').fadeIn();
                } else {
                    $('#back-to-top').fadeOut();
                }
            });

            $('#back-to-top').click(function (e) {
                e.preventDefault();
                $('html, body').animate({ scrollTop: 0 }, 800);
            });
        }

        $('.navbar-nav .nav-link').on('click', function () {
            if ($(window).width() < 992) {
                $('.navbar-collapse').collapse('hide');
            }
        });

        $('form').on('submit', function () {
            $(this).find('button[type="submit"]').addClass('disabled').append('<span class="spinner-border spinner-border-sm ms-2" role="status" aria-hidden="true"></span>');
        });

        if ('IntersectionObserver' in window) {
            var lazyImages = document.querySelectorAll('img[data-src]');
            var imageObserver = new IntersectionObserver(function (entries, observer) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        var img = entry.target;
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        imageObserver.unobserve(img);
                    }
                });
            });

            lazyImages.forEach(function (img) {
                imageObserver.observe(img);
            });
        }

        function checkScroll() {
            $('.fade-in-up').each(function () {
                var bottom_of_element = $(this).offset().top + $(this).outerHeight();
                var bottom_of_window = $(window).scrollTop() + $(window).height();

                if (bottom_of_window > bottom_of_element) {
                    $(this).addClass('animated');
                }
            });
        }

        $(window).scroll(function () {
            checkScroll();
        });

        checkScroll();

        initializeHBLWidgets();
    });

    $(window).on('elementor/frontend/init', function () {
        setTimeout(function () {
            initializeHBLWidgets();
        }, 100);
    });

    $(window).on('load', function () {
        $('#preloader').fadeOut('slow');

        if (typeof AOS !== 'undefined') {
            AOS.init({
                duration: 800,
                easing: 'ease-in-out',
                once: true,
                mirror: false
            });
        }

        setTimeout(function () {
            initializeHBLWidgets();
        }, 500);
    });

    function initializeHBLWidgets() {
        initializeDirectorySearch();

        initializeBusinessCards();

        initializeFeaturedBusinesses();

        initializeCategoriesGrid();

        initializeTestimonials();

        initializeFAQ();

        initializeLocalNews();

        initializeEventsCalendar();

        initializeSearchWidget();

        initializeStaticGridSlider();

        initializeCTASection();

        initializeBlogsSection();

        initializeCalendarWidget();

        initializePricingPlan();

        initializeHBLDashboard();

        initializeHBLAccountMenu();
    }




    function initializeDirectorySearch() {
        $('.hbl-directory-search-widget').each(function () {
        });
    }

    function initializeBusinessCards() {
        $('.hbl-business-cards-widget').each(function () {
        });
    }

    function initializeFeaturedBusinesses() {
        $('.hbl-featured-businesses-widget').each(function () {
        });
    }

    function initializeCategoriesGrid() {
        $('.hbl-categories-grid-widget').each(function () {
        });
    }

    function initializeTestimonials() {
        $('.hbl-testimonials-widget').each(function () {
        });
    }

    function initializeFAQ() {
        $('.hbl-faq-widget').each(function () {
            const $widget = $(this);

            if ($widget.data('initialized')) {
                return;
            }

            $widget.data('initialized', true);

            const $faqHeaders = $widget.find('.faq-header');
            const $faqItems = $widget.find('.faq-item');

            $faqHeaders.on('click', function (e) {
                e.preventDefault();
                const $clickedItem = $(this).closest('.faq-item');
                const isActive = $clickedItem.hasClass('active');

                $faqItems.removeClass('active');
                $faqHeaders.attr('aria-expanded', 'false');

                if (!isActive) {
                    $clickedItem.addClass('active');
                    $(this).attr('aria-expanded', 'true');
                }
            });

            $faqHeaders.on('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).click();
                }
            });
        });
    }

    function initializeLocalNews() {
        $('.hbl-local-news-widget').each(function () {
        });
    }

    function initializeEventsCalendar() {
        $('.hbl-events-calendar-widget').each(function () {
        });
    }

    function initializeDirectoristStarRating() {
        var $ratingSelects = $('.directorist-review-criteria-select');

        if (typeof $.fn.barrating !== 'undefined') {
            $ratingSelects.each(function () {
                var $this = $(this);
                if (!$this.data('barrating')) {
                    try {
                        $this.barrating({
                            theme: 'bootstrap-stars'
                        });
                    } catch (error) {
                        try {
                            $this.barrating();
                        } catch (error2) {
                        }
                    }
                }
            });
        } else {
            var attempts = 0;
            var maxAttempts = 5;

            function tryInitialize() {
                attempts++;

                if (typeof $.fn.barrating !== 'undefined') {
                    $('.directorist-review-criteria-select').each(function () {
                        var $this = $(this);
                        if (!$this.data('barrating')) {
                            try {
                                $this.barrating({
                                    theme: 'bootstrap-stars'
                                });
                            } catch (error) {
                                try {
                                    $this.barrating();
                                } catch (error2) {
                                }
                            }
                        }
                    });
                } else if (attempts < maxAttempts) {
                    setTimeout(tryInitialize, 1000);
                }
            }

            setTimeout(tryInitialize, 500);
        }
    }

    function initializeSearchWidget() {
        $('.hbl-search-widget').each(function () {
            var $widget = $(this);
            var $form = $widget.find('.hbl-search-form');
            var $input = $widget.find('.hbl-search-input');

            $form.on('submit', function (e) {
                var searchValue = $input.val().trim();

                if (searchValue === '') {
                    e.preventDefault();
                    $input.focus();

                    $input.css('background', 'rgba(220, 53, 69, 0.1)');
                    setTimeout(function () {
                        $input.css('background', '');
                    }, 1000);

                    return false;
                }

            });
        });
    }

    function initializeCTASection() {
        $('.hbl-cta-section').each(function () {
            var $widget = $(this);

            if ($widget.data('hbl-cta-initialized')) {
                return;
            }

            $widget.data('hbl-cta-initialized', true);

            $widget.find('.hbl-cta-item').on('click', function (e) {
                var href = $(this).attr('href');

                if (href && href.charAt(0) === '#' && href.length > 1) {
                    var $target = $(href);

                    if ($target.length) {
                        e.preventDefault();
                        $('html, body').animate({
                            scrollTop: $target.offset().top - 100
                        }, 600);
                    }
                }
            });
        });
    }

    function initializeStaticGridSlider() {
        if (typeof Swiper === 'undefined') {
            return;
        }
    }

    function initializeBlogsSection() {
        $('.hbl-blogs-section').each(function () {
            var $widget = $(this);

            if ($widget.data('hbl-blogs-initialized')) {
                return;
            }

            $widget.data('hbl-blogs-initialized', true);

            $widget.find('.hbl-blog-read-more, .hbl-blogs-button').on('click', function (e) {
                var href = $(this).attr('href');

                if (href && href.charAt(0) === '#' && href.length > 1) {
                    var $target = $(href);

                    if ($target.length) {
                        e.preventDefault();
                        $('html, body').animate({
                            scrollTop: $target.offset().top - 100
                        }, 600);
                    }
                }
            });

            $widget.find('.hbl-blog-image, .hbl-blog-featured').each(function () {
                var $elem = $(this);
                var bgImage = $elem.css('background-image');

                if (bgImage && bgImage !== 'none') {
                    $elem.css('opacity', '0');

                    var imageUrl = bgImage.replace(/url\(['"]?(.*?)['"]?\)/i, '$1');

                    var img = new Image();
                    img.onload = function () {
                        $elem.animate({ opacity: 1 }, 400);
                    };
                    img.src = imageUrl;
                }
            });
        });
    }

    function initializeRowSearchWidget($scope) {
        const $container = $scope || $('body');

        $container.find('.hbl-row-search-dropdown').off('click');
        $container.find('.hbl-row-search-dropdown-option').off('click');

        $container.find('.hbl-row-search-dropdown').each(function () {
            const $dropdown = $(this);
            const $label = $dropdown.find('.hbl-row-search-dropdown-label');
            const $hiddenInput = $dropdown.find('.hbl-row-search-dropdown-value');
            const $menu = $dropdown.find('.hbl-row-search-dropdown-menu');
            const $options = $dropdown.find('.hbl-row-search-dropdown-option');
            const defaultLabel = $label.text();

            $dropdown.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                $container.find('.hbl-row-search-dropdown').not($dropdown).removeClass('active');

                $dropdown.toggleClass('active');
            });

            $options.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const value = $(this).data('value');
                const text = $(this).text().trim();

                $label.text(text);

                $hiddenInput.val(value);

                $options.removeClass('selected');
                $(this).addClass('selected');

                $dropdown.removeClass('active');
            });
        });

        $(document).off('click.rowsearch').on('click.rowsearch', function (e) {
            if (!$(e.target).closest('.hbl-row-search-dropdown').length) {
                $container.find('.hbl-row-search-dropdown').removeClass('active');
            }
        });
    }

    $(document).ready(function () {
        initializeRowSearchWidget();
    });

    $(window).on('elementor/frontend/init', function () {
        elementorFrontend.hooks.addAction('frontend/element_ready/hbl-row-search.default', function ($scope) {
            initializeRowSearchWidget($scope);
        });
    });

    function initializeFAQsWidget($scope) {
        const $container = $scope || $('body');

        $container.find('.hbl-faq-question').off('click');

        $container.find('.hbl-faqs-wrapper').each(function () {
            const $wrapper = $(this);
            const behavior = $wrapper.data('behavior') || 'single';
            const $faqItems = $wrapper.find('.hbl-faq-item');
            const $questions = $wrapper.find('.hbl-faq-question');

            $questions.on('click', function () {
                const $question = $(this);
                const $faqItem = $question.closest('.hbl-faq-item');
                const $answer = $faqItem.find('.hbl-faq-answer-wrapper');
                const $icon = $faqItem.find('.hbl-faq-icon');
                const isActive = $faqItem.hasClass('active');

                if (behavior === 'single' && !isActive) {
                    $faqItems.each(function () {
                        if ($(this)[0] !== $faqItem[0]) {
                            const $otherAnswer = $(this).find('.hbl-faq-answer-wrapper');
                            const $otherIcon = $(this).find('.hbl-faq-icon');

                            $(this).removeClass('active');
                            $otherAnswer.slideUp(300);

                            if ($otherIcon.length) {
                                const collapsedIcon = $otherIcon.data('collapsed');
                                $otherIcon.removeClass().addClass('hbl-faq-icon ' + collapsedIcon);
                            }
                        }
                    });
                }

                if (isActive) {
                    $faqItem.removeClass('active');
                    $answer.slideUp(300);

                    if ($icon.length) {
                        const collapsedIcon = $icon.data('collapsed');
                        $icon.removeClass().addClass('hbl-faq-icon ' + collapsedIcon);
                    }
                } else {
                    $faqItem.addClass('active');
                    $answer.slideDown(300);

                    if ($icon.length) {
                        const expandedIcon = $icon.data('expanded');
                        $icon.removeClass().addClass('hbl-faq-icon ' + expandedIcon);
                    }
                }
            });
        });
    }

    $(document).ready(function () {
        initializeFAQsWidget();
    });

    function initializePricingPlan() {
        $('.hbl-pricing-plan-wrapper').each(function () {
            var $widget = $(this);

            if ($widget.data('hbl-pricing-plan-initialized')) {
                return;
            }

            $widget.data('hbl-pricing-plan-initialized', true);

            $widget.find('.hbl-pricing-plan-button').on('click', function (e) {
                var href = $(this).attr('href');

                if (href && href.charAt(0) === '#' && href.length > 1) {
                    var $target = $(href);

                    if ($target.length) {
                        e.preventDefault();
                        $('html, body').animate({
                            scrollTop: $target.offset().top - 100
                        }, 600);
                    }
                }
            });
        });
    }

    function initializeCalendarWidget() {
        $('.hbl-calendar-widget').each(function () {
            var $widget = $(this);

            if ($widget.data('hbl-calendar-initialized')) {
                return;
            }

            $widget.data('hbl-calendar-initialized', true);

            var currentYear = parseInt($widget.data('year'));
            var currentMonth = parseInt($widget.data('month'));

            $widget.on('click', '.hbl-calendar-date', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $dateBtn = $(this);

                if ($dateBtn.hasClass('empty')) {
                    return;
                }

                var selectedDate = $dateBtn.data('date');

                $widget.find('.hbl-calendar-date').removeClass('active');

                $dateBtn.addClass('active');

                updateDayNavigation($widget, selectedDate);

                saveCalendarState(selectedDate, currentYear, currentMonth, getWidgetFilters($widget));

                loadEventsForDate($widget, selectedDate, 1, true);

                setTimeout(function () {
                    var $eventsSection = $widget.find('.hbl-calendar-events-container');
                    if ($eventsSection.length && $eventsSection.is(':visible')) {
                        $('html, body').animate({
                            scrollTop: $eventsSection.offset().top - 100
                        }, 800);
                    }
                }, 300);
            });

            $widget.on('click', '.hbl-calendar-event-link', function () {
                var selectedDate = $(this).data('selected-date');
                if (selectedDate) {
                    try {
                        sessionStorage.setItem('hbl_event_selected_date', selectedDate);
                    } catch (e) {
                    }
                }
            });

            $widget.on('click', '.hbl-calendar-page-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $btn = $(this);
                if ($btn.hasClass('disabled')) {
                    return;
                }

                var page = parseInt($btn.data('page'));
                var selectedDate = $widget.find('.hbl-calendar-date.active').data('date');

                if (!selectedDate) {
                    var now = new Date();
                    selectedDate = now.getFullYear() + '-' +
                        String(now.getMonth() + 1).padStart(2, '0') + '-' +
                        String(now.getDate()).padStart(2, '0');
                }

                loadEventsForDate($widget, selectedDate, page, true);
            });

            $widget.on('click', '.hbl-calendar-nav-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $navBtn = $(this);
                var direction = $navBtn.data('direction');

                if (direction === 'prev') {
                    currentMonth--;
                    if (currentMonth < 1) {
                        currentMonth = 12;
                        currentYear--;
                    }
                } else {
                    currentMonth++;
                    if (currentMonth > 12) {
                        currentMonth = 1;
                        currentYear++;
                    }
                }

                $widget.data('year', currentYear);
                $widget.data('month', currentMonth);

                loadCalendarMonth($widget, currentYear, currentMonth);
            });

            $widget.on('click', '.hbl-v2-popular-search-btn', function (e) {
                e.preventDefault();
                var tag = $(this).data('tag');
                $widget.data('custom-tag', tag);
                var $active = $widget.find('.hbl-calendar-date.active');
                if ($active.length) {
                    $widget.data('last-active-date', $active.data('date'));
                    $active.removeClass('active');
                }
                loadEventsForDate($widget, '', 1, false);
            });

            $widget.on('click', '.hbl-v2-letter-btn', function (e) {
                e.preventDefault();
                $widget.find('.hbl-v2-letter-btn').removeClass('active');
                $(this).addClass('active');

                var letter = $(this).data('letter');
                $widget.data('az-pattern', letter);
                var $active = $widget.find('.hbl-calendar-date.active');
                if ($active.length) {
                    $widget.data('last-active-date', $active.data('date'));
                    $active.removeClass('active');
                }
                loadEventsForDate($widget, '', 1, false);
            });

            var searchTimeout;
            $widget.on('input', '.hbl-calendar-search-input', function (e) {
                var val = $(this).val();
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function () {
                    $widget.data('search-pattern', val);
                    var $active = $widget.find('.hbl-calendar-date.active');
                    if ($active.length) {
                        $widget.data('last-active-date', $active.data('date'));
                        $active.removeClass('active');
                    }
                    saveCalendarState('', currentYear, currentMonth, getWidgetFilters($widget));
                    loadEventsForDate($widget, '', 1, false);
                }, 500);
            });

            $widget.on('click', '.hbl-calendar-filter-trigger', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $trigger = $(this);
                var $dropdown = $trigger.closest('.hbl-calendar-category-wrapper, .hbl-calendar-sort-wrapper, .hbl-calendar-more-filters-wrapper').find('.hbl-calendar-filter-dropdown');

                $widget.find('.hbl-calendar-filter-dropdown').not($dropdown).slideUp(200);
                $widget.find('.hbl-calendar-filter-trigger').not($trigger).attr('aria-expanded', 'false');

                var isExpanded = $trigger.attr('aria-expanded') === 'true';
                $trigger.attr('aria-expanded', !isExpanded);

                if (isExpanded) {
                    $dropdown.slideUp(200);
                } else {
                    $dropdown.slideDown(200);
                }
            });

            $(document).on('click', function (e) {
                if (!$(e.target).closest('.hbl-calendar-category-wrapper, .hbl-calendar-sort-wrapper, .hbl-calendar-more-filters-wrapper').length) {
                    $widget.find('.hbl-calendar-filter-dropdown').slideUp(200);
                    $widget.find('.hbl-calendar-filter-trigger').attr('aria-expanded', 'false');
                }
            });

            $widget.on('click', '.hbl-calendar-category-wrapper .hbl-calendar-filter-item', function (e) {
                e.preventDefault();
                var $item = $(this);
                var category = $item.data('category');
                var categoryName = $item.text();

                $item.closest('.hbl-calendar-category-wrapper').find('.hbl-calendar-filter-label').text(categoryName);
                $item.closest('.hbl-calendar-filter-dropdown').slideUp(200);
                $item.closest('.hbl-calendar-category-wrapper').find('.hbl-calendar-filter-trigger').attr('aria-expanded', 'false');

                $widget.data('category', category);
                var $active = $widget.find('.hbl-calendar-date.active');
                if ($active.length) {
                    $widget.data('last-active-date', $active.data('date'));
                    $active.removeClass('active');
                }
                loadEventsForDate($widget, '', 1, false);
            });

            $widget.on('click', '.hbl-calendar-sort-wrapper .hbl-calendar-filter-item', function (e) {
                e.preventDefault();
                var $item = $(this);
                var sortOrder = $item.data('sort');
                var sortLabel = $item.text();

                $item.closest('.hbl-calendar-sort-wrapper').find('.hbl-calendar-filter-label').text(sortLabel);
                $item.closest('.hbl-calendar-filter-dropdown').slideUp(200);
                $item.closest('.hbl-calendar-sort-wrapper').find('.hbl-calendar-filter-trigger').attr('aria-expanded', 'false');

                $widget.data('sort-order', sortOrder);
                var $active = $widget.find('.hbl-calendar-date.active');
                if ($active.length) {
                    $widget.data('last-active-date', $active.data('date'));
                    $active.removeClass('active');
                }
                loadEventsForDate($widget, '', 1, false);
            });

            $widget.on('click', '.hbl-calendar-more-filters-wrapper .hbl-calendar-filter-item', function (e) {
                e.preventDefault();
                var $item = $(this);
                var customTag = $item.data('filter');
                var tagLabel = $item.text();

                $item.closest('.hbl-calendar-more-filters-wrapper').find('.hbl-calendar-filter-label').text(tagLabel);
                $item.closest('.hbl-calendar-filter-dropdown').slideUp(200);
                $item.closest('.hbl-calendar-more-filters-wrapper').find('.hbl-calendar-filter-trigger').attr('aria-expanded', 'false');

                $widget.data('custom-tag', customTag);
                var $active = $widget.find('.hbl-calendar-date.active');
                if ($active.length) {
                    $widget.data('last-active-date', $active.data('date'));
                    $active.removeClass('active');
                }
                loadEventsForDate($widget, '', 1, false);
            });


            $widget.on('click', '.hbl-calendar-today-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $todayBtn = $(this);
                var todayYear = parseInt($todayBtn.data('today-year'));
                var todayMonth = parseInt($todayBtn.data('today-month'));
                var todayDate = $todayBtn.data('today-date');
                currentYear = todayYear;
                currentMonth = todayMonth;

                $widget.data('year', currentYear);
                $widget.data('month', currentMonth);

                loadCalendarMonth($widget, currentYear, currentMonth, function () {
                    var $todayDateBtn = $widget.find('.hbl-calendar-date[data-date="' + todayDate + '"]');
                    if ($todayDateBtn.length) {
                        $widget.find('.hbl-calendar-date').removeClass('active');
                        $todayDateBtn.addClass('active');
                        updateDayNavigation($widget, todayDate);
                        saveCalendarState(todayDate, currentYear, currentMonth, getWidgetFilters($widget));
                        loadEventsForDate($widget, todayDate, 1, true);
                    }
                });
            });

            $widget.on('click', '.hbl-calendar-day-nav-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $dayNavBtn = $(this);
                var targetDate = $dayNavBtn.data('date');
                var targetYear = parseInt($dayNavBtn.data('year'));
                var targetMonth = parseInt($dayNavBtn.data('month'));

                if (targetYear !== currentYear || targetMonth !== currentMonth) {
                    currentYear = targetYear;
                    currentMonth = targetMonth;

                    $widget.data('year', currentYear);
                    $widget.data('month', currentMonth);

                    loadCalendarMonth($widget, currentYear, currentMonth, function () {
                        var $targetDateBtn = $widget.find('.hbl-calendar-date[data-date="' + targetDate + '"]');
                        if ($targetDateBtn.length) {
                            $widget.find('.hbl-calendar-date').removeClass('active');
                            $targetDateBtn.addClass('active');
                        }
                        updateDayNavigation($widget, targetDate);
                        saveCalendarState(targetDate, currentYear, currentMonth, getWidgetFilters($widget));
                        loadEventsForDate($widget, targetDate, 1, true);
                    });
                } else {
                    var $targetDateBtn = $widget.find('.hbl-calendar-date[data-date="' + targetDate + '"]');
                    if ($targetDateBtn.length) {
                        $widget.find('.hbl-calendar-date').removeClass('active');
                        $targetDateBtn.addClass('active');
                    }
                    updateDayNavigation($widget, targetDate);
                    saveCalendarState(targetDate, currentYear, currentMonth, getWidgetFilters($widget));
                    loadEventsForDate($widget, targetDate, 1, true);
                }
            });

            var savedState = getCalendarState();
            var today = new Date();
            var todayStr = today.getFullYear() + '-' +
                String(today.getMonth() + 1).padStart(2, '0') + '-' +
                String(today.getDate()).padStart(2, '0');

            if (savedState && savedState.keyword) {
                $widget.data('search-pattern', savedState.keyword);
                $widget.find('.hbl-calendar-search-input').val(savedState.keyword);
            }
            if (savedState && savedState.category) {
                $widget.data('category', savedState.category);
            }
            if (savedState && savedState.sortOrder) {
                $widget.data('sort-order', savedState.sortOrder);
            }
            if (savedState && savedState.customTag) {
                $widget.data('custom-tag', savedState.customTag);
            }
            if (savedState && savedState.azPattern) {
                $widget.data('az-pattern', savedState.azPattern);
                $widget.find('.hbl-v2-letter-btn[data-letter="' + savedState.azPattern + '"]').addClass('active');
            }

            if (savedState && savedState.date) {
                var savedDate = savedState.date;
                var savedDateObj = new Date(savedDate + 'T00:00:00');
                var savedYear = savedDateObj.getFullYear();
                var savedMonth = savedDateObj.getMonth() + 1;

                if (savedYear !== currentYear || savedMonth !== currentMonth) {
                    currentYear = savedYear;
                    currentMonth = savedMonth;
                    $widget.data('year', currentYear);
                    $widget.data('month', currentMonth);

                    loadCalendarMonth($widget, currentYear, currentMonth, function () {
                        var $savedDateBtn = $widget.find('.hbl-calendar-date[data-date="' + savedDate + '"]');
                        if ($savedDateBtn.length) {
                            $widget.find('.hbl-calendar-date').removeClass('active');
                            $savedDateBtn.addClass('active');
                            updateDayNavigation($widget, savedDate);
                            loadEventsForDate($widget, savedDate, 1, false);
                        }
                    });
                } else {
                    var $savedDateBtn = $widget.find('.hbl-calendar-date[data-date="' + savedDate + '"]');
                    if ($savedDateBtn.length) {
                        $widget.find('.hbl-calendar-date').removeClass('active');
                        $savedDateBtn.addClass('active');
                        updateDayNavigation($widget, savedDate);
                        loadEventsForDate($widget, savedDate, 1, false);
                    }
                }
            } else if (savedState && savedState.keyword) {
                loadEventsForDate($widget, '', 1, false);
            } else {
                var $todayBtn = $widget.find('.hbl-calendar-date[data-date="' + todayStr + '"]');
                if ($todayBtn.length) {
                    $todayBtn.addClass('active');
                    loadEventsForDate($widget, todayStr, 1, false);
                }
            }
        });
    }

    function loadEventsForDate($widget, date, page, shouldScroll) {
        var $eventsContainer = $widget.find('.hbl-calendar-events-container');
        var eventsPerDate = parseInt($widget.data('events-per-date')) || 12;
        var category = $widget.data('category') || '';
        var searchPattern = $widget.data('search-pattern') || '';
        var sortOrder = $widget.data('sort-order') || '';
        var customTag = $widget.data('custom-tag') || '';
        var azFilterPattern = $widget.data('az-pattern') || '';
        var readMoreText = $widget.data('read-more') || 'Read More';
        var noEventsMessage = $widget.data('no-events-message') || 'No events found for this date.';

        page = page || 1;

        var $activeFiltersContainer = $widget.find('.hbl-v2-active-filters-container');
        var $activeFiltersWrapper = $widget.find('.hbl-v2-active-filters');

        if ($activeFiltersContainer.length) {
            $activeFiltersContainer.empty();
            var hasActiveFilters = false;

            function addActiveFilter(type, label, iconHtml) {
                var $tag = $('<div class="hbl-v2-active-filter-tag" data-filter-type="' + type + '"></div>');
                if (iconHtml) {
                    $tag.append('<span class="hbl-v2-active-filter-tag-icon">' + iconHtml + '</span>');
                }
                $tag.append('<span class="hbl-v2-active-filter-tag-text">' + label + '</span>');

                var $clearBtn = $('<button type="button" class="hbl-v2-active-filter-tag-clear" aria-label="Clear filter"><svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 3L3 9M3 3L9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>');
                $clearBtn.on('click', function (e) {
                    e.preventDefault();
                    if (type === 'keyword') {
                        $widget.data('search-pattern', '');
                        $widget.find('.hbl-calendar-search-input').val('');
                    } else if (type === 'category') {
                        $widget.data('category', '');
                        $widget.find('.hbl-calendar-category-wrapper .hbl-calendar-filter-label').text('Search Categories');
                    } else if (type === 'sort') {
                        $widget.data('sort-order', '');
                        $widget.find('.hbl-calendar-sort-wrapper .hbl-calendar-filter-label').text('Sort By');
                    } else if (type === 'tag') {
                        $widget.data('custom-tag', '');
                    } else if (type === 'letter') {
                        $widget.data('az-pattern', '');
                        $widget.find('.hbl-v2-letter-btn').removeClass('active');
                        $widget.find('.hbl-v2-letter-btn[data-letter=""]').addClass('active');
                    }
                    
                    var searchPattern = $widget.data('search-pattern') || '';
                    var customTag = $widget.data('custom-tag') || '';
                    var azFilterPattern = $widget.data('az-pattern') || '';
                    var activeLabel = $widget.find('.hbl-v2-letter-btn.active').data('letter');
                    
                    var remainingCatLabel = $widget.find('.hbl-calendar-category-wrapper .hbl-calendar-filter-label').text().trim();
                    var remainingSortLabel = $widget.find('.hbl-calendar-sort-wrapper .hbl-calendar-filter-label').text().trim();
                    var hasRemainingFilters = searchPattern !== '' ||
                                             (remainingCatLabel !== 'Search Categories') ||
                                             (remainingSortLabel !== 'Sort By') ||
                                             customTag !== '' ||
                                             azFilterPattern !== '' ||
                                             (activeLabel !== undefined && activeLabel !== '');

                    var activeDate = $widget.find('.hbl-calendar-date.active').data('date');
                    
                    if (!hasRemainingFilters && !activeDate) {
                        var restoredDate = $widget.data('last-active-date');
                        if (!restoredDate) {
                            var today = new Date();
                            restoredDate = today.getFullYear() + '-' +
                                String(today.getMonth() + 1).padStart(2, '0') + '-' +
                                String(today.getDate()).padStart(2, '0');
                        }
                        $widget.find('.hbl-calendar-date[data-date="' + restoredDate + '"]').addClass('active');
                        loadEventsForDate($widget, restoredDate, 1, false);
                    } else {
                        loadEventsForDate($widget, activeDate || '', 1, false);
                    }
                });
                $tag.append($clearBtn);
                $activeFiltersContainer.append($tag);
                hasActiveFilters = true;
            }

            if (searchPattern) {
                addActiveFilter('keyword', 'Search: ' + searchPattern);
            }
            var catLabel = $widget.find('.hbl-calendar-category-wrapper .hbl-calendar-filter-label').text().trim();
            if (catLabel && catLabel !== 'Search Categories') {
                addActiveFilter('category', 'Category: ' + catLabel);
            }

            var sortLabel = $widget.find('.hbl-calendar-sort-wrapper .hbl-calendar-filter-label').text().trim();
            if (sortLabel && sortLabel !== 'Sort By') {
                addActiveFilter('sort', 'Sort: ' + sortLabel);
            }

            if (customTag) {
                addActiveFilter('tag', 'Tag: ' + customTag);
            }
            if (azFilterPattern !== '') {
                addActiveFilter('letter', 'Starts with: ' + azFilterPattern);
            }

            if (hasActiveFilters) {
                $activeFiltersWrapper.slideDown(200);
            } else {
                $activeFiltersWrapper.slideUp(200);
            }
        }

        $eventsContainer.html('<div class="hbl-calendar-loading" style="text-align: center; padding: 40px;">Loading events...</div>');

        $.ajax({
            url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'hbl_get_calendar_events',
                date: date,
                events_per_date: eventsPerDate,
                category: category,
                page: page,
                search: searchPattern,
                sort: sortOrder,
                tag: customTag,
                azFilter: azFilterPattern,
                nonce: hblData.nonce || ''
            },
            success: function (response) {
                if (response.success && response.data && response.data.events) {
                    var events = response.data.events;
                    var pagination = response.data.pagination || {};
                    var html = '';

                    if (events.length > 0) {
                        $.each(events, function (index, event) {
                            var eventImage = event.image || '';
                            var eventTitle = event.title || '';
                            var eventVenue = event.venue || '';
                            var eventCategory = event.category || '';
                            var eventCategoryLink = event.category_link || '#';
                            var eventExcerpt = event.excerpt || '';
                            var eventUrl = event.url || '#';

                            html += '<div class="hbl-calendar-event-card">';

                            if (eventImage) {
                                html += '<div class="hbl-calendar-event-image" style="background-image: url(\'' + eventImage + '\');"></div>';
                            }

                            html += '<div class="hbl-calendar-event-content">';
                            html += '<h4 class="hbl-calendar-event-title">' + eventTitle + '</h4>';

                            if (eventVenue) {
                                html += '<p class="hbl-calendar-event-venue"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 5.03 7.03 1 12 1S21 5.03 21 10Z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/></svg>' + eventVenue + '</p>';
                            }

                            if (eventCategory) {
                                html += '<p class="hbl-calendar-event-category"><a href="' + eventCategoryLink + '">' + eventCategory + '</a></p>';
                            }

                            if (eventExcerpt) {
                                html += '<p class="hbl-calendar-event-excerpt">' + eventExcerpt + '</p>';
                            }

                            html += '<a href="' + eventUrl + '" class="hbl-calendar-event-link" data-selected-date="' + date + '">';
                            html += readMoreText;
                            html += '<svg width="8" height="11" viewBox="0 0 8 11" fill="none" xmlns="http://www.w3.org/2000/svg">';
                            html += '<path d="M4 2L6 4L4 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
                            html += '</svg>';
                            html += '</a>';
                            html += '</div>';
                            html += '</div>';
                        });

                        if (pagination.total_pages > 1) {
                            var currentPage = parseInt(pagination.current_page);
                            var totalPages = parseInt(pagination.total_pages);

                            html += '<div class="hbl-calendar-pagination">';

                            if (currentPage > 1) {
                                html += '<button type="button" class="hbl-calendar-page-btn prev" data-page="' + (currentPage - 1) + '">Previous</button>';
                            } else {
                                html += '<button type="button" class="hbl-calendar-page-btn prev disabled" disabled>Previous</button>';
                            }

                            html += '<span class="hbl-calendar-page-info">Page ' + currentPage + ' of ' + totalPages + '</span>';

                            if (currentPage < totalPages) {
                                html += '<button type="button" class="hbl-calendar-page-btn next" data-page="' + (currentPage + 1) + '">Next</button>';
                            } else {
                                html += '<button type="button" class="hbl-calendar-page-btn next disabled" disabled>Next</button>';
                            }

                            html += '</div>';
                        }
                    } else {
                        html = '<p class="hbl-calendar-no-events">' + noEventsMessage + '</p>';
                    }

                    var totalEvents = parseInt(pagination.total_events) || events.length;

                    if (date) {
                        var countText = totalEvents === 1 ? '1 Event for today' : totalEvents + ' Events for today';
                        $widget.find('.hbl-calendar-event-count').text(countText);

                        var dateParts = date.split('-');
                        var displayDate = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);

                        try {
                            var formattedDate = displayDate.toLocaleDateString('en-US', {
                                weekday: 'long',
                                year: 'numeric',
                                month: 'long',
                                day: 'numeric'
                            });
                            $widget.find('.hbl-calendar-selected-date').text(formattedDate);
                        } catch (e) {
                            $widget.find('.hbl-calendar-selected-date').text(date);
                        }
                    } else {
                        var countText = totalEvents === 1 ? '1 Event found' : totalEvents + ' Events found';
                        $widget.find('.hbl-calendar-event-count').text(countText);
                        $widget.find('.hbl-calendar-selected-date').text('Upcoming Filtered Events');
                    }

                    $eventsContainer.html(html);

                    if (shouldScroll === true) {
                        setTimeout(function () {
                            var $eventsSection = $widget.find('#events-list');
                            if ($eventsSection.length) {
                                $('html, body').animate({
                                    scrollTop: $eventsSection.offset().top - 100
                                }, 800);
                            }
                        }, 100);
                    }
                } else {
                    $eventsContainer.html('<p class="hbl-calendar-no-events">Error loading events. Please try again.</p>');
                }
            },
            error: function () {
                $eventsContainer.html('<p class="hbl-calendar-no-events">Error loading events. Please try again.</p>');
            }
        });
    }

    function loadCalendarMonth($widget, year, month, callback) {
        var $calendarGrid = $widget.find('.hbl-calendar-grid');
        $calendarGrid.css('opacity', '0.5');

        var category = $widget.data('category') || '';

        $.ajax({
            url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'hbl_get_calendar_month',
                year: year,
                month: month,
                category: category,
                nonce: hblData.nonce || ''
            },
            success: function (response) {
                if (response.success && response.data && response.data.calendar) {
                    var monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'];
                    var monthName = monthNames[month - 1];
                    $widget.find('.hbl-calendar-month-title').text('Events for ' + monthName + ' ' + year);

                    var calendarHtml = response.data.calendar;
                    $widget.find('.hbl-calendar-dates').html(calendarHtml);

                    var prevMonth = month - 1;
                    var prevYear = year;
                    if (prevMonth < 1) {
                        prevMonth = 12;
                        prevYear--;
                    }

                    var nextMonth = month + 1;
                    var nextYear = year;
                    if (nextMonth > 12) {
                        nextMonth = 1;
                        nextYear++;
                    }

                    var prevMonthName = monthNames[prevMonth - 1];
                    var nextMonthName = monthNames[nextMonth - 1];

                    $widget.find('.hbl-calendar-prev span').text(prevMonthName);
                    $widget.find('.hbl-calendar-next span').text(nextMonthName);
                    $widget.find('.hbl-calendar-current-month-label').text(monthName);


                    if (typeof callback === 'function') {
                        $calendarGrid.css('opacity', '1');
                        callback();
                    } else {
                        var today = new Date();
                        var todayStr = today.getFullYear() + '-' +
                            String(today.getMonth() + 1).padStart(2, '0') + '-' +
                            String(today.getDate()).padStart(2, '0');

                        var $todayBtn = $widget.find('.hbl-calendar-date[data-date="' + todayStr + '"]');
                        if ($todayBtn.length && month === today.getMonth() + 1 && year === today.getFullYear()) {
                            $todayBtn.addClass('active');
                            loadEventsForDate($widget, todayStr, false);
                        }

                        $calendarGrid.css('opacity', '1');
                    }
                } else {
                    $calendarGrid.css('opacity', '1');
                }
            },
            error: function (xhr, status, error) {
                $calendarGrid.css('opacity', '1');
            }
        });
    }

    function updateDayNavigation($widget, selectedDate) {
        var $dayNav = $widget.find('.hbl-calendar-day-navigation');
        if (!$dayNav.length) return;

        var selected = new Date(selectedDate + 'T00:00:00');
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        var prevDate = new Date(selected);
        prevDate.setDate(prevDate.getDate() - 1);
        var nextDate = new Date(selected);
        nextDate.setDate(nextDate.getDate() + 1);

        function formatDate(d) {
            return d.getFullYear() + '-' +
                String(d.getMonth() + 1).padStart(2, '0') + '-' +
                String(d.getDate()).padStart(2, '0');
        }

        var prevDateStr = formatDate(prevDate);
        var nextDateStr = formatDate(nextDate);
        var todayStr = formatDate(today);

        var label = '';
        var yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        var tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);

        if (selectedDate === todayStr) {
            label = 'Today';
        } else if (selectedDate === formatDate(yesterday)) {
            label = 'Yesterday';
        } else if (selectedDate === formatDate(tomorrow)) {
            label = 'Tomorrow';
        } else {
            var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            var monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
            label = dayNames[selected.getDay()] + ', ' + selected.getDate() + ' ' + monthNames[selected.getMonth()];
        }

        $dayNav.find('.hbl-calendar-day-label').text(label).data('date', selectedDate);

        var $prevBtn = $dayNav.find('.hbl-calendar-day-prev');
        $prevBtn.data('date', prevDateStr);
        $prevBtn.data('year', prevDate.getFullYear());
        $prevBtn.data('month', prevDate.getMonth() + 1);

        var $nextBtn = $dayNav.find('.hbl-calendar-day-next');
        $nextBtn.data('date', nextDateStr);
        $nextBtn.data('year', nextDate.getFullYear());
        $nextBtn.data('month', nextDate.getMonth() + 1);
    }

    function getWidgetFilters($widget) {
        return {
            keyword:  $widget.data('search-pattern') || '',
            category: $widget.data('category')       || '',
            sortOrder:$widget.data('sort-order')     || '',
            customTag:$widget.data('custom-tag')     || '',
            azPattern:$widget.data('az-pattern')     || ''
        };
    }

    function saveCalendarState(selectedDate, year, month, filters) {
        try {
            var state = {
                date: selectedDate,
                year: year,
                month: month,
                keyword: (filters && filters.keyword) ? filters.keyword : '',
                category: (filters && filters.category) ? filters.category : '',
                sortOrder: (filters && filters.sortOrder) ? filters.sortOrder : '',
                customTag: (filters && filters.customTag) ? filters.customTag : '',
                azPattern: (filters && filters.azPattern) ? filters.azPattern : '',
                timestamp: Date.now()
            };
            sessionStorage.setItem('hbl_calendar_state', JSON.stringify(state));
        } catch (e) {
        }
    }

    function getCalendarState() {
        try {
            var stateStr = sessionStorage.getItem('hbl_calendar_state');
            if (stateStr) {
                var state = JSON.parse(stateStr);
                if (state.timestamp && (Date.now() - state.timestamp) < 24 * 60 * 60 * 1000) {
                    return state;
                }
            }
        } catch (e) {
        }
        return null;
    }


    function initializeHBLDashboard() {
        $('.hbl-dashboard-widget').each(function () {
            var $widget = $(this);

            if ($widget.data('hbl-dashboard-initialized')) {
                return;
            }

            $widget.data('hbl-dashboard-initialized', true);

            var $sidebar = $widget.find('.hbl-dash-sidebar');

            function setSidebar(open) {
                $sidebar.toggleClass('is-open', open);
                $widget.find('.hbl-dash-menu-toggle').attr('aria-expanded', open ? 'true' : 'false');
                $('body').toggleClass('hbl-dash-menu-open', open);
            }

            $widget.find('.hbl-dash-menu-toggle').on('click', function () {
                setSidebar(!$sidebar.hasClass('is-open'));
            });

            $widget.find('[data-hbl-dash-close]').on('click', function () {
                setSidebar(false);
            });

            $(document).on('keydown.hblDash', function (e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    setSidebar(false);
                }
            });

            var searchTimeout;
            $widget.find('.hbl-dashboard-search-input').on('input', function () {
                var searchQuery = $(this).val().toLowerCase();

                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function () {
                    $widget.find('.hbl-dashboard-listing-card').each(function () {
                        var $card = $(this);
                        var title = $card.find('.hbl-dashboard-listing-title').text().toLowerCase();
                        var category = $card.find('.hbl-dashboard-listing-category').text().toLowerCase();

                        if (title.indexOf(searchQuery) > -1 || category.indexOf(searchQuery) > -1 || searchQuery === '') {
                            $card.fadeIn(200);
                        } else {
                            $card.fadeOut(200);
                        }
                    });
                }, 300);
            });

            $widget.find('.hbl-dashboard-filter-select').on('change', function () {
                var filterValue = $(this).val();

                $widget.find('.hbl-dashboard-listing-card').each(function () {
                    var $card = $(this);
                    var status = $card.data('status');

                    if (filterValue === 'all' || status === filterValue) {
                        $card.fadeIn(200);
                    } else {
                        $card.fadeOut(200);
                    }
                });
            });

            $widget.find('.hbl-dashboard-action-delete').on('click', function (e) {
                e.preventDefault();

                var $btn = $(this);
                var listingId = $btn.data('listing-id');
                var $card = $btn.closest('.hbl-dashboard-listing-card');

                if (confirm('Are you sure you want to delete this listing? This action cannot be undone.')) {
                    $btn.addClass('loading').prop('disabled', true);

                    $.ajax({
                        url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
                        type: 'POST',
                        data: {
                            action: 'hbl_delete_listing',
                            listing_id: listingId,
                            nonce: hblData.nonce || ''
                        },
                        success: function (response) {
                            if (response.success) {
                                $card.animate({
                                    opacity: 0,
                                    height: 0,
                                    paddingTop: 0,
                                    paddingBottom: 0,
                                    marginBottom: 0
                                }, 400, function () {
                                    $card.remove();

                                    var $tabCount = $widget.find('.hbl-dash-nav-link[data-view="listings"] .hbl-dash-nav-count');
                                    if ($tabCount.length) {
                                        var currentCount = parseInt($tabCount.text()) || 0;
                                        $tabCount.text(Math.max(0, currentCount - 1));
                                    }

                                    if ($widget.find('.hbl-dashboard-listing-card').length === 0) {
                                        location.reload();
                                    }
                                });
                            } else {
                                alert('Error deleting listing. Please try again.');
                                $btn.removeClass('loading').prop('disabled', false);
                            }
                        },
                        error: function () {
                            alert('Error deleting listing. Please try again.');
                            $btn.removeClass('loading').prop('disabled', false);
                        }
                    });
                }
            });

            $widget.on('click', '.hbl-dashboard-favorite-remove', function (e) {
                e.preventDefault();

                var $btn = $(this);
                var itemId = $btn.data('item-id') || $btn.data('listing-id');
                var itemType = $btn.data('type') || 'listing';
                var $card = $btn.closest('.hbl-dashboard-favorite-card');

                $.ajax({
                    url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'hbl_toggle_favorite',
                        item_id: itemId,
                        item_type: itemType,
                        nonce: hblData.nonce || ''
                    },
                    success: function (response) {
                        if (response.success) {
                            $card.animate({
                                opacity: 0,
                                transform: 'scale(0.8)'
                            }, 300, function () {
                                $card.remove();

                                var $tabCount = $widget.find('.hbl-dash-nav-link[data-view="favorites"] .hbl-dash-nav-count');
                                if ($tabCount.length) {
                                    var currentCount = parseInt($tabCount.text()) || 0;
                                    $tabCount.text(Math.max(0, currentCount - 1));
                                }

                                if ($widget.find('.hbl-dashboard-favorite-card').length === 0) {
                                    location.reload();
                                }
                            });
                        } else {
                            alert('Error removing from favorites. Please try again.');
                        }
                    },
                    error: function () {
                        alert('Error removing from favorites. Please try again.');
                    }
                });
            });

            $widget.on('click', '#hbl-upload-profile-image', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                    alert('Media uploader is not available. Please refresh the page and try again.');
                    return;
                }

                var frame = wp.media({
                    title: 'Select Profile Photo',
                    button: { text: 'Use This Photo' },
                    multiple: false,
                    library: { type: 'image' }
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var imageUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

                    $widget.find('#hbl-profile-image-img').attr('src', imageUrl);
                    $widget.find('#hbl-profile-image-input').val(attachment.id);

                    $widget.find('#hbl-save-profile-image').show();

                    if ($widget.find('#hbl-remove-profile-image').length === 0) {
                        $widget.find('#hbl-profile-image-preview').append(
                            '<button type="button" class="hbl-dashboard-profile-image-remove" id="hbl-remove-profile-image" title="Remove Photo">' +
                            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                            '<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                            '</svg></button>'
                        );
                    }
                });

                frame.open();
            });

            $widget.on('click', '#hbl-remove-profile-image', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var gravatarUrl = $widget.find('#hbl-profile-image-img').data('gravatar') ||
                    HBL_GRAVATAR_FALLBACK;

                $widget.find('#hbl-profile-image-img').attr('src', gravatarUrl);
                $widget.find('#hbl-profile-image-input').val('');
                $(this).remove();

                $widget.find('#hbl-save-profile-image').show();
            });

            $widget.on('click', '#hbl-save-profile-image', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $btn = $(this);
                var $form = $widget.find('.hbl-dashboard-profile-form');
                var profileImageId = $widget.find('#hbl-profile-image-input').val();
                var nonce = $form.find('#hbl_profile_nonce').val();

                var originalHtml = $btn.html();
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

                $.ajax({
                    url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'hbl_save_profile_image',
                        profile_image: profileImageId,
                        hbl_profile_nonce: nonce
                    },
                    success: function (response) {
                        if (response.success) {
                            $btn.html(HBL_SAVED_SVG + 'Saved!');
                            setTimeout(function () {
                                $btn.html(originalHtml).prop('disabled', false).hide();
                            }, 2000);
                        } else {
                            alert(response.data || 'Error saving profile image.');
                            $btn.html(originalHtml).prop('disabled', false);
                        }
                    },
                    error: function () {
                        alert('Error saving profile image. Please try again.');
                        $btn.html(originalHtml).prop('disabled', false);
                    }
                });
            });

            $widget.find('.hbl-dashboard-profile-form').on('submit', function (e) {
                e.preventDefault();

                var $form = $(this);
                var $submitBtn = $form.find('button[type="submit"]');
                var originalText = $submitBtn.text();

                $submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

                $.ajax({
                    url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: $form.serialize() + '&action=hbl_update_profile',
                    success: function (response) {
                        if (response.success) {
                            $submitBtn.html(HBL_SAVED_SVG + 'Saved!');

                            setTimeout(function () {
                                $submitBtn.prop('disabled', false).text(originalText);
                            }, 2000);
                        } else {
                            alert(response.data || 'Error saving profile. Please try again.');
                            $submitBtn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function () {
                        alert('Error saving profile. Please try again.');
                        $submitBtn.prop('disabled', false).text(originalText);
                    }
                });
            });

            $widget.find('#hbl-event-form').on('submit', function (e) {
                e.preventDefault();

                var $form = $(this);
                var $submitBtn = $form.find('button[type="submit"]');
                var originalText = $submitBtn.text();

                $submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

                $.ajax({
                    url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: $form.serialize() + '&action=hbl_save_event',
                    success: function (response) {
                        if (response.success) {
                            var currentUrl = window.location.href.split('?')[0];
                            window.location.href = currentUrl + '?event_saved=1';
                        } else {
                            alert(response.data.message || 'Error saving event. Please try again.');
                            $submitBtn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function () {
                        alert('Error saving event. Please try again.');
                        $submitBtn.prop('disabled', false).text(originalText);
                    }
                });
            });

            $widget.on('click', '.hbl-delete-event', function (e) {
                e.preventDefault();

                if (!confirm('Are you sure you want to delete this event?')) {
                    return;
                }

                var $btn = $(this);
                var eventId = $btn.data('event-id');
                var $card = $btn.closest('.hbl-dashboard-event-card');

                $.ajax({
                    url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'hbl_delete_event',
                        event_id: eventId,
                        nonce: hblData.nonce || ''
                    },
                    success: function (response) {
                        if (response.success) {
                            $card.fadeOut(300, function () {
                                $(this).remove();

                                var $eventsCount = $widget.find('.hbl-dash-nav-link[data-view="events"] .hbl-dash-nav-count');
                                if ($eventsCount.length) {
                                    var currentCount = parseInt($eventsCount.text()) || 0;
                                    $eventsCount.text(Math.max(0, currentCount - 1));
                                }

                                if ($widget.find('.hbl-dashboard-event-card').length === 0) {
                                    location.reload();
                                }
                            });
                        } else {
                            alert(response.data.message || 'Error deleting event. Please try again.');
                        }
                    },
                    error: function () {
                        alert('Error deleting event. Please try again.');
                    }
                });
            });

            $widget.on('input', '#event_color', function () {
                var color = $(this).val();
                $(this).siblings('.hbl-dashboard-color-preview').css('background-color', color);
            });
        });
    }

    function initializeHBLAccountMenu() {
        $('[data-hbl-account-menu]').each(function () {
            var $menu = $(this);

            if ($menu.data('hbl-account-menu-initialized')) {
                return;
            }
            $menu.data('hbl-account-menu-initialized', true);

            var $trigger = $menu.find('.hbl-account-menu-trigger');

            function closeAll() {
                $('[data-hbl-account-menu]').removeClass('is-open')
                    .find('.hbl-account-menu-trigger').attr('aria-expanded', 'false');
                $('body').removeClass('hbl-account-menu-open');
            }

            $trigger.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var willOpen = !$menu.hasClass('is-open');
                closeAll();

                if (willOpen) {
                    $menu.addClass('is-open');
                    $trigger.attr('aria-expanded', 'true');
                    $('body').addClass('hbl-account-menu-open');
                }
            });

            $menu.find('[data-hbl-account-menu-close]').on('click', function (e) {
                e.preventDefault();
                closeAll();
            });
        });

        if (!$(document.body).data('hbl-account-menu-doc-bound')) {
            $(document.body).data('hbl-account-menu-doc-bound', true);

            function closeAllMenus() {
                $('[data-hbl-account-menu]').removeClass('is-open')
                    .find('.hbl-account-menu-trigger').attr('aria-expanded', 'false');
                $('body').removeClass('hbl-account-menu-open');
            }

            $(document).on('click', function (e) {
                if (!$(e.target).closest('[data-hbl-account-menu]').length) {
                    closeAllMenus();
                }
            });

            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    closeAllMenus();
                }
            });
        }
    }

    $(document).ready(function () {
        initializeHBLDashboard();
        initializeHBLAccountMenu();

        initializeHBLAddEventForm();
        initializeHBLAddListingForm();
        initializeHBLSigninSignupForm();
    });

    $(window).on('elementor/frontend/init', function () {
        elementorFrontend.hooks.addAction('frontend/element_ready/hbl-dashboard.default', function ($scope) {
            initializeHBLDashboard();
        });
        elementorFrontend.hooks.addAction('frontend/element_ready/hbl-account-menu.default', function ($scope) {
            initializeHBLAccountMenu();
        });
        elementorFrontend.hooks.addAction('frontend/element_ready/hbl-add-event-form.default', function ($scope) {
            initializeHBLAddEventForm();
        });
        elementorFrontend.hooks.addAction('frontend/element_ready/hbl-add-listing-form.default', function ($scope) {
            initializeHBLAddListingForm();
        });
        elementorFrontend.hooks.addAction('frontend/element_ready/hbl-signin-signup-form.default', function ($scope) {
            initializeHBLSigninSignupForm();
        });
    });

    function initializeHBLAddEventForm() {
        $('.hbl-event-form').each(function () {
            var $form = $(this);

            if ($form.data('hbl-event-form-initialized')) {
                return;
            }
            $form.data('hbl-event-form-initialized', true);

            $form.on('submit', function (e) {
                e.preventDefault();
                var $submitBtn = $form.find('#hbl-event-submit-btn');
                var originalText = $submitBtn.html();
                var $widget = $form.closest('.hbl-add-event-form-widget');
                var isEditing = $form.find('input[name="event_id"]').val() > 0;

				if (typeof grecaptcha !== 'undefined' && $form.find('.g-recaptcha').length) {
					if (!grecaptcha.getResponse()) {
						$form.find('.hbl-recaptcha-error').show();
						return;
					}
					$form.find('.hbl-recaptcha-error').hide();
				}

                $submitBtn.prop('disabled', true).html('<span class="hbl-btn-spinner"></span><span>Saving...</span>');

                var formData = new FormData(this);
                formData.append('action', 'hbl_save_event');

                if (!formData.has('nonce') && typeof hblData !== 'undefined' && hblData.nonce) {
                    formData.append('nonce', hblData.nonce);
                }

                var ajaxUrl = (typeof hblData !== 'undefined' && hblData.ajaxUrl) ? hblData.ajaxUrl : '/wp-admin/admin-ajax.php';

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        if (response.success) {
                            var eventId = response.data.event_id;
                            var eventSlug = response.data.slug || '';
                            var viewUrl = eventSlug ? '/events/' + eventSlug + '/' : '/events/?event_id=' + eventId;

                            var successHtml = '<div class="hbl-form-success-overlay">' +
                                '<div class="hbl-form-success-content">' +
                                '<div class="hbl-form-success-icon">' +
                                '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                                '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>' +
                                '<path d="M9 12L11 14L15 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                                '</svg>' +
                                '</div>' +
                                '<h3>' + (isEditing ? 'Event Updated!' : 'Event Submitted!') + '</h3>' +
                                '<p>Your event has been ' + (isEditing ? 'updated' : 'submitted') + ' successfully.</p>' +
                                '<div class="hbl-form-success-actions">' +
                                '<a href="' + viewUrl + '" class="hbl-form-btn hbl-form-btn-primary">View Event</a>' +
                                '<a href="/add-event/" class="hbl-form-btn hbl-form-btn-secondary">Add Another Event</a>' +
                                '</div>' +
                                '</div>' +
                                '</div>';

                            $form.fadeOut(300, function () {
                                $widget.append(successHtml);
                                $widget.find('.hbl-form-success-overlay').hide().fadeIn(300);
                            });
                        } else {
                            alert(response.data && response.data.message ? response.data.message : 'Error saving event. Please check all required fields.');
                            if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
                            $submitBtn.prop('disabled', false).html(originalText);
                        }
                    },
                    error: function (xhr, status, error) {
                        alert('Error saving event. Please try again.');
                        if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
                        $submitBtn.prop('disabled', false).html(originalText);
                    }
                });
            });

            var $colorInput = $form.find('.hbl-form-color-input');
            if ($colorInput.length) {
                var initialColor = $colorInput.val();
                $colorInput.closest('.hbl-form-color-wrapper').find('.hbl-form-color-value').text(initialColor.toUpperCase());
            }

            $form.on('input', '.hbl-form-color-input', function () {
                var colorValue = $(this).val();
                $(this).siblings('.hbl-form-color-preview').css('background-color', colorValue);
                $(this).closest('.hbl-form-color-wrapper').find('.hbl-form-color-value').text(colorValue.toUpperCase());
            });

            var mediaUploader = null;
            $form.on('click', '.hbl-upload-image', function (e) {
                e.preventDefault();

                if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                    alert('Media uploader is not available. Please refresh the page.');
                    return;
                }

                var $button = $(this);
                var $imagePreview = $form.find('.hbl-form-image-preview');
                var $hiddenInput = $form.find('#featured_image');
                var $removeButton = $form.find('.hbl-remove-image');

                if (mediaUploader) {
                    mediaUploader.open();
                    return;
                }

                mediaUploader = wp.media({
                    title: 'Choose Event Image',
                    button: {
                        text: 'Choose Image'
                    },
                    multiple: false
                });

                mediaUploader.on('select', function () {
                    var attachment = mediaUploader.state().get('selection').first().toJSON();
                    var imgUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                    $imagePreview.html('<img src="' + imgUrl + '" alt="Event Image">');
                    $hiddenInput.val(attachment.id);
                    $removeButton.show();
                });

                mediaUploader.open();
            });

            $form.on('click', '.hbl-remove-image', function () {
                var $button = $(this);
                var $imagePreview = $form.find('.hbl-form-image-preview');
                var $hiddenInput = $form.find('#featured_image');

                $imagePreview.html('<div class="hbl-form-image-placeholder"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><p>Upload an image to make your event stand out</p></div>');
                $hiddenInput.val('');
                $button.hide();
            });

            var $schedulingType = $form.find('input[name="scheduling_type"]');
            var $dateBlockSingle = $form.find('#date-block-single');
            var $dateBlockMulti = $form.find('#date-block-multi');
            var $frequencyBlock = $form.find('#frequency-block-single');

            function updateSchedulingVisibility() {
                var type = $schedulingType.filter(':checked').val();

                if (type === 'multi') {
                    $dateBlockSingle.hide();
                    $dateBlockMulti.slideDown(200);
                    $frequencyBlock.slideUp(200);

                    $dateBlockSingle.find('input').prop('required', false);
                    $dateBlockMulti.find('input[type="date"], input[type="time"]').prop('required', true);
                    $('#event_frequency').prop('required', false);
                } else {
                    $dateBlockMulti.hide();
                    $dateBlockSingle.slideDown(200);
                    $frequencyBlock.slideDown(200);

                    $dateBlockMulti.find('input').prop('required', false);
                    $dateBlockSingle.find('input[type="date"], input[type="time"]').prop('required', true);
                    $('#event_frequency').prop('required', true);
                }
            }

            updateSchedulingVisibility();

            $schedulingType.on('change', function () {
                updateSchedulingVisibility();
            });

            $form.on('click', '.hbl-form-days-grid-multi .hbl-form-day-option-multi', function (e) {
                e.stopImmediatePropagation();
                var $this = $(this);
                var $container = $this.closest('.hbl-form-days-grid-multi');
                var $hiddenInput = $form.find('#multi_open_days');

                $this.toggleClass('hbl-selected');

                var selectedDays = [];
                $container.find('.hbl-selected').each(function () {
                    selectedDays.push($(this).data('day'));
                });
                $hiddenInput.val(selectedDays.join(','));
            });

            var $frequencySelect = $form.find('#event_frequency');
            var $weeklyOptions = $form.find('.hbl-form-recurrence-weekly');
            var $monthlyOptions = $form.find('.hbl-form-recurrence-monthly');
            var $recurrenceType = $form.find('#recurrence_type');

            function updateRecurrenceVisibility(animate) {
                var frequency = $frequencySelect.val();
                var duration = animate ? 200 : 0;

                if (animate) {
                    $weeklyOptions.slideUp(duration);
                    $monthlyOptions.slideUp(duration);
                } else {
                    $weeklyOptions.hide();
                    $monthlyOptions.hide();
                }

                $weeklyOptions.find('input[type="checkbox"]').prop('required', false);
                $monthlyOptions.find('input[type="checkbox"], input[type="radio"]').prop('required', false);

                if (frequency === 'weekly') {
                    if (animate) {
                        $weeklyOptions.slideDown(duration);
                    } else {
                        $weeklyOptions.show();
                    }
                    $recurrenceType.val('weekly');
                } else if (frequency === 'monthly') {
                    if (animate) {
                        $monthlyOptions.slideDown(duration);
                    } else {
                        $monthlyOptions.show();
                    }
                    $recurrenceType.val('monthly');
                } else {
                    $recurrenceType.val('');
                }
            }

            updateRecurrenceVisibility(false);

            $frequencySelect.on('change', function () {
                updateRecurrenceVisibility(true);
            });

            $form.on('change', '.hbl-form-recurrence-weekly .hbl-form-day-option input, .hbl-form-week-option input', function () {
                var $label = $(this).closest('label');
                if ($(this).is(':checked')) {
                    $label.addClass('hbl-selected');
                } else {
                    $label.removeClass('hbl-selected');
                }
            });

            $form.on('click', '.hbl-form-days-grid-monthly .hbl-form-day-option-monthly', function () {
                var $this = $(this);
                var $container = $this.closest('.hbl-form-days-grid-monthly');
                var $hiddenInput = $form.find('#recurrence_day_monthly');
                var dayValue = $this.data('day');

                if ($this.hasClass('hbl-selected')) {
                    $this.removeClass('hbl-selected');
                    $hiddenInput.val('');
                } else {
                    $container.find('.hbl-form-day-option-monthly').removeClass('hbl-selected');
                    $this.addClass('hbl-selected');
                    $hiddenInput.val(dayValue);
                }
            });

            $form.find('.hbl-form-recurrence-weekly .hbl-form-day-option input:checked, .hbl-form-week-option input:checked').each(function () {
                $(this).closest('label').addClass('hbl-selected');
            });
        });
    }

    function initializeHBLAddListingForm() {
        $('#hbl-listing-form').each(function () {
            var $form = $(this);

            if ($form.data('hbl-listing-form-initialized')) {
                return;
            }
            $form.data('hbl-listing-form-initialized', true);

            $form.on('submit', function (e) {
                e.preventDefault();
                var $submitBtn = $form.find('#hbl-listing-submit-btn');
                var originalText = $submitBtn.html();

				if (typeof grecaptcha !== 'undefined' && $form.find('.g-recaptcha').length) {
					if (!grecaptcha.getResponse()) {
						$form.find('.hbl-recaptcha-error').show();
						return;
					}
					$form.find('.hbl-recaptcha-error').hide();
				}

                $submitBtn.prop('disabled', true).html('<span class="hbl-loading-spinner"></span> Creating Listing...');

                var formData = new FormData(this);
                formData.append('action', 'hbl_save_listing');

                $.ajax({
                    url: hblData.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        if (response.success) {
                            var isEditing = response.data && response.data.is_update;
                            var listingId = response.data && response.data.listing_id ? response.data.listing_id : '';
                            var listingUrl = response.data && response.data.redirect_url ? response.data.redirect_url : '';
                            var requiresPayment = response.data && response.data.requires_payment;

                            if (requiresPayment && listingUrl) {
                                var paymentHtml = '<div class="hbl-form-success-overlay">' +
                                    '<div class="hbl-form-success-content">' +
                                    '<div class="hbl-form-success-icon hbl-form-payment-icon">' +
                                    '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                                    '<rect x="1" y="4" width="22" height="16" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>' +
                                    '<path d="M1 10H23" stroke="currentColor" stroke-width="2"/>' +
                                    '</svg>' +
                                    '</div>' +
                                    '<h3>Listing Saved!</h3>' +
                                    '<p>Redirecting to checkout to complete your payment...</p>' +
                                    '<div class="hbl-loading-spinner"></div>' +
                                    '</div>' +
                                    '</div>';

                                $form.fadeOut(200, function () {
                                    $form.after(paymentHtml);
                                    var $paymentOverlay = $form.next('.hbl-form-success-overlay');
                                    $paymentOverlay.fadeIn(200);

                                    setTimeout(function () {
                                        window.location.href = listingUrl;
                                    }, 1500);
                                });
                                return;
                            }

                            var successHtml = '<div class="hbl-form-success-overlay">' +
                                '<div class="hbl-form-success-content">' +
                                '<div class="hbl-form-success-icon">' +
                                '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                                '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>' +
                                '<path d="M9 12L11 14L15 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                                '</svg>' +
                                '</div>' +
                                '<h3>' + (isEditing ? 'Listing Updated!' : 'Listing Submitted!') + '</h3>' +
                                '<p>' + (response.data.message || 'Your listing has been ' + (isEditing ? 'updated' : 'submitted') + ' successfully.') + '</p>' +
                                '<div class="hbl-form-success-actions">' +
                                (listingUrl ? '<a href="' + listingUrl + '" class="hbl-form-btn hbl-form-btn-primary">View Listing</a>' : '') +
                                '<a href="/add-listing/" class="hbl-form-btn hbl-form-btn-secondary">Add Another Listing</a>' +
                                '</div>' +
                                '</div>' +
                                '</div>';

                            $form.fadeOut(300, function () {
                                $form.after(successHtml);
                                var $successOverlay = $form.next('.hbl-form-success-overlay');
                                $successOverlay.fadeIn(300);

                                if (listingUrl) {
                                    setTimeout(function () {
                                        window.location.href = listingUrl;
                                    }, 3000);
                                }
                            });
					} else {
						alert(response.data.message || 'Error creating listing.');
						if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
						$submitBtn.prop('disabled', false).html(originalText);
					}
				},
				error: function () {
					alert('Error creating listing. Please try again.');
					if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
					$submitBtn.prop('disabled', false).html(originalText);
				}
                });
            });

            var listingMediaUploader = null;
            $form.on('click', '.hbl-upload-listing-image', function (e) {
                e.preventDefault();

                if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                    alert('Media uploader is not available. Please refresh the page.');
                    return;
                }

                var $imagePreview = $('#hbl-listing-image-preview');
                var $hiddenInput = $('#listing_image');
                var $removeButton = $form.find('.hbl-remove-listing-image');

                if (listingMediaUploader) {
                    listingMediaUploader.open();
                    return;
                }

                listingMediaUploader = wp.media({
                    title: 'Choose Business Image',
                    button: {
                        text: 'Choose Image'
                    },
                    multiple: false
                });

                listingMediaUploader.on('select', function () {
                    var attachment = listingMediaUploader.state().get('selection').first().toJSON();
                    var imgUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                    $imagePreview.html('<img src="' + imgUrl + '" alt="Business Image" style="max-width: 100%; height: auto; border-radius: 12px;">');
                    $hiddenInput.val(attachment.id);
                    $removeButton.show();
                });

                listingMediaUploader.open();
            });

            $form.on('click', '.hbl-remove-listing-image', function () {
                var $button = $(this);
                var $imagePreview = $('#hbl-listing-image-preview');
                var $hiddenInput = $('#listing_image');

                $imagePreview.html('<div class="hbl-form-image-placeholder"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><p>Upload your business logo or a featured image</p></div>');
                $hiddenInput.val('');
                $button.hide();
            });

            $form.on('change', '#listing_pricing_type', function () {
                var pricingType = $(this).val();
                var $exactPrice = $form.find('.hbl-pricing-exact');
                var $priceRange = $form.find('.hbl-pricing-range');

                $exactPrice.hide();
                $priceRange.hide();

                if (pricingType === 'price') {
                    $exactPrice.slideDown(200);
                } else if (pricingType === 'range') {
                    $priceRange.slideDown(200);
                }
            });

            $form.on('click', '#hbl-add-service-btn', function () {
                var $servicesList = $('#hbl-services-list');
                var serviceItemHtml = '<div class="hbl-service-item">' +
                    '<div class="hbl-form-input-wrapper">' +
                    '<input type="text" name="listing_services[]" class="hbl-form-input" placeholder="Enter a service (e.g., Home Delivery)">' +
                    '</div>' +
                    '<button type="button" class="hbl-remove-service-btn" title="Remove">' +
                    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                    '<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                    '</svg></button></div>';
                $servicesList.append(serviceItemHtml);
            });

            $form.on('click', '.hbl-remove-service-btn', function () {
                var $item = $(this).closest('.hbl-service-item');
                var $servicesList = $('#hbl-services-list');

                if ($servicesList.find('.hbl-service-item').length > 1) {
                    $item.fadeOut(200, function () {
                        $(this).remove();
                    });
                } else {
                    $item.find('input').val('');
                }
            });

            $form.on('click', '#hbl-add-pricing-btn', function () {
                var $pricingList = $('#hbl-pricing-list');
                var pricingItemHtml = '<div class="hbl-service-item">' +
                    '<div class="hbl-form-input-wrapper">' +
                    '<input type="text" name="listing_pricing[]" class="hbl-form-input" placeholder="Enter pricing option (e.g., Basic Wash - $25)">' +
                    '</div>' +
                    '<button type="button" class="hbl-remove-pricing-btn" title="Remove">' +
                    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                    '<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                    '</svg></button></div>';
                $pricingList.append(pricingItemHtml);
            });

            $form.on('click', '.hbl-remove-pricing-btn', function () {
                var $item = $(this).closest('.hbl-service-item');
                var $pricingList = $('#hbl-pricing-list');

                if ($pricingList.find('.hbl-service-item').length > 1) {
                    $item.fadeOut(200, function () {
                        $(this).remove();
                    });
                } else {
                    $item.find('input').val('');
                }
            });

            $form.on('change', '#manual_coordinate', function () {
                var $coordsWrapper = $form.find('.hbl-form-coordinates-wrapper');
                if ($(this).is(':checked')) {
                    $coordsWrapper.slideDown(200);
                } else {
                    $coordsWrapper.slideUp(200);
                }
            });

            var galleryMediaUploader = null;
            var galleryImages = window.hblExistingGalleryIds || [];

            $form.on('click', '#hbl-gallery-add-btn', function (e) {
                e.preventDefault();

                if ($(this).hasClass('hbl-btn-disabled')) {
                    alert('Please upgrade your plan to add gallery images.');
                    return;
                }

                if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                    alert('Media uploader is not available. Please refresh the page.');
                    return;
                }

                var maxImages = parseInt($form.data('max-images')) || 0;

                if (maxImages > 0 && galleryImages.length >= maxImages) {
                    alert('You have reached the maximum number of images allowed for your plan (' + maxImages + ' images).');
                    return;
                }

                if (galleryMediaUploader) {
                    galleryMediaUploader.open();
                    return;
                }

                galleryMediaUploader = wp.media({
                    title: 'Select Gallery Images',
                    button: {
                        text: 'Add to Gallery'
                    },
                    multiple: true
                });

                galleryMediaUploader.on('select', function () {
                    var attachments = galleryMediaUploader.state().get('selection').toJSON();
                    var $galleryPreview = $('#hbl-listing-gallery-preview');
                    var $hiddenInput = $('#listing_gallery');

                    var currentMax = parseInt($form.data('max-images')) || 0;
                    var addedCount = 0;

                    attachments.forEach(function (attachment) {
                        if (galleryImages.indexOf(attachment.id) !== -1) {
                            return;
                        }

                        if (currentMax > 0 && galleryImages.length >= currentMax) {
                            if (addedCount === 0) {
                                alert('You have reached the maximum number of images allowed for your plan (' + currentMax + ' images).');
                            }
                            return;
                        }

                        galleryImages.push(attachment.id);
                        addedCount++;

                        var imgUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

                        var galleryItemHtml = '<div class="hbl-form-gallery-item" data-id="' + attachment.id + '">' +
                            '<img src="' + imgUrl + '" alt="Gallery Image">' +
                            '<button type="button" class="hbl-form-gallery-item-remove" title="Remove">' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                            '<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                            '</svg></button></div>';

                        $galleryPreview.append(galleryItemHtml);
                    });

                    $hiddenInput.val(galleryImages.join(','));

                    $(document).trigger('hbl-gallery-updated');
                });

                galleryMediaUploader.open();
            });

            $form.on('click', '.hbl-form-gallery-item-remove', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var $item = $(this).closest('.hbl-form-gallery-item');
                var imageId = parseInt($item.data('id'));
                var $hiddenInput = $('#listing_gallery');

                var index = galleryImages.indexOf(imageId);
                if (index > -1) {
                    galleryImages.splice(index, 1);
                }

                $hiddenInput.val(galleryImages.join(','));

                $item.fadeOut(200, function () {
                    $(this).remove();
                    $(document).trigger('hbl-gallery-updated');
                });
            });

            var listingMap = null;
            var listingMarker = null;
            var defaultLat = -25.2985784;
            var defaultLng = 152.8535216;

            function initListingMap(forceReinit) {
                var $mapContainer = $('#hbl-listing-map');
                if ($mapContainer.length === 0 || typeof L === 'undefined') {
                    return;
                }

                var $mapSection = $('#hbl-section-map');
                if ($mapSection.hasClass('hbl-section-restricted')) {
                    return;
                }

                if (listingMap && !forceReinit) {
                    listingMap.invalidateSize();
                    return;
                }

                if (listingMap && forceReinit) {
                    listingMap.remove();
                    listingMap = null;
                    listingMarker = null;
                }

                try {
                    listingMap = L.map('hbl-listing-map').setView([defaultLat, defaultLng], 13);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    }).addTo(listingMap);

                    listingMarker = L.marker([defaultLat, defaultLng], {
                        draggable: true
                    }).addTo(listingMap);

                    listingMarker.on('dragend', function (e) {
                        var position = listingMarker.getLatLng();
                        $('#listing_lat').val(position.lat.toFixed(7));
                        $('#listing_lng').val(position.lng.toFixed(7));
                    });

                    listingMap.on('click', function (e) {
                        listingMarker.setLatLng(e.latlng);
                        $('#listing_lat').val(e.latlng.lat.toFixed(7));
                        $('#listing_lng').val(e.latlng.lng.toFixed(7));
                    });

                    setTimeout(function () {
                        if (listingMap) {
                            listingMap.invalidateSize();
                        }
                    }, 100);
                } catch (e) {
                }
            }

            $(window).on('hbl-reinit-map', function () {
                setTimeout(function () {
                    initListingMap(true);
                }, 100);
            });

            $form.on('click', '#hbl-generate-map', function () {
                var lat = parseFloat($('#listing_lat').val());
                var lng = parseFloat($('#listing_lng').val());

                if (!isNaN(lat) && !isNaN(lng) && listingMap && listingMarker) {
                    listingMap.setView([lat, lng], 15);
                    listingMarker.setLatLng([lat, lng]);
                } else {
                    alert('Please enter valid latitude and longitude values.');
                }
            });

            setTimeout(function () {
                initListingMap();
            }, 500);

            function geocodeAddress(address) {
                if (!address || typeof L === 'undefined' || !listingMap) {
                    return;
                }

                $.ajax({
                    url: 'https://nominatim.openstreetmap.org/search',
                    data: {
                        q: address,
                        format: 'json',
                        limit: 1
                    },
                    success: function (results) {
                        if (results && results.length > 0) {
                            var lat = parseFloat(results[0].lat);
                            var lng = parseFloat(results[0].lon);

                            listingMap.setView([lat, lng], 15);
                            listingMarker.setLatLng([lat, lng]);
                            $('#listing_lat').val(lat.toFixed(7));
                            $('#listing_lng').val(lng.toFixed(7));
                        } else {
                            alert('Address not found. Please try a different address or manually place the marker on the map.');
                        }
                    },
                    error: function () {
                        alert('Error searching for address. Please try again.');
                    }
                });
            }

            $form.on('click', '#hbl-search-address-btn', function () {
                var address = $('#listing_map_address').val();
                geocodeAddress(address);
            });

            $form.on('keypress', '#listing_map_address', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    var address = $(this).val();
                    geocodeAddress(address);
                }
            });

            $form.on('blur', '#listing_address', function () {
                var address = $(this).val();
                if (address && !$('#listing_map_address').val()) {
                    geocodeAddress(address);
                }
            });
        });
    }

    function initializeHBLSigninSignupForm() {
        $('.hbl-signin-signup-form-widget').each(function () {
            var $widget = $(this);

            if ($widget.data('hbl-auth-initialized')) {
                return;
            }
            $widget.data('hbl-auth-initialized', true);

            var defaultTab = $widget.data('default-tab') || 'signin';

            function activateTab(tab) {
                $widget.find('.hbl-auth-tab').removeClass('active');
                $widget.find('.hbl-auth-tab[data-tab="' + tab + '"]').addClass('active');
                $widget.find('.hbl-auth-form').removeClass('active');
                $widget.find('.hbl-' + tab + '-form').addClass('active');
            }

            $widget.on('click', '.hbl-auth-tab', function () {
                activateTab($(this).data('tab'));
            });

            try {
                var urlTab = new URLSearchParams(window.location.search).get('tab');
                if (urlTab === 'signup' || urlTab === 'register') {
                    if ($widget.find('.hbl-auth-tab[data-tab="signup"]').length) {
                        activateTab('signup');
                    }
                } else if (urlTab === 'signin' || urlTab === 'login') {
                    activateTab('signin');
                }
            } catch (e) {}

            $widget.on('click', '.hbl-form-password-toggle', function () {
                var $button = $(this);
                var $input = $button.siblings('input');
                var type = $input.attr('type') === 'password' ? 'text' : 'password';
                $input.attr('type', type);

                if (type === 'text') {
                    $button.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17.94 17.94C16.2306 19.243 14.1491 19.9649 12 20C5 20 1 12 1 12C2.24389 9.68192 3.96914 7.65663 6.06 6.06M9.9 4.24C10.5883 4.0789 11.2931 3.99836 12 4C19 4 23 12 23 12C22.393 13.1356 21.6691 14.2048 20.84 15.19M14.12 14.12C13.8454 14.4148 13.5141 14.6512 13.1462 14.8151C12.7782 14.9791 12.3809 15.0673 11.9781 15.0744C11.5753 15.0815 11.1751 15.0074 10.8016 14.8565C10.4281 14.7056 10.0887 14.4811 9.80385 14.1962C9.51897 13.9113 9.29439 13.572 9.14351 13.1984C8.99262 12.8249 8.91853 12.4247 8.92563 12.0219C8.93274 11.6191 9.02091 11.2218 9.18488 10.8538C9.34884 10.4859 9.58525 10.1546 9.88 9.88" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M1 1L23 23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>');
                } else {
                    $button.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 12S5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>');
                }
            });

            $widget.find('.hbl-signin-form').on('submit', function (e) {
                e.preventDefault();
                var $form = $(this);
                var $submitBtn = $form.find('button[type="submit"]');
                var originalText = $submitBtn.html();

				if (typeof grecaptcha !== 'undefined' && $form.find('.g-recaptcha').length) {
					if (!grecaptcha.getResponse()) {
						$form.find('.hbl-recaptcha-error').show();
						return;
					}
					$form.find('.hbl-recaptcha-error').hide();
				}

				$submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Signing in...');

				$.ajax({
					url: hblData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'hbl_ajax_login',
						username: $form.find('#signin_username').val(),
						password: $form.find('#signin_password').val(),
						rememberme: $form.find('[name="rememberme"]').is(':checked') ? 1 : 0,
						security: $form.find('[name="security"]').val(),
						redirect_to: $form.find('[name="redirect_to"]').val(),
						'g-recaptcha-response': (typeof grecaptcha !== 'undefined') ? grecaptcha.getResponse() : ''
					},
					success: function (response) {
						if (response.success) {
							$widget.find('.hbl-form-messages').html('<div class="hbl-form-notice hbl-form-notice-success">' + (response.data.message || 'Login successful! Redirecting...') + '</div>');
							if (response.data.redirect) {
								window.location.href = response.data.redirect;
							} else {
								setTimeout(function () {
									location.reload();
								}, 1500);
							}
						} else {
							$widget.find('.hbl-form-messages').html('<div class="hbl-form-notice hbl-form-notice-error">' + (response.data.message || 'Login failed. Please try again.') + '</div>');
							if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
							$submitBtn.prop('disabled', false).html(originalText);
						}
					},
					error: function () {
						$widget.find('.hbl-form-messages').html('<div class="hbl-form-notice hbl-form-notice-error">Error occurred. Please try again.</div>');
						if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
					$submitBtn.prop('disabled', false).html(originalText);
				}
			});
		});

        $widget.find('.hbl-signup-form').on('submit', function (e) {
                e.preventDefault();
                var $form = $(this);
                var $submitBtn = $form.find('button[type="submit"]');
                var originalText = $submitBtn.html();

                $submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Creating account...');

                $.ajax({
                    url: hblData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'hbl_ajax_register',
                        user_login: $form.find('#signup_username').val(),
                        user_email: $form.find('#signup_email').val(),
                        user_pass: $form.find('#signup_password').val(),
                        security: $form.find('[name="security"]').val()
                    },
                    success: function (response) {
                        if (response.success) {
                            $widget.find('.hbl-form-messages').html('<div class="hbl-form-notice hbl-form-notice-success">' + (response.data.message || 'Registration successful! Redirecting...') + '</div>');
                            if (response.data.redirect) {
                                window.location.href = response.data.redirect;
                            } else {
                                setTimeout(function () {
                                    location.reload();
                                }, 1500);
                            }
                        } else {
                            $widget.find('.hbl-form-messages').html('<div class="hbl-form-notice hbl-form-notice-error">' + (response.data.message || 'Registration failed. Please try again.') + '</div>');
                            $submitBtn.prop('disabled', false).html(originalText);
                        }
                    },
                    error: function () {
                        $widget.find('.hbl-form-messages').html('<div class="hbl-form-notice hbl-form-notice-error">Error occurred. Please try again.</div>');
                        $submitBtn.prop('disabled', false).html(originalText);
                    }
                });
            });
        });
    }

    $(document).on('click', '#hbl-upload-profile-image', function (e) {
        e.preventDefault();
        e.stopPropagation();

        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            alert('Media uploader is not available. Please refresh the page and try again.');
            return;
        }

        var $widget = $(this).closest('.hbl-dashboard-widget');

        var frame = wp.media({
            title: 'Select Profile Photo',
            button: { text: 'Use This Photo' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var imageUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

            $widget.find('#hbl-profile-image-img').attr('src', imageUrl);
            $widget.find('#hbl-profile-image-input').val(attachment.id);

            $widget.find('#hbl-save-profile-image').show();

            if ($widget.find('#hbl-remove-profile-image').length === 0) {
                $widget.find('#hbl-profile-image-preview').append(
                    '<button type="button" class="hbl-dashboard-profile-image-remove" id="hbl-remove-profile-image" title="Remove Photo">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                    '<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                    '</svg></button>'
                );
            }
        });

        frame.open();
    });

    $(document).on('click', '#hbl-remove-profile-image', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $widget = $(this).closest('.hbl-dashboard-widget');
        var gravatarUrl = $widget.find('#hbl-profile-image-img').data('gravatar') ||
            HBL_GRAVATAR_FALLBACK;

        $widget.find('#hbl-profile-image-img').attr('src', gravatarUrl);
        $widget.find('#hbl-profile-image-input').val('');
        $(this).remove();

        $widget.find('#hbl-save-profile-image').show();
    });

    $(document).on('click', '#hbl-save-profile-image', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var $widget = $(this).closest('.hbl-dashboard-widget');
        var $form = $widget.find('.hbl-dashboard-profile-form');
        var profileImageId = $widget.find('#hbl-profile-image-input').val();
        var nonce = $form.find('#hbl_profile_nonce').val();

        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

        $.ajax({
            url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'hbl_save_profile_image',
                profile_image: profileImageId,
                hbl_profile_nonce: nonce
            },
            success: function (response) {
                if (response.success) {
                    $btn.html(HBL_SAVED_SVG + 'Saved!');
                    setTimeout(function () {
                        $btn.html(originalHtml).prop('disabled', false).hide();
                    }, 2000);
                } else {
                    alert(response.data || 'Error saving profile image.');
                    $btn.html(originalHtml).prop('disabled', false);
                }
            },
            error: function () {
                alert('Error saving profile image. Please try again.');
                $btn.html(originalHtml).prop('disabled', false);
            }
        });
    });

    $(document).on('click', '.hbl-favorite-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var itemId = $btn.data('listing-id') || $btn.data('item-id');
        var itemType = $btn.data('type') || 'listing';
        var $svg = $btn.find('svg');

        $btn.addClass('loading').prop('disabled', true);

        $.ajax({
            url: hblData.ajaxUrl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'hbl_toggle_favorite',
                nonce: hblData.nonce || '',
                item_id: itemId,
                item_type: itemType
            },
            success: function (response) {
                $btn.removeClass('loading').prop('disabled', false);

                if (response.success) {
                    if (response.data.is_favorited) {
                        $btn.addClass('is-favorited');
                        $svg.attr('fill', '#ffffff');
                        $btn.attr('title', 'Remove from Favorites');
                    } else {
                        $btn.removeClass('is-favorited');
                        $svg.attr('fill', 'none');
                        $btn.attr('title', 'Add to Favorites');
                    }
                } else {
                    if (response.data && response.data.login_required) {
                        var loginUrl = hblData.loginUrl || '/sign-in/';
                        var returnUrl = encodeURIComponent(window.location.href);
                        window.location.href = loginUrl + '?redirect_to=' + returnUrl;
                    } else {
                        alert(response.data.message || 'Error updating favorites.');
                    }
                }
            },
            error: function () {
                $btn.removeClass('loading').prop('disabled', false);
                alert('Error updating favorites. Please try again.');
            }
        });
    });
    (function () {
        var lightboxHtml = '<div id="hbl-lightbox" class="hbl-lightbox">' +
            '<button class="hbl-lightbox-close">&times;</button>' +
            '<button class="hbl-lightbox-prev"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
            '<button class="hbl-lightbox-next"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
            '<div class="hbl-lightbox-content">' +
            '<div class="hbl-lightbox-loader" style="display: none;"><div class="hbl-loading-spinner"></div></div>' +
            '<img src="" alt="" class="hbl-lightbox-image">' +
            '</div>' +
            '<div class="hbl-lightbox-counter"></div>' +
            '</div>';

        $('body').append(lightboxHtml);

        var $lightbox = $('#hbl-lightbox');
        var $lightboxImg = $lightbox.find('.hbl-lightbox-image');
        var $lightboxLoader = $lightbox.find('.hbl-lightbox-loader');
        var $counter = $lightbox.find('.hbl-lightbox-counter');
        var galleryImages = [];
        var currentIndex = 0;

        function openLightbox(images, index) {
            galleryImages = images;
            currentIndex = index;
            showImage(currentIndex);
            $lightbox.addClass('active');
            $('body').addClass('hbl-lightbox-open');
        }

        function closeLightbox() {
            $lightbox.removeClass('active');
            $('body').removeClass('hbl-lightbox-open');
        }

        function showImage(index) {
            if (index < 0) index = galleryImages.length - 1;
            if (index >= galleryImages.length) index = 0;
            currentIndex = index;

            $lightboxLoader.show();
            $lightboxImg.css('opacity', '0');
            $lightboxImg.attr('src', '');

            if (!galleryImages || galleryImages.length === 0) {
                $lightboxLoader.hide();
                closeLightbox();
                return;
            }

            var imageUrl = galleryImages[currentIndex];
            if (!imageUrl || imageUrl === 'undefined' || imageUrl === '') {
                $lightboxLoader.hide();
                return;
            }

            var img = new Image();
            img.onload = function () {
                $lightboxImg.attr('src', imageUrl);
                $lightboxImg.css('opacity', '1');
                $lightboxLoader.hide();
            };
            img.onerror = function () {
                $lightboxLoader.hide();
                $lightboxImg.attr('src', imageUrl);
                $lightboxImg.css('opacity', '1');
            };
            img.src = imageUrl;

            $counter.text((currentIndex + 1) + ' / ' + galleryImages.length);

            if (galleryImages.length <= 1) {
                $lightbox.find('.hbl-lightbox-prev, .hbl-lightbox-next').hide();
                $counter.hide();
            } else {
                $lightbox.find('.hbl-lightbox-prev, .hbl-lightbox-next').show();
                $counter.show();
            }
        }

        $(document).on('click', '.hbl-single-listing-gallery-item', function (e) {
            e.preventDefault();

            var $gallery = $(this).closest('.hbl-single-listing-gallery');
            var images = [];

            $gallery.find('.hbl-single-listing-gallery-item').each(function () {
                var href = $(this).attr('href');
                if (href && href !== '' && href !== 'undefined') {
                    images.push(href);
                }
            });

            if (images.length === 0) {
                return;
            }

            var index = $gallery.find('.hbl-single-listing-gallery-item').index(this);
            if (index < 0) index = 0;

            openLightbox(images, index);
        });

        $lightbox.on('click', '.hbl-lightbox-close', closeLightbox);
        $lightbox.on('click', function (e) {
            if ($(e.target).hasClass('hbl-lightbox')) {
                closeLightbox();
            }
        });

        $lightbox.on('click', '.hbl-lightbox-prev', function (e) {
            e.stopPropagation();
            showImage(currentIndex - 1);
        });
        $lightbox.on('click', '.hbl-lightbox-next', function (e) {
            e.stopPropagation();
            showImage(currentIndex + 1);
        });

        $(document).on('keydown', function (e) {
            if (!$lightbox.hasClass('active')) return;

            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') showImage(currentIndex - 1);
            if (e.key === 'ArrowRight') showImage(currentIndex + 1);
        });
    })();

})(jQuery);

