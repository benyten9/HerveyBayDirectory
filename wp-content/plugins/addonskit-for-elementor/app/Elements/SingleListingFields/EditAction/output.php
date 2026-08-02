<?php
/**
 * Author: wpWax
 * Since: 6.6
 * Version: 7.7.0
 */

use AddonskitForElementor\Utils\Helper;
use Directorist\Directorist_Single_Listing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$listing = Directorist_Single_Listing::instance( $id );
$is_edit = Helper::is_edit();
?>

<div class="directorist-single-listing-top directorist-flex directorist-align-center directorist-justify-content-between">

    <?php if ( $is_edit || ( $listing->display_back_link() && ( empty( $actions ) || in_array( 'back', $actions, true ) ) ) ): ?>
        <a href="javascript:history.back()" class="directorist-single-listing-action directorist-return-back directorist-btn directorist-btn-sm directorist-btn-light">
            <?php directorist_icon( 'las la-arrow-left' );?>
            <span class="directorist-single-listing-action__text"><?php esc_html_e( 'Go Back', 'addonskit-for-elementor' );?></span>
        </a>
    <?php endif;?>

    <div class="directorist-single-listing-quick-action directorist-flex directorist-align-center directorist-justify-content-between">

        <?php if ( $is_edit || ( $listing->submit_link() && ( empty( $actions ) || in_array( 'continue', $actions, true ) ) ) ): ?>
            <a href="<?php echo esc_url( $listing->submit_link() ); ?>" class="directorist-single-listing-action directorist-btn directorist-btn-sm directorist-btn-light directorist-signle-listing-top__btn-continue">
                <span class="directorist-single-listing-action__text"><?php esc_html_e( 'Continue', 'addonskit-for-elementor' );?></span>
            </a>
        <?php endif;?>

        <?php if ( $is_edit || ( empty( $actions ) || in_array( 'edit', $actions, true ) ) ): ?>
            <a href="<?php echo esc_url( $listing->edit_link() ) ?>" class="directorist-single-listing-action directorist-btn directorist-btn-sm directorist-btn-light directorist-signle-listing-top__btn-edit">
                <?php directorist_icon( 'las la-pen' );?>
                <span class="directorist-single-listing-action__text"><?php esc_html_e( 'Edit', 'addonskit-for-elementor' );?></span>
            </a>
        <?php endif;?>

    </div>

</div>
