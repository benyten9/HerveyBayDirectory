//Request Matomo Analytics
jQuery(document).ready(function ($) {
    //Ajax
    $('.spinner').css("visibility", "visible");
    $('.spinner').css("float", "none");
    $.ajax({
        method: 'GET',
        url: seopressAjaxRequestMatomoAnalytics.seopress_request_matomo_analytics,
        data: {
            action: 'seopress_request_matomo_analytics',
            _ajax_nonce: seopressAjaxRequestMatomoAnalytics.seopress_nonce,
        },
        success: function (data) {
            // Mark a pending reload on submit so the widget rebuilds with
            // fresh data once the cron transient was invalidated by save.
            // sessionStorage is used instead of `window.location.hash` to
            // avoid polluting the URL with `&`-separated tokens that WP
            // core's site-health.min.js feeds to `$()` as a CSS selector.
            $('#seopress_matomo_dashboard_widget #submit').on('click', function () {
                try { window.sessionStorage.setItem('seopress_matomo_widget_reload_pending', '1'); } catch (e) {}
            });
            try {
                if (window.sessionStorage.getItem('seopress_matomo_widget_reload_pending') === '1'
                    && $('#seopress_matomo_dashboard_widget .inside').length === 1) {
                    window.sessionStorage.removeItem('seopress_matomo_widget_reload_pending');
                    window.location.reload(true);
                }
            } catch (e) {}

            if (data.success) {
                //Graph
                if (typeof ctxseopress_matomo !== 'undefined') {
                    var data = {
                        labels: data.data.sessions_graph_labels,
                        datasets: [
                            {
                                label: data.data.sessions_graph_title,
                                fill: true,
                                lineTension: 0.1,
                                backgroundColor: "#9ED8FF",
                                borderColor: "#2C97DF",
                                borderCapStyle: 'butt',
                                borderDash: [],
                                borderDashOffset: 0.0,
                                borderJoinStyle: 'miter',
                                pointBorderColor: "#2C97DF",
                                pointBackgroundColor: "#9ED8FF",
                                pointBorderWidth: 1,
                                pointHoverRadius: 5,
                                pointHoverBackgroundColor: "#9ED8FF",
                                pointHoverBorderColor: "#2C97DF",
                                pointHoverBorderWidth: 2,
                                pointRadius: 2,
                                pointHitRadius: 10,
                                data: data.data.sessions_graph_data,
                                spanGaps: false,
                            }
                        ]
                    };
                    var myLineChart = new Chart(ctxseopress_matomo, {
                        type: 'line',
                        data: data,
                        options: {
                            scales: {
                                x: {
                                    display: false
                                }
                            }
                        }
                    });
                }
            }
        },
        complete: function () {
            $('.spinner').css("visibility", "hidden");
        }
    });
});
