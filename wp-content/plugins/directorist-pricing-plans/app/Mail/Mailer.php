<?php

namespace DirectoristPricingPlan\App\Mail;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\App;
use DirectoristPricingPlan\WpMVC\View\View;

abstract class Mailer {
    /**
     * Send email.
     *
     * @param string|array $to Recipient email address(es)
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param array $headers Optional email headers
     * @return bool Whether the email was sent successfully
     */
    protected function send( $to, string $subject, string $body, array $headers = [] ): bool {
        try {
            $default_headers = $this->get_default_headers();
            $headers         = array_merge( $default_headers, $headers );

            $result = wp_mail( $to, $subject, $body, $headers );

            if ( ! $result ) {
                error_log(
                    sprintf( 
                        'Directorist Pricing Plans: Failed to send email to %s with subject: %s', 
                        is_array( $to ) ? implode( ', ', $to ) : $to, 
                        $subject 
                    ) 
                );
            }

            return $result;
        } catch ( \Exception $e ) {
            error_log(
                sprintf( 
                    'Directorist Pricing Plans: Email send exception - %s', 
                    $e->getMessage() 
                ) 
            );
            return false;
        }
    }

    /**
     * Render email template with data.
     *
     * @param string $template_file Path to template file relative to plugin root
     * @param array $data Data to replace in template
     * @return string Rendered template content
     */
    public function render_template( string $template_file, array $data ): string {
        $path          = "emails/{$template_file}.html";
        $template_file = App::get_dir( "resources/views/{$path}" );

        if ( ! is_file( $template_file ) ) {
            error_log(
                sprintf( 
                    'Directorist Pricing Plans: Email template not found: %s', 
                    $template_file 
                ) 
            );
            return '';
        }

        return $this->replace_placeholders( View::get( $path, $data ), $data );
    }

    /**
     * Replace placeholders in content with actual values.
     *
     * @param string $content Content with placeholders
     * @param array $data Data to replace placeholders with
     * @return string Content with replaced placeholders
     */
    protected function replace_placeholders( string $content, array $data ): string {
        foreach ( $data as $key => $value ) {
            $placeholder = '{{' . $key . '}}';
            $content     = str_replace( $placeholder, $value, $content );
        }

        return $content;
    }

    /**
     * Get default email headers.
     *
     * @return array Default headers
     */
    protected function get_default_headers(): array {
        $site_name   = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $site_name, $admin_email ),
        ];

        return apply_filters( 'directorist_pricing_plans_email_headers', $headers );
    }

    /**
     * Get common placeholder data.
     *
     * @return array Common placeholder data
     */
    protected function get_common_placeholders(): array {
        return [
            'site_name' => get_bloginfo( 'name' ),
            'site_url'  => get_site_url(),
            'year'      => date( 'Y' ),
        ];
    }

    /**
     * Format date for display.
     *
     * @param mixed $date DateTime object or timestamp
     * @return string Formatted date
     */
    protected function format_date( $date ): string {
        if ( $date instanceof DateTime ) {
            return $date->format( 'F j, Y' );
        }

        if ( is_numeric( $date ) ) {
            return date( 'F j, Y', $date );
        }

        if ( is_string( $date ) ) {
            return date( 'F j, Y', strtotime( $date ) );
        }

        return '';
    }

    /**
     * Get dashboard URL from Directorist settings.
     *
     * @return string Dashboard URL
     */
    protected function get_dashboard_url(): string {
        // Try to use Directorist's permalink class if available
        if ( class_exists( 'ATBDP_Permalink' ) ) {
            return \ATBDP_Permalink::get_dashboard_page_link();
        }

        // Final fallback to home URL
        return home_url( '/' );
    }
}

