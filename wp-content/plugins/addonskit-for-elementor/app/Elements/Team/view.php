<?php
/**
 * @author  wpWax
 * @since   1.0
 * @version 1.0
 */

use AddonskitForElementor\Utils\Helper;

$attr = '';
$name = $data['name'] ? $data['name'] : '';

if ( $data['team_image']['url'] ) {
    $team_image = sprintf( '<img src="%s">', esc_url($data['team_image']['url']) );
} elseif ( isset( $data['team_image']['id'] ) ) {
    $team_image = wp_get_attachment_image_url( $data['team_image']['id'], 'full' );
} else {
    $team_image = '';
}
?>
<div class="theme-team-single">
    <figure>
        <div class="theme-team-single__img">
            <?php echo wp_kses_post($team_image); ?>
        </div>

        <figcaption>
            <h6 class="theme-team-single__title"><?php echo wp_kses_post( $name ); ?></h6>

            <?php if ( $data['designation'] ): ?>
                <p class="theme-team-single__position"><?php echo wp_kses_post( $data['designation'] ); ?></p>
            <?php endif; ?>

            <?php if ( $data['designation'] ): ?>
                <ul class="theme-team-single__social">
                    <?php if ( isset( $data['facebook'] ) && ! empty( $data['facebook'] ) ): ?>
                        <li><a href="<?php echo esc_url( $data['facebook'] ); ?>"><?php echo wp_kses_post(Helper::get_svg_icon( "facebook" )); ?></a></li>
                    <?php endif; ?>
                    <?php if ( isset( $data['twitter'] ) && ! empty( $data['twitter'] ) ): ?>
                        <li><a href="<?php echo esc_url( $data['twitter'] ); ?>"><?php echo wp_kses_post(Helper::get_svg_icon( "twitter" )); ?></a></li>
                    <?php endif; ?>
                    <?php if ( isset( $data['youtube'] ) && ! empty( $data['youtube'] ) ): ?>
                        <li><a href="<?php echo esc_url( $data['youtube'] ); ?>"><?php echo wp_kses_post(Helper::get_svg_icon( "youtube" )); ?></a></li>
                    <?php endif; ?>
                    <?php if ( isset( $data['instagram'] ) && ! empty( $data['instagram'] ) ): ?>
                        <li><a href="<?php echo esc_url( $data['instagram'] ); ?>"><?php echo wp_kses_post(Helper::get_svg_icon( "instagram" )); ?></a></li>
                    <?php endif; ?>
                    <?php if ( isset( $data['pinterest'] ) && ! empty( $data['pinterest'] ) ): ?>
                        <li><a href="<?php echo esc_url( $data['pinterest'] ); ?>"><?php echo wp_kses_post(Helper::get_svg_icon( "pinterest" )); ?></a></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </figcaption>
    </figure>
</div>