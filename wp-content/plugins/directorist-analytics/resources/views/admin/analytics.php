<?php
/**
 * Analytics Dashboard Template
 * 
 * @package Directorist Analytics
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="directorist-analytics-wrap">
    <!-- React App will mount here -->
    <div id="directorist-analytics-root"></div>
    
    <!-- Fallback content if JavaScript is disabled -->
    <noscript>
        <div style="padding: 20px; background: #fff; border-radius: 8px; margin: 20px 0;">
            <h2><?php esc_html_e( 'JavaScript Required', 'directorist-advanced-review' ); ?></h2>
            <p><?php esc_html_e( 'Please enable JavaScript to use the Analytics Dashboard.', 'directorist-advanced-review' ); ?></p>
        </div>
    </noscript>
</div>