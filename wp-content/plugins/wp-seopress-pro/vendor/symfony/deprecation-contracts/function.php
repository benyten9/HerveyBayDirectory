<?php

// Kept in the GLOBAL namespace by SEOPress dependency scoping so the bundled
// Google SDK can call trigger_deprecation() as an unqualified global function.
if ( ! function_exists( 'trigger_deprecation' ) ) {
	function trigger_deprecation( string $package, string $version, string $message, ...$args ) : void
	{
		@trigger_error( ( $package || $version ? "Since $package $version: " : '' ) . ( $args ? vsprintf( $message, $args ) : $message ), \E_USER_DEPRECATED );
	}
}
