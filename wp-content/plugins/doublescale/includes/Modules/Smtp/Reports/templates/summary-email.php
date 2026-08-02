<?php
/**
 * Weekly summary email HTML template.
 *
 * @package DoubleScale
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scope-local; populated by the parent renderer via extract().

defined( 'ABSPATH' ) || exit;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\EmailLog\EmailLogHandler;

$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

$start_date = gmdate( 'Y-m-d H:i:s', strtotime( 'last Saturday' ) );
$end_date   = gmdate( 'Y-m-d H:i:s' );

$total_emails = EmailLogHandler::get_count( false, $start_date, $end_date );
$succeeded    = EmailLogHandler::get_count( 'succeeded', $start_date, $end_date );
$failed       = EmailLogHandler::get_count( 'failed', $start_date, $end_date );

$last_weekend_start_date = gmdate( 'Y-m-d H:i:s', strtotime( 'last Saturday - 7 days' ) );
$last_weekend_end_date   = gmdate( 'Y-m-d H:i:s', strtotime( 'last Saturday - 1 day' ) );

$last_weekend_total_emails = EmailLogHandler::get_count( false, $last_weekend_start_date, $last_weekend_end_date );
$last_weekend_succeeded    = EmailLogHandler::get_count( 'succeeded', $last_weekend_start_date, $last_weekend_end_date );
$last_weekend_failed       = EmailLogHandler::get_count( 'failed', $last_weekend_start_date, $last_weekend_end_date );

$total_emails_change = $total_emails > $last_weekend_total_emails ? '+' . ( $total_emails - $last_weekend_total_emails ) : ( $total_emails - $last_weekend_total_emails );
$succeeded_change    = $succeeded > $last_weekend_succeeded ? '+' . ( $succeeded - $last_weekend_succeeded ) : ( $succeeded - $last_weekend_succeeded );
$failed_change       = $failed > $last_weekend_failed ? '+' . ( $failed - $last_weekend_failed ) : ( $failed - $last_weekend_failed );

/**
 * Fires before summary email HTML output.
 */
do_action( 'doublescale_smtp_before_summary_email' );

?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width">
	<title><?php echo esc_html__( 'DoubleScale SMTP Weekly Summary', 'doublescale' ); ?></title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Helvetica Neue', 'Arial', sans-serif; background-color: #f5f5f5;">
	<div id="wrapper" style="width: 100%; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #ffffff; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);">
		<div id="header" style="text-align: center; margin-bottom: 20px; color: #3498db;">
			<h1 style="font-size: 32px; margin: 0; font-weight: bold; color: #2c3e50;">
				<?php echo esc_html__( 'DoubleScale SMTP', 'doublescale' ) . ' ' . esc_html( $blogname ) . ' ' . esc_html__( 'Weekly Email Report', 'doublescale' ); ?>
			</h1>
			<p style="font-size: 14px; color: #555555;">
				<?php echo esc_html__( 'Unlock insights into your email performance.', 'doublescale' ); ?>
			</p>
		</div>
		<div id="body" style="color: #555555; text-align: center;">
			<p><?php echo esc_html__( 'Greetings! Here is your weekly summary of emails sent through DoubleScale SMTP.', 'doublescale' ); ?></p>
			<div class="box-container" style="display: flex; flex-wrap: wrap; margin-top: 20px;">
				<div class="box total-box" style="flex: 1; margin: 10px; padding: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); background-color: #ffffff; border: 2px solid #3498db; color: #3498db;">
					<h2 style="font-size: 18px; margin-bottom: 10px; color: #333333;">
						<span class="icon">&#9993;&#65039;</span>
						<?php echo esc_html__( 'Total Emails', 'doublescale' ); ?>
					</h2>
					<p style="margin: 0; font-size: 14px; color: #555555;">
						<?php echo esc_html( (string) $total_emails ); ?>
					</p>
					<p style="margin: 0; font-size: 14px; color: #555555;">
						<?php echo '(' . esc_html( (string) $total_emails_change ) . ')'; ?>
					</p>
				</div>
				<div class="box success-box" style="flex: 1; margin: 10px; padding: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); background-color: #ffffff; border: 2px solid #2ecc71; color: #2ecc71;">
					<h2 style="font-size: 18px; margin-bottom: 10px; color: #333333;">
						<span class="icon" style="color: #2ecc71;">&#9989;</span>
						<?php echo esc_html__( 'Succeeded', 'doublescale' ); ?>
					</h2>
					<p style="margin: 0; font-size: 14px; color: #555555;">
						<?php echo esc_html( (string) $succeeded ); ?>
					</p>
					<p style="margin: 0; font-size: 14px; color: #555555;">
						<?php echo '(' . esc_html( (string) $succeeded_change ) . ')'; ?>
					</p>
				</div>
				<div class="box fail-box" style="flex: 1; margin: 10px; padding: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); background-color: #ffffff; border: 2px solid #e74c3c; color: #e74c3c;">
					<h2 style="font-size: 18px; margin-bottom: 10px; color: #333333;">
						<span class="icon">&#10060;</span>
						<?php echo esc_html__( 'Failed', 'doublescale' ); ?>
					</h2>
					<p style="margin: 0; font-size: 14px; color: #555555;">
						<?php echo esc_html( (string) $failed ); ?>
					</p>
					<p style="margin: 0; font-size: 14px; color: #555555;">
						<?php echo '(' . esc_html( (string) $failed_change ) . ')'; ?>
					</p>
				</div>
			</div>
		</div>
		<div id="footer" style="text-align: center; margin-top: 20px; color: #777777;">
			<p style="font-size: 14px; color: #555555;">
				<?php echo esc_html__( 'Stay informed with DoubleScale SMTP — your email delivery companion.', 'doublescale' ); ?>
			</p>
		</div>
	</div>
</body>
</html>
<?php
/**
 * Fires after summary email HTML output.
 */
do_action( 'doublescale_smtp_after_summary_email' );
