<?php

defined( "ABSPATH" ) || exit;

/**
 * @var int $total_due
 */
?>
<tr class="directorist-summery-total">
    <td colspan="2" class="">
        <span class="directorist-summery-label"><?php printf( esc_html__( 'Total due today', 'directorist-pricing-plans' ) ); ?></h4>
    </td>
    <td class="directorist-text-right">
        <div class="directorist-summery-amount"><?php echo wp_kses_post( directorist_price( $total_due ) ) ?></div>
    </td>
</tr>