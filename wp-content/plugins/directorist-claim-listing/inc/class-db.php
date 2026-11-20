<?php
/** Compatibility include: request parsing belongs to the native adapter. */
defined( 'ABSPATH' ) || exit;
return DCL_Claim_Native::save_review( (int) $post_id );
