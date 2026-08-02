<?php

defined( "ABSPATH" ) || exit;

return apply_filters(
    "directorist_pricing_plan_app_configurations", [
        [
            "type"  => "apple_app_store",
            "label" => "AppStore",
        ],
        [
            "type"  => "google_play_store",
            "label" => "PlayStore",
        ],
    ]
);
