<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — form-plugin detection helpers.
 *
 * Used by html-to-component to (a) detect a <form> in source HTML and (b)
 * surface the list of installed form plugins so the agent can ask the user
 * which one to wrap as an etch/shortcode block.
 *
 * Mirrors the 9-plugin map from includes/premium/integrations/plugin-integrations.php
 * so it works even when nibwp/forms-manage is locked behind a license gate.
 */

/**
 * Map of known form plugins with their detection probe and primary shortcode tag.
 *
 * @return array<string,array{label:string,probe:callable():bool,shortcode_tag:string,shortcode_example:string}>
 */
function nibwp_etchwp_form_plugin_map(): array
{
    return [
        'gravityforms' => [
            'label'             => 'Gravity Forms',
            'probe'             => static fn() => class_exists('GFForms'),
            'shortcode_tag'     => 'gravityform',
            'shortcode_example' => '[gravityform id="N" title="false" description="false" ajax="true"]',
        ],
        'wpforms' => [
            'label'             => 'WPForms',
            'probe'             => static fn() => defined('WPFORMS_VERSION'),
            'shortcode_tag'     => 'wpforms',
            'shortcode_example' => '[wpforms id="N"]',
        ],
        'fluentform' => [
            'label'             => 'Fluent Forms',
            'probe'             => static fn() => defined('FLUENTFORM'),
            'shortcode_tag'     => 'fluentform',
            'shortcode_example' => '[fluentform id="N"]',
        ],
        'cf7' => [
            'label'             => 'Contact Form 7',
            'probe'             => static fn() => defined('WPCF7_VERSION') || class_exists('WPCF7'),
            'shortcode_tag'     => 'contact-form-7',
            'shortcode_example' => '[contact-form-7 id="N"]',
        ],
        'ninjaforms' => [
            'label'             => 'Ninja Forms',
            'probe'             => static fn() => defined('NF_PLUGIN_VERSION') || class_exists('Ninja_Forms'),
            'shortcode_tag'     => 'ninja_form',
            'shortcode_example' => '[ninja_form id="N"]',
        ],
        'formidable' => [
            'label'             => 'Formidable Forms',
            'probe'             => static fn() => function_exists('load_formidable_forms') || class_exists('FrmForm'),
            'shortcode_tag'     => 'formidable',
            'shortcode_example' => '[formidable id="N"]',
        ],
        'forminator' => [
            'label'             => 'Forminator',
            'probe'             => static fn() => defined('FORMINATOR_VERSION') || class_exists('Forminator_API'),
            'shortcode_tag'     => 'forminator_form',
            'shortcode_example' => '[forminator_form id="N"]',
        ],
        'happyforms' => [
            'label'             => 'Happy Forms',
            'probe'             => static fn() => defined('HAPPYFORMS_VERSION'),
            'shortcode_tag'     => 'happyforms',
            'shortcode_example' => '[happyforms id="N"]',
        ],
        'jetform' => [
            'label'             => 'JetFormBuilder',
            'probe'             => static fn() => class_exists('\\Jet_Form_Builder\\Plugin'),
            'shortcode_tag'     => 'jet_form',
            'shortcode_example' => '[jet_form id="N"]',
        ],
    ];
}

/**
 * Scan source HTML for <form> tags or known form-plugin shortcodes.
 * Returns the installed plugin list (so the agent can ask the user which to use).
 *
 * @param array{html?:string,url?:string,notes?:string} $source
 * @return array{has_form:bool,hint_plugin:?string,installed_plugins:array<int,array{slug:string,label:string,installed:bool,shortcode_tag:string,shortcode_example:string}>}
 */
function nibwp_etchwp_detect_form_in_source(array $source): array
{
    $html = (string) ($source['html'] ?? '');
    $has_form = false;
    $hint = null;

    if ($html !== '') {
        if (stripos($html, '<form') !== false) {
            $has_form = true;
        }
        foreach (nibwp_etchwp_form_plugin_map() as $slug => $info) {
            if (stripos($html, '[' . $info['shortcode_tag']) !== false) {
                $has_form = true;
                $hint = $slug;
                break;
            }
        }
    }

    $installed = [];
    foreach (nibwp_etchwp_form_plugin_map() as $slug => $info) {
        $installed[] = [
            'slug'              => $slug,
            'label'             => $info['label'],
            'installed'         => (bool) ($info['probe'])(),
            'shortcode_tag'     => $info['shortcode_tag'],
            'shortcode_example' => $info['shortcode_example'],
        ];
    }

    return [
        'has_form'          => $has_form,
        'hint_plugin'       => $hint,
        'installed_plugins' => $installed,
    ];
}
