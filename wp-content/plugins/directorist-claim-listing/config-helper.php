<?php

if ( ! function_exists( 'dcl_get_version_from_content' ) ) {
    function dcl_get_version_from_content( $content = '' ) {
        $version = '';

        if ( preg_match('/\*[\s\t]+?version:[\s\t]+?([0-9.]+)/i', $content, $v) ) {
            $version = $v[1];
        }

        return $version;
    }
}

if ( ! function_exists( 'dcl_get_version_from_file_content' ) ) {
    function dcl_get_version_from_file_content( $file_path = '' ) {
        $version = '';

        if ( ! file_exists( $file_path ) ) {
            return $version;
        }

        $content = file_get_contents( $file_path );
        $version = dcl_get_version_from_content( $content );
        
        return $version;
    }
}