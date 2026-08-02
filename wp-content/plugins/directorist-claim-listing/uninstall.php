<?php
/**
 * @package Directorist Pricing Plans
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
// Access the database via SQL
global $wpdb;
include_once("directorist-claim-listing.php");
$enable_uninstall = get_directorist_option('enable_uninstall',0);
if(!empty($enable_uninstall)) {
    // Delete posts + data.
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'dcl_claim_listing';");

    //Delete all metabox
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id Not IN  (SELECT id FROM {$wpdb->posts})");

    delete_option('widget_dcl_widget');
}