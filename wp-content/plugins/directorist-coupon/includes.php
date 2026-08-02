<?php
/**
 * @package  Directorist - Coupon
 */

if( ! class_exists( 'SWBDPCIncludeClasses' ) ){
    /**
     * All classes includer class
     */
    class SWBDPCIncludeClasses
    {   
        /**
         * This function include all classes
         * 
         * @return void
         */
        public static function includes()
        {
            $files = array( 
                'Inc/Controller/Base/Enqueue',
                'Inc/Controller/Base/Activate',
                'Inc/Controller/Base/DynamicStyle',
                'Inc/Controller/Base/AjaxHandler',
                'Inc/Controller/Base/CouponHandler',
                'Inc/Controller/Base/HelperFunctions',
                'Inc/Controller/Base/ShortcodeHandler',
                'Inc/Controller/Base/ExtensionSettings',
                'Inc/Controller/Admin/SWBDPCouponCPT',
                'Inc/Controller/Admin/CustomWpListTable',
                'Inc/View/AdminCallbacks',
            );

            foreach( $files as $file ){
                $file_path = SWBDPC_PLUGIN_DIR_PATH ."$file.php";
            
                if( file_exists( $file_path ) ){
                    require_once( $file_path ); 
                }
            }
        }

    }// End class

} 