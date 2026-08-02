<?php

defined( "ABSPATH" ) || exit;

return apply_filters(
    "directorist_pricing_plan_features", [
        //Plan Fields

        // TODO: get type fields
        // directorist_get_directory_submission_fields( 
        //     directorist_get_pricing_plan_by_id($id) ?? (object) ['directory_type_id' => default_directory_type()]
        // )

        "listing_type"                     => [
            "name"                     => "Listing Type",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "customer_role"                    => [
            "name"                     => "Customer Review",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "excluded_category"                => [
            "name"                     => "Excluded Category",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
            'categories'               => [29, 36, 67, 89]
        ],
        
        //Directory Fields
        "listing_title"                    => [
            "name"                     => "Title",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => true,
        ],
        "tagline"                          => [
            "name"                     => "Tagline",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "zip"                              => [
            "name"                     => "Zip/Post Code",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "listing_content"                  => [
            "name"                     => "Description",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "fax"                              => [
            "name"                     => "Fax",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
            "is_unlimited"             => true,
            "limit"                    => 5,
        ],
        "excerpt"                          => [
            "name"                     => "Excerpt",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
            "is_unlimited"             => false,
            "limit"                    => 5,
        ],
        "phone2"                           => [
            "name"                     => "Phone 2",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "social"                           => [
            "name"                     => "Social Info",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "admin_category_select[]"          => [ // TODO
            "name"                     => "Admin Category Select",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "tax_input[at_biz_dir-tags][]"     => [
            "name"                     => "Tags",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "email"                            => [
            "name"                     => "Email",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "phone"                            => [
            "name"                     => "Phone",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "website"                          => [
            "name"                     => "Website",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "tax_input[at_biz_dir-location][]" => [
            "name"                     => "Location",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "address"                          => [
            "name"                     => "Address",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "map"                              => [
            "name"                     => "Map",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "listing_img"                      => [
            "name"                     => "Images",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
            "is_unlimited"             => false,
            "limit"                    => 5,
        ],
        "videourl"                         => [
            "name"                     => "Video",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-text"                      => [
            "name"                     => "Custom Text",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-textarea"                  => [
            "name"                     => "Custom Textarea",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-select"                    => [
            "name"                     => "Custom Select",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-file"                      => [
            "name"                     => "Custom File",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-radio"                     => [
            "name"                     => "Custom Radio",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-checkbox"                  => [
            "name"                     => "Custom Checkbox",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-color-picker"              => [
            "name"                     => "Custom Color Picker",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-number"                    => [
            "name"                     => "Custom Number",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-url"                       => [
            "name"                     => "Custom URL",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-time"                      => [
            "name"                     => "Custom Time",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
        "custom-date"                      => [
            "name"                     => "Custom Date",
            "is_enabled"               => true,
            "is_show_in_pricing_table" => false,
        ],
    ]
);