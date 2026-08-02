//Request Google Analytics
jQuery(document).ready(function ($) {
    //Ajax
    $('.spinner').css("visibility", "visible");
    $('.spinner').css("float", "none");
    $.ajax({
        method: 'GET',
        url: seopressAjaxRequestGoogleAnalytics.seopress_request_google_analytics,
        data: {
            action: 'seopress_request_google_analytics',
            _ajax_nonce: seopressAjaxRequestGoogleAnalytics.seopress_nonce,
        },
        success: function (data) {
            // Mark a pending reload on submit so the widget rebuilds with
            // fresh data once the cron transient was invalidated by save.
            // sessionStorage is used instead of `window.location.hash` to
            // avoid polluting the URL with `&`-separated tokens that WP
            // core's site-health.min.js feeds to `$()` as a CSS selector.
            $('#seopress_ga_dashboard_widget #submit').on('click', function () {
                try { window.sessionStorage.setItem('seopress_ga_widget_reload_pending', '1'); } catch (e) {}
            });
            try {
                if (window.sessionStorage.getItem('seopress_ga_widget_reload_pending') === '1'
                    && $('#seopress_ga_dashboard_widget .inside').length === 1) {
                    window.sessionStorage.removeItem('seopress_ga_widget_reload_pending');
                    window.location.reload(true);
                }
            } catch (e) {}

            if (data.success) {
                $('#seopress-ga-sessions').html(data.data.sessions);
                $('#seopress-ga-users').html(data.data.users);
                $('#seopress-ga-pageviews').html(data.data.pageviews);
                $('#seopress-ga-pageviewsPerSession').html(data.data.pageviewsPerSession);
                $('#seopress-ga-avgSessionDuration').html(data.data.avgSessionDuration);
                $('#seopress-ga-bounceRate').html(data.data.bounceRate + '%');
                $('#seopress-ga-percentNewSessions').html(data.data.percentNewSessions + '%');

                //Graph
                if (typeof ctxseopress !== 'undefined') {
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
                    var myLineChart = new Chart(ctxseopress, {
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
