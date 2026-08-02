<?php
/**
 * Email Footer.
 * Heavily influenced by the great AffiliateWP plugin by Pippin Williamson.
 * https://github.com/JustinSainton/AffiliateWP/blob/master/includes/emails/class-affwp-emails.php
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails
 */


defined( 'ABSPATH' ) || exit;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$doublescale_background_color = '#e9eaec';
?>
															</td>
														</tr>
													</tbody>
												</table>
											</td>
										</tr>
									</tbody>
								</table>
							</td>
						</tr>
						<tr>
							<td valign="top" id="templateFooter" style="mso-line-height-rule: exactly;-ms-text-size-adjust: 100%;-webkit-text-size-adjust: 100%;background-color: <?php echo esc_attr( $doublescale_background_color ); ?>;border-top: 0;border-bottom: 0;padding-top: 12px;padding-bottom: 12px;">
								<table border="0" cellpadding="0" cellspacing="0" width="100%" class="mcnTextBlock" style="min-width: 100%;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;-ms-text-size-adjust: 100%;-webkit-text-size-adjust: 100%;">
									<tbody class="mcnTextBlockOuter">
										<tr>
											<td valign="top" class="mcnTextBlockInner" style="mso-line-height-rule: exactly;-ms-text-size-adjust: 100%;-webkit-text-size-adjust: 100%;">
												<table align="left" border="0" cellpadding="0" cellspacing="0" width="100%" style="min-width: 100%;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;-ms-text-size-adjust: 100%;-webkit-text-size-adjust: 100%;" class="mcnTextContentContainer">
													<tbody>
														<tr>
															<td valign="top" class="mcnTextContent" style="padding-top: 9px;padding-right: 18px;padding-bottom: 9px;padding-left: 18px;mso-line-height-rule: exactly;-ms-text-size-adjust: 100%;-webkit-text-size-adjust: 100%;word-break: break-word;color: #aaa;font-family: Helvetica;font-size: 12px;line-height: 150%;text-align: center;">

																<!-- Footer content -->
															<?php
															$doublescale_site_name = wp_specialchars_decode( get_bloginfo( 'name' ) );
															if ( empty( $doublescale_site_name ) ) {
																$doublescale_site_name = wp_parse_url( home_url(), PHP_URL_HOST );
															}
															/* translators: %s: site name */
															$doublescale_footer = sprintf( esc_html__( 'Sent from %s', 'doublescale' ), '<a href="' . esc_url( home_url() ) . '" style="color:#bbbbbb;">' . esc_html( $doublescale_site_name ) . '</a>' );
															// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $footer is escaped above, filter allows customization
															echo apply_filters( 'doublescale_mail_footer_text', $doublescale_footer );
															?>

															</td>
														</tr>
													</tbody>
												</table>
											</td>
										</tr>
									</tbody>
								</table>
							</td>
						</tr>
					</table>
					<!--[if gte mso 9]>
					</td>
					</tr>
					</table>
					<![endif]-->
					<!-- // END TEMPLATE -->
					</td>
				</tr>
			</table>
		</center>
	</body>
</html>
