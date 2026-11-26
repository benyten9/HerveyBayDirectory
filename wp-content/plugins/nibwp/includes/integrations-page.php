<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integrations admin page — activate/deactivate page builder and custom field integrations.
 */

add_action('wp_ajax_nibwp_request_integration', 'nibwp_handle_integration_request');

/**
 * Receive a custom integration / skill request and mail it to support.
 *
 * The modal used to call showSuccess() without sending anything, so every
 * request anyone made went nowhere while telling them it had arrived.
 */
function nibwp_handle_integration_request(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You are not allowed to do that.', 'nibwp')], 403);
    }

    check_ajax_referer('nibwp_request_integration');

    $request = [
        'type'        => sanitize_text_field(wp_unslash($_POST['request_type'] ?? '')),
        'plugin_name' => sanitize_text_field(wp_unslash($_POST['plugin_name'] ?? '')),
        'plugin_url'  => esc_url_raw(trim((string) wp_unslash($_POST['plugin_url'] ?? ''))),
        'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'priority'    => sanitize_text_field(wp_unslash($_POST['priority'] ?? '')),
        'name'        => sanitize_text_field(wp_unslash($_POST['user_name'] ?? '')),
        'email'       => sanitize_email(wp_unslash($_POST['user_email'] ?? '')),
        'notify'      => !empty($_POST['newsletter']),
    ];

    if ($request['description'] === '' || !is_email($request['email'])) {
        wp_send_json_error(
            ['message' => __('We need a description and an email address we can reply to.', 'nibwp')],
            400
        );
    }

    if (!nibwp_mail_integration_request($request)) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: support email address. */
                __('This site could not send the mail. Please write to %s instead.', 'nibwp'),
                nibwp_support_email()
            ),
        ], 500);
    }

    wp_send_json_success();
}

/**
 * Mail one integration request to support.
 *
 * Split from the handler so it can be checked without a request cycle. Site
 * and WordPress version ride along because "it does not work with my setup"
 * is answerable only if we know the setup.
 */
function nibwp_mail_integration_request(array $request): bool
{
    global $wp_version;

    $lines = [
        sprintf('Type:        %s', $request['type'] !== '' ? $request['type'] : '(not chosen)'),
        sprintf('Priority:    %s', $request['priority'] !== '' ? $request['priority'] : '(not set)'),
        sprintf('Plugin:      %s', $request['plugin_name'] !== '' ? $request['plugin_name'] : '(not named)'),
        sprintf('URL:         %s', $request['plugin_url'] !== '' ? $request['plugin_url'] : '(none)'),
        '',
        'What they want AI agents to be able to do:',
        $request['description'],
        '',
        '----',
        sprintf('From:        %s <%s>', $request['name'], $request['email']),
        sprintf('Notify when built: %s', !empty($request['notify']) ? 'yes' : 'no'),
        sprintf('Site:        %s', home_url()),
        sprintf('WordPress:   %s', $wp_version ?? 'unknown'),
        sprintf('NIBWP:       %s', defined('NIBWP_VERSION') ? NIBWP_VERSION : 'unknown'),
    ];

    // Both of these can be empty — only the description is required — and a
    // subject line ending in a dash is an inbox nobody can sort.
    $label = $request['plugin_name'] !== ''
        ? $request['plugin_name']
        : ($request['type'] !== '' ? $request['type'] : __('unspecified', 'nibwp'));

    return (bool) wp_mail(
        nibwp_support_email(),
        sprintf('NibWP integration request — %s', $label),
        implode("\n", $lines),
        [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('Reply-To: %s <%s>', $request['name'], $request['email']),
        ]
    );
}

/**
 * Get the list of enabled integrations from options.
 *
 * @return array<string, bool>
 */
function nibwp_get_enabled_integrations(): array
{
    $saved = get_option('nibwp_enabled_integrations', []);
    if (!is_array($saved)) {
        return [];
    }

    // Legacy-key migration: the old `acss` key was merged into `automaticcss`.
    // If a user enabled either one previously, keep them enabled under the new
    // canonical key, then persist the cleanup once.
    if (array_key_exists('acss', $saved)) {
        if (!empty($saved['acss']) && empty($saved['automaticcss'] ?? null)) {
            $saved['automaticcss'] = true;
        }
        unset($saved['acss']);
        update_option('nibwp_enabled_integrations', $saved);
    }

    return $saved;
}

/**
 * Check if a specific integration is enabled by the user.
 */
/**
 * How many distinct operations an integration actually offers.
 *
 * Most integrations expose one ability carrying an `action` enum — the
 * FluentAffiliate hub alone runs 33 actions, Directorist 39. Counting the
 * ability NAMES reported every one of those as "1 AI ability", which reads as
 * a stub rather than the most complete integrations we ship.
 *
 * Reads the enum off the registered ability, so the number cannot drift from
 * what the ability really accepts. An integration that is switched off has
 * nothing registered to inspect, so it falls back to the name count.
 *
 * @param array<int, string> $abilities Ability slugs, with or without the nibwp/ prefix.
 */
function nibwp_integration_operation_count(array $abilities, int $declared = 0): int
{
    $total = 0;

    foreach ($abilities as $slug) {
        $slug = (string) $slug;
        $name = str_contains($slug, '/') ? $slug : 'nibwp/' . $slug;

        $ability = function_exists('nibwp_has_ability') && nibwp_has_ability($name)
            ? wp_get_ability($name)
            : null;

        if ($ability === null || !method_exists($ability, 'get_input_schema')) {
            // An integration whose plugin is not installed registers nothing,
            // so there is no enum to read. Counting the name as one operation
            // advertised Easy Digital Downloads' five actions as "1 AI action"
            // on every site that had not installed it yet — which is exactly
            // the site the card is trying to persuade. The card declares what
            // the ability carries, and a test pins that number to the enum.
            if ($declared > 0) {
                return $declared;
            }

            $total++;
            continue;
        }

        // A schema can come back with nested stdClass — WP_Ability keeps
        // whatever shape it was given, and some abilities are registered from
        // decoded JSON. A plain (array) cast only converts the top level, so
        // reaching into ['properties']['action'] then fatals on an object.
        $schema = $ability->get_input_schema();
        $schema = json_decode(json_encode($schema) ?: '[]', true);
        $enum   = is_array($schema) ? ($schema['properties']['action']['enum'] ?? null) : null;

        $total += is_array($enum) && $enum !== [] ? count($enum) : 1;
    }

    return $total;
}

/**
 * The first few operation names an integration offers, for the card footer.
 *
 * The footer used to print ability names, which reads as a contradiction on a
 * hub: "31 AI actions - fluentcart-manage". The names a visitor wants to see
 * are the actions, so read them from the ability's action enum where there is
 * one, fall back to what the card declares when the plugin is not installed and
 * nothing is registered, and to the ability name when neither applies.
 *
 * @param string[] $abilities Ability slugs, with or without the nibwp/ prefix.
 * @param string[] $declared  Example action names the card declares.
 * @param int      $limit     How many to return.
 * @return string[]
 */
function nibwp_integration_operation_labels(array $abilities, array $declared = [], int $limit = 8): array
{
    $labels = [];

    foreach ($abilities as $slug) {
        $slug = (string) $slug;
        $name = str_contains($slug, '/') ? $slug : 'nibwp/' . $slug;
        $bare = str_replace('nibwp/', '', $slug);

        $ability = function_exists('nibwp_has_ability') && nibwp_has_ability($name)
            ? wp_get_ability($name)
            : null;

        if ($ability === null || !method_exists($ability, 'get_input_schema')) {
            if ($declared !== []) {
                return array_slice(array_map('strval', $declared), 0, $limit);
            }

            $labels[] = $bare;
            continue;
        }

        $schema = $ability->get_input_schema();
        $schema = json_decode(json_encode($schema) ?: '[]', true);
        $enum   = is_array($schema) ? ($schema['properties']['action']['enum'] ?? null) : null;

        if (is_array($enum) && $enum !== []) {
            foreach ($enum as $action) {
                $labels[] = (string) $action;
            }
            continue;
        }

        $labels[] = $bare;
    }

    return array_slice($labels, 0, $limit);
}

/**
 * Integrations that ship switched ON.
 *
 * These are the built-in toolkits rather than third-party plugin bridges —
 * there is nothing to detect, so they are useful from the moment Pro is
 * active. They still have to be switchable off, which is what was broken:
 * their cards hardcoded `enabled => true`, so the toggle wrote the option and
 * the page read back the literal.
 *
 * @return array<int, string>
 */
function nibwp_integrations_default_on(): array
{
    return ['security', 'notifications', 'migration', 'content-fetcher', 'content-planner', 'seo-advanced'];
}

function nibwp_is_integration_enabled(string $key): bool
{
    // Memory is always enabled.
    if ($key === 'memory') {
        return true;
    }

    $enabled = nibwp_get_enabled_integrations();

    // array_key_exists, not empty(): a stored `false` is a deliberate "off"
    // and must beat the default. Reading it with empty() is what would turn
    // every switched-off toolkit back on at the next page load.
    if (array_key_exists($key, $enabled)) {
        return (bool) $enabled[$key];
    }

    return in_array($key, nibwp_integrations_default_on(), true);
}

/**
 * Has any plugin other than Elementor itself registered an Elementor widget?
 *
 * That is the whole of add-on pack detection, and it is deliberately not a list
 * of pack constants: there are dozens of packs, each renames its constants
 * eventually, and a hardcoded list is wrong the moment a new one ships. Where a
 * widget class actually lives on disk cannot be wrong.
 *
 * This lives here rather than in the integration file because it is what decides
 * whether that file loads at all. Once it has loaded, its own resolver — which
 * also reads plugin headers for names and versions — answers instead, so there
 * is only ever one opinion per request.
 *
 * Reflecting widget classes is the expensive part, so the loop stops at the
 * first non-Elementor provider it finds and the answer is memoised.
 */
function nibwp_elementor_addon_pack_present(): bool
{
    static $present = null;

    if ($present !== null) {
        return $present;
    }

    $present = false;

    if (!defined('ELEMENTOR_VERSION') || !class_exists('\\Elementor\\Plugin')) {
        return $present;
    }

    if (function_exists('nibwp_elementor_addons_pack_dirs')) {
        $present = nibwp_elementor_addons_pack_dirs() !== [];

        return $present;
    }

    try {
        if (!property_exists('\\Elementor\\Plugin', 'instance')) {
            return $present;
        }
        $plugin = \Elementor\Plugin::$instance;
        if (!is_object($plugin) || !isset($plugin->widgets_manager)) {
            return $present;
        }
        $manager = $plugin->widgets_manager;
        if (!is_object($manager) || !method_exists($manager, 'get_widget_types')) {
            return $present;
        }
        $types = $manager->get_widget_types();
    } catch (\Throwable $e) {
        return $present;
    }

    if (!is_array($types)) {
        return $present;
    }

    $root = defined('WP_PLUGIN_DIR') ? rtrim(str_replace('\\', '/', (string) WP_PLUGIN_DIR), '/') . '/' : '';
    $core = ['elementor', 'elementor-pro'];
    $seen = [];

    foreach ($types as $widget) {
        if (!is_object($widget)) {
            continue;
        }
        $class = get_class($widget);
        if (isset($seen[$class])) {
            continue;
        }
        $seen[$class] = true;

        try {
            $file = (new ReflectionClass($widget))->getFileName();
        } catch (\Throwable $e) {
            continue;
        }
        if (!is_string($file) || $file === '') {
            continue;
        }

        $path = str_replace('\\', '/', $file);
        $dir = '';
        if ($root !== '' && str_starts_with($path, $root)) {
            $dir = (string) strtok(substr($path, strlen($root)), '/');
        } elseif (preg_match('#/plugins/([^/]+)/#', $path, $matches) === 1) {
            $dir = $matches[1];
        }

        if ($dir !== '' && !in_array($dir, $core, true)) {
            $present = true;
            break;
        }
    }

    return $present;
}

/**
 * Check if a specific integration's plugin is installed and active.
 */
function nibwp_is_integration_available(string $key): bool
{
    return match ($key) {
        'elementor' => defined('ELEMENTOR_VERSION'),
        'elementor-addons' => defined('ELEMENTOR_VERSION') && nibwp_elementor_addon_pack_present(),
        'rometheme' => defined('ELEMENTOR_VERSION') && (defined('RTM_KIT_VERSION') || defined('RTM_KIT_DIR') || class_exists('RTMKit\\Core\\Plugin') || class_exists('RTMKit\\Modules\\Storage')),
        'wpb-addons' => defined('ELEMENTOR_VERSION') && (defined('WPB_EA_VERSION') || defined('WPB_EA_PATH') || defined('WPB_EA_PREFIX') || class_exists('WPB_Elementor_Addons')),
        'mega-elements' => defined('ELEMENTOR_VERSION') && (defined('MEGA_ELEMENTS_ADDONS_FOR_ELEMENTOR_VERSION') || defined('MEGA_ELEMENTS_ADDONS_FOR_ELEMENTOR_FILE') || class_exists('MegaElementsAddonsForElementor\\Widgets_Manager') || class_exists('MegaElementsAddonsForElementor\\Mega_Elements_Addons_For_Elementor')),
        'lastudio-kit' => defined('ELEMENTOR_VERSION') && (class_exists('LaStudio_Kit') || class_exists('LaStudio_Kit_Settings') || function_exists('lastudio_kit') || function_exists('lastudio_kit_settings')),
        'htmega' => defined('ELEMENTOR_VERSION') && (defined('HTMEGA_VERSION') || defined('HTMEGA_ADDONS_PL_ROOT') || class_exists('HTMega_Addons_Elementor') || class_exists('HTMega_Widgets_Control')),
        'move-addons' => defined('ELEMENTOR_VERSION') && (defined('MOVE_ADDONS_VERSION') || defined('MOVE_ADDONS_FILE') || class_exists('MoveAddons\\Elementor\\Base') || class_exists('MoveAddons\\Elementor\\Widgets_Control') || function_exists('move_addons_get_option_data')),
        'ht-slider' => defined('ELEMENTOR_VERSION') && (defined('HTSLIDER_VERSION') || defined('HTSLIDER_ADDONS_PL_ROOT') || class_exists('HTSlider_Addons_Elementor') || class_exists('HTSlider_Template_Library') || function_exists('htslider_render_build_content')),
        'elementinvader' => defined('ELEMENTOR_VERSION') && (defined('ELI_VERSION') || defined('ELI_PATH') || defined('ELI_FILE__') || class_exists('ELI\\Modules\\Forms\\Ajax_Handler') || function_exists('eli_notify_admin')),
        'anywhere-elementor' => defined('ELEMENTOR_VERSION') && (defined('AE_PRO_VERSION') || defined('AE_PLUGIN_VERSION') || class_exists('Aepro\\Plugin') || class_exists('Aepro\\Classes\\ModuleManager') || function_exists('aepro_is_pro_active')),
        'unlimited-elements' => defined('ELEMENTOR_VERSION') && (defined('UNLIMITED_ELEMENTS_INC') || defined('UNLIMITED_ELEMENTS_VERSION') || class_exists('GlobalsUnlimitedElements') || class_exists('UniteCreatorElementorIntegrate') || function_exists('uefe_fs')),
        'qi-addons' => defined('ELEMENTOR_VERSION') && (defined('QI_ADDONS_FOR_ELEMENTOR_VERSION') || defined('QI_ADDONS_FOR_ELEMENTOR_ABS_PATH') || class_exists('QiAddonsForElementor') || function_exists('qi_addons_for_elementor_is_widget_disabled')),
        'plus-addons' => defined('ELEMENTOR_VERSION') && (defined('L_THEPLUS_VERSION') || defined('L_THEPLUS_FILE') || defined('L_THEPLUS_PATH') || class_exists('Tpae_Hooks') || function_exists('l_registered_widgets') || function_exists('l_theplus_get_option')),
        'jeg-kit' => defined('ELEMENTOR_VERSION') && (defined('JEG_ELEMENTOR_KIT') || defined('JEG_ELEMENTOR_KIT_VERSION') || class_exists('Jeg\\Elementor_Kit\\Elements\\Element')),
        'addon-elements' => defined('ELEMENTOR_VERSION') && (defined('EAE_VERSION') || defined('EAE_PLUGIN_BASE') || class_exists('WTS_EAE\\Plugin') || class_exists('WTS_EAE\\Classes\\Helper')),
        'royal-addons' => defined('ELEMENTOR_VERSION') && (defined('WPR_ADDONS_VERSION') || class_exists('WprAddons\\Plugin') || class_exists('WprAddons\\Classes\\Utilities')),
        'premium-addons' => defined('ELEMENTOR_VERSION') && (defined('PREMIUM_ADDONS_VERSION') || class_exists('PremiumAddons\\Includes\\PA_Core') || class_exists('PremiumAddons\\Admin\\Includes\\Admin_Helper')),
        'happy-addons' => defined('ELEMENTOR_VERSION') && (defined('HAPPY_ADDONS_VERSION') || class_exists('Happy_Addons\\Elementor\\Classes\\Widgets_Manager') || function_exists('ha_has_pro')),
        'elementskit' => defined('ELEMENTOR_VERSION') && (class_exists('ElementsKit_Lite') || class_exists('ElementsKit_Lite\\Plugin')),
        'eael' => defined('ELEMENTOR_VERSION') && (defined('EAEL_PLUGIN_VERSION') || class_exists('\\Essential_Addons_Elementor\\Classes\\Bootstrap') || function_exists('eael_neutralize_shortcodes')),
        'hfe' => defined('ELEMENTOR_VERSION') && (defined('HFE_VER') || class_exists('HFE_Helper')),
        'bricks' => defined('BRICKS_VERSION'),
        'builderius' => function_exists('builderius_check_requirements') || post_type_exists('builderius_template'),
        'mosaic' => defined('MOSAIC_VERSION'),
        'acf' => function_exists('acf_get_field_groups'),
        'jetengine' => function_exists('jet_engine'),
        'metabox' => function_exists('rwmb_meta'),
        'pods' => function_exists('pods'),
        'acpt' => defined('ACPT_PLUGIN_VERSION'),
        'ase' => defined('ASENHA_VERSION'),
        'woocommerce' => class_exists('WooCommerce'),
        // The same two markers the integration's own guard uses, so the card and
        // the ability cannot disagree about whether the plugin is there.
        'mailpoet' => defined('MAILPOET_VERSION')
            || class_exists('MailPoet\\API\\API'),
        'everest-forms' => defined('EVF_VERSION')
            || function_exists('evf')
            || class_exists('EverestForms'),
        'metform' => defined('METFORM_VERSION')
            || class_exists('MetForm\\Plugin'),
        'survey-maker' => defined('SURVEY_MAKER_VERSION')
            && defined('SURVEY_MAKER_DB_PREFIX'),
        'license-manager' => defined('LMFWC_VERSION')
            || class_exists('LicenseManagerForWooCommerce\\Setup'),
        'stackable' => defined('STACKABLE_VERSION')
            || function_exists('stackable_get_blocks_array'),
        'generateblocks' => defined('GENERATEBLOCKS_VERSION')
            || function_exists('generateblocks_get_option_defaults'),
        'gutenverse' => defined('GUTENVERSE_VERSION')
            || class_exists('Gutenverse\\Gutenverse'),
        'surerank' => defined('SURERANK_VERSION')
            || class_exists('SureRank\\Inc\\Functions\\Settings'),
        'suredash' => defined('SUREDASHBOARD_VER')
            || class_exists('SureDashboard\\Portals_Loader'),
        'sureforms' => defined('SRFM_VER')
            || class_exists('SRFM\\Plugin_Loader'),
        'suretriggers' => defined('SURE_TRIGGERS_VER')
            || class_exists('SureTriggers\\Controllers\\OptionController'),
        'seedprod' => defined('SEEDPROD_VERSION')
            || function_exists('seedprod_lite_post_type'),
        'siteorigin' => defined('SITEORIGIN_PANELS_VERSION')
            || class_exists('SiteOrigin_Panels'),
        'eventprime' => defined('EVENTPRIME_VERSION')
            || class_exists('Eventprime_Basic_Functions'),
        'mailoptin' => defined('MAILOPTIN_VERSION_NUMBER')
            || class_exists('MailOptin\\Core\\Core'),
        'eventin' => class_exists('Wpeventin')
            || defined('ETN_DEFAULT_TICKET_NAME'),
        'events-manager' => defined('EM_VERSION')
            || class_exists('EM_Event'),
        'blocksy' => function_exists('blocksy_get_theme_mod')
            || function_exists('blocksy_manager')
            || (function_exists('wp_get_theme') && wp_get_theme()->get_template() === 'blocksy'),
        'themify' => defined('THEMIFY_BUILDER_DIR')
            || class_exists('Themify_Builder'),
        'wordfence' => defined('WORDFENCE_VERSION')
            || class_exists('wfConfig'),
        'wp-mail-smtp' => class_exists('WPMailSMTP\\Core')
            || defined('WPMS_PLUGIN_VER'),
        'ultimate-member' => class_exists('UM')
            || function_exists('UM'),
        'thirstyaffiliates' => function_exists('ThirstyAffiliates')
            || class_exists('ThirstyAffiliates\\Helpers\\Plugin_Constants'),
        'seo' => defined('WPSEO_VERSION') || class_exists('WPSEO_Options')
            || defined('RANK_MATH_VERSION') || class_exists('RankMath')
            || defined('AIOSEO_VERSION') || function_exists('aioseo'),
        'etchwp' => defined('ETCH_PLUGIN_FILE'),
        'etchedy' => defined('ETCHEDY_PLUGIN_FILE'),
        'bricksed' => defined('BRICKSED_PLUGIN_FILE'),
        'automaticcss' => defined('ACSS_PLUGIN_FILE'),
        'forms' => class_exists('GFForms')
            || defined('WPFORMS_VERSION')
            || defined('FLUENTFORM')
            || defined('WPCF7_VERSION') || class_exists('WPCF7')
            || defined('NF_PLUGIN_VERSION') || class_exists('Ninja_Forms')
            || function_exists('load_formidable_forms') || class_exists('FrmForm')
            || defined('FORMINATOR_VERSION') || class_exists('Forminator_API')
            || defined('HAPPYFORMS_VERSION')
            || defined('BITFORMS_VERSION')
            || class_exists('\\Jet_Form_Builder\\Plugin'),
        // BREAKDANCE_MODE alone is not enough. Breakdance defines it at the top
        // of its bootstrap and THEN returns early when migration mode is on, so
        // the constant can exist on a request where nothing else of Breakdance
        // loaded. Requiring get_tree() as well makes this agree with what the
        // abilities can actually do, rather than promising a surface that is
        // not there.
        'breakdance' => defined('BREAKDANCE_MODE') && function_exists('Breakdance\\Data\\get_tree'),
        'cf7' => defined('WPCF7_VERSION') || class_exists('WPCF7'),
        'gravityforms' => class_exists('GFAPI') && class_exists('GFCommon'),
        'fluentform' => defined('FLUENTFORM') && function_exists('fluentFormApi'),
        'wpforms' => function_exists('wpforms') && defined('WPFORMS_VERSION'),
        'jetformbuilder' => defined('JET_FORM_BUILDER_VERSION'),
        'formidable' => class_exists('FrmForm') && class_exists('FrmField'),
        'forminator' => class_exists('Forminator_API'),
        'happyforms' => defined('HAPPYFORMS_VERSION') && function_exists('happyforms_get_form_controller'),
        // BITFORMS_VERSION is defined at the top of bitforms.php, before its
        // autoloader runs, so the constant alone would report a half-loaded
        // plugin as available. FormModel is what the abilities actually need.
        'bitform' => defined('BITFORMS_VERSION') && class_exists('BitCode\BitForm\Core\Database\FormModel'),
        'ninjaforms' => function_exists('Ninja_Forms'),
        'weglot' => defined('WEGLOT_VERSION') || function_exists('weglot_get_options'),
        'wsform' => defined('WS_FORM_VERSION'),
        'fluentcrm' => defined('FLUENTCRM'),
        'fluentcart' => defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\App'),
        'fluentaffiliate' => defined('FLUENT_AFFILIATE_VERSION') || class_exists('FluentAffiliate\\App\\App'),
        'directorist' => defined('ATBDP_VERSION'),
        'edd' => class_exists('Easy_Digital_Downloads'),
        'events' => class_exists('Tribe__Events__Main'),
        'buddypress' => class_exists('BuddyPress'),
        'learndash' => defined('LEARNDASH_VERSION'),
        'lifterlms' => defined('LLMS_PLUGIN_FILE'),
        'memberpress' => defined('MEPR_VERSION'),
        'tutorlms' => defined('TUTOR_VERSION'),
        'givewp' => defined('GIVE_VERSION'),
        'redirection' => class_exists('Red_Item'),
        'tablepress' => class_exists('TablePress'),
        'translatepress' => defined('TRP_PLUGIN_VERSION'),
        'wpml' => defined('ICL_SITEPRESS_VERSION'),
        'wp-job-manager' => defined('JOB_MANAGER_VERSION') || class_exists('WP_Job_Manager'),
        'generatepress' => function_exists('generate_get_defaults')
            || wp_get_theme()->get_template() === 'generatepress'
            || wp_get_theme()->get_stylesheet() === 'generatepress',
        // Kadence Blocks counts, not just the Kadence theme. The blocks plugin
        // is routinely run under a different theme, and the Kadence Pro skill
        // builds Kadence BLOCKS — so a theme-only test declared the integration
        // unavailable, which silently withheld the skill's routing card AND
        // stopped its ability files from loading at all. The agent was then left
        // with no Kadence tools and reached for execute-php instead.
        'kadence' => class_exists('Kadence\\Theme')
            || function_exists('kadence')
            || defined('KADENCE_BLOCKS_VERSION')
            || class_exists('Kadence_Blocks_Frontend')
            || wp_get_theme()->get_template() === 'kadence'
            || wp_get_theme()->get_stylesheet() === 'kadence',
        'voxel' => class_exists('\\Voxel\\Post')
            || wp_get_theme()->get_template() === 'voxel'
            || wp_get_theme()->get_stylesheet() === 'voxel',
        'divi' => defined('ET_BUILDER_VERSION')
            || defined('ET_BUILDER_PLUGIN_VERSION')
            || function_exists('et_setup_theme')
            || in_array(strtolower((string) wp_get_theme()->get('Name')), ['divi', 'extra'], true)
            || in_array(strtolower((string) wp_get_theme()->get_template()), ['divi', 'extra'], true),
        'fluentcommunity' => defined('FLUENT_COMMUNITY_PLUGIN_VERSION') || function_exists('fluentCommunityApp'),
        'fluentsmtp' => defined('FLUENTMAIL_PLUGIN_VERSION') || function_exists('fluentMailGetSettings'),
        'seopress' => defined('SEOPRESS_VERSION'),
        'slimseo' => defined('SLIM_SEO_VER') || function_exists('slim_seo'),
        // RANK_MATH_VERSION is defined in Rank Math's own bootstrap; the
        // RankMath\Helper class is the surface the abilities reach for, so
        // either one is evidence enough and a half-loaded plugin still reads as
        // present rather than silently withholding the whole integration.
        'rankmath' => defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper'),
        // WPSEO_VERSION is defined in wp-seo.php before Yoast's own classes
        // load, so the class is checked too — a half-loaded Yoast would
        // otherwise read as available and every ability would then find the
        // meta and option APIs missing.
        'yoast' => defined('WPSEO_VERSION') || class_exists('WPSEO_Options'),
        'surecart' => class_exists('SureCart') || defined('SURECART_PLUGIN_FILE'),
        // Figma is a cloud service — "available" means the user has connected an
        // account token (there is no local plugin to detect).
        'figma' => function_exists('nibwp_figma_is_connected')
            ? nibwp_figma_is_connected()
            : (bool) get_option('nibwp_figma_token'),
        // Paper has no cloud API and no token: its MCP server runs on the
        // designer's own machine. "Available" therefore means an agent has
        // reported it could see Paper from here — see nibwp_paper_is_connected().
        'paper' => function_exists('nibwp_paper_is_connected') && nibwp_paper_is_connected(),
        'memory' => true,
        default => false,
    };
}

/**
 * Get all available integrations with their metadata.
 *
 * @return array<string, array{name: string, description: string, plugin_available: bool, enabled: bool, icon: string, category: string, abilities: list<string>, plugin_name: string}>
 */
function nibwp_get_integrations(): array
{
    return [
        'figma' => [
            'name' => 'Figma',
            'description' => __('Connect a Figma account to read designs — node tree, auto-layout, Variables and styles — and convert frames or components into native WordPress via the figma-pro skill. Read-only (v1); pixel-diff verified.', 'nibwp'),
            'plugin_available' => function_exists('nibwp_figma_is_connected')
                ? nibwp_figma_is_connected()
                : (bool) get_option('nibwp_figma_token'),
            'enabled' => nibwp_is_integration_enabled('figma'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M8.5 24a3.5 3.5 0 0 0 3.5-3.5V17H8.5a3.5 3.5 0 1 0 0 7z"/><path d="M5 12a3.5 3.5 0 0 1 3.5-3.5H12v7H8.5A3.5 3.5 0 0 1 5 12z"/><path d="M5 4.5A3.5 3.5 0 0 1 8.5 1H12v7H8.5A3.5 3.5 0 0 1 5 4.5z"/><path d="M12 1h3.5a3.5 3.5 0 1 1 0 7H12V1z"/><path d="M19 12a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0z"/></svg>',
            'category' => 'design',
            'abilities' => ['figma-pull', 'figma-list', 'figma-get', 'figma-pro-fetch', 'figma-pro-convert', 'figma-pro-detect-builder', 'figma-pro-analyze'],
            'plugin_name' => 'Figma',
            // Cloud service — no local plugin. Card shows Connected/Not connected
            // (+ a Connect link), never "not installed".
            'cloud' => true,
        ],
        'paper' => [
            'name' => 'paper.design',
            'description' => __('Build WordPress pages from paper.design. Paper\'s MCP server runs on the designer\'s own machine and Paper has no cloud API, so this site never fetches anything: your agent reads the design out of Paper and hands it over. From there it is normalised into the same design object the Figma bridge uses, kept in a library, and converted to core blocks or passed to the active builder\'s skill — with its colours and type scale matched against the tokens this site already has.', 'nibwp'),
            'plugin_available' => function_exists('nibwp_paper_is_connected') && nibwp_paper_is_connected(),
            'enabled' => nibwp_is_integration_enabled('paper'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M5 2h9l5 5v15H5z" opacity=".35"/><path d="M14 2l5 5h-5z"/><path d="M8 11h8v2H8zm0 4h6v2H8z"/></svg>',
            'category' => 'design',
            'abilities' => ['paper-manage', 'paper-pro-plan', 'paper-pro-convert', 'paper-pro-refine', 'paper-pro-audit'],
            'plugin_name' => 'paper.design',
            // Same card treatment as Figma — there is no local plugin to detect,
            // so the state is paired / not paired rather than installed. Unlike
            // Figma there is no modal to open: pairing is a workflow the agent
            // has to run, and that workflow lives on the Paper screen.
            'cloud' => true,
            'connect_url' => 'admin.php?page=nibwp-paper&tab=connect',
        ],
        'elementor' => [
            'name' => 'Elementor',
            'description' => __('Create pages with sections, build custom atomic widgets, manage global colors/fonts, create and manage templates.', 'nibwp'),
            'plugin_available' => defined('ELEMENTOR_VERSION'),
            'enabled' => nibwp_is_integration_enabled('elementor'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zM8 17V7h2v10H8zm4 0V7h4v2h-2v2h2v2h-2v2h2v2h-4z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['elementor-create-page', 'elementor-create-widget', 'elementor-global-styles', 'elementor-manage-templates', 'elementor-list-pages', 'elementor-get-page-structure'],
            'actions' => 10,
            'examples' => ['elementor-create-page', 'elementor-create-widget', 'get', 'set', 'add', 'list'],
            'plugin_name' => 'Elementor',
        ],
        'hfe' => [
            'name' => 'Elementor Header & Footer Builder',
            'description' => __('Manage the Elementor header, footer and block templates on this site: list them, create one, set which pages and roles it appears on, and switch the builder\'s own widgets on or off. Also reports which templates are published with no display rules, so they render nowhere.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('hfe'),
            'enabled' => nibwp_is_integration_enabled('hfe'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['hfe-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'Elementor Header & Footer Builder',
        ],
        'eael' => [
            'name' => 'Essential Addons for Elementor',
            'description' => __('Manage this element pack\'s theme builder templates: list the headers and footers it stores, create one, and set the display rules that decide where it appears. Also switches any of the pack\'s elements and page extensions on or off, and reports templates that are published with no display rules, so they render nowhere.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('eael'),
            'enabled' => nibwp_is_integration_enabled('eael'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['eael-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'Essential Addons for Elementor',
        ],
        'elementskit' => [
            'name' => 'ElementsKit',
            'description' => __('Manage the templates and custom widgets this add-on stores: list the header and footer templates, create one, switch it on and set which pages it covers, and turn the add-on\'s own feature modules on or off. Also reports templates that were never opened in Elementor and ones published with nothing to show them on.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('elementskit'),
            'enabled' => nibwp_is_integration_enabled('elementskit'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['elementskit-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'ElementsKit',
        ],
        'happy-addons' => [
            'name' => 'Happy Elementor Addons',
            'description' => __('Manage the Happy Elementor Addons pack on this site: list the widgets it contributes to the editor and switch any of them on or off, and work with its theme builder templates — headers, footers, single and archive layouts — including the pages each one is shown on. Also reports templates that were never opened in Elementor, or that are published with no display rules and so appear nowhere.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('happy-addons'),
            'enabled' => nibwp_is_integration_enabled('happy-addons'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['happy-addons-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'Happy Elementor Addons',
        ],
        'premium-addons' => [
            'name' => 'Premium Addons for Elementor',
            'description' => __('Manage Premium Addons for Elementor on this site: report its version, list every widget it contributes to the Elementor registry, and switch any single one of its widgets, site-wide extensions or per-widget SVG-draw options on or off without disturbing the rest. Also reports which of them are currently off, which is usually the reason a section renders as nothing.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('premium-addons'),
            'enabled' => nibwp_is_integration_enabled('premium-addons'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['premium-addons-manage'],
            'actions' => 8,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_modules', 'enable_module', 'disable_module', 'audit'],
            'plugin_name' => 'Premium Addons for Elementor',
        ],
        'royal-addons' => [
            'name' => 'Royal Addons for Elementor',
            'description' => __('Manage this element pack on this site: list the headers, footers, archive and single layouts and popups it keeps in one template library, list its mega menu dropdowns and the widgets built with its widget builder, create a template, and switch any of its widgets or section extensions on or off. Also reports templates that were never opened in Elementor, so they output nothing.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('royal-addons'),
            'enabled' => nibwp_is_integration_enabled('royal-addons'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['royal-addons-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'Royal Addons for Elementor',
        ],
        'addon-elements' => [
            'name' => 'Addon Elements for Elementor',
            'description' => __('Manage this element pack on this site: report its version, list the widgets it contributes to the Elementor editor, read one widget\'s full control schema, and find which pages are built with it. Switch any single one of its modules — the widgets and the site-wide extensions it lists together and stores together — on or off without disturbing the rest, and report which are off, the usual reason a section renders as nothing.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('addon-elements'),
            'enabled' => nibwp_is_integration_enabled('addon-elements'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['addon-elements-manage'],
            'actions' => 8,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_modules', 'enable_module', 'disable_module', 'audit'],
            'plugin_name' => 'Addon Elements for Elementor',
        ],
        'jeg-kit' => [
            'name' => 'Jeg Kit for Elementor',
            'description' => __('Manage the Jeg Kit pack on this site: list the elements it contributes to the editor and switch any of them on or off, and work with its header, footer and theme builder templates — list them, create one, and set which pages, archives or post types each one is shown on. Also reports templates that were never opened in Elementor, and published ones whose display rules were never stored, which output nowhere.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('jeg-kit'),
            'enabled' => nibwp_is_integration_enabled('jeg-kit'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['jeg-kit-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'Jeg Kit for Elementor',
        ],
        'plus-addons' => [
            'name' => 'The Plus Addons for Elementor',
            'description' => __('Manage this add-on pack on this site: list the widgets it contributes to the Elementor editor, read one widget\'s full control schema before building with it, find which pages are built with a given widget, and switch any of its widgets on or off. A widget switched off is never registered with Elementor at all, so a page already using it keeps the widget in its saved data and renders nothing in its place — the audit reports how many are off before anything is changed. This pack keeps no template post type of its own, so there are no template actions here.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('plus-addons'),
            'enabled' => nibwp_is_integration_enabled('plus-addons'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['plus-addons-manage'],
            'actions' => 8,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_modules', 'enable_module', 'disable_module', 'audit'],
            'plugin_name' => 'The Plus Addons for Elementor',
        ],
        'qi-addons' => [
            'name' => 'Qi Addons for Elementor',
            'description' => __('Manage this widget pack on this site: report its version, list every widget it contributes to the Elementor editor, read one widget\'s full control schema, find the pages built with it, and switch any single widget off or back on. It keeps no templates of its own, so this is inventory, usage and the widget on/off list rather than a template library.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('qi-addons'),
            'enabled' => nibwp_is_integration_enabled('qi-addons'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['qi-addons-manage'],
            'actions' => 8,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_modules', 'enable_module', 'disable_module', 'audit'],
            'plugin_name' => 'Qi Addons for Elementor',
        ],
        'unlimited-elements' => [
            'name' => 'Unlimited Elements for Elementor',
            'description' => __('Take stock of this widget pack on this site: list the widgets it contributes to the editor, read one widget\'s full control schema so a layout can be built with it, and find which pages and templates already use it. This is inventory and usage rather than management — the pack keeps its widget catalogue and each widget\'s on/off state in database tables of its own, so widgets are switched on and off from the add-on\'s own Elements screen, and its templates are saved as Elementor library posts that the Elementor abilities handle.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('unlimited-elements'),
            'enabled' => nibwp_is_integration_enabled('unlimited-elements'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['unlimited-elements-manage'],
            'actions' => 5,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'audit'],
            'plugin_name' => 'Unlimited Elements for Elementor',
        ],
        'anywhere-elementor' => [
            'name' => 'Dynific Addons for Elementor',
            'description' => __('Manage this global template pack on this site: list the templates it keeps in one library, create one, set which post type or taxonomy a template takes over and whether it applies to all of them, and switch the pack\'s own modules on or off. Every template can also be inserted anywhere by shortcode, so the audit only reports the three kinds that really are matched by a stored rule.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('anywhere-elementor'),
            'enabled' => nibwp_is_integration_enabled('anywhere-elementor'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['anywhere-elementor-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'Dynific Addons for Elementor',
        ],
        'elementinvader' => [
            'name' => 'ElementInvader Addons for Elementor',
            'description' => __('Take stock of this widget pack on this site: list the widgets it contributes to the editor, read one widget\'s full control schema so a layout can be built with it, and find which pages and templates already use it, which is the question that matters before removing or replacing the pack. This is inventory and usage rather than management — the pack registers no templates of its own and has nothing an owner can switch on or off, so there is nothing else here to manage.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('elementinvader'),
            'enabled' => nibwp_is_integration_enabled('elementinvader'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['elementinvader-manage'],
            'actions' => 5,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'audit'],
            'plugin_name' => 'ElementInvader Addons for Elementor',
        ],
        'ht-slider' => [
            'name' => 'HT Slider for Elementor',
            'description' => __('Manage this slider pack on this site: list the slides it keeps as its own post type, create one, retitle or delete it, list the widgets it contributes to the Elementor editor, read one widget\'s control schema, and find which pages are built with them. The pack keeps no display rules and no on/off switches, so where a slide appears is decided by the widget or the shortcode that collects it, and this integration says so rather than offering settings that do not exist.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('ht-slider'),
            'enabled' => nibwp_is_integration_enabled('ht-slider'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['ht-slider-manage'],
            'actions' => 10,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'HT Slider for Elementor',
        ],
        'move-addons' => [
            'name' => 'Move Addons for Elementor',
            'description' => __('Manage this widget pack on this site: report its version, list every widget it contributes to the Elementor editor, read one widget\'s full control schema, find the pages built with it, and switch any single widget off or back on. It keeps no templates of its own, so this is inventory, usage and the widget on/off list rather than a template library.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('move-addons'),
            'enabled' => nibwp_is_integration_enabled('move-addons'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['move-addons-manage'],
            'actions' => 8,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_modules', 'enable_module', 'disable_module', 'audit'],
            'plugin_name' => 'Move Addons for Elementor',
        ],
        'htmega' => [
            'name' => 'HT Mega Addons for Elementor',
            'description' => __('Manage this element pack on this site: list the widgets it contributes to the editor and switch any of them on or off, and work with its theme builder templates — header, footer, single and blog layouts — including which template each slot is set to. Also reports templates that were never opened in Elementor, and published templates no slot is set to, so they appear nowhere.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('htmega'),
            'enabled' => nibwp_is_integration_enabled('htmega'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['htmega-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'HT Mega Addons for Elementor',
        ],
        'lastudio-kit' => [
            'name' => 'LA-Studio Element Kit for Elementor',
            'description' => __('Manage this widget pack on this site: report its version, list every widget it contributes to the Elementor editor, read one widget\'s full control schema, find the pages built with it, and switch any single widget off or back on. It keeps no template library of its own — its theme builder builds on Elementor\'s own saved templates — so this is inventory, usage and the widget on/off list rather than a template library.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('lastudio-kit'),
            'enabled' => nibwp_is_integration_enabled('lastudio-kit'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['lastudio-kit-manage'],
            'actions' => 8,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_modules', 'enable_module', 'disable_module', 'audit'],
            'plugin_name' => 'LA-Studio Element Kit for Elementor',
        ],
        'mega-elements' => [
            'name' => 'Mega Elements - Addons for Elementor',
            'description' => __('Manage this widget pack on this site: report its version, list the widgets it contributes to the Elementor editor, read one widget\'s full control schema so a layout can be built with it, and find which pages already use it. Switch any single one of its widgets on or off without disturbing the rest, and report which are off — the usual reason a section renders as nothing. The pack keeps no templates of its own, so the layouts its widgets sit in are ordinary posts and pages that the Elementor abilities handle.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('mega-elements'),
            'enabled' => nibwp_is_integration_enabled('mega-elements'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['mega-elements-manage'],
            'actions' => 8,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_modules', 'enable_module', 'disable_module', 'audit'],
            'plugin_name' => 'Mega Elements - Addons for Elementor',
        ],
        'wpb-addons' => [
            'name' => 'WPB Elementor Addons',
            'description' => __('Manage this widget pack on this site: list the fifteen widgets it contributes to the Elementor editor, read one widget\'s full control schema so a layout can be built with it, find which pages already use it, and switch any of its elements on or off — the pack gates every widget registration on that switch, so one that is off is not in the editor at all. It keeps no templates of its own, so there is nothing else here to manage.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('wpb-addons'),
            'enabled' => nibwp_is_integration_enabled('wpb-addons'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['wpb-addons-manage'],
            'actions' => 8,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_modules', 'enable_module', 'disable_module', 'audit'],
            'plugin_name' => 'WPB Elementor Addons',
        ],
        'rometheme' => [
            'name' => 'Rometheme for Elementor',
            'description' => __('Manage the Rometheme kit on this site: its header, footer, archive, single, search and 404 templates, which pages each applies to, whether each is switched on, and which of the kit\'s modules are enabled. Also reports templates that are published but inactive, so they render nowhere.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('rometheme'),
            'enabled' => nibwp_is_integration_enabled('rometheme'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm1 2v14h14V5H5z"/><path d="M9 8h2v3h2V8h2v3a3 3 0 01-2 2.83V17h-2v-3.17A3 3 0 019 11V8z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['rometheme-manage'],
            'actions' => 13,
            'examples' => ['info', 'list_widgets', 'describe_widget', 'find_usage', 'list_templates', 'get_template', 'create_template', 'update_template'],
            'plugin_name' => 'Rometheme for Elementor',
        ],
        'mailpoet' => [
            'name' => 'MailPoet',
            'description' => __('Run the mailing list from where the work is: lists and who is on them, subscribers added, updated, unsubscribed or moved between lists, custom subscriber fields, newsletters and post-notification campaigns with their open and click statistics, and the sending method\'s own queue. Audits the three things that quietly kill a list — lists with nobody on them, campaigns stuck in the queue, and a bounced or never-confirmed share large enough to say the list is decaying. There is deliberately no action that sends a campaign to a list: irreversible mail to real people is not something to trigger from a tool call. A test send to one named address is available and says so. A subscriber\'s unsubscribe token or link hash never leaves, because holding one lets somebody act as that person.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('mailpoet'),
            'enabled' => nibwp_is_integration_enabled('mailpoet'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/><path d="M7 18h4"/></svg>',
            'category' => 'crm',
            'abilities' => ['mailpoet-manage'],
            'actions' => 24,
            'examples' => ['list_lists', 'delete_list', 'add_subscriber', 'delete_subscriber', 'list_subscriber_fields', 'get_campaign', 'bounce_report', 'sending_queue'],
            'plugin_name' => 'MailPoet',
        ],
        'everest-forms' => [
            'name' => 'Everest Forms',
            'description' => __('Build and run forms without opening the builder: list them, read one with its whole field structure, create one from a description, add, update, reorder or remove fields, and set the success message, redirect, required fields and anti-spam. Reads entries, counts them over a period, and marks them read. Audits the two failures nobody notices until a customer complains — a form with no notification at all, and a form that has never received a single entry. The field structure is written in exactly the shape the plugin\'s own builder writes, so a form this touches still opens there. Entries are personal data and sit behind the plugin\'s own entries capability; captcha secrets and mail keys are reported as a name and whether they are set.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('everest-forms'),
            'enabled' => nibwp_is_integration_enabled('everest-forms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h3"/></svg>',
            'category' => 'forms',
            'abilities' => ['everest-forms-manage'],
            'actions' => 27,
            'examples' => ['list_forms', 'get_form', 'create_form', 'add_field', 'set_notification', 'list_entries', 'count_entries', 'audit_no_notification'],
            'plugin_name' => 'Everest Forms',
        ],
        'metform' => [
            'name' => 'MetForm',
            'description' => __('Manage forms and their entries: list forms, read one with its fields, create and rename, set the success message, redirect and email notifications, and read, count or delete entries. Audits forms with no notification configured and forms that never received an entry. Because these forms are built as Elementor content, the field structure lives in Elementor\'s saved data — so field writes are read-only here rather than risk a form that no longer opens in its editor, and the response says so plainly. A form id is checked against the plugin\'s own post type before any capability, so a delete cannot be handed the id of a page.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('metform'),
            'enabled' => nibwp_is_integration_enabled('metform'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>',
            'category' => 'forms',
            'abilities' => ['metform-manage'],
            'actions' => 22,
            'examples' => ['forms_overview', 'list_forms', 'get_form_fields', 'get_notifications', 'list_entries', 'count_entries', 'audit_forms_without_notification', 'audit_forms_without_entries'],
            'plugin_name' => 'MetForm',
        ],
        'survey-maker' => [
            'name' => 'Survey Maker',
            'description' => __('Build surveys and read what they found, in aggregate: surveys with their questions and answer options, per-question answer counts and percentages, response totals over a period, and an audit of surveys published but never answered or questions with no options to choose from. Answers are what a real person said, so the aggregate actions are the ones to reach for; the individual-response reads are bounded and never return a respondent\'s IP address or email — a logged-in respondent is reported as a flag, not an identity. Deleting a survey deletes every response anybody ever gave it, which that action says before it does it.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('survey-maker'),
            'enabled' => nibwp_is_integration_enabled('survey-maker'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/></svg>',
            'category' => 'forms',
            'abilities' => ['survey-maker-manage'],
            'actions' => 18,
            'examples' => ['overview', 'list_surveys', 'get_survey', 'list_questions', 'survey_results', 'question_results', 'response_totals', 'audit'],
            'plugin_name' => 'Survey Maker',
        ],
        'license-manager' => [
            'name' => 'License Manager for WooCommerce',
            'description' => __('Run the licence desk for a store: search keys by status, product, order, customer or expiry, create them, change a status, expiry or activation limit, manage generators and what they are attached to, read and deactivate activations, and find a customer\'s licences by order or email. Audits the four things that cost support time — licences expiring soon, licences over their activation limit, keys never activated, and orders whose licences were never generated. A licence key is a bearer credential: a search or listing returns a masked form and the metadata, never the whole key. One deliberately named action reveals a single whole key, because an owner does legitimately need to resend one, and it says that it is handing over a live credential.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('license-manager'),
            'enabled' => nibwp_is_integration_enabled('license-manager'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M15 7a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="M11 11H3v3h3v3h3v-3h2"/></svg>',
            'category' => 'utilities',
            'abilities' => ['license-manager-manage'],
            'actions' => 23,
            'examples' => ['search_licenses', 'get_license', 'create_license', 'update_license_status', 'list_generators', 'list_activations', 'licenses_for_order', 'audit_expiring_licenses'],
            'plugin_name' => 'License Manager for WooCommerce',
        ],
        'stackable' => [
            'name' => 'Stackable',
            'description' => __('Build with the real block vocabulary instead of guessing at it: every block this version registers, each with its actual attribute schema read live from the block registry, so an agent writes attribute names that exist. Reports and changes which individual blocks are switched on or off, the global colours and typography, and which posts use a given block. Audits the commonest “my section disappeared” report — posts still holding a block that is currently switched off, which stays in the saved content and renders nothing. Posts are checked one at a time, so a listing can hold fewer than the site has and says how many it skipped.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('stackable'),
            'enabled' => nibwp_is_integration_enabled('stackable'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['stackable-manage'],
            'actions' => 21,
            'examples' => ['overview', 'get_block', 'list_variants', 'get_block_state', 'global_typography', 'breakpoints', 'block_usage', 'set_block_state'],
            'plugin_name' => 'Stackable',
        ],
        'generateblocks' => [
            'name' => 'GenerateBlocks',
            'description' => __('Give an agent the block vocabulary it needs to build with: every block type this version registers with its real attribute schema read from the registry, which posts and pages are built with a given block, the plugin\'s global style and settings defaults, and its reusable styles. Audits posts whose block markup no longer validates and blocks left sitting on placeholder content. Anything touching a specific post asks about that post, not about posts in general, and a listing says how many it had to leave out.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('generateblocks'),
            'enabled' => nibwp_is_integration_enabled('generateblocks'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="10" height="6" rx="1"/><rect x="16" y="14" width="5" height="6" rx="1"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['generateblocks-manage'],
            'actions' => 18,
            'examples' => ['overview', 'list_block_types', 'list_block_attributes', 'find_posts_using_block', 'get_settings', 'list_global_classes', 'audit_invalid_markup', 'audit_placeholder_content'],
            'plugin_name' => 'GenerateBlocks',
        ],
        'gutenverse' => [
            'name' => 'Gutenverse',
            'description' => __('Build with every block this version registers, each with its attribute schema read live from the block registry rather than from a list that goes stale. Reads and writes the global settings and theme styles, manages the template and section library with the rules that decide where each one shows, and finds which posts use a given block. Audits posts still holding a block this build no longer registers — they stay in the saved content and render nothing. An id from a caller is treated as a claim: the post type is checked first and alone, so a delete cannot be handed the id of an ordinary page.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('gutenverse'),
            'enabled' => nibwp_is_integration_enabled('gutenverse'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['gutenverse-manage'],
            'actions' => 19,
            'examples' => ['overview', 'list_blocks', 'get_block', 'find_block_usage', 'get_settings', 'get_global_style', 'list_templates', 'audit_unregistered_blocks'],
            'plugin_name' => 'Gutenverse',
        ],
        'surerank' => [
            'name' => 'SureRank',
            'description' => __('Read and write SureRank SEO on this site. Per post: get_post_seo, update_post_seo, clear_post_seo, get_post_seo_checks and bulk_get_post_seo over a whole post type. Site-wide: get_settings, get_title_templates, get_robots_settings, get_sitemap_state, get_robots_txt, get_social_defaults, get_schema_settings, get_feature_flags, get_search_console_status, list_content_types, and the matching update_title_templates, update_robots_settings, update_sitemap_settings, update_robots_txt and update_social_defaults. The audit — audit, audit_duplicates, audit_noindex_in_sitemap, audit_summary — finds the faults that quietly cost traffic: no meta description, titles and descriptions outside the lengths that render, the same title or description on more than one page, content indexed in the sitemap while its post type says noindex, no canonical on paginated content, and no social image. Writing a robots directive or a canonical can remove a page from Google. The Search Console tokens, the site-verification token and the AI account record are never returned.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('surerank'),
            'enabled' => nibwp_is_integration_enabled('surerank'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="m3 17 6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>',
            'category' => 'seo',
            'abilities' => ['surerank-manage'],
            'actions' => 24,
            'examples' => ['get_post_seo', 'update_post_seo', 'get_title_templates', 'get_robots_txt', 'get_feature_flags', 'update_title_templates', 'update_robots_txt', 'audit_duplicates'],
            'plugin_name' => 'SureRank',
        ],
        'suredash' => [
            'name' => 'SureDash',
            'description' => __('Read and run a SureDash members\' portal: its spaces and sidebar groups, the sidebar widgets on each space, who can reach what, the members and their contribution counts, the posts and comments members have written, the emails the portal sends, and an audit of what needs an owner\'s attention. Writes: create and update a space, change a space\'s access, create and update a sidebar group, reorder groups and spaces, set a space\'s widgets, moderate or delete a member\'s post, moderate a comment. Moderating or deleting destroys something a real member wrote, and changing a space\'s access changes what paying members can reach — both say so plainly and both report exactly what changed. This build has no direct or private messages, and no action here would return a message body if it did. No password hash, session token, application password or verification key leaves this hub, and the portal\'s stored third-party credentials (Google, Facebook, reCAPTCHA, Turnstile, Giphy) are reported as a name and whether they are set, never as a value.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('suredash'),
            'enabled' => nibwp_is_integration_enabled('suredash'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="13" y="10" width="8" height="11" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/></svg>',
            'category' => 'community',
            'abilities' => ['suredash-manage'],
            'actions' => 26,
            'examples' => ['community_summary', 'get_space', 'list_members', 'member_access', 'email_notifications', 'update_space', 'update_group', 'set_space_widgets'],
            'plugin_name' => 'SureDash',
        ],
        'sureforms' => [
            'name' => 'SureForms',
            'description' => __('Read and manage SureForms: list the forms, read one with its field structure, read and change what happens after a submission (the success message, the redirect, the email notification and its recipients), switch the spam protection, read entries and count them over a period, mark one read, export a bounded set, and audit for forms that tell nobody when a submission arrives, forms notifying somewhere other than the site admin, forms that have never received an entry, and forms taking traffic with the spam protection switched off. Creates, renames, duplicates, trashes and permanently deletes forms, and permanently deletes a single entry. Fields are READ-ONLY: a SureForms form is a Gutenberg document and a field\'s submission key is derived from its block_id, slug and label together, so writing one from outside the editor silently decouples new submissions from the existing entries — use the block editor for that. Entries are personal data and take manage_options, which is what SureForms requires for its own entry abilities. No captcha secret, API key, token, webhook URL or licence key is ever returned.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('sureforms'),
            'enabled' => nibwp_is_integration_enabled('sureforms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>',
            'category' => 'forms',
            'abilities' => ['sureforms-manage'],
            'actions' => 27,
            'examples' => ['forms_overview', 'get_form_fields', 'list_notifications', 'count_entries', 'audit_notification_recipients', 'create_form', 'delete_form_permanently', 'set_notification'],
            'plugin_name' => 'SureForms',
        ],
        'suretriggers' => [
            'name' => 'SureTriggers',
            'description' => __('Read what the SureTriggers automation plugin keeps on THIS site. Be clear about the model before you answer anything: the automations are not stored here. Recipes, their steps, their filters and every credential for a connected app live in the vendor\'s hosted service, and the WordPress screen is a frame around that service. What is local, and what this reads: the trigger subscriptions the service pushes down so the site knows which hooks to watch; the full catalogue of triggers and actions this install registers and for which other plugins, which is the useful part when planning an automation; the delivery log of outbound trigger sends, as counts, statuses, response codes and timings; the connection state, the background schedules, and who may open the automation dashboard. So this is a deliberately small integration — to list, read, edit or switch off an automation, the owner goes to the hosted dashboard, and saying so is the correct answer. Nothing here runs, re-runs, retries or re-sends an automation, and nothing here re-arms the retry schedule, because that re-sends too. Two writes, both the same local switch: turn delivery logging on or off. The connection secret and any third-party credential are never returned, and neither is a delivery payload.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('suretriggers'),
            'enabled' => nibwp_is_integration_enabled('suretriggers'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M13 2 4.5 13H11l-1 9 8.5-11H12l1-9Z"/></svg>',
            'category' => 'utilities',
            'abilities' => ['suretriggers-manage'],
            'actions' => 15,
            'examples' => ['overview', 'connection_status', 'list_trigger_subscriptions', 'list_integrations', 'search_events', 'list_delivery_log', 'access_control', 'enable_delivery_log'],
            'plugin_name' => 'SureTriggers',
        ],
        'seedprod' => [
            'name' => 'SeedProd',
            'description' => __('Read and manage SeedProd: whether coming soon or maintenance mode is on and which page each uses, the landing pages SeedProd built, their SEO, access and bypass settings, the email subscribers captured, and an audit of the misconfigurations that cost the most. Turning coming soon or maintenance mode on takes the entire public site offline for visitors and search engines; set_mode is the only action that can do it, it needs manage_options, and it reports exactly what changed. Page content is read-only: the layout lives in SeedProd\'s own builder format across two columns that must agree, and only the builder can write it. No licence key, service token, third-party API key or coming-soon bypass phrase is ever returned.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('seedprod'),
            'enabled' => nibwp_is_integration_enabled('seedprod'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M3 15s2-6 9-6 9 6 9 6"/><path d="M12 9V3"/><path d="M8 20h8"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['seedprod-manage'],
            'actions' => 21,
            'examples' => ['summary', 'list_pages', 'access_settings', 'analytics_settings', 'list_subscribers', 'assign_mode_page', 'rename_page', 'trash_page'],
            'plugin_name' => 'SeedProd',
        ],
        'siteorigin' => [
            'name' => 'SiteOrigin Page Builder',
            'description' => __('Read and edit SiteOrigin Page Builder layouts: find the pages built with the builder, read one as rows, cells and widgets, edit a widget\'s settings, add, remove or move a widget between cells, add, remove, reorder rows and change a row\'s cell layout and cell weights. Also reports which widget types this site can render, the plugin\'s own settings, the widget bundle it ships, and the reusable layouts available. The audit finds the page faults that look like content vanishing: a widget whose class is no longer registered, empty rows and cells, a widget pointing at a row or cell that does not exist, a stale copy of the rendered page, and a layout that exists but is not what renders. Layout writes go through Page Builder\'s own sanitiser, never straight into post meta.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('siteorigin'),
            'enabled' => nibwp_is_integration_enabled('siteorigin'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="13" width="8" height="7" rx="1"/><rect x="13" y="13" width="8" height="7" rx="1"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['siteorigin-manage'],
            'actions' => 25,
            'examples' => ['summary', 'layout_outline', 'get_widget', 'remove_widget', 'add_row', 'set_row_cells', 'update_settings', 'get_prebuilt_layout'],
            'plugin_name' => 'SiteOrigin Page Builder',
        ],
        'eventprime' => [
            'name' => 'EventPrime',
            'description' => __('Read and manage EventPrime. Events: list_events, get_event, create_event, update_event, set_event_status, trash_event, delete_event_permanently. Venues: list_venues, get_venue, create_venue, update_venue. Event types: list_event_types, create_event_type. Organizers: list_organizers. Performers: list_performers, get_performer, create_performer. Bookings: list_bookings, get_booking, count_bookings, event_attendance. Capacity: event_capacity, list_tickets. Calendar: calendar_between, upcoming_events. Plus get_settings and audit_events, which reports what is wrong with the calendar.nibwp', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('eventprime'),
            'enabled' => nibwp_is_integration_enabled('eventprime'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/><circle cx="12" cy="16" r="2"/></svg>',
            'category' => 'events',
            'abilities' => ['eventprime-manage'],
            'actions' => 27,
            'examples' => ['list_events', 'update_event', 'delete_event_permanently', 'update_venue', 'list_organizers', 'create_performer', 'event_attendance', 'calendar_between'],
            'plugin_name' => 'EventPrime',
        ],
        'mailoptin' => [
            'name' => 'MailOptin',
            'description' => __('Manage MailOptin on this site: optin campaigns and their display rules, the leads they captured, which email service each campaign feeds, impression and conversion statistics, the newsletters and automated emails this build has, plus an audit that names campaigns shown to everybody that convert nobody, campaigns with no connected list so their leads go nowhere, campaigns with no display rules at all, and a connection whose credentials are missing. There is deliberately NO action that sends a newsletter or a broadcast — that stays in wp-admin where the recipient count is on screen. Enabling or disabling a campaign changes what every visitor to the site sees, immediately. Deleting a campaign or a lead is irreversible; both need confirm: true and manage_options, and deleting a campaign leaves its captured leads behind rather than removing them. No email-service API key, OAuth token or licence key is ever returned — those come back as a name and whether they are set. Nothing here contacts an email service: it reads this site only.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('mailoptin'),
            'enabled' => nibwp_is_integration_enabled('mailoptin'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M3 8l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M12 14v5"/></svg>',
            'category' => 'crm',
            'abilities' => ['mailoptin-manage'],
            'actions' => 23,
            'examples' => ['list_campaigns', 'create_campaign', 'disable_campaign', 'set_display_rule', 'count_leads', 'list_connections', 'stats_leaderboard', 'get_email_campaign'],
            'plugin_name' => 'MailOptin',
        ],
        'eventin' => [
            'name' => 'Eventin',
            'description' => __('Read and manage Eventin. Events: list_events, get_event, create_event, update_event, set_event_status, trash_event, delete_event_permanently, upcoming_events. People: list_speakers, get_speaker, speaker_events, list_organizers, get_organizer, organizer_events. Agenda: list_schedules, get_schedule, event_schedule. Tickets: list_ticket_types, ticket_availability. Attendees: list_attendees, get_attendee, count_attendees, list_checkins. Money: event_revenue. Configuration: event_settings. And audit_calendar, which reports what is wrong with the calendar. delete_event_permanently is irreversible and destroys the event its attendee records hang off, leaving every booking for it unreadable; it requires confirm: true. Attendee reads need the etn_manage_attendee capability. There is no refund or cancel action.nibwp', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('eventin'),
            'enabled' => nibwp_is_integration_enabled('eventin'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/><path d="m9 16 2 2 4-4"/></svg>',
            'category' => 'events',
            'abilities' => ['eventin-manage'],
            'actions' => 26,
            'examples' => ['list_events', 'update_event', 'delete_event_permanently', 'get_speaker', 'organizer_events', 'event_schedule', 'list_attendees', 'list_checkins'],
            'plugin_name' => 'Eventin',
        ],
        'events-manager' => [
            'name' => 'Events Manager',
            'description' => __('Read and manage events, locations, categories, tags, bookings and tickets in Events Manager. This plugin keeps its own database tables beside the WordPress posts, and an event only works when both agree — every write here goes through the plugin\'s own EM_Event and EM_Location classes so it stays that way, and five audits report where it already does not. Bookings are real people: contact details and payment data never leave, and reading bookings takes the manage_bookings capability the plugin gates its own Bookings screen on. Nothing here can refund a booking, delete a booking, or change what someone was charged.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('events-manager'),
            'enabled' => nibwp_is_integration_enabled('events-manager'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/><path d="M7 15h4M7 18h7"/></svg>',
            'category' => 'events',
            'abilities' => ['events-manager-manage'],
            'actions' => 27,
            'examples' => ['list_events', 'update_event', 'list_recurring_events', 'update_location', 'set_event_terms', 'count_bookings', 'ticket_availability', 'audit_post_table_mismatch'],
            'plugin_name' => 'Events Manager',
        ],
        'blocksy' => [
            'name' => 'Blocksy',
            'description' => __('Read and manage a Blocksy site: theme options (colours, typography, layout, header and footer structure), the global colour palette and saved palettes, per-post Customizer overrides, Blocksy Pro Content Blocks with their display conditions, and which Companion extensions are switched on. Blocksy is a THEME and its settings are WordPress theme mods, so every write outside the per-post actions changes EVERY PAGE on the site and takes the edit_theme_options capability WordPress gates the Customizer on. Writes go through set_theme_mod() one key at a time, so settings this integration does not understand survive untouched. Licence keys and third-party credentials never leave: Companion options report a name and whether they are set unless they are on a short allow-list. audit_customization reports stale per-post overrides, Content Blocks with no display condition, extensions switched on with nothing configured, and palette colours nothing references.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('blocksy'),
            'enabled' => nibwp_is_integration_enabled('blocksy'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/></svg>',
            // Blocksy is a theme with a builder's job: headers, footers, Content
            // Blocks and layout. It belongs next to the builders people compare
            // it against, not in Design, which is where the design tools we
            // bridge to live.
            'category' => 'page-builders',
            'abilities' => ['blocksy-manage'],
            'actions' => 26,
            'examples' => ['get_theme_info', 'set_theme_options', 'set_color_palette', 'delete_saved_palette', 'get_footer_structure', 'set_post_overrides', 'get_content_block', 'delete_content_block'],
            'plugin_name' => 'Blocksy',
        ],
        'themify' => [
            'name' => 'Themify Builder',
            'description' => __('Read and edit Themify Builder content: find the pages built with the builder, read one as rows, columns and modules, edit a module\'s settings, add, remove or move a module between columns, add, remove, reorder and restyle rows, and change a row\'s column widths. Also reports which module types this site can render, Themify\'s layout parts and saved layouts with the display conditions that govern them, the global styles the site defines, and the builder\'s own settings. The audit finds the faults that look like content vanishing: a module whose type is no longer registered, empty rows and columns, a stale plain-text mirror or an unmigrated legacy meta row, and layout parts that nothing on the site embeds. Builder writes go through Themify\'s own save seam, never straight into post meta.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('themify'),
            'enabled' => nibwp_is_integration_enabled('themify'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="4" width="18" height="5" rx="1"/><rect x="3" y="11" width="8" height="9" rx="1"/><rect x="13" y="11" width="8" height="9" rx="1"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['themify-manage'],
            'actions' => 26,
            'examples' => ['summary', 'layout_outline', 'remove_row', 'add_column', 'get_module', 'remove_module', 'get_layout_part', 'update_settings'],
            'plugin_name' => 'Themify Builder',
        ],
        'wordfence' => [
            'name' => 'Wordfence',
            'description' => __('Answer the one security question an owner cannot answer from the dashboard: is this site actually protected. Reports whether the firewall runs before WordPress loads or only after it, when the last scan ran and how it ended, what it found and how much of it is critical, which installed plugins or themes are known to be vulnerable, whether administrators are still allowed to sign in with a password alone, and what has been blocked lately as counts by reason. Read-mostly on purpose: three writes and no others — start a scan, block an IP, unblock an IP. Nothing here can switch the firewall or the scanner off, change or whitelist a rule, turn off two-factor, or repair or delete a file the scanner flagged; those stay in wp-admin where the consequences are in front of a person. The plugin\'s API key and any two-factor secret are never returned, and the blocked-traffic report gives counts rather than visitors\' addresses.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('wordfence'),
            'enabled' => nibwp_is_integration_enabled('wordfence'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>',
            'category' => 'security',
            'abilities' => ['wordfence-manage'],
            'actions' => 12,
            'examples' => ['security_summary', 'protection_status', 'scan_findings', 'vulnerable_components', 'blocked_activity', 'list_blocks', 'start_scan', 'block_ip'],
            'plugin_name' => 'Wordfence',
        ],
        'wp-mail-smtp' => [
            'name' => 'WP Mail SMTP',
            'description' => __('Answer the question a silently broken store cannot: can this site send mail at all. Reports the active mailer and whether it is fully configured, the From name and address, the recorded send failure, and the errors logged over a period — then runs a connection test that drives the provider session without delivering anything. Also checks SPF, DKIM and DMARC. Credentials are never returned: every API key, password, client secret and token is reported by name and whether it is set, and a value that comes from wp-config.php is named as a constant rather than read out.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('wp-mail-smtp'),
            'enabled' => nibwp_is_integration_enabled('wp-mail-smtp'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>',
            'category' => 'utilities',
            'abilities' => ['wp-mail-smtp-manage'],
            'actions' => 20,
            'examples' => ['status', 'get_diagnostics', 'get_mailer', 'list_mailers', 'get_mailer_settings', 'test_connection', 'last_send_failure', 'count_failures'],
            'plugin_name' => 'WP Mail SMTP',
        ],
        'ultimate-member' => [
            'name' => 'Ultimate Member',
            'description' => __('Manage member accounts and what they can reach: who is pending, approved, rejected or deactivated, and move them between those states; the plugin\'s own roles and permissions; its registration and profile forms and their fields; member directories; content restriction, site-wide and per post; and the emails it sends. Member reads are asked per member, so a response can hold fewer records than the site has and says how many it skipped. A password hash, a session token, an application password or a capability map never leaves, whatever it is stored under.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('ultimate-member'),
            'enabled' => nibwp_is_integration_enabled('ultimate-member'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'category' => 'membership',
            'abilities' => ['ultimate-member-manage'],
            'actions' => 25,
            'examples' => ['plugin_info', 'list_members', 'get_member', 'member_status_counts', 'approve_member', 'list_roles', 'list_forms', 'get_restriction_settings'],
            'plugin_name' => 'Ultimate Member',
        ],
        'thirstyaffiliates' => [
            'name' => 'ThirstyAffiliates',
            'description' => __('Manage cloaked affiliate links: list them, create one, repoint a destination, rename a slug, set the redirect type and the nofollow, new-window and uncloak flags, and organise link categories. Finds which posts use a given link, and reports the three things that quietly lose clicks — links with no destination at all, cloaked paths that shadow a real page, and destinations that now answer with a redirect or an error. Renaming a slug or changing the link prefix breaks copies already published elsewhere, so those actions ask for confirmation and say what they will break.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('thirstyaffiliates'),
            'enabled' => nibwp_is_integration_enabled('thirstyaffiliates'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M10 13a5 5 0 007.07 0l2.12-2.12a5 5 0 00-7.07-7.07L10.7 5.24"/><path d="M14 11a5 5 0 00-7.07 0L4.81 13.1a5 5 0 007.07 7.07L13.3 18.76"/></svg>',
            'category' => 'utilities',
            'abilities' => ['thirstyaffiliates-manage'],
            'actions' => 23,
            'examples' => ['list_links', 'get_link', 'create_link', 'update_link', 'set_destination', 'set_slug', 'find_link_usage', 'audit_destinations'],
            'plugin_name' => 'ThirstyAffiliates',
        ],
        'elementor-addons' => [
            'name' => 'Elementor Add-on Packs',
            'description' => __('Work with the add-on packs actually installed beside Elementor — Ultimate Addons, Essential Addons, ElementsKit, Premium Addons, Royal Addons, HappyAddons and any other. Every pack is identified from where its widget classes live on disk, so premium and brand-new packs are covered too. Detect packs, list their widgets, read any widget\'s full control schema, find where a widget is used, and audit pages for widgets whose pack is gone.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('elementor-addons'),
            'enabled' => nibwp_is_integration_enabled('elementor-addons'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 3h8v8H3V3zm2 2v4h4V5H5z"/><path d="M13 3h8v8h-8V3zm2 2v4h4V5h-4z"/><path d="M3 13h8v8H3v-8zm2 2v4h4v-4H5z"/><path d="M18 13h-2v3h-3v2h3v3h2v-3h3v-2h-3v-3z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['elementor-addons-detect', 'elementor-addons-list-widgets', 'elementor-addons-describe-widget', 'elementor-addons-find-usage', 'elementor-addons-audit'],
            'actions' => 5,
            'plugin_name' => 'Elementor + at least one add-on pack',
        ],
        'bricks' => [
            'name' => 'Bricks',
            'description' => __('Create templates (header, footer, content, archive), build custom elements, manage global classes and styles.', 'nibwp'),
            'plugin_available' => defined('BRICKS_VERSION'),
            'enabled' => nibwp_is_integration_enabled('bricks'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><rect x="2" y="2" width="9" height="9" rx="1"/><rect x="13" y="2" width="9" height="9" rx="1"/><rect x="2" y="13" width="9" height="9" rx="1"/><rect x="13" y="13" width="9" height="9" rx="1"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['bricks-create-template', 'bricks-create-element', 'bricks-manage-styles'],
            'actions' => 6,
            'examples' => ['bricks-create-template', 'bricks-create-element', 'list', 'create', 'update', 'delete'],
            'plugin_name' => 'Bricks Builder',
        ],
        'builderius' => [
            'name' => 'Builderius',
            'description' => __('Read the template/version graph, components, releases and global settings; author templates, components and fragments by committing configs through Builderius\'s own git-like versioning.', 'nibwp'),
            'plugin_available' => function_exists('builderius_check_requirements') || post_type_exists('builderius_template'),
            'enabled' => nibwp_is_integration_enabled('builderius'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 3 7v10l9 5 9-5V7l-9-5zm0 2.3 6.5 3.6L12 11.5 5.5 7.9 12 4.3zM5 9.6l6 3.3v6.8l-6-3.3V9.6zm14 0v6.8l-6 3.3v-6.8l6-3.3z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['builderius-list-templates', 'builderius-get-template', 'builderius-list-versions', 'builderius-list-components', 'builderius-list-releases', 'builderius-list-fragments', 'builderius-list-global-settings', 'builderius-list-form-submissions', 'builderius-list-starters', 'builderius-list-modules', 'builderius-get-component', 'builderius-build-config', 'builderius-create-template', 'builderius-update-template', 'builderius-create-component', 'builderius-create-fragment', 'builderius-update-global-settings', 'builderius-create-branch', 'builderius-export-config', 'builderius-import-config', 'builderius-delete'],
            'plugin_name' => 'Builderius',
        ],
        'mosaic' => [
            'name' => 'Mosaic',
            'description' => __('Build pages in Mosaic: give any page its own template, insert sections and elements, restyle them per breakpoint, and grow the theme\'s classes and components, all through Mosaic\'s own editor model.', 'nibwp'),
            'plugin_available' => defined('MOSAIC_VERSION'),
            'enabled' => nibwp_is_integration_enabled('mosaic'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><rect x="3" y="3" width="8" height="5" rx="1"/><rect x="13" y="3" width="8" height="9" rx="1"/><rect x="3" y="10" width="8" height="11" rx="1"/><rect x="13" y="14" width="8" height="7" rx="1"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['mosaic-status', 'mosaic-list-themes', 'mosaic-list-templates', 'mosaic-list-components', 'mosaic-list-design', 'mosaic-list-elements', 'mosaic-get-tree', 'mosaic-create-page', 'mosaic-insert-nodes', 'mosaic-update-node', 'mosaic-delete-node', 'mosaic-create-class', 'mosaic-create-component'],
            'plugin_name' => 'Mosaic',
        ],
        'acf' => [
            'name' => 'ACF',
            'description' => __('CRUD field groups and fields, manage custom post types, taxonomies, options pages, and read/write field values.', 'nibwp'),
            'plugin_available' => function_exists('acf_get_field_groups'),
            'enabled' => nibwp_is_integration_enabled('acf'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 3h18v18H3V3zm2 2v14h14V5H5zm2 2h4v2H7V7zm0 4h10v2H7v-2zm0 4h10v2H7v-2z"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['acf-manage-fields', 'acf-manage-options'],
            'actions' => 9,
            'examples' => ['list_groups', 'get_group', 'create_group', 'update_group', 'delete_group', 'list_pages'],
            'plugin_name' => 'Advanced Custom Fields',
        ],
        'jetengine' => [
            'name' => 'JetEngine',
            'description' => __('Manage meta boxes, Custom Content Types (CCT), options pages, and read/write dynamic content values.', 'nibwp'),
            'plugin_available' => function_exists('jet_engine'),
            'enabled' => nibwp_is_integration_enabled('jetengine'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['jetengine-manage-fields'],
            'actions' => 6,
            'examples' => ['list_meta_boxes', 'create_meta_box', 'list_cct', 'create_cct', 'get_cct_items', 'create_cct_item'],
            'plugin_name' => 'JetEngine',
        ],
        'metabox' => [
            'name' => 'Meta Box',
            'description' => __('Manage field groups, register custom post types and taxonomies, create relationships, read/write meta values.', 'nibwp'),
            'plugin_available' => function_exists('rwmb_meta'),
            'enabled' => nibwp_is_integration_enabled('metabox'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 4h16v16H4V4zm2 2v12h12V6H6zm2 2h8v2H8V8zm0 4h8v2H8v-2z"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['metabox-manage-fields'],
            'actions' => 7,
            'examples' => ['list_field_groups', 'create_field_group', 'register_post_type', 'register_taxonomy', 'create_relationship', 'get_values'],
            'plugin_name' => 'Meta Box',
        ],
        'pods' => [
            'name' => 'Pods',
            'description' => __('Manage Pods, custom post types, advanced content types, add fields, list/create/update items.', 'nibwp'),
            'plugin_available' => function_exists('pods'),
            'enabled' => nibwp_is_integration_enabled('pods'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><circle cx="12" cy="12" r="3"/><circle cx="6" cy="6" r="2"/><circle cx="18" cy="6" r="2"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['pods-manage-content'],
            'actions' => 7,
            'examples' => ['list_pods', 'get_pod', 'create_pod', 'add_field', 'list_items', 'create_item'],
            'plugin_name' => 'Pods',
        ],
        'acpt' => [
            'name' => 'ACPT',
            'description' => __('Manage post types, taxonomies, meta groups, and option pages via the ACPT framework.', 'nibwp'),
            'plugin_available' => defined('ACPT_PLUGIN_VERSION'),
            'enabled' => nibwp_is_integration_enabled('acpt'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1 7V3.5L18.5 9H13zM8 13h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['acpt-manage-types'],
            'actions' => 6,
            'examples' => ['list_post_types', 'create_post_type', 'list_taxonomies', 'create_taxonomy', 'list_meta_groups', 'create_meta_group'],
            'plugin_name' => 'ACPT',
        ],
        'ase' => [
            'name' => 'ASE',
            'description' => __('Manage custom field groups, post types, taxonomies, and field values via Admin and Site Enhancements.', 'nibwp'),
            'plugin_available' => defined('ASENHA_VERSION'),
            'enabled' => nibwp_is_integration_enabled('ase'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 15.5A3.5 3.5 0 018.5 12 3.5 3.5 0 0112 8.5a3.5 3.5 0 013.5 3.5 3.5 3.5 0 01-3.5 3.5m7.43-2.53c.04-.32.07-.64.07-.97 0-.33-.03-.66-.07-1l2.11-1.63c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64L4.57 11c-.04.34-.07.67-.07 1 0 .33.03.65.07.97l-2.11 1.66c-.19.15-.25.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1.01c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.58 1.69-.98l2.49 1.01c.22.08.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.66z"/></svg>',
            'category' => 'custom-fields',
            'abilities' => ['ase-manage-fields'],
            'actions' => 8,
            'examples' => ['list_field_groups', 'create_field_group', 'list_post_types', 'create_post_type', 'list_taxonomies', 'create_taxonomy'],
            'plugin_name' => 'Admin and Site Enhancements',
        ],
        'woocommerce' => [
            'name' => 'WooCommerce',
            'description' => __('Run the store, not just read it: products and variations, orders, refunds, customers, coupons, categories, reviews, bulk stock, and an audit that finds what is quietly costing money.', 'nibwp'),
            'plugin_available' => class_exists('WooCommerce'),
            'enabled' => nibwp_is_integration_enabled('woocommerce'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M2 4h20a1 1 0 011 1v11a1 1 0 01-1 1h-7l2 4h-2l-2-4H11l-2 4H7l2-4H2a1 1 0 01-1-1V5a1 1 0 011-1zm5.5 4a1.5 1.5 0 00-1.49 1.32l-.8 4.68H6.5L7.3 9.5h.01a.5.5 0 01.44-.5h.5a.5.5 0 01.49.5l.56 4.5H10l-.8-4.68A1.5 1.5 0 007.5 8zm5 0a1.5 1.5 0 100 3 1.5 1.5 0 000-3zm5 0a1.5 1.5 0 00-1.49 1.32l-.8 4.68h1.29l.8-4.5h.01a.5.5 0 01.44-.5h.5a.5.5 0 01.49.5l.56 4.5H20l-.8-4.68A1.5 1.5 0 0017.5 8z"/></svg>',
            'category' => 'ecommerce',
            'abilities' => [
                'wc-list-products', 'wc-get-product', 'wc-create-product', 'wc-update-product', 'wc-delete-product',
                'wc-list-variations', 'wc-create-variation', 'wc-update-variation', 'wc-delete-variation',
                'wc-list-orders', 'wc-get-order', 'wc-update-order-status', 'wc-create-order', 'wc-update-order',
                'wc-create-refund', 'wc-add-order-note',
                'wc-list-customers', 'wc-get-customer', 'wc-create-customer', 'wc-update-customer',
                'wc-list-coupons', 'wc-create-coupon', 'wc-update-coupon', 'wc-delete-coupon',
                'wc-list-categories', 'wc-create-category', 'wc-update-category', 'wc-delete-category',
                'wc-list-reviews', 'wc-moderate-review',
                'wc-bulk-update-stock', 'wc-get-reports', 'wc-audit-store',
            ],
            'actions' => 37,
            'examples' => ['wc-list-products', 'wc-get-product', 'wc-create-product', 'wc-update-product', 'wc-delete-product', 'wc-list-variations'],
            'plugin_name' => 'WooCommerce',
        ],
        'seo' => [
            'name' => 'SEO Tools',
            'description' => __('Get and update SEO metadata, analyze content, check sitemaps. Works with Yoast, Rank Math, and AIOSEO.', 'nibwp'),
            'plugin_available' => defined('WPSEO_VERSION') || class_exists('WPSEO_Options')
                || defined('RANK_MATH_VERSION') || class_exists('RankMath')
                || defined('AIOSEO_VERSION') || function_exists('aioseo'),
            'enabled' => nibwp_is_integration_enabled('seo'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>',
            'category' => 'seo',
            'abilities' => ['seo-get-post-meta', 'seo-update-post-meta', 'seo-analyze-content', 'seo-get-sitemap-info', 'seo-bulk-get-meta'],
            'actions' => 5,
            'plugin_name' => 'Yoast / Rank Math / AIOSEO',
        ],
        'forms' => [
            'name' => __('Forms (Universal)', 'nibwp'),
            'description' => __('One ability that talks to whichever form plugin you have: Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms, Formidable, Forminator, Happy Forms, or JetFormBuilder. List forms, read submissions, create or delete entries.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('forms'),
            'enabled' => nibwp_is_integration_enabled('forms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>',
            'category' => 'forms',
            'abilities' => ['forms-manage'],
            'actions' => 8,
            'examples' => ['list_plugins', 'list_forms', 'get_form', 'create_form', 'delete_form', 'get_entries'],
            'plugin_name' => 'Contact Form 7 / WPForms / Gravity / Fluent / Ninja / Formidable / Forminator / Happy / JetForm',
            // Not shown as a card. Every plugin it covers now has a dedicated
            // integration with far more of that plugin's surface, so offering
            // both only invites an agent to choose the shallower one. The key
            // stays registered so anything already enabled keeps working and
            // no recorded workflow calling nibwp/forms-manage breaks.
            'hidden' => true,
        ],
        'weglot' => [
            'name' => 'Weglot',
            'description' => __('Set up and audit a Weglot translation: languages, hreflang and translated slugs, the switcher, and the exclusions that keep code and brand names out of the translator. Includes a readiness audit and a step-by-step plan built from what the site already has.', 'nibwp'),
            'plugin_available' => defined('WEGLOT_VERSION') || function_exists('weglot_get_options'),
            'enabled' => nibwp_is_integration_enabled('weglot'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm6.9 9h-3a15.7 15.7 0 0 0-1.2-5.4A8 8 0 0 1 18.9 11zM12 4c.8 1.2 1.6 3.4 1.8 7h-3.6C10.4 7.4 11.2 5.2 12 4zM4.3 13h3c.1 2 .5 3.9 1.2 5.4A8 8 0 0 1 4.3 13zm3-2h-3a8 8 0 0 1 4.2-5.4A15.7 15.7 0 0 0 7.3 11zm4.7 9c-.8-1.2-1.6-3.4-1.8-7h3.6c-.2 3.6-1 5.8-1.8 7zm2.7-.6c.7-1.5 1.1-3.4 1.2-5.4h3a8 8 0 0 1-4.2 5.4z"/></svg>',
            'category' => 'multilingual',
            'abilities' => ['weglot-info', 'weglot-languages', 'weglot-settings', 'weglot-exclusions', 'weglot-switcher', 'weglot-slugs', 'weglot-pages', 'weglot-media', 'weglot-plan', 'weglot-audit', 'weglot-workflow', 'weglot-sync'],
            'actions' => 22,
            'examples' => ['weglot-info', 'configured', 'available', 'add', 'get', 'set'],
            'plugin_name' => 'Weglot',
        ],
        'breakdance' => [
            'name' => 'Breakdance',
            'description' => __('Build with Breakdance end to end: the element vocabulary, page node trees and surgical edits to single nodes, headers, footers, popups, templates and global blocks, display conditions, global settings, selectors, presets, variables, form submissions and revisions. Works on Oxygen 6 too.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('breakdance'),
            'enabled' => nibwp_is_integration_enabled('breakdance'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 2 7l10 5 10-5-10-5zm-8.2 8.3L2 11.2l10 5 10-5-1.8-.9-8.2 4.1-8.2-4.1zm0 4.5L2 15.7l10 5 10-5-1.8-.9-8.2 4.1-8.2-4.1z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['breakdance-info', 'breakdance-elements', 'breakdance-tree', 'breakdance-nodes', 'breakdance-pages', 'breakdance-templates', 'breakdance-conditions', 'breakdance-global-settings', 'breakdance-selectors', 'breakdance-presets', 'breakdance-variables', 'breakdance-forms', 'breakdance-revisions', 'breakdance-delete'],
            'actions' => 41,
            'examples' => ['breakdance-info', 'list', 'get', 'categories', 'get', 'outline'],
            'plugin_name' => 'Breakdance',
        ],
        'ninjaforms' => [
            'name' => 'Ninja Forms',
            'description' => __('Forms, fields, submissions and exports, plus the actions that define what a form does. Ninja Forms treats STORING a submission as an action, so a form can be configured to lose data in two separate ways — the audit finds both.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('ninjaforms'),
            'enabled' => nibwp_is_integration_enabled('ninjaforms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 4 6v6c0 5 3.4 9.4 8 10 4.6-.6 8-5 8-10V6l-8-4zm-1 13-3-3 1.4-1.4L11 12.2l4.6-4.6L17 9l-6 6z"/></svg>',
            'category' => 'forms',
            'abilities' => ['ninjaforms-info', 'ninjaforms-forms', 'ninjaforms-fields', 'ninjaforms-actions', 'ninjaforms-submissions', 'ninjaforms-settings', 'ninjaforms-export', 'ninjaforms-audit', 'ninjaforms-delete'],
            'actions' => 20,
            'examples' => ['ninjaforms-info', 'list', 'get', 'rename', 'duplicate', 'shortcode'],
            'plugin_name' => 'Ninja Forms',
        ],
        'formidable' => [
            'name' => 'Formidable Forms',
            'description' => __('Forms, fields, entries and exports, plus the form actions that decide whether a submission reaches anyone — Formidable keeps those in separate posts, so nothing on the form itself reveals a form that stores every entry and emails nobody.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('formidable'),
            'enabled' => nibwp_is_integration_enabled('formidable'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 4h16v4H4V4zm0 6h10v4H4v-4zm0 6h16v4H4v-4z"/></svg>',
            'category' => 'forms',
            'abilities' => ['formidable-info', 'formidable-forms', 'formidable-fields', 'formidable-entries', 'formidable-actions', 'formidable-export', 'formidable-audit', 'formidable-delete'],
            'actions' => 23,
            'examples' => ['formidable-info', 'list', 'get', 'create', 'update', 'duplicate'],
            'plugin_name' => 'Formidable Forms',
        ],
        'forminator' => [
            'name' => 'Forminator',
            'description' => __('All three Forminator module types, not just forms: forms, polls and quizzes, with their fields, entries and settings, an export, and an audit that finds forms storing entries nobody is notified about.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('forminator'),
            'enabled' => nibwp_is_integration_enabled('forminator'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 2 8v8l10 6 10-6V8l-10-6zm0 2.3L19.5 9 12 13.4 4.5 9 12 4.3zM4 10.8l7 4.1v5.3l-7-4.2v-5.2zm9 9.4v-5.3l7-4.1V16l-7 4.2z"/></svg>',
            'category' => 'forms',
            'abilities' => ['forminator-info', 'forminator-forms', 'forminator-fields', 'forminator-entries', 'forminator-settings', 'forminator-polls', 'forminator-quizzes', 'forminator-audit', 'forminator-delete'],
            'actions' => 30,
            'examples' => ['forminator-info', 'list', 'get', 'create', 'update', 'shortcode'],
            'plugin_name' => 'Forminator',
        ],
        'happyforms' => [
            'name' => 'HappyForms',
            'description' => __('Forms, their parts, settings and stored submissions, with an audit for the worst case HappyForms allows: a form that neither emails anyone nor keeps what was submitted, so the enquiry simply disappears.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('happyforms'),
            'enabled' => nibwp_is_integration_enabled('happyforms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM8.5 9a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm7 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zM12 18a6 6 0 0 1-5.2-3h10.4A6 6 0 0 1 12 18z"/></svg>',
            'category' => 'forms',
            'abilities' => ['happyforms-info', 'happyforms-forms', 'happyforms-parts', 'happyforms-settings', 'happyforms-submissions', 'happyforms-audit', 'happyforms-delete'],
            'actions' => 14,
            'examples' => ['happyforms-info', 'list', 'get', 'create', 'rename', 'duplicate'],
            'plugin_name' => 'HappyForms',
        ],
        'bitform' => [
            'name' => 'Bit Form',
            'description' => __('Forms, fields, entries and exports out of the tables Bit Form keeps for itself, plus the notifications that decide whether a submission reaches a person — and an audit for the two ways Bit Form loses an enquiry quietly: every notification switched off, or the submission restriction discarding the entry after the form has said thank you.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('bitform'),
            'enabled' => nibwp_is_integration_enabled('bitform'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 4h7v7H3V4zm2 2v3h3V6H5zm9-2h7v7h-7V4zm2 2v3h3V6h-3zM3 13h7v7H3v-7zm2 2v3h3v-3H5zm12.6-2L14 17.6h2.3l-1 3.9 4.7-5.4h-2.4l1-3.1z"/></svg>',
            'category' => 'forms',
            'abilities' => ['bitform-info', 'bitform-forms', 'bitform-fields', 'bitform-entries', 'bitform-settings', 'bitform-mail', 'bitform-audit', 'bitform-export', 'bitform-delete'],
            'actions' => 17,
            'examples' => ['bitform-info', 'list', 'get', 'shortcode', 'set_status', 'bitform-fields'],
            'plugin_name' => 'Bit Form',
        ],
        'wpforms' => [
            'name' => 'WPForms',
            'description' => __('Forms, fields, notifications, confirmations and settings, plus entries on Pro. Knows that WPForms Lite stores no submissions at all, and says so instead of returning an empty list when someone asks where their enquiries went.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('wpforms'),
            'enabled' => nibwp_is_integration_enabled('wpforms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm2 4v2h10V7H7zm0 4v2h10v-2H7zm0 4v2h6v-2H7z"/></svg>',
            'category' => 'forms',
            'abilities' => ['wpforms-info', 'wpforms-forms', 'wpforms-fields', 'wpforms-notifications', 'wpforms-confirmations', 'wpforms-settings', 'wpforms-entries', 'wpforms-audit', 'wpforms-delete'],
            'actions' => 28,
            'examples' => ['wpforms-info', 'list', 'get', 'create', 'rename', 'duplicate'],
            'plugin_name' => 'WPForms',
        ],
        'jetformbuilder' => [
            'name' => 'JetFormBuilder',
            'description' => __('Forms built from Gutenberg blocks: fields parsed from the block markup, the post-submit actions that decide whether anything happens at all, messages, settings, restrictions, gateways and records — plus an audit that catches a form silently discarding every submission.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('jetformbuilder'),
            'enabled' => nibwp_is_integration_enabled('jetformbuilder'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 3h8v8H3V3zm10 0h8v5h-8V3zM3 13h8v8H3v-8zm10 3h8v5h-8v-5zm0-6h8v4h-8v-4z"/></svg>',
            'category' => 'forms',
            'abilities' => ['jetformbuilder-info', 'jetformbuilder-forms', 'jetformbuilder-fields', 'jetformbuilder-actions', 'jetformbuilder-settings', 'jetformbuilder-records', 'jetformbuilder-gateways', 'jetformbuilder-audit', 'jetformbuilder-delete'],
            'actions' => 20,
            'examples' => ['jetformbuilder-info', 'list', 'get', 'create', 'rename', 'duplicate'],
            'plugin_name' => 'JetFormBuilder',
        ],
        'fluentform' => [
            'name' => 'Fluent Forms',
            'description' => __('Fluent Forms and Fluent Forms Pro in full: forms and fields, submissions, email notifications, confirmations, form settings, every integration feed an add-on writes, payments and subscriptions, analytics, export, and an audit that finds forms collecting submissions nobody is told about.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('fluentform'),
            'enabled' => nibwp_is_integration_enabled('fluentform'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm0 7h10a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1zm0 7h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1z"/></svg>',
            'category' => 'forms',
            'abilities' => ['fluentform-info', 'fluentform-forms', 'fluentform-fields', 'fluentform-entries', 'fluentform-notifications', 'fluentform-confirmations', 'fluentform-settings', 'fluentform-integrations', 'fluentform-payments', 'fluentform-analytics', 'fluentform-export', 'fluentform-audit', 'fluentform-delete'],
            'actions' => 42,
            'examples' => ['fluentform-info', 'list', 'get', 'create', 'rename', 'duplicate'],
            'plugin_name' => 'Fluent Forms',
        ],
        'gravityforms' => [
            'name' => 'Gravity Forms',
            'description' => __('The whole of Gravity Forms: forms and fields, entries and their notes, notifications, confirmations, add-on feeds (Mailchimp, Stripe, User Registration), form settings, validation and real submission, entry export, and an audit that finds forms quietly collecting entries nobody is told about.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('gravityforms'),
            'enabled' => nibwp_is_integration_enabled('gravityforms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 3 7v10l9 5 9-5V7l-9-5zm0 2.3 6.5 3.6-6.5 3.6L5.5 7.9 12 4.3zM5 9.7l6 3.3v6.6l-6-3.3V9.7zm8 9.9V13l6-3.3v6.6l-6 3.3z"/></svg>',
            'category' => 'forms',
            'abilities' => ['gravityforms-info', 'gravityforms-forms', 'gravityforms-fields', 'gravityforms-entries', 'gravityforms-notes', 'gravityforms-notifications', 'gravityforms-confirmations', 'gravityforms-feeds', 'gravityforms-settings', 'gravityforms-submit', 'gravityforms-export', 'gravityforms-audit', 'gravityforms-delete'],
            'actions' => 58,
            'examples' => ['gravityforms-info', 'list', 'get', 'create', 'update', 'duplicate'],
            'plugin_name' => 'Gravity Forms',
        ],
        'cf7' => [
            'name' => 'Contact Form 7',
            'description' => __('Go deeper than the universal forms tool: the form-tag template and its parsed fields, both mail templates, response messages, additional settings, Flamingo submissions and a spam-protection check. The mail template is where a CF7 form usually fails, and this reaches it.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('cf7'),
            'enabled' => nibwp_is_integration_enabled('cf7'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4.2-8 4.8-8-4.8V6l8 4.8L20 6v2.2z"/></svg>',
            'category' => 'forms',
            'abilities' => ['cf7-info', 'cf7-forms', 'cf7-template', 'cf7-fields', 'cf7-mail', 'cf7-messages', 'cf7-settings', 'cf7-submissions', 'cf7-spam', 'cf7-delete'],
            'actions' => 24,
            'examples' => ['cf7-info', 'list', 'get', 'create', 'rename', 'duplicate'],
            'plugin_name' => 'Contact Form 7',
        ],
        'wsform' => [
            'name' => 'WS Form',
            'description' => __('Build and run WS Form end to end: forms and their JSON, fields, tabs and sections, the actions that fire on submit, submissions and exports, styles and templates.', 'nibwp'),
            'plugin_available' => defined('WS_FORM_VERSION'),
            'enabled' => nibwp_is_integration_enabled('wsform'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm2 4v2h12V7H6zm0 4v2h12v-2H6zm0 4v2h7v-2H6z"/></svg>',
            'category' => 'forms',
            'abilities' => ['wsform-info', 'wsform-forms', 'wsform-json', 'wsform-fields', 'wsform-tabs', 'wsform-sections', 'wsform-actions', 'wsform-submissions', 'wsform-export', 'wsform-notes', 'wsform-styles', 'wsform-templates', 'wsform-config', 'wsform-delete'],
            'actions' => 76,
            'examples' => ['wsform-info', 'list', 'get', 'create', 'create_from_template', 'update_label'],
            'plugin_name' => 'WS Form',
        ],
        'fluentcrm' => [
            'name' => 'FluentCRM',
            'description' => __('Manage contacts, tags, lists, and campaigns. View campaign stats and automate email marketing.', 'nibwp'),
            'plugin_available' => defined('FLUENTCRM'),
            'enabled' => nibwp_is_integration_enabled('fluentcrm'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>',
            'category' => 'crm',
            'abilities' => ['fluentcrm-manage'],
            'actions' => 27,
            'examples' => ['list_contacts', 'update_contact', 'list_tags', 'remove_tag', 'rename_list', 'list_custom_fields', 'get_campaign_stats', 'get_automation'],
            'plugin_name' => 'FluentCRM',
        ],
        'fluentcart' => [
            'name' => 'FluentCart',
            'description' => __('Manage FluentCart products and orders.', 'nibwp'),
            'plugin_available' => defined('FLUENTCART_VERSION') || class_exists('FluentCart\\App\\App'),
            'enabled' => nibwp_is_integration_enabled('fluentcart'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>',
            'category' => 'ecommerce',
            'abilities' => ['fluentcart-manage'],
            'actions' => 31,
            'examples' => ['list_products', 'get_product', 'create_product', 'update_product', 'delete_product', 'list_variations'],
            'plugin_name' => 'FluentCart',
        ],
        'fluentaffiliate' => [
            'name' => 'FluentAffiliate',
            'description' => __('Run a full affiliate program: approve/reject affiliates, set commission rates, create groups, log referrals, batch payouts, and pull revenue & top-performer reports.', 'nibwp'),
            'plugin_available' => defined('FLUENT_AFFILIATE_VERSION') || class_exists('FluentAffiliate\\App\\App'),
            'enabled' => nibwp_is_integration_enabled('fluentaffiliate'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/><path d="M19 3v3h-3v2h3v3h2V8h3V6h-3V3h-2z" opacity=".5"/></svg>',
            'category' => 'affiliate',
            'abilities' => ['fluentaffiliate-manage'],
            'actions' => 33,
            'examples' => ['list_affiliates', 'get_affiliate', 'create_affiliate', 'update_affiliate', 'delete_affiliate', 'approve_affiliate'],
            'plugin_name' => 'FluentAffiliate',
        ],
        'directorist' => [
            'name' => 'Directorist',
            'description' => __('Manage a full business directory: listings, categories, locations, tags, reviews, orders, pricing plans, and user favorites. Publish / expire / feature listings, approve reviews, and pull directory reports.', 'nibwp'),
            'plugin_available' => defined('ATBDP_VERSION'),
            'enabled' => nibwp_is_integration_enabled('directorist'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
            'category' => 'directory',
            'abilities' => ['directorist-manage'],
            'actions' => 39,
            'examples' => ['list_listings', 'get_listing', 'create_listing', 'update_listing', 'delete_listing', 'set_listing_status'],
            'plugin_name' => 'Directorist',
        ],
        'edd' => [
            'name' => 'Easy Digital Downloads',
            'description' => __('Manage digital products, payments, customers, and sales statistics.', 'nibwp'),
            'plugin_available' => class_exists('Easy_Digital_Downloads'),
            'enabled' => nibwp_is_integration_enabled('edd'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 6H12l-2-2H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6 10H6v-2h8v2zm4-4H6V10h12v2z"/></svg>',
            'category' => 'ecommerce',
            'abilities' => ['edd-manage'],
            'actions' => 24,
            'examples' => ['list_downloads', 'update_download_price', 'get_payment', 'search_customers', 'list_discounts', 'deactivate_discount', 'best_sellers', 'downloads_without_files'],
            'plugin_name' => 'Easy Digital Downloads',
        ],
        'events' => [
            'name' => 'The Events Calendar',
            'description' => __('Manage events, venues, and organizers. Create and update event listings with dates, costs, and locations.', 'nibwp'),
            'plugin_available' => class_exists('Tribe__Events__Main'),
            'enabled' => nibwp_is_integration_enabled('events'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z"/></svg>',
            'category' => 'events',
            'abilities' => ['events-manage'],
            'actions' => 24,
            'examples' => ['list_events', 'update_event', 'delete_event_permanently', 'get_venue', 'venue_events', 'create_organizer', 'list_event_categories', 'calendar_between'],
            'plugin_name' => 'The Events Calendar',
        ],
        'buddypress' => [
            'name' => 'BuddyPress',
            'description' => __('Manage members, groups, and activity streams. Post activity and create community groups.', 'nibwp'),
            'plugin_available' => class_exists('BuddyPress'),
            'enabled' => nibwp_is_integration_enabled('buddypress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
            'category' => 'community',
            'abilities' => ['buddypress-manage'],
            'actions' => 26,
            'examples' => ['community_summary', 'search_members', 'list_profile_fields', 'get_group', 'delete_group', 'remove_group_member', 'get_activity', 'hide_activity'],
            'plugin_name' => 'BuddyPress / BuddyBoss',
        ],
        'learndash' => [
            'name' => 'LearnDash',
            'description' => __('Manage courses, lessons, quizzes. Enroll users and track learning progress.', 'nibwp'),
            'plugin_available' => defined('LEARNDASH_VERSION'),
            'enabled' => nibwp_is_integration_enabled('learndash'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>',
            'category' => 'lms',
            'abilities' => ['learndash-manage'],
            'actions' => 7,
            'examples' => ['list_courses', 'get_course', 'list_lessons', 'list_quizzes', 'enroll_user', 'get_user_progress'],
            'plugin_name' => 'LearnDash',
        ],
        'lifterlms' => [
            'name' => 'LifterLMS',
            'description' => __('Manage courses, memberships, and student enrollments.', 'nibwp'),
            'plugin_available' => defined('LLMS_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('lifterlms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>',
            'category' => 'lms',
            'abilities' => ['lifterlms-manage'],
            'actions' => 25,
            'examples' => ['list_courses', 'update_course', 'get_lesson', 'list_memberships', 'list_course_students', 'unenroll_student', 'list_engagements', 'completion_rates'],
            'plugin_name' => 'LifterLMS',
        ],
        'memberpress' => [
            'name' => 'MemberPress',
            'description' => __('Manage memberships, members, transactions, and subscriptions.', 'nibwp'),
            'plugin_available' => defined('MEPR_VERSION'),
            'enabled' => nibwp_is_integration_enabled('memberpress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM12 17c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zM15.1 8H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>',
            'category' => 'membership',
            'abilities' => ['memberpress-manage'],
            'actions' => 5,
            'examples' => ['list_memberships', 'get_membership', 'list_members', 'list_transactions', 'list_subscriptions'],
            'plugin_name' => 'MemberPress',
        ],
        'tutorlms' => [
            'name' => 'Tutor LMS',
            'description' => __('Manage courses, lessons, quizzes, students, instructors, monetization, and reports.', 'nibwp'),
            'plugin_available' => defined('TUTOR_VERSION'),
            'enabled' => nibwp_is_integration_enabled('tutorlms'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>',
            'category' => 'lms',
            'abilities' => ['tutorlms-courses', 'tutorlms-content', 'tutorlms-students', 'tutorlms-quizzes', 'tutorlms-instructors', 'tutorlms-monetization', 'tutorlms-reports'],
            'actions' => 67,
            'examples' => ['list', 'get', 'create', 'update', 'delete', 'pricing_summary'],
            'plugin_name' => 'Tutor LMS',
        ],
        'generatepress' => [
            'name' => 'GeneratePress',
            'description' => __('Control the GeneratePress theme — settings, layout, colors + Global Colors, typography, spacing, and Premium Elements (hooks, headers, layouts).', 'nibwp'),
            'plugin_available' => function_exists('generate_get_defaults') || wp_get_theme()->get_template() === 'generatepress' || wp_get_theme()->get_stylesheet() === 'generatepress',
            'enabled' => nibwp_is_integration_enabled('generatepress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 2 7l10 5 10-5-10-5zm-7.79 8L12 13.5 19.79 10 22 11.1l-10 5-10-5L4.21 10zm0 5L12 18.5 19.79 15 22 16.1l-10 5-10-5L4.21 15z"/></svg>',
            'category' => 'theme',
            'abilities' => ['generatepress-info', 'generatepress-settings', 'generatepress-colors', 'generatepress-typography', 'generatepress-spacing', 'generatepress-elements'],
            'actions' => 30,
            'examples' => ['generatepress-info', 'get', 'update', 'get_defaults', 'reset', 'get'],
            'plugin_name' => 'GeneratePress',
        ],
        'kadence' => [
            'name' => 'Kadence',
            'description' => __('Control the Kadence theme — settings, layout/header rows, the Global Color Palette, typography, Kadence Blocks global settings, and Pro Elements (hooked custom content with display conditions).', 'nibwp'),
            'plugin_available' => class_exists('Kadence\\Theme') || function_exists('kadence') || defined('KADENCE_BLOCKS_VERSION') || class_exists('Kadence_Blocks_Frontend') || wp_get_theme()->get_template() === 'kadence' || wp_get_theme()->get_stylesheet() === 'kadence',
            'enabled' => nibwp_is_integration_enabled('kadence'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M4 3h4v7.6L14.4 3h5L11 12.4 20 21h-5.2L8 13.7V21H4V3z"/></svg>',
            'category' => 'theme',
            'abilities' => ['kadence-info', 'kadence-settings', 'kadence-colors', 'kadence-typography', 'kadence-blocks', 'kadence-elements'],
            'actions' => 24,
            'examples' => ['kadence-info', 'get', 'update', 'list_saved', 'get', 'list_palette'],
            'plugin_name' => 'Kadence',
        ],
        'voxel' => [
            'name' => 'Voxel',
            'description' => __('Run a Voxel directory with AI — listings with every Voxel field type (location, work hours, galleries, repeaters), post type schemas, search through Voxel\'s own index, taxonomy terms, orders and bookings, membership plans, timeline, and direct messages. Voxel has no REST API; this is the programmatic surface it never shipped.', 'nibwp'),
            'plugin_available' => class_exists('\\Voxel\\Post') || wp_get_theme()->get_template() === 'voxel' || wp_get_theme()->get_stylesheet() === 'voxel',
            'enabled' => nibwp_is_integration_enabled('voxel'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 2 7v10l10 5 10-5V7L12 2zm0 2.2 7.5 3.75L12 11.7 4.5 7.95 12 4.2zM4 9.6l7 3.5v6.6l-7-3.5V9.6zm16 6.6-7 3.5v-6.6l7-3.5v6.6z"/></svg>',
            'category' => 'theme',
            'abilities' => ['voxel-info', 'voxel-post-types', 'voxel-schema', 'voxel-posts', 'voxel-posts-write', 'voxel-terms', 'voxel-orders', 'voxel-plans', 'voxel-timeline', 'voxel-messages', 'voxel-templates', 'voxel-render', 'voxel-delete'],
            'plugin_name' => 'Voxel',
        ],
        'divi' => [
            'name' => 'Divi',
            'description' => __('Control Divi (theme or Builder plugin) — per-post builder content (Divi 4 shortcode + Divi 5 serialized), theme options, the Global Color palette, the Divi Library, the Theme Builder, global module presets, and the Role Editor.', 'nibwp'),
            'plugin_available' => defined('ET_BUILDER_VERSION') || defined('ET_BUILDER_PLUGIN_VERSION') || function_exists('et_setup_theme') || in_array(strtolower((string) wp_get_theme()->get('Name')), ['divi', 'extra'], true) || in_array(strtolower((string) wp_get_theme()->get_template()), ['divi', 'extra'], true),
            'enabled' => nibwp_is_integration_enabled('divi'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 3h7.5c4.7 0 8.5 3.8 8.5 8.5v.5c0 4.7-3.8 8.5-8.5 8.5H3V3zm4 4v9.9h3.3c2.8 0 5-2.2 5-5v-.4c0-2.7-2.2-4.9-5-4.9H7z"/></svg>',
            'category' => 'theme',
            'abilities' => ['divi-info', 'divi-content', 'divi-theme-options', 'divi-colors', 'divi-library', 'divi-theme-builder', 'divi-presets', 'divi-roles'],
            'actions' => 35,
            'examples' => ['divi-info', 'get', 'set_content', 'enable_builder', 'disable_builder', 'get_meta'],
            'plugin_name' => 'Divi',
        ],
        'fluentcommunity' => [
            'name' => 'FluentCommunity',
            'description' => __('Run a FluentCommunity portal with AI — spaces, space groups and courses, members and roles, the feed, comments, reactions, and member profiles with gamification points.', 'nibwp'),
            'plugin_available' => defined('FLUENT_COMMUNITY_PLUGIN_VERSION') || function_exists('fluentCommunityApp'),
            'enabled' => nibwp_is_integration_enabled('fluentcommunity'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
            'category' => 'community',
            'abilities' => ['fluentcommunity-info', 'fluentcommunity-spaces', 'fluentcommunity-members', 'fluentcommunity-feed', 'fluentcommunity-comments', 'fluentcommunity-reactions', 'fluentcommunity-profiles'],
            'actions' => 32,
            'examples' => ['fluentcommunity-info', 'list', 'get', 'create', 'update', 'delete'],
            'plugin_name' => 'FluentCommunity',
        ],
        'fluentsmtp' => [
            'name' => 'FluentSMTP',
            'description' => __('Configure how WordPress sends mail — set up a custom SMTP server or any API provider (SendGrid, Mailgun, SES, Postmark, Gmail…), route senders, send real test emails, and read the delivery log.', 'nibwp'),
            'plugin_available' => defined('FLUENTMAIL_PLUGIN_VERSION') || function_exists('fluentMailGetSettings'),
            'enabled' => nibwp_is_integration_enabled('fluentsmtp'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>',
            'category' => 'email',
            'abilities' => ['fluentsmtp-info', 'fluentsmtp-connections', 'fluentsmtp-configure', 'fluentsmtp-send-test', 'fluentsmtp-logs', 'fluentsmtp-settings'],
            'actions' => 17,
            'examples' => ['fluentsmtp-info', 'list', 'get', 'delete', 'save_smtp', 'save_provider'],
            'plugin_name' => 'FluentSMTP',
        ],
        'seopress' => [
            'name' => 'SEOPress',
            'description' => __('Drive SEOPress with AI — per-post titles, meta, canonical, robots and target keywords; Open Graph + Twitter; and the global Titles, Social/Knowledge Graph, XML Sitemap, Advanced and feature-toggle settings, plus Pro redirections.', 'nibwp'),
            'plugin_available' => defined('SEOPRESS_VERSION'),
            'enabled' => nibwp_is_integration_enabled('seopress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.49 4.49 0 0 1 9.5 14z"/></svg>',
            'category' => 'seo',
            'abilities' => ['seopress-info', 'seopress-post', 'seopress-social-post', 'seopress-titles', 'seopress-social', 'seopress-sitemap', 'seopress-advanced', 'seopress-toggles', 'seopress-redirections'],
            'actions' => 20,
            'examples' => ['seopress-info', 'get', 'set', 'bulk_set', 'get', 'set'],
            'plugin_name' => 'SEOPress',
        ],
        'rankmath' => [
            'name' => 'Rank Math SEO',
            'description' => __('Drive Rank Math with AI — per-post titles, descriptions, focus keywords, canonical, robots and social overrides; the same across a whole query at once; the global Titles, module and sitemap settings; its own redirections; and an audit that finds missing descriptions, over-long titles, noindexed pages sitting in your menus, redirect loops and chains, and the 404s piling up in its monitor.', 'nibwp'),
            'plugin_available' => defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper'),
            'enabled' => nibwp_is_integration_enabled('rankmath'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 21V10h4v11H3zm7 0V3h4v18h-4zm7 0v-7h4v7h-4z"/></svg>',
            'category' => 'seo',
            'abilities' => ['rankmath-info', 'rankmath-post', 'rankmath-bulk', 'rankmath-settings', 'rankmath-redirections', 'rankmath-sitemap', 'rankmath-audit'],
            'actions' => 20,
            'examples' => ['rankmath-info', 'get', 'set', 'get', 'set', 'get'],
            'plugin_name' => 'Rank Math SEO',
        ],
        'slimseo' => [
            'name' => 'Slim SEO',
            'description' => __('Control Slim SEO + Slim SEO Schema with AI — per-post title, description, canonical, robots and social images; the global features and social defaults; and per-post structured-data overrides.', 'nibwp'),
            'plugin_available' => defined('SLIM_SEO_VER') || function_exists('slim_seo'),
            'enabled' => nibwp_is_integration_enabled('slimseo'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 4h18v2H3V4zm2 4h14v2H5V8zm-2 4h18v2H3v-2zm2 4h14v2H5v-2zm-2 4h18v2H3v-2z"/></svg>',
            'category' => 'seo',
            'abilities' => ['slimseo-info', 'slimseo-post', 'slimseo-settings', 'slimseo-schema'],
            'actions' => 10,
            'examples' => ['slimseo-info', 'get', 'set', 'bulk_set', 'get', 'update'],
            'plugin_name' => 'Slim SEO',
        ],
        'yoast' => [
            'name' => 'Yoast SEO',
            'description' => __('Drive Yoast SEO with AI — per-post SEO title, meta description, focus keyphrase, canonical, the three-state robots setting, Open Graph and X/Twitter overrides, cornerstone and breadcrumb title; bulk reads and writes across a query; the title and meta templates for post types and taxonomies; term SEO; Premium redirects; and an audit that finds missing descriptions, truncated titles, noindexed cornerstone content, orphaned pages and duplicate canonicals.', 'nibwp'),
            'plugin_available' => nibwp_is_integration_available('yoast'),
            'enabled' => nibwp_is_integration_enabled('yoast'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 3 6v6c0 5 3.8 9.3 9 10 5.2-.7 9-5 9-10V6l-9-4zm0 2.2 7 3.1V12c0 4-2.9 7.5-7 8.2-4.1-.7-7-4.2-7-8.2V7.3l7-3.1zM9.4 7.5 12 14l-1 2.5H8.8l.9-2.2-2.5-6.8h2.2zm5.4 0h2L13.6 17h-2l1-2.5 2.2-7z"/></svg>',
            'category' => 'seo',
            'abilities' => ['yoast-info', 'yoast-post', 'yoast-bulk', 'yoast-templates', 'yoast-term', 'yoast-redirects', 'yoast-audit'],
            'actions' => 14,
            'examples' => ['yoast-info', 'get', 'set', 'get', 'set', 'get'],
            'plugin_name' => 'Yoast SEO',
        ],
        'surecart' => [
            'name' => 'SureCart',
            'description' => __('Run a SureCart store with AI — products, prices, collections, coupons, customers, orders, the checkout forms + store pages, and a catalog of SureCart storefront blocks that the EtchWP & Bricks Pro skills use to design product pages, pricing tables and checkout.', 'nibwp'),
            'plugin_available' => class_exists('SureCart') || defined('SURECART_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('surecart'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 21 4H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>',
            'category' => 'ecommerce',
            'abilities' => ['surecart-info', 'surecart-products', 'surecart-prices', 'surecart-collections', 'surecart-coupons', 'surecart-customers', 'surecart-orders', 'surecart-forms', 'surecart-blocks'],
            'actions' => 31,
            'examples' => ['surecart-info', 'list', 'get', 'create', 'update', 'archive'],
            'plugin_name' => 'SureCart',
        ],
        'givewp' => [
            'name' => 'GiveWP',
            'description' => __('Manage donation forms, donations, donors, and fundraising statistics.', 'nibwp'),
            'plugin_available' => defined('GIVE_VERSION'),
            'enabled' => nibwp_is_integration_enabled('givewp'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>',
            'category' => 'donations',
            'abilities' => ['givewp-manage'],
            'actions' => 26,
            'examples' => ['list_forms', 'update_form_goal', 'list_donations', 'donation_totals', 'get_donor', 'get_stats', 'average_gift', 'audit_forms_without_goal'],
            'plugin_name' => 'GiveWP',
        ],
        'redirection' => [
            'name' => 'Redirection',
            'description' => __('Manage URL redirects (301/302/307), view and clear 404 error logs.', 'nibwp'),
            'plugin_available' => class_exists('Red_Item'),
            'enabled' => nibwp_is_integration_enabled('redirection'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 15l-6 6-1.42-1.42L15.17 16H4V4h2v10h9.17l-3.59-3.58L13 9l6 6z"/></svg>',
            'category' => 'utilities',
            'abilities' => ['redirection-manage'],
            'actions' => 18,
            'examples' => ['list_redirects', 'get_redirect', 'create_redirect', 'update_redirect', 'delete_redirect', 'enable_redirect'],
            'plugin_name' => 'Redirection',
        ],
        'tablepress' => [
            'name' => 'TablePress',
            'description' => __('Create, edit, and manage data tables with rows and columns.', 'nibwp'),
            'plugin_available' => class_exists('TablePress'),
            'enabled' => nibwp_is_integration_enabled('tablepress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 3v18h18V3H3zm8 16H5v-6h6v6zm0-8H5V5h6v6zm8 8h-6v-6h6v6zm0-8h-6V5h6v6z"/></svg>',
            'category' => 'utilities',
            'abilities' => ['tablepress-manage'],
            'actions' => 16,
            'examples' => ['list_tables', 'get_table', 'create_table', 'update_table', 'delete_table', 'copy_table'],
            'plugin_name' => 'TablePress',
        ],
        'translatepress' => [
            'name' => 'TranslatePress',
            'description' => __('Manage languages and translations for multilingual sites.', 'nibwp'),
            'plugin_available' => defined('TRP_PLUGIN_VERSION'),
            'enabled' => nibwp_is_integration_enabled('translatepress'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12.87 15.07l-2.54-2.51.03-.03c1.74-1.94 2.98-4.17 3.71-6.53H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/></svg>',
            'category' => 'multilingual',
            'abilities' => ['translatepress-manage'],
            'actions' => 20,
            'examples' => ['translation_summary', 'translation_progress', 'get_translations', 'get_settings', 'translation_audit', 'remove_language_and_its_translations', 'unpublish_language', 'update_translations'],
            'plugin_name' => 'TranslatePress',
        ],
        'wpml' => [
            'name' => 'WPML',
            'description' => __('Manage languages, translations, and content duplication for multilingual WordPress.', 'nibwp'),
            'plugin_available' => defined('ICL_SITEPRESS_VERSION'),
            'enabled' => nibwp_is_integration_enabled('wpml'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12.87 15.07l-2.54-2.51.03-.03c1.74-1.94 2.98-4.17 3.71-6.53H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/></svg>',
            'category' => 'multilingual',
            'abilities' => ['wpml-manage'],
            'actions' => 4,
            'examples' => ['list_languages', 'get_translations', 'set_language', 'duplicate_post'],
            'plugin_name' => 'WPML',
        ],
        // Automatic.css (ACSS). Single integration covers both design-token and
        // settings/recipe management. Legacy `'acss'` key is migrated on read
        // into `'automaticcss'` so users who toggled either one stay enabled.
        'automaticcss' => [
            'name' => 'Automatic.css',
            'description' => __('Manage ACSS design tokens (colors, spacing, typography, sizing), CSS recipes, utility classes, and regenerate the stylesheet. The #1 utility framework for page builders.', 'nibwp'),
            'plugin_available' => defined('ACSS_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('automaticcss'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['acss-get-variables', 'acss-update-variables', 'acss-get-classes', 'acss-regenerate'],
            'actions' => 4,
            'plugin_name' => 'Automatic.css',
        ],
        'etchwp' => [
            'name' => 'EtchWP',
            'description' => __('Manage blocks, custom fields, styles, fonts, assets, and preprocessor settings via the Etch unified dev environment.', 'nibwp'),
            'plugin_available' => defined('ETCH_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('etchwp'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['etchwp-manage-blocks', 'etchwp-manage-fields', 'etchwp-manage-styles', 'etchwp-manage-assets'],
            'actions' => 15,
            'examples' => ['list', 'get', 'create', 'update', 'delete', 'list_groups'],
            'plugin_name' => 'EtchWP',
        ],
        'etchedy' => [
            'name' => 'Etchedy',
            'description' => __('Browse, create, edit, rename and re-file components in the Etchedy library, and reuse saved ones on any site.', 'nibwp'),
            'plugin_available' => defined('ETCHEDY_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('etchedy'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v7M14 17.5h7"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['etchedy-manage'],
            'actions' => 13,
            'examples' => ['list', 'get', 'search', 'save', 'update', 'rename', 'move', 'delete'],
            'plugin_name' => 'Etchedy',
        ],
        'bricksed' => [
            'name' => 'Bricksed',
            'description' => __('Browse, create, edit, rename and re-file components in the Bricksed library for Bricks, and reuse saved ones on any site.', 'nibwp'),
            'plugin_available' => defined('BRICKSED_PLUGIN_FILE'),
            'enabled' => nibwp_is_integration_enabled('bricksed'),
            'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M17.5 14v7M14 17.5h7"/></svg>',
            'category' => 'page-builders',
            'abilities' => ['bricksed-manage'],
            'actions' => 13,
            'examples' => ['list', 'get', 'search', 'save', 'update', 'rename', 'move', 'delete'],
            'plugin_name' => 'Bricksed',
        ],
        'content-planner' => [
            'name' => __('Content Planner', 'nibwp'),
            'description' => __('Schedule posts, editorial calendar, bulk scheduling with intervals, draft manager with priority and notes, reschedule future posts.', 'nibwp'),
            'plugin_available' => true,
            'enabled' => nibwp_is_integration_enabled('content-planner'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['content-schedule-post', 'content-list-scheduled', 'content-reschedule', 'content-calendar', 'content-bulk-schedule', 'content-draft-manager'],
            'plugin_name' => '',
        ],
        'content-fetcher' => [
            'name' => __('Content Fetcher', 'nibwp'),
            'description' => __('Fetch content from URLs, parse RSS feeds, rewrite/transform text, import to drafts, bulk import from feeds, extract sitemap URLs.', 'nibwp'),
            'plugin_available' => true,
            'enabled' => nibwp_is_integration_enabled('content-fetcher'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['fetch-url-content', 'fetch-rss-feed', 'fetch-rewrite-content', 'fetch-to-draft', 'fetch-bulk-import', 'fetch-sitemap-urls'],
            'plugin_name' => '',
        ],
        'seo-advanced' => [
            'name' => __('SEO Advanced', 'nibwp'),
            'description' => __('Image SEO audit & fix, JSON-LD schema generator, broken link checker, redirect manager, bulk meta generator, internal linking suggestions.', 'nibwp'),
            'plugin_available' => true,
            'enabled' => nibwp_is_integration_enabled('seo-advanced'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>',
            'category' => 'seo',
            'abilities' => ['seo-image-optimize', 'seo-schema-markup', 'seo-broken-links', 'seo-redirect-manager', 'seo-meta-bulk-generate', 'seo-internal-linking'],
            'plugin_name' => '',
        ],
        'migration' => [
            'name' => __('Migration Toolkit', 'nibwp'),
            'description' => __('Export/import posts, options, menus. Clone posts with meta. Search & replace URLs across the database with serialized data support.', 'nibwp'),
            'plugin_available' => true,
            'enabled' => nibwp_is_integration_enabled('migration'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['migration-export-content', 'migration-import-content', 'migration-export-options', 'migration-import-options', 'migration-search-replace-urls', 'migration-clone-post', 'migration-export-menus', 'migration-import-menus'],
            'plugin_name' => '',
        ],
        'notifications' => [
            'name' => __('Notifications & SMS', 'nibwp'),
            'description' => __('Send emails, SMS via Twilio, bulk messaging, webhooks, notification templates. Full 2-way SMS with WP Twilio Core integration.', 'nibwp'),
            'plugin_available' => true,
            'enabled' => nibwp_is_integration_enabled('notifications'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['notify-send-email', 'notify-send-sms', 'notify-bulk-email', 'notify-bulk-sms', 'notify-manage-templates', 'notify-configure-twilio', 'notify-send-wp-notification', 'notify-webhook'],
            'plugin_name' => '',
        ],
        'security' => [
            'name' => __('Security & Maintenance', 'nibwp'),
            'description' => __('Malware scanner, core integrity checker, auto-repair, database cleanup, quarantine, file permissions, user audit, find & replace, health check, and password reset.', 'nibwp'),
            'plugin_available' => true,
            'enabled' => nibwp_is_integration_enabled('security'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>',
            'category' => 'security',
            'abilities' => ['security-verify-core', 'security-repair-core', 'security-scan-malware', 'security-quarantine-file', 'security-find-replace', 'security-scan-database', 'security-db-find-replace', 'security-audit-users', 'security-health-check', 'security-cleanup', 'security-file-permissions', 'security-change-passwords'],
            'plugin_name' => '',
        ],
        'wp-job-manager' => [
            'name' => 'WP Job Manager',
            'description' => __('Manage job listings — create, update, delete, search, and filter. Handle company info, taxonomies (types/categories), location, application contact, and featured/filled states.', 'nibwp'),
            'plugin_available' => defined('JOB_MANAGER_VERSION') || class_exists('WP_Job_Manager'),
            'enabled' => nibwp_is_integration_enabled('wp-job-manager'),
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg>',
            'category' => 'jobs',
            'abilities' => ['wpjm-list-jobs', 'wpjm-get-job', 'wpjm-create-job', 'wpjm-update-job', 'wpjm-delete-job', 'wpjm-list-taxonomies'],
            'actions' => 6,
            'plugin_name' => 'WP Job Manager',
        ],
        'memory' => [
            'name' => __('Memory', 'nibwp'),
            'description' => __('Store and recall project context across AI sessions. Remembers conventions, decisions, patterns, and configuration.', 'nibwp'),
            'plugin_available' => true,
            'enabled' => true,
            'icon' => '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>',
            'category' => 'built-in',
            'abilities' => ['memory-store', 'memory-recall', 'memory-delete', 'memory-list-keys'],
            'actions' => 4,
            'plugin_name' => '',
        ],
    ];
}

/**
 * Handle integration toggle submissions.
 */
function nibwp_handle_integration_toggle(): ?string
{
    if (!isset($_POST['nibwp_integration_toggle'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }
    check_admin_referer('nibwp_integration_toggle');

    $integration_key = sanitize_key($_POST['nibwp_integration_key'] ?? '');
    $new_state = !empty($_POST['nibwp_integration_state']);

    $integrations = nibwp_get_integrations();
    if (!array_key_exists($integration_key, $integrations) || $integration_key === 'memory') {
        return null;
    }

    $enabled = nibwp_get_enabled_integrations();
    $enabled[$integration_key] = $new_state;
    update_option('nibwp_enabled_integrations', $enabled);

    return $integration_key;
}

/**
 * Handle "Activate all" / "Activate only detected" bulk submissions.
 * Returns ['mode' => 'all'|'detected', 'count' => newly-enabled] or null.
 */
function nibwp_handle_integration_bulk(): ?array
{
    if (empty($_POST['nibwp_integration_bulk'])) {
        return null;
    }
    if (!current_user_can('manage_options')) {
        return null;
    }
    check_admin_referer('nibwp_integration_toggle');

    $mode = sanitize_key((string) $_POST['nibwp_integration_bulk']);
    if (!in_array($mode, ['all', 'detected', 'none'], true)) {
        return null;
    }
    $on = $mode !== 'none';

    $integrations = nibwp_get_integrations();
    $enabled = nibwp_get_enabled_integrations();
    $count = 0;
    foreach ($integrations as $key => $info) {
        if ($key === 'memory') {
            continue; // always on
        }
        if ($on && $mode === 'detected' && empty($info['plugin_available'])) {
            continue;
        }
        if ($on && !empty($info['premium']) && !nibwp_is_pro() && !nibwp_has_entitlement('integration:' . $key)) {
            continue;
        }
        if ((bool) ($enabled[$key] ?? false) !== $on) {
            $count++;
        }
        $enabled[$key] = $on;
    }
    update_option('nibwp_enabled_integrations', $enabled);

    return ['mode' => $mode, 'count' => $count];
}

/**
 * REST: toggle an integration on/off. Used by the AJAX toggle on the
 * Integrations page (no page reload). Permission = manage_options + wp_rest
 * nonce. Memory is always on; the endpoint refuses to flip it.
 */
add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/integrations/toggle', [
        'methods'             => 'POST',
        'permission_callback' => static function (): bool {
            return current_user_can('manage_options');
        },
        'args' => [
            'key'     => ['type' => 'string',  'required' => true],
            'enabled' => ['type' => 'boolean', 'required' => true],
        ],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $key     = sanitize_key((string) $req->get_param('key'));
            $enabled = (bool) $req->get_param('enabled');

            $integrations = nibwp_get_integrations();
            if (!array_key_exists($key, $integrations)) {
                return new \WP_REST_Response(['ok' => false, 'message' => __('Unknown integration.', 'nibwp')], 404);
            }
            if ($key === 'memory') {
                return new \WP_REST_Response(['ok' => false, 'message' => __('Memory is always on.', 'nibwp')], 422);
            }

            // Block toggling a Pro/locked integration without a license.
            $integration = $integrations[$key];
            if (!empty($integration['premium']) && !nibwp_is_pro() && !nibwp_has_entitlement('integration:' . $key)) {
                return new \WP_REST_Response(['ok' => false, 'message' => __('This integration requires NIBWP Pro.', 'nibwp')], 403);
            }

            $state = nibwp_get_enabled_integrations();
            $state[$key] = $enabled;
            update_option('nibwp_enabled_integrations', $state);

            // Re-read so the response reflects normalized state.
            $integrations = nibwp_get_integrations();
            $i = $integrations[$key];
            return new \WP_REST_Response([
                'ok'        => true,
                'key'       => $key,
                'enabled'   => (bool) $i['enabled'],
                'available' => (bool) $i['plugin_available'],
                'active'    => (bool) $i['plugin_available'] && (bool) $i['enabled'],
                'active_count' => (int) array_sum(array_map(
                    static fn ($it) => ($it['enabled'] && $it['plugin_available']) ? 1 : 0,
                    $integrations,
                )),
            ], 200);
        },
    ]);

    // Bulk activate: mode=all|detected. Enables every integration (or only the
    // installed ones), skipping memory + premium integrations the user can't
    // unlock. Returns a per-key state map so the page updates live (no reload).
    register_rest_route('nibwp/v1', '/integrations/bulk', [
        'methods'             => 'POST',
        'permission_callback' => static fn (): bool => current_user_can('manage_options'),
        'args' => ['mode' => ['type' => 'string', 'required' => true]],
        'callback' => static function (\WP_REST_Request $req): \WP_REST_Response {
            $mode = sanitize_key((string) $req->get_param('mode'));
            if (!in_array($mode, ['all', 'detected', 'none'], true)) {
                return new \WP_REST_Response(['ok' => false, 'message' => 'Bad mode'], 422);
            }
            $on = $mode !== 'none';
            $integrations = nibwp_get_integrations();
            $state = nibwp_get_enabled_integrations();
            foreach ($integrations as $key => $info) {
                if ($key === 'memory') {
                    continue;
                }
                if ($on && $mode === 'detected' && empty($info['plugin_available'])) {
                    continue;
                }
                if ($on && !empty($info['premium']) && !nibwp_is_pro() && !nibwp_has_entitlement('integration:' . $key)) {
                    continue; // can't unlock — don't pretend to enable
                }
                $state[$key] = $on;
            }
            update_option('nibwp_enabled_integrations', $state);

            $integrations = nibwp_get_integrations();
            $items = [];
            foreach ($integrations as $key => $i) {
                $items[$key] = [
                    'enabled'   => (bool) $i['enabled'],
                    'available' => (bool) $i['plugin_available'],
                    'active'    => (bool) $i['plugin_available'] && (bool) $i['enabled'],
                ];
            }
            return new \WP_REST_Response([
                'ok'           => true,
                'mode'         => $mode,
                'items'        => $items,
                'active_count' => (int) array_sum(array_map(static fn ($it) => $it['active'] ? 1 : 0, $items)),
            ], 200);
        },
    ]);
});

/**
 * Render the Integrations admin page.
 */
function nibwp_render_integrations_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $toggled = nibwp_handle_integration_toggle();
    $bulk = nibwp_handle_integration_bulk();
    // Re-fetch after save so toggle states are current.
    $integrations = nibwp_get_integrations();

    // Hidden entries are dropped once, here, rather than skipped at render
    // time — the tab counts and the "x / y" search total are computed from
    // this same array, and filtering only the card loop would have left every
    // count one too high.
    $integrations = array_filter($integrations, static fn(array $i): bool => empty($i['hidden']));

    $categories = [
        'built-in' => __('Built-in', 'nibwp'),
        'security' => __('Security & Maintenance', 'nibwp'),
        'page-builders' => __('Page Builders', 'nibwp'),
        'design' => __('Design', 'nibwp'),
        'ecommerce' => __('E-Commerce', 'nibwp'),
        'forms' => __('Forms', 'nibwp'),
        'crm' => __('CRM & Marketing', 'nibwp'),
        'events' => __('Events', 'nibwp'),
        'community' => __('Community', 'nibwp'),
        'lms' => __('Learning (LMS)', 'nibwp'),
        'membership' => __('Membership', 'nibwp'),
        'donations' => __('Donations', 'nibwp'),
        'seo' => __('SEO', 'nibwp'),
        'multilingual' => __('Multilingual', 'nibwp'),
        'utilities' => __('Utilities', 'nibwp'),
        'custom-fields' => __('Custom Fields & Content Types', 'nibwp'),
        'jobs' => __('Jobs & Recruitment', 'nibwp'),
    ];

    $active_count = count(array_filter($integrations, static fn($i) => $i['plugin_available'] && $i['enabled']));
    $total_count = count($integrations);

    ?>
    <?php nibwp_render_admin_header(); ?>
    <div class="wrap nibwp-wrap">
        <div class="nibwp-page-header nibwp-page-header--with-search">
            <div>
                <h1><?php esc_html_e('Integrations', 'nibwp'); ?></h1>
                <p class="nibwp-subtitle">
                    <span id="nw-int-stat-active"><?php echo (int) $active_count; ?></span>
                    <?php printf(
                        /* translators: %d = total integrations */
                        esc_html__('of %d integrations active.', 'nibwp'),
                        $total_count,
                    ); ?>
                </p>
            </div>
            <div class="nw-page-search-wrap">
                <div class="nw-tier-filter" role="group" aria-label="<?php esc_attr_e('Filter by tier', 'nibwp'); ?>">
                    <button type="button" class="nw-tier-pill is-active" data-tier="all"><?php esc_html_e('All', 'nibwp'); ?></button>
                    <button type="button" class="nw-tier-pill" data-tier="free"><?php esc_html_e('Free', 'nibwp'); ?></button>
                    <button type="button" class="nw-tier-pill" data-tier="premium"><?php esc_html_e('Premium', 'nibwp'); ?></button>
                </div>
                <form method="post" class="nw-tier-filter nw-bulk-acts" aria-label="<?php esc_attr_e('Bulk activate', 'nibwp'); ?>">
                    <?php wp_nonce_field('nibwp_integration_toggle'); ?>
                    <button type="submit" name="nibwp_integration_bulk" value="detected" class="nw-tier-pill"><?php esc_html_e('Activate detected', 'nibwp'); ?></button>
                    <button type="submit" name="nibwp_integration_bulk" value="all" class="nw-tier-pill nw-bulk-btn--all"><?php esc_html_e('Activate all', 'nibwp'); ?></button>
                    <button type="submit" name="nibwp_integration_bulk" value="none" class="nw-tier-pill nw-bulk-btn--off"><?php esc_html_e('Deactivate all', 'nibwp'); ?></button>
                </form>
                <div class="nw-page-search" role="search">
                    <span class="nw-page-search__icon" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg>
                    </span>
                    <label for="nw-int-search" class="screen-reader-text"><?php esc_html_e('Search integrations', 'nibwp'); ?></label>
                    <input
                        type="search"
                        id="nw-int-search"
                        class="nw-page-search__input"
                        placeholder="<?php esc_attr_e('Search integrations & press Enter', 'nibwp'); ?>"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <button type="button" class="nw-page-search__clear" id="nw-int-search-clear" aria-label="<?php esc_attr_e('Clear search', 'nibwp'); ?>" hidden>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <span class="nw-page-search__count" id="nw-int-search-count"><?php echo (int) $total_count; ?> / <?php echo (int) $total_count; ?></span>
                </div>
                <div class="nw-sort-acts">
                    <label for="nw-int-sort" class="screen-reader-text"><?php esc_html_e('Sort integrations', 'nibwp'); ?></label>
                    <select id="nw-int-sort" class="nw-sort-select" aria-label="<?php esc_attr_e('Sort integrations', 'nibwp'); ?>">
                        <option value="default"><?php esc_html_e('Default', 'nibwp'); ?></option>
                        <option value="detected"><?php esc_html_e('Per plugin detected', 'nibwp'); ?></option>
                        <option value="az"><?php esc_html_e('A–Z Sort', 'nibwp'); ?></option>
                    </select>
                </div>
                
            </div>
        </div>
        <?php // wp-header-end: tells WP admin_notices to inject BELOW the header, not inside its flex row (which squeezed the search bar). ?>
        <hr class="wp-header-end" style="border:0;margin:0;height:0">

        <?php if ($bulk !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php
                $nibwp_bulk_count = (int) $bulk['count'];
                if ($bulk['mode'] === 'none') {
                    /* translators: %d: number of integrations switched off */
                    $nibwp_bulk_text = _n('%d integration deactivated (all).', '%d integrations deactivated (all).', $nibwp_bulk_count, 'nibwp');
                } elseif ($bulk['mode'] === 'detected') {
                    /* translators: %d: number of integrations switched on */
                    $nibwp_bulk_text = _n('%d integration activated (detected only).', '%d integrations activated (detected only).', $nibwp_bulk_count, 'nibwp');
                } else {
                    /* translators: %d: number of integrations switched on */
                    $nibwp_bulk_text = _n('%d integration activated (all).', '%d integrations activated (all).', $nibwp_bulk_count, 'nibwp');
                }
                echo esc_html(sprintf($nibwp_bulk_text, $nibwp_bulk_count));
            ?></p></div>
        <?php endif; ?>

        <?php if ($toggled !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php
                $info = $integrations[$toggled] ?? null;
                if ($info !== null) {
                    printf(
                        $info['enabled']
                            /* translators: %s: integration name */
                            ? esc_html__('%s integration activated.', 'nibwp')
                            /* translators: %s: integration name */
                            : esc_html__('%s integration deactivated.', 'nibwp'),
                        '<strong>' . esc_html($info['name']) . '</strong>',
                    );
                }
            ?></p></div>
        <?php endif; ?>

        <!-- Tabs (hover-to-scroll) -->
        <div class="nw-int-tabs-wrap" id="nw-int-tabs-wrap">
            <div class="nw-int-tabs" id="nw-int-tabs">
                <button type="button" class="nw-int-tab is-active" data-tab="all">
                    <?php esc_html_e('All', 'nibwp'); ?>
                    <span class="nw-int-tab-count"><?php echo $total_count; ?></span>
                </button>
                <?php foreach ($categories as $cat_key => $cat_label):
                    $cat_count = count(array_filter($integrations, static fn($i) => $i['category'] === $cat_key));
                    if ($cat_count === 0) continue;
                ?>
                    <button type="button" class="nw-int-tab" data-tab="<?php echo esc_attr($cat_key); ?>">
                        <?php echo esc_html($cat_label); ?>
                        <span class="nw-int-tab-count"><?php echo $cat_count; ?></span>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="nw-int-tab nw-int-tab-cta" id="nw-request-integration-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-inline-end:4px;"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8 5.8 21.3l2.4-7.4L2 9.4h7.6z"/></svg>
                    <?php esc_html_e('Need a custom Integration?', 'nibwp'); ?>
                </button>
            </div>
        </div>

        <!-- Custom Integration Request Modal -->
        <div class="nw-req-modal" id="nw-req-modal" role="dialog" aria-hidden="true">
            <div class="nw-req-modal__backdrop" id="nw-req-modal-backdrop"></div>
            <div class="nw-req-modal__panel">
                <button type="button" class="nw-req-modal__close" id="nw-req-modal-close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>

                <div class="nw-req-modal__head">
                    <div class="nw-req-modal__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8 5.8 21.3l2.4-7.4L2 9.4h7.6z"/></svg>
                    </div>
                    <div>
                        <strong><?php esc_html_e('Request a Custom Skill or Integration', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Tell us what you need. We build new integrations every week.', 'nibwp'); ?></span>
                    </div>
                </div>

                <!-- Step indicator -->
                <div class="nw-req-steps">
                    <div class="nw-req-step is-active" data-step="1"><span>1</span><?php esc_html_e('Type', 'nibwp'); ?></div>
                    <div class="nw-req-step-divider"></div>
                    <div class="nw-req-step" data-step="2"><span>2</span><?php esc_html_e('Details', 'nibwp'); ?></div>
                    <div class="nw-req-step-divider"></div>
                    <div class="nw-req-step" data-step="3"><span>3</span><?php esc_html_e('Contact', 'nibwp'); ?></div>
                </div>

                <form id="nw-req-form" class="nw-req-form">
                    <?php wp_nonce_field('nibwp_request_integration', '_ajax_nonce'); ?>
                    <!-- STEP 1: Type selector -->
                    <div class="nw-req-pane is-active" data-pane="1">
                        <div class="nw-req-pane__title"><?php esc_html_e('What do you need?', 'nibwp'); ?></div>
                        <div class="nw-req-options">
                            <label class="nw-req-option">
                                <input type="radio" name="request_type" value="plugin-integration" required>
                                <div class="nw-req-option__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4M4.9 4.9l2.8 2.8m8.6 8.6l2.8 2.8M4.9 19.1l2.8-2.8m8.6-8.6l2.8-2.8"/></svg>
                                </div>
                                <div class="nw-req-option__text">
                                    <strong><?php esc_html_e('Plugin integration', 'nibwp'); ?></strong>
                                    <span><?php esc_html_e('Add AI tools for a specific WordPress plugin', 'nibwp'); ?></span>
                                </div>
                            </label>
                            <label class="nw-req-option">
                                <input type="radio" name="request_type" value="custom-skill">
                                <div class="nw-req-option__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </div>
                                <div class="nw-req-option__text">
                                    <strong><?php esc_html_e('Custom AI skill', 'nibwp'); ?></strong>
                                    <span><?php esc_html_e('A specific MCP tool for your workflow', 'nibwp'); ?></span>
                                </div>
                            </label>
                            <label class="nw-req-option">
                                <input type="radio" name="request_type" value="api-connection">
                                <div class="nw-req-option__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                                </div>
                                <div class="nw-req-option__text">
                                    <strong><?php esc_html_e('Third-party API', 'nibwp'); ?></strong>
                                    <span><?php esc_html_e('Connect external services (Stripe, Slack, etc.)', 'nibwp'); ?></span>
                                </div>
                            </label>
                            <label class="nw-req-option">
                                <input type="radio" name="request_type" value="other">
                                <div class="nw-req-option__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                </div>
                                <div class="nw-req-option__text">
                                    <strong><?php esc_html_e('Something else', 'nibwp'); ?></strong>
                                    <span><?php esc_html_e('Tell us more in the next step', 'nibwp'); ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- STEP 2: Details -->
                    <div class="nw-req-pane" data-pane="2">
                        <div class="nw-req-pane__title"><?php esc_html_e('Tell us more', 'nibwp'); ?></div>
                        <div class="nw-req-field">
                            <label for="nw-req-name-plugin"><?php esc_html_e('Plugin / Service name', 'nibwp'); ?></label>
                            <input type="text" id="nw-req-name-plugin" name="plugin_name" placeholder="<?php esc_attr_e('e.g. Easy Affiliate, Stripe API, custom CRM', 'nibwp'); ?>">
                        </div>
                        <div class="nw-req-field">
                            <label for="nw-req-url"><?php esc_html_e('Plugin or API URL', 'nibwp'); ?> <span class="nw-req-optional"><?php esc_html_e('optional', 'nibwp'); ?></span></label>
                            <input type="url" id="nw-req-url" name="plugin_url" placeholder="https://...">
                        </div>
                        <div class="nw-req-field">
                            <label for="nw-req-desc"><?php esc_html_e('What should AI agents be able to do?', 'nibwp'); ?></label>
                            <textarea id="nw-req-desc" name="description" rows="4" placeholder="<?php esc_attr_e('e.g. List products, create orders, update inventory, send notifications...', 'nibwp'); ?>" required></textarea>
                        </div>
                        <div class="nw-req-field">
                            <label><?php esc_html_e('Priority', 'nibwp'); ?></label>
                            <div class="nw-req-priority">
                                <label><input type="radio" name="priority" value="low"><span><?php esc_html_e('Nice to have', 'nibwp'); ?></span></label>
                                <label><input type="radio" name="priority" value="medium" checked><span><?php esc_html_e('Important', 'nibwp'); ?></span></label>
                                <label><input type="radio" name="priority" value="high"><span><?php esc_html_e('Urgent', 'nibwp'); ?></span></label>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3: Contact -->
                    <div class="nw-req-pane" data-pane="3">
                        <div class="nw-req-pane__title"><?php esc_html_e('How can we reach you?', 'nibwp'); ?></div>
                        <div class="nw-req-field">
                            <label for="nw-req-name"><?php esc_html_e('Your name', 'nibwp'); ?></label>
                            <input type="text" id="nw-req-name" name="user_name" value="<?php echo esc_attr(wp_get_current_user()->display_name); ?>" required>
                        </div>
                        <div class="nw-req-field">
                            <label for="nw-req-email"><?php esc_html_e('Email address', 'nibwp'); ?></label>
                            <input type="email" id="nw-req-email" name="user_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
                        </div>
                        <div class="nw-req-field">
                            <label class="nw-req-checkbox-row">
                                <input type="checkbox" name="newsletter" value="1" checked>
                                <span><?php esc_html_e('Notify me when this integration is built', 'nibwp'); ?></span>
                            </label>
                        </div>
                    </div>

                    <!-- SUCCESS pane -->
                    <div class="nw-req-pane nw-req-pane-success" data-pane="success">
                        <div class="nw-req-success-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <strong><?php esc_html_e('Request sent!', 'nibwp'); ?></strong>
                        <p><?php esc_html_e('We received your request. Our team reviews every submission and prioritizes new builds based on community demand.', 'nibwp'); ?></p>
                        <p style="font-size:12px;color:var(--nw-text-muted);"><?php esc_html_e("You'll hear from us within 2 business days.", 'nibwp'); ?></p>
                    </div>

                    <!-- Footer with buttons -->
                    <div class="nw-req-foot">
                        <button type="button" class="nw-req-btn nw-req-btn-secondary" id="nw-req-back" disabled><?php esc_html_e('Back', 'nibwp'); ?></button>
                        <button type="button" class="nw-req-btn nw-req-btn-primary" id="nw-req-next"><?php esc_html_e('Next', 'nibwp'); ?>
                            <svg class="nw-rtl-flip" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </button>
                        <button type="submit" class="nw-req-btn nw-req-btn-primary" id="nw-req-submit" style="display:none;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            <?php esc_html_e('Send Request', 'nibwp'); ?>
                        </button>
                    </div>

                    <p class="nw-req-error" id="nw-req-error" role="alert" style="display:none;"></p>
                </form>
            </div>
        </div>

        <!-- Figma Connect Modal (same styling system as the request modal) -->
        <?php
        $fig_connected = function_exists('nibwp_figma_is_connected') && nibwp_figma_is_connected();
        $fig_handle    = (string) get_option('nibwp_figma_handle', '');
        $fig_cid       = (string) get_option('nibwp_figma_oauth_client_id', '');
        $fig_secret_ok = get_option('nibwp_figma_oauth_client_secret', '') !== '';
        $fig_cb        = function_exists('nibwp_figma_oauth_redirect_uri') ? nibwp_figma_oauth_redirect_uri() : '';
        $fig_post      = esc_url(admin_url('admin-post.php'));
        ?>
        <div class="nw-req-modal" id="nw-fig-modal" role="dialog" aria-hidden="true">
            <div class="nw-req-modal__backdrop" id="nw-fig-modal-backdrop"></div>
            <div class="nw-req-modal__panel">
                <button type="button" class="nw-req-modal__close" id="nw-fig-modal-close" aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>

                <div class="nw-req-modal__head">
                    <div class="nw-req-modal__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H8.5a3.5 3.5 0 000 7H12zm0 0h3.5a3.5 3.5 0 110 7H12zM8.5 9a3.5 3.5 0 000 7H12V9zm0 7a3.5 3.5 0 103.5 3.5V16zm7-7a3.5 3.5 0 100 7 3.5 3.5 0 000-7z"/></svg>
                    </div>
                    <div>
                        <strong><?php esc_html_e('Connect Figma', 'nibwp'); ?></strong>
                        <span><?php esc_html_e('Read-only. Pull frames as images + CSS tokens — NibWP + AI decide what to build.', 'nibwp'); ?></span>
                    </div>
                </div>

                <style>
                    /* Figma connect modal — scoped enrichments on top of nw-req-* */
                    #nw-fig-modal .nw-req-field input { width: 100%; max-width: none; }
                    /* Action rows: buttons and button-links sit on one baseline.
                       WP admin styles anchors blue and squares their focus ring, so
                       anchors styled as buttons need explicit color + radius here. */
                    #nw-fig-modal .nw-fig-actions {
                        display: flex; align-items: center; gap: 8px;
                        flex-wrap: wrap; margin-top: 12px;
                    }
                    #nw-fig-modal .nw-fig-actions form { margin: 0; }
                    #nw-fig-modal .nw-req-btn { margin-inline-start: 0; border-radius: 8px; }
                    #nw-fig-modal a.nw-req-btn,
                    #nw-fig-modal a.nw-req-btn:hover,
                    #nw-fig-modal a.nw-req-btn:focus,
                    #nw-fig-modal a.nw-req-btn:active { text-decoration: none; box-shadow: none; }
                    #nw-fig-modal a.nw-req-btn-primary,
                    #nw-fig-modal a.nw-req-btn-primary:hover,
                    #nw-fig-modal a.nw-req-btn-primary:focus,
                    #nw-fig-modal a.nw-req-btn-primary:active { color: #fff; }
                    #nw-fig-modal .nw-req-btn:focus-visible {
                        outline: none;
                        box-shadow: 0 0 0 3px rgba(245, 158, 11, .35);
                    }
                    /* Figma's accent is amber — keep primaries on-brand for this
                       modal instead of the default blue. */
                    #nw-fig-modal .nw-req-btn-primary {
                        background: linear-gradient(135deg, #f59e0b, #d97706);
                        border-color: #d97706;
                    }
                    #nw-fig-modal .nw-req-btn-primary:hover:not(:disabled) {
                        background: linear-gradient(135deg, #f59e0b, #b45309) !important;
                        color: #fff;
                    }
                    #nw-fig-modal .nw-fig-mask {
                        display: inline-block; margin-inline-start: 8px; padding: 1px 6px;
                        border-radius: 5px; background: var(--nw-surface-2, #f6f7f9);
                        border: 1px solid var(--nw-border, #e2e4e7);
                        font-size: 11.5px; letter-spacing: .04em;
                        color: var(--nw-text-2, #3c434a);
                    }
                    #nw-fig-modal .nw-fig-choose {
                        margin: 0 0 10px; font-size: 12.5px; font-weight: 500;
                        color: var(--nw-text-2, #3c434a);
                    }
                    /* Method tabs */
                    #nw-fig-modal .nw-fig-tabs {
                        display: flex; gap: 8px; margin: 2px 0 16px; flex-wrap: wrap;
                    }
                    #nw-fig-modal .nw-fig-tab {
                        display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
                        border: 1px solid var(--nw-border, #e2e4e7); border-radius: 999px;
                        background: var(--nw-surface, #fff); color: var(--nw-text-2, #3c434a);
                        font-size: 12.5px; font-weight: 600; padding-block: 7px; padding-inline: 8px 14px;
                        transition: border-color .15s, box-shadow .15s, background .15s;
                    }
                    #nw-fig-modal .nw-fig-tab:hover { border-color: #f0b25a; }
                    #nw-fig-modal .nw-fig-tab .nw-fig-tab__num {
                        width: 20px; height: 20px; border-radius: 50%; display: inline-grid; place-items: center;
                        font-size: 11px; font-weight: 700; color: #b45309;
                        background: #fff7ed; border: 1px solid #fed7aa;
                    }
                    #nw-fig-modal .nw-fig-tab.is-active {
                        background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border-color: #d97706;
                        box-shadow: 0 4px 12px rgba(217, 119, 6, .28);
                    }
                    #nw-fig-modal .nw-fig-tab.is-active .nw-fig-tab__num {
                        background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.45); color: #fff;
                    }
                    #nw-fig-modal .nw-fig-tab small { font-weight: 500; opacity: .75; font-size: 10.5px; }
                    /* Method panes */
                    #nw-fig-modal .nw-fig-pane { display: none; }
                    #nw-fig-modal .nw-fig-pane.is-active { display: block; animation: nw-pane-in 0.2s ease; }
                    #nw-fig-modal .nw-fig-method {
                        border: 1px solid var(--nw-border, #e2e4e7); border-radius: 12px;
                        padding: 16px 18px; background: var(--nw-surface, #fff);
                    }
                    #nw-fig-modal .nw-fig-method__head { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
                    #nw-fig-modal .nw-fig-method__num {
                        flex: 0 0 26px; width: 26px; height: 26px; border-radius: 50%;
                        display: inline-grid; place-items: center; font-size: 13px; font-weight: 700;
                        background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff;
                    }
                    #nw-fig-modal .nw-fig-method__head strong { font-size: 14px; }
                    #nw-fig-modal .nw-fig-method__head .nw-fig-tag {
                        font-size: 10.5px; font-weight: 600; color: #b45309; background: #fff7ed;
                        border: 1px solid #fed7aa; border-radius: 999px; padding: 1px 8px; margin-inline-start: 2px;
                    }
                    #nw-fig-modal .nw-fig-steps { margin: 8px 0 12px; padding: 0; padding-inline-start: 4px; list-style: none; counter-reset: figstep; }
                    #nw-fig-modal .nw-fig-steps li {
                        counter-increment: figstep; position: relative; padding-block: 3px; padding-inline: 30px 0;
                        font-size: 12.5px; color: var(--nw-text-2, #3c434a); line-height: 1.55;
                    }
                    #nw-fig-modal .nw-fig-steps li::before {
                        content: counter(figstep); position: absolute; inset-inline-start: 0; top: 4px;
                        width: 19px; height: 19px; border-radius: 50%; font-size: 11px; font-weight: 700;
                        display: grid; place-items: center; color: #b45309;
                        background: #fff7ed; border: 1px solid #fed7aa;
                    }
                    #nw-fig-modal .nw-fig-steps a { font-weight: 600; }
                    #nw-fig-modal .nw-fig-callback {
                        display: block; background: var(--nw-surface-2, #f6f7f9); border: 1px solid var(--nw-border, #e2e4e7);
                        border-radius: 8px; padding: 8px 10px; font-size: 11.5px; word-break: break-all; margin: 4px 0 2px;
                    }
                    #nw-fig-modal .nw-fig-note { font-size: 12px; color: var(--nw-text-muted, #787c82); margin: 8px 0 0; line-height: 1.55; }
                    #nw-fig-modal .nw-req-modal__panel { max-width: 640px; }
                </style>
                <div class="nw-req-form">
                    <div class="nw-req-pane is-active" data-pane="fig" style="overflow-y:auto;">
                        <?php
                        $fig_msg = isset($_GET['figma_msg']) ? sanitize_text_field((string) wp_unslash($_GET['figma_msg'])) : '';
                        $fig_err = isset($_GET['figma_err']) ? sanitize_text_field((string) wp_unslash($_GET['figma_err'])) : '';
                        if ($fig_err !== '') {
                            echo '<p style="background:#fdecec;border:1px solid #f3c2c2;color:#b32d2e;border-radius:8px;padding:8px 12px;font-size:12.5px;margin:0 0 12px;">' . esc_html($fig_err) . '</p>';
                        } elseif ($fig_msg !== '') {
                            echo '<p style="background:#e7f6ec;border:1px solid #b7e0c4;color:#137333;border-radius:8px;padding:8px 12px;font-size:12.5px;margin:0 0 12px;">' . esc_html($fig_msg) . '</p>';
                        }
                        ?>
                        <?php if ($fig_connected): ?>
                            <div class="nw-fig-method">
                                <div class="nw-fig-method__head">
                                    <span class="nw-fig-method__num" style="background:linear-gradient(135deg,#22c55e,#15803d);">✓</span>
                                    <strong><?php
                                        echo $fig_handle !== ''
                                            /* translators: %s: Figma account handle */
                                            ? esc_html(sprintf(__('Connected — %s', 'nibwp'), $fig_handle))
                                            : esc_html__('Connected', 'nibwp');
                                    ?></strong>
                                    <?php $fig_mask = function_exists('nibwp_figma_token_mask') ? nibwp_figma_token_mask() : ''; ?>
                                    <?php if ($fig_mask !== ''): ?>
                                        <code class="nw-fig-mask"><?php echo esc_html($fig_mask); ?></code>
                                    <?php endif; ?>
                                </div>
                                <ol class="nw-fig-steps">
                                    <li><?php esc_html_e('Open any Figma file, select a frame or section, and copy its link (Share → Copy link, or ⌘/Ctrl-L).', 'nibwp'); ?></li>
                                    <li><?php esc_html_e('Pull it into your library from the Figma page — it is cached as an image + CSS design tokens. Nothing is converted yet.', 'nibwp'); ?></li>
                                    <li><?php esc_html_e('Ask NibWP AI what to do with it — reuse its colors/typography, or build it with your page builder in any workflow.', 'nibwp'); ?></li>
                                </ol>
                                <div class="nw-fig-actions">
                                    <a class="nw-req-btn nw-req-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=nibwp-figma')); ?>"><?php esc_html_e('Open Figma library', 'nibwp'); ?></a>
                                    <form method="post" action="<?php echo $fig_post; ?>">
                                        <?php wp_nonce_field('nibwp_figma_disconnect'); ?>
                                        <input type="hidden" name="action" value="nibwp_figma_disconnect" />
                                        <input type="hidden" name="from_modal" value="1" />
                                        <button type="submit" class="nw-req-btn nw-req-btn-secondary"><?php esc_html_e('Disconnect', 'nibwp'); ?></button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>

                            <p class="nw-fig-choose"><?php esc_html_e('Pick one method — you only need a single one to connect.', 'nibwp'); ?></p>

                            <!-- Method tabs -->
                            <div class="nw-fig-tabs" role="tablist">
                                <button type="button" class="nw-fig-tab is-active" data-fig-tab="token" role="tab">
                                    <span class="nw-fig-tab__num">1</span><?php esc_html_e('Access token', 'nibwp'); ?>&nbsp;<small><?php esc_html_e('2 min', 'nibwp'); ?></small>
                                </button>
                                <button type="button" class="nw-fig-tab" data-fig-tab="oauth" role="tab">
                                    <span class="nw-fig-tab__num">2</span><?php esc_html_e('OAuth app', 'nibwp'); ?>&nbsp;<small><?php esc_html_e('teams', 'nibwp'); ?></small>
                                </button>
                                <button type="button" class="nw-fig-tab" data-fig-tab="mcp" role="tab">
                                    <span class="nw-fig-tab__num">3</span><?php esc_html_e('Dev Mode MCP', 'nibwp'); ?>&nbsp;<small><?php esc_html_e('AI in Figma', 'nibwp'); ?></small>
                                </button>
                            </div>

                            <!-- Pane 1 · Personal access token -->
                            <div class="nw-fig-pane is-active" data-fig-pane="token">
                                <div class="nw-fig-method">
                                    <div class="nw-fig-method__head">
                                        <strong><?php esc_html_e('Personal access token', 'nibwp'); ?></strong>
                                        <span class="nw-fig-tag"><?php esc_html_e('Recommended', 'nibwp'); ?></span>
                                    </div>
                                    <ol class="nw-fig-steps">
                                        <li><?php esc_html_e('In Figma, click your profile avatar (top-left) → Settings. A settings window opens.', 'nibwp'); ?></li>
                                        <li><?php esc_html_e('Go to the Security tab → Personal access tokens → Generate new token.', 'nibwp'); ?></li>
                                        <li><?php esc_html_e('Name it NibWP, set File content to Read-only, then Generate.', 'nibwp'); ?></li>
                                        <li><?php esc_html_e('Copy the token (figd_…, shown only once) and paste it below.', 'nibwp'); ?></li>
                                    </ol>
                                    <form method="post" action="<?php echo $fig_post; ?>">
                                        <?php wp_nonce_field('nibwp_figma_save_token'); ?>
                                        <input type="hidden" name="action" value="nibwp_figma_save_token" />
                                        <input type="hidden" name="from_modal" value="1" />
                                        <div class="nw-req-field">
                                            <label for="nw-fig-token"><?php esc_html_e('Personal access token', 'nibwp'); ?></label>
                                            <input type="password" id="nw-fig-token" name="figma_token" placeholder="figd_…" autocomplete="off" />
                                        </div>
                                        <div class="nw-fig-actions">
                                            <button type="submit" class="nw-req-btn nw-req-btn-primary"><?php esc_html_e('Save & Connect', 'nibwp'); ?></button>
                                        </div>
                                    </form>
                                    <p class="nw-fig-note"><?php esc_html_e('Read-only: NibWP can read files your Figma account can open (private included) — it can never change your designs. The token is validated with Figma before it is saved.', 'nibwp'); ?></p>
                                </div>
                            </div>

                            <!-- Pane 2 · OAuth app -->
                            <div class="nw-fig-pane" data-fig-pane="oauth">
                                <div class="nw-fig-method">
                                    <div class="nw-fig-method__head">
                                        <strong><?php esc_html_e('OAuth app', 'nibwp'); ?></strong>
                                        <span class="nw-fig-tag"><?php esc_html_e('Teams & agencies', 'nibwp'); ?></span>
                                    </div>
                                    <ol class="nw-fig-steps">
                                        <li><?php printf(
                                            /* translators: %s: link to Figma developer apps */
                                            esc_html__('Create an app at %s (any name/logo).', 'nibwp'),
                                            '<a href="https://www.figma.com/developers/apps" target="_blank" rel="noopener">figma.com/developers/apps</a>'
                                        ); ?></li>
                                        <li><?php esc_html_e('Add this exact callback URL to the app:', 'nibwp'); ?>
                                            <code class="nw-fig-callback"><?php echo esc_html($fig_cb); ?></code>
                                        </li>
                                        <li><?php esc_html_e('Paste the app\'s Client ID and Client Secret below, save, then click "Connect with Figma" and approve access.', 'nibwp'); ?></li>
                                    </ol>
                                    <form method="post" action="<?php echo $fig_post; ?>">
                                        <?php wp_nonce_field('nibwp_figma_save_oauth'); ?>
                                        <input type="hidden" name="action" value="nibwp_figma_save_oauth" />
                                        <input type="hidden" name="from_modal" value="1" />
                                        <div class="nw-req-field">
                                            <label><?php esc_html_e('Client ID', 'nibwp'); ?></label>
                                            <input type="text" name="figma_client_id" value="<?php echo esc_attr($fig_cid); ?>" />
                                        </div>
                                        <div class="nw-req-field">
                                            <label><?php esc_html_e('Client Secret', 'nibwp'); ?></label>
                                            <input type="password" name="figma_client_secret" placeholder="<?php echo $fig_secret_ok ? esc_attr__('•••••• (saved — leave blank to keep)', 'nibwp') : ''; ?>" autocomplete="off" />
                                        </div>
                                        <div class="nw-fig-actions">
                                            <button type="submit" class="nw-req-btn nw-req-btn-secondary"><?php esc_html_e('Save app credentials', 'nibwp'); ?></button>
                                        </div>
                                    </form>
                                    <?php if ($fig_cid !== '' && $fig_secret_ok): ?>
                                        <form method="post" action="<?php echo $fig_post; ?>" class="nw-fig-actions" style="margin-top:8px;">
                                            <?php wp_nonce_field('nibwp_figma_oauth_start'); ?>
                                            <input type="hidden" name="action" value="nibwp_figma_oauth_start" />
                                            <button type="submit" class="nw-req-btn nw-req-btn-primary"><?php esc_html_e('Connect with Figma (OAuth)', 'nibwp'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Pane 3 · Dev Mode MCP -->
                            <div class="nw-fig-pane" data-fig-pane="mcp">
                                <div class="nw-fig-method">
                                    <div class="nw-fig-method__head">
                                        <strong><?php esc_html_e('Figma Dev Mode MCP', 'nibwp'); ?></strong>
                                        <span class="nw-fig-tag"><?php esc_html_e('AI, inside Figma', 'nibwp'); ?></span>
                                    </div>
                                    <ol class="nw-fig-steps">
                                        <li><?php printf(
                                            /* translators: %s: link to Figma desktop download */
                                            esc_html__('Install the %s (requires a Dev or Full seat).', 'nibwp'),
                                            '<a href="https://www.figma.com/downloads/" target="_blank" rel="noopener">' . esc_html__('Figma desktop app', 'nibwp') . '</a>'
                                        ); ?></li>
                                        <li><?php printf(
                                            /* translators: %s: link to Dev Mode MCP guide */
                                            esc_html__('Enable the Dev Mode MCP server — see %s.', 'nibwp'),
                                            '<a href="https://help.figma.com/hc/en-us/articles/32132100833559" target="_blank" rel="noopener">' . esc_html__('Figma\'s MCP guide', 'nibwp') . '</a>'
                                        ); ?></li>
                                        <li><?php esc_html_e('Your AI agent then reads the frame you have selected in Figma and works with NibWP directly — no token needed for that flow.', 'nibwp'); ?></li>
                                    </ol>
                                    <p class="nw-fig-note"><?php esc_html_e('Best of both: connect an access token (tab 1) so NibWP can pull frames headlessly any time, and use Dev Mode MCP when you are designing live in Figma.', 'nibwp'); ?></p>
                                </div>
                            </div>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Integration Cards -->
        <?php
        // Pro-only integration + toolkit keys. The Free wp.org build does not
        // ship the license client (premium code is excluded), so these keys
        // are hidden from the grid entirely — Free shows only the integrations
        // it actually supports. The standalone Pro plugin loads the license
        // client (NIBWP_LICENSE_CLIENT_LOADED), at which point the full list
        // is shown with per-card unlock state.
        $pro_integration_keys = [
            'elementor', 'elementor-addons', 'bricks', 'rometheme', 'wpb-addons', 'mega-elements', 'lastudio-kit', 'htmega', 'move-addons', 'ht-slider', 'elementinvader', 'anywhere-elementor', 'unlimited-elements', 'qi-addons', 'plus-addons', 'jeg-kit', 'addon-elements', 'royal-addons', 'premium-addons', 'happy-addons', 'elementskit', 'eael', 'hfe', 'builderius', 'mosaic', 'etchwp', 'etchedy', 'bricksed', 'automaticcss',
            'acf', 'jetengine', 'metabox', 'pods', 'acpt', 'ase',
            'fluentcrm', 'fluentcart', 'fluentaffiliate', 'edd', 'forms',
            'directorist',
            'learndash', 'lifterlms', 'memberpress', 'tutorlms',
            'buddypress', 'events', 'givewp',
            'redirection', 'tablepress', 'translatepress', 'wpml',
            'thirstyaffiliates',
            'ultimate-member',
            'wp-mail-smtp',
            'wordfence',
            'themify',
            'blocksy',
            // The whole paper/ directory sits behind the premium markers, so in
            // the Free build nibwp_paper_is_connected() does not exist and the
            // card would offer a Connect link to a screen that was stripped.
            'paper',
            'events-manager',
            'eventin',
            'mailoptin',
            'eventprime',
            'siteorigin',
            'seedprod',
            'suretriggers',
            'sureforms',
            'suredash',
            'surerank',
            'gutenverse',
            'generateblocks',
            'stackable',
            'license-manager',
            'survey-maker',
            'metform',
            'everest-forms',
            'mailpoet',
            'seo', 'wp-job-manager',
            'generatepress', 'kadence', 'divi', 'voxel',
            'fluentcommunity', 'fluentsmtp',
            'seopress', 'slimseo', 'surecart',
        ];
        $pro_toolkit_keys = ['security', 'notifications', 'migration', 'content-fetcher', 'content-planner', 'seo-advanced'];
        $pro_keys = array_merge($pro_integration_keys, $pro_toolkit_keys);

        if (!defined('NIBWP_LICENSE_CLIENT_LOADED')) {
            // Free build: hide every premium card.
            $integrations = array_diff_key($integrations, array_flip($pro_keys));
            $pro_keys = [];
        }

        $user_is_pro = function_exists('nibwp_is_pro') && nibwp_is_pro();

        // Default order: ACTIVE integrations first, then DETECTED (plugin
        // installed but not enabled), then everything else. Stable within each
        // group (PHP 8 sort is stable) so the catalog order is preserved. This
        // becomes the JS "Default" sort baseline too (defaultOrder reads the DOM).
        uasort($integrations, static function (array $a, array $b): int {
            $rank = static function (array $i): int {
                $available = !empty($i['plugin_available']);
                if ($available && !empty($i['enabled'])) {
                    return 0; // active
                }
                return $available ? 1 : 2; // detected : not installed
            };
            return $rank($a) <=> $rank($b);
        });
        ?>
        <div class="nibwp-integration-grid" id="nw-int-grid">
            <?php foreach ($integrations as $key => $integration): ?>
                <?php
                // Hidden entries stay in the catalogue and keep working — they
                // are simply not offered as a card. The universal forms tool is
                // the only one: every plugin it covers now has a dedicated
                // integration, so showing both invites an agent to pick the
                // shallower one.
                if (!empty($integration['hidden'])) {
                    continue;
                }
                ?>
                <?php
                $is_available = $integration['plugin_available'];
                $is_enabled = $integration['enabled'];
                $is_active = $is_available && $is_enabled;
                $is_memory = $key === 'memory';
                $is_pro_integration = in_array($key, $pro_keys, strict: true);
                $skill_entitlement = 'integration:' . $key;
                $unlocked_by_skill = function_exists('nibwp_has_entitlement') && nibwp_has_entitlement($skill_entitlement);
                $is_locked = $is_pro_integration && !$user_is_pro && !$unlocked_by_skill;
                ?>
                <?php
                $card_haystack = strtolower(implode(' ', array_filter([
                    (string) ($integration['name'] ?? ''),
                    (string) ($integration['plugin_name'] ?? ''),
                    (string) $key,
                    (string) ($integration['description'] ?? ''),
                    implode(' ', (array) ($integration['abilities'] ?? [])),
                    (string) ($integration['category'] ?? ''),
                ])));
                ?>
                <div class="nibwp-integration-card <?php
                    if ($is_locked) {
                        echo 'is-locked';
                    } else {
                        echo $is_active ? 'is-active' : ($is_available ? 'is-available' : 'is-unavailable');
                    }
                ?>"
                     data-key="<?php echo esc_attr((string) $key); ?>"
                     data-cat="<?php echo esc_attr($integration['category']); ?>"
                     data-tier="<?php echo $is_pro_integration ? 'premium' : 'free'; ?>"
                     data-locked="<?php echo $is_locked ? '1' : '0'; ?>"
                     data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>"
                     data-available="<?php echo $is_available ? '1' : '0'; ?>"
                     data-plugin-name="<?php echo esc_attr((string) ($integration['plugin_name'] ?? '')); ?>"
                     data-search="<?php echo esc_attr($card_haystack); ?>">
                    <?php // @nibwp:premium-start ?>
                    <?php if ($is_pro_integration): ?>
                        <span class="nibwp-pro-badge" title="<?php esc_attr_e('Pro feature', 'nibwp'); ?>">PRO</span>
                    <?php endif; ?>
                    <?php // @nibwp:premium-end ?>
                    <div class="nibwp-integration-header">
                        <div class="nibwp-integration-icon <?php echo $is_active ? 'is-active' : ''; ?>">
                            <?php echo $integration['icon']; ?>
                        </div>
                        <?php
                        // Compute the badge state ONCE so the markup below can
                        // be a sequence of independent if blocks. Premium-only
                        // states (the "locked — needs Pro" badge) sit inside
                        // build-strip markers, so the strip removes the block
                        // cleanly without leaving orphan elseifs in a chain.
                        $badge_state = 'inactive';
                        if ($is_locked) {
                            $badge_state = 'locked';
                        } elseif ($is_memory) {
                            $badge_state = 'always';
                        } elseif ($is_active) {
                            $badge_state = 'active';
                        } elseif ($is_available && !$is_enabled) {
                            $badge_state = 'ready';
                        } elseif ($is_enabled && !$is_available) {
                            $badge_state = 'missing';
                        }
                        // Cloud services (e.g. Figma) have no local plugin — the
                        // state is Connected / Not connected, not installed.
                        $is_cloud = !empty($integration['cloud']);
                        if ($is_cloud && !$is_locked) {
                            $badge_state = $is_available ? 'cloud_on' : 'cloud_off';
                        }
                        ?>
                        <div class="nibwp-integration-info">
                            <h3><?php echo esc_html($integration['name']); ?></h3>
                            <?php // @nibwp:premium-start ?>
                            <?php if ($badge_state === 'locked'): ?>
                                <span class="nibwp-integration-badge is-locked-badge"><?php esc_html_e('Locked — needs Pro', 'nibwp'); ?></span>
                            <?php endif; ?>
                            <?php // @nibwp:premium-end ?>
                            <?php if ($badge_state === 'always'): ?>
                                <span class="nibwp-integration-badge is-always"><?php esc_html_e('Always on', 'nibwp'); ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'active'): ?>
                                <span class="nibwp-integration-badge is-active"><?php esc_html_e('Active', 'nibwp'); ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'ready'): ?>
                                <?php
                                // Names the plugin on purpose. "Ready — not
                                // activated" sat next to a plugin's own name and
                                // read as though THAT plugin were switched off,
                                // which is exactly backwards: it is detected, and
                                // it is our integration that is not on yet.
                                ?>
                                <span class="nibwp-integration-badge is-ready"><?php
                                    /* translators: %s: third-party plugin name */
                                    printf(esc_html__('%s detected — switch on to use', 'nibwp'), esc_html($integration['plugin_name']));
                                ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'missing'): ?>
                                <span class="nibwp-integration-badge is-missing"><?php
                                    /* translators: %s: third-party plugin name */
                                    printf(esc_html__('%s not detected', 'nibwp'), esc_html($integration['plugin_name']));
                                ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'inactive'): ?>
                                <span class="nibwp-integration-badge is-inactive"><?php
                                    /* translators: %s: third-party plugin name */
                                    printf(esc_html__('%s not installed', 'nibwp'), esc_html($integration['plugin_name']));
                                ?></span>
                            <?php endif; ?>
                            <?php if ($badge_state === 'cloud_on'): ?>
                                <span class="nibwp-integration-badge is-active"><?php esc_html_e('Connected', 'nibwp'); ?></span>
                                <a href="#" class="nw-fig-connect-open" style="margin-inline-start:8px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e('Manage', 'nibwp'); ?></a>
                            <?php endif; ?>
                            <?php if ($badge_state === 'cloud_off'): ?>
                                <span class="nibwp-integration-badge is-inactive"><?php esc_html_e('Not connected', 'nibwp'); ?></span>
                                <?php
                                // Figma connects in a lightbox on this page. Paper
                                // cannot: its pairing is a workflow the agent runs,
                                // so a card that declares connect_url sends people
                                // to that screen instead of opening a modal that
                                // would have nothing to put in it.
                                $connect_url = (string) ($integration['connect_url'] ?? '');
                                ?>
                                <a href="<?php echo $connect_url !== '' ? esc_url(admin_url($connect_url)) : '#'; ?>" class="<?php echo $connect_url !== '' ? 'nw-int-connect-link' : 'nw-fig-connect-open'; ?>" style="margin-inline-start:8px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e('Connect', 'nibwp'); ?> <span class="nw-rtl-flip" aria-hidden="true">→</span></a>
                            <?php endif; ?>
                        </div>
                        <?php // @nibwp:premium-start ?>
                        <?php if ($is_locked): ?>
                            <div class="nibwp-integration-toggle">
                                <a class="nibwp-unlock-btn" href="<?php echo esc_url(nibwp_pricing_url()); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e('Upgrade to NIBWP Pro', 'nibwp'); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                    <span><?php esc_html_e('Unlock', 'nibwp'); ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                        <?php // @nibwp:premium-end ?>
                        <?php if (!$is_locked && !$is_memory): ?>
                            <div class="nibwp-integration-toggle">
                                <form method="post" style="margin:0;">
                                    <?php wp_nonce_field('nibwp_integration_toggle'); ?>
                                    <input type="hidden" name="nibwp_integration_key" value="<?php echo esc_attr($key); ?>" />
                                    <input type="hidden" name="nibwp_integration_state" value="<?php echo $is_enabled ? '0' : '1'; ?>" />
                                    <button type="submit" name="nibwp_integration_toggle"
                                            class="nibwp-toggle-btn <?php echo $is_enabled ? 'is-on' : 'is-off'; ?>"
                                            title="<?php echo $is_enabled
                                                ? esc_attr__('Deactivate', 'nibwp')
                                                : esc_attr__('Activate', 'nibwp'); ?>"
                                    >
                                        <span class="nibwp-toggle-track">
                                            <span class="nibwp-toggle-thumb"></span>
                                        </span>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="nibwp-integration-desc"><?php echo esc_html($integration['description']); ?></p>
                    <div class="nibwp-integration-footer">
                        <?php
                        // Operations, not ability names — one hub ability can carry
                        // thirty-odd actions, and counting names called that "1".
                        $nibwp_ops = nibwp_integration_operation_count(
                            (array) $integration['abilities'],
                            (int) ($integration['actions'] ?? 0),
                        );
                        ?>
                        <span class="nibwp-ability-count">
                            <?php printf(
                                /* translators: %d: number of operations the integration offers */
                                esc_html(_n('%d AI action', '%d AI actions', $nibwp_ops, 'nibwp')),
                                $nibwp_ops,
                            ); ?>
                        </span>
                        <?php
                        if (!$is_memory) {
                            $nibwp_labels = nibwp_integration_operation_labels(
                                (array) $integration['abilities'],
                                (array) ($integration['examples'] ?? []),
                            );
                            $nibwp_rest = $nibwp_ops - count($nibwp_labels);
                            ?>
                            <span class="nibwp-ability-list">
                                <?php echo esc_html(implode(', ', $nibwp_labels)); ?>
                                <?php if ($nibwp_rest > 0) {
                                    printf(
                                        /* translators: %d: how many further actions the integration offers */
                                        esc_html__('+%d more', 'nibwp'),
                                        $nibwp_rest,
                                    );
                                } ?>
                            </span>
                        <?php } ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="nw-bulk-overlay" id="nw-bulk-overlay" hidden>
            <div class="nw-bulk-overlay__box">
                <span class="nw-bulk-spinner" aria-hidden="true"></span>
                <span class="nw-bulk-overlay__text" id="nw-bulk-overlay-text" role="status" aria-live="polite"></span>
            </div>
        </div>
    </div>

    <script>
    window.nibwpInt = {
        restRoot: '<?php echo esc_js(rest_url('nibwp/v1/')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
    };
    // Every word the scripts below put on screen. The badge strings carry a %s
    // for the plugin name — the JS fills it in the same way the PHP badge does,
    // so the two cannot drift apart.
    var nibwpIntegrationsI18n = <?php echo wp_json_encode([
        'activate'       => __('Activate', 'nibwp'),
        'deactivate'     => __('Deactivate', 'nibwp'),
        'bulk_all'       => __('Activating all integrations…', 'nibwp'),
        'bulk_detected'  => __('Activating detected integrations…', 'nibwp'),
        'bulk_none'      => __('Deactivating all integrations…', 'nibwp'),
        'toggle_failed'  => __('Could not change this integration. Nothing was saved.', 'nibwp'),
        'toggle_offline' => __('Could not reach the site. The integration was not changed.', 'nibwp'),
        'badge_always'   => __('Always on', 'nibwp'),
        'badge_active'   => __('Active', 'nibwp'),
        /* translators: %s: third-party plugin name */
        'badge_ready'    => __('%s detected — switch on to use', 'nibwp'),
        /* translators: %s: third-party plugin name */
        'badge_missing'  => __('%s not detected', 'nibwp'),
        /* translators: %s: third-party plugin name */
        'badge_inactive' => __('%s not installed', 'nibwp'),
        'request_done'   => __('Done', 'nibwp'),
        'request_choose' => __('Please choose what you need.', 'nibwp'),
        'request_sending' => __('Sending…', 'nibwp'),
        'request_failed' => __('That did not send. Please try again.', 'nibwp'),
        'request_offline' => __('That did not send — the site could not be reached.', 'nibwp'),
    ]); ?>;
    </script>
    <script>
    (function(){
        var tabs   = document.querySelectorAll('#nw-int-tabs .nw-int-tab');
        var cards  = document.querySelectorAll('#nw-int-grid .nibwp-integration-card');
        var wrap   = document.getElementById('nw-int-tabs-wrap');
        var tabsContainer = document.getElementById('nw-int-tabs');
        var search = document.getElementById('nw-int-search');
        var searchClear = document.getElementById('nw-int-search-clear');
        var searchCount = document.getElementById('nw-int-search-count');
        var stateActive = document.getElementById('nw-int-stat-active');

        var currentTab = 'all';
        var currentQuery = '';
        var currentTier = 'all';

        function applyFilters(){
            var visible = 0, total = 0;
            cards.forEach(function(card){
                total++;
                var catOk = (currentTab === 'all' || card.getAttribute('data-cat') === currentTab);
                var tierOk = (currentTier === 'all' || card.getAttribute('data-tier') === currentTier);
                var hay = (card.getAttribute('data-search') || '').toLowerCase();
                var queryOk = currentQuery === '' || hay.indexOf(currentQuery) !== -1;
                var show = catOk && tierOk && queryOk;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (searchCount) {
                searchCount.hidden = false;
                searchCount.textContent = visible + ' / ' + total;
            }
        }

        /* Tier filter pills (Free / Premium / All) */
        document.querySelectorAll('.nw-tier-filter .nw-tier-pill').forEach(function(pill){
            pill.addEventListener('click', function(){
                document.querySelectorAll('.nw-tier-filter .nw-tier-pill').forEach(function(p){ p.classList.remove('is-active'); });
                pill.classList.add('is-active');
                currentTier = pill.getAttribute('data-tier') || 'all';
                applyFilters();
            });
        });

        /* Sort dropdown — reorders the grid in place, keeping filters/search intact.
           default  = the catalog order the page rendered in.
           detected = integrations whose plugin is installed/available float to the top
                      (stable, so order within each group is preserved).
           az       = smart, case-insensitive, numeric-aware sort of the card titles. */
        var grid = document.getElementById('nw-int-grid');
        var defaultOrder = Array.prototype.slice.call(cards);
        function cardName(c){ var h = c.querySelector('.nibwp-integration-header h3'); return h ? h.textContent.trim() : ''; }
        function isDetected(c){ return c.getAttribute('data-available') === '1' ? 0 : 1; }
        var sortSelect = document.getElementById('nw-int-sort');
        if (sortSelect && grid) {
            sortSelect.addEventListener('change', function(){
                var mode = sortSelect.value, order;
                if (mode === 'az') {
                    order = defaultOrder.slice().sort(function(a, b){
                        return cardName(a).localeCompare(cardName(b), undefined, { sensitivity: 'base', numeric: true });
                    });
                } else if (mode === 'detected') {
                    order = defaultOrder.slice().sort(function(a, b){ return isDetected(a) - isDetected(b); });
                } else {
                    order = defaultOrder;
                }
                order.forEach(function(c){ grid.appendChild(c); });
            });
        }

        /* Tab click — filter cards via composed filter */
        tabs.forEach(function(tab){
            tab.addEventListener('click', function(){
                tabs.forEach(function(t){ t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                currentTab = tab.getAttribute('data-tab') || 'all';
                applyFilters();
            });
        });

        /* Search — instant filter across name, slug, plugin, description, abilities */
        if (search) {
            var debounceTimer = null;
            search.addEventListener('input', function(){
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function(){
                    currentQuery = (search.value || '').trim().toLowerCase();
                    if (searchClear) searchClear.hidden = currentQuery === '';
                    applyFilters();
                }, 60);
            });
            search.addEventListener('keydown', function(e){
                if (e.key === 'Escape') {
                    search.value = '';
                    currentQuery = '';
                    if (searchClear) searchClear.hidden = true;
                    applyFilters();
                    search.blur();
                }
            });
        }
        if (searchClear) {
            searchClear.addEventListener('click', function(){
                search.value = '';
                currentQuery = '';
                searchClear.hidden = true;
                applyFilters();
                search.focus();
            });
        }

        /* ── AJAX toggle — intercept form submit, swap state without reload ── */

        /* Pending / error feedback.
           The request is usually fast, but "usually" is why the control felt
           dead: on anything slower than instant there was no sign a click had
           registered at all. */
        function setPending(btn, card, on){
            if (btn) {
                btn.classList.toggle('is-pending', !!on);
                /* Left clickable but ignored while pending — a disabled button
                   loses focus, which drops a keyboard user out of the grid. */
                btn.setAttribute('aria-busy', on ? 'true' : 'false');
            }
            if (card) card.classList.toggle('is-busy', !!on);
        }

        function clearCardError(card){
            if (!card) return;
            var existing = card.querySelector('.nibwp-toggle-error');
            if (existing) existing.remove();
        }

        function showCardError(card, message){
            if (!card) return;
            clearCardError(card);
            var el = document.createElement('span');
            el.className = 'nibwp-toggle-error';
            el.setAttribute('role', 'status');
            el.textContent = message;
            card.appendChild(el);
            /* Cleared on the next attempt rather than on a timer — an error
               that vanishes on its own is one the user may never have read. */
        }

        function badgeHtmlFor(card){
            var available = card.getAttribute('data-available') === '1';
            var enabled   = card.getAttribute('data-enabled')   === '1';
            var pluginName = card.getAttribute('data-plugin-name') || '';
            var key = card.getAttribute('data-key');
            var i18n = nibwpIntegrationsI18n;
            if (key === 'memory') {
                return '<span class="nibwp-integration-badge is-always">' + i18n.badge_always + '</span>';
            }
            if (available && enabled) {
                return '<span class="nibwp-integration-badge is-active">' + i18n.badge_active + '</span>';
            }
            if (available && !enabled) {
                return '<span class="nibwp-integration-badge is-ready">' + i18n.badge_ready.replace('%s', pluginName) + '</span>';
            }
            if (!available && enabled) {
                return '<span class="nibwp-integration-badge is-missing">' + i18n.badge_missing.replace('%s', pluginName) + '</span>';
            }
            return '<span class="nibwp-integration-badge is-inactive">' + i18n.badge_inactive.replace('%s', pluginName) + '</span>';
        }
        function applyCardState(card, data){
            card.setAttribute('data-enabled', data.enabled ? '1' : '0');
            card.setAttribute('data-available', data.available ? '1' : '0');
            card.classList.remove('is-active', 'is-available', 'is-unavailable');
            if (data.active) {
                card.classList.add('is-active');
            } else if (data.available) {
                card.classList.add('is-available');
            } else {
                card.classList.add('is-unavailable');
            }
            var icon = card.querySelector('.nibwp-integration-icon');
            if (icon) icon.classList.toggle('is-active', !!data.active);
            var btn = card.querySelector('.nibwp-toggle-btn');
            if (btn) {
                btn.classList.toggle('is-on', !!data.enabled);
                btn.classList.toggle('is-off', !data.enabled);
                btn.disabled = false;
                btn.title = data.enabled ? nibwpIntegrationsI18n.deactivate : nibwpIntegrationsI18n.activate;
                /* Flip the hidden state field so a fallback form submit (no-JS) toggles correctly. */
                var stateField = btn.closest('form') && btn.closest('form').querySelector('input[name="nibwp_integration_state"]');
                if (stateField) stateField.value = data.enabled ? '0' : '1';
            }
            var badgeWrap = card.querySelector('.nibwp-integration-info');
            if (badgeWrap) {
                var oldBadge = badgeWrap.querySelector('.nibwp-integration-badge');
                if (oldBadge) {
                    oldBadge.outerHTML = badgeHtmlFor(card);
                }
            }
        }

        var grid = document.getElementById('nw-int-grid');
        if (grid) {
            /* Declared here as well as in the bulk block below: the two scopes
               are separate, and the toggle handler referencing the bulk one
               would throw a ReferenceError on the first failed request. */
            var L = nibwpIntegrationsI18n;
            grid.addEventListener('submit', function(e){
                var form = e.target;
                if (!form.matches('.nibwp-integration-toggle form')) return;
                e.preventDefault();
                var card = form.closest('.nibwp-integration-card');
                if (!card) return;
                if (card.getAttribute('data-locked') === '1') return;
                var btn = form.querySelector('.nibwp-toggle-btn');
                var key = card.getAttribute('data-key');
                var enabled = !(card.getAttribute('data-enabled') === '1');

                /* Ignore a second click while one is already in flight —
                   otherwise two requests race and the last response wins,
                   which can leave the card showing the opposite of what the
                   user asked for. */
                if (btn && btn.classList.contains('is-pending')) return;

                setPending(btn, card, true);
                clearCardError(card);
                fetch(window.nibwpInt.restRoot + 'integrations/toggle', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.nibwpInt.nonce
                    },
                    body: JSON.stringify({ key: key, enabled: enabled })
                })
                .then(function(r){ return r.json().catch(function(){ return { ok: false }; }); })
                .then(function(data){
                    setPending(btn, card, false);
                    if (!data || !data.ok) {
                        /* Surfaced on the card, not just in the console. A
                           refusal the user cannot see is indistinguishable
                           from a toggle that silently does nothing. */
                        showCardError(card, (data && data.message) || L.toggle_failed);
                        return;
                    }
                    applyCardState(card, data);
                    if (btn) {
                        btn.classList.add('just-changed');
                        setTimeout(function(){ btn.classList.remove('just-changed'); }, 600);
                    }
                    if (stateActive && typeof data.active_count === 'number') {
                        stateActive.textContent = data.active_count;
                    }
                })
                .catch(function(err){
                    setPending(btn, card, false);
                    showCardError(card, L.toggle_offline);
                    console.warn('NIBWP toggle failed:', err);
                });
            });
        }

        /* Bulk activate (detected / all) — AJAX, applies the response to every card. */
        var bulkForm = document.querySelector('.nw-bulk-acts');
        if (bulkForm) {
            var overlay = document.getElementById('nw-bulk-overlay');
            var overlayText = document.getElementById('nw-bulk-overlay-text');
            var L = nibwpIntegrationsI18n;
            bulkForm.addEventListener('submit', function(e){
                e.preventDefault();
                var mode = (e.submitter && e.submitter.value) || 'detected';
                var bbtns = bulkForm.querySelectorAll('button');
                bbtns.forEach(function(b){ b.disabled = true; });
                if (overlay) {
                    if (overlayText) overlayText.textContent = mode === 'none' ? L.bulk_none : (mode === 'detected' ? L.bulk_detected : L.bulk_all);
                    overlay.hidden = false;
                }
                fetch(window.nibwpInt.restRoot + 'integrations/bulk', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.nibwpInt.nonce },
                    body: JSON.stringify({ mode: mode })
                })
                .then(function(r){ return r.json().catch(function(){ return { ok: false }; }); })
                .then(function(data){
                    bbtns.forEach(function(b){ b.disabled = false; });
                    if (overlay) overlay.hidden = true;
                    if (!data || !data.ok || !data.items) return;
                    document.querySelectorAll('#nw-int-grid .nibwp-integration-card').forEach(function(card){
                        var st = data.items[card.getAttribute('data-key')];
                        if (st) applyCardState(card, st);
                    });
                    if (stateActive && typeof data.active_count === 'number') {
                        stateActive.textContent = data.active_count;
                    }
                    applyFilters();
                })
                .catch(function(){ bbtns.forEach(function(b){ b.disabled = false; }); if (overlay) overlay.hidden = true; });
            });
        }

        /* Hover-to-scroll — mouse position determines scroll direction & speed */
        if (!wrap || !tabsContainer) return;

        var rafId = null;
        var scrollVelocity = 0;
        var DEAD_ZONE = 0.05;  /* center 10% = no scroll */
        var MAX_SPEED = 38;    /* max pixels per frame — fast! */

        function updateShadows(){
            var max = tabsContainer.scrollWidth - tabsContainer.clientWidth;
            // Right to left, scrollLeft runs from 0 at the start down to -max at
            // the end, and the RTL stylesheet swaps the fades, so the distance
            // from the start is what both classes need.
            wrap.classList.toggle('has-scroll-left', Math.abs(tabsContainer.scrollLeft) > 2);
            wrap.classList.toggle('has-scroll-right', Math.abs(tabsContainer.scrollLeft) < max - 2);
        }

        function animateScroll(){
            if (Math.abs(scrollVelocity) < 0.1) {
                rafId = null;
                return;
            }
            tabsContainer.scrollLeft += scrollVelocity;
            updateShadows();
            rafId = requestAnimationFrame(animateScroll);
        }

        function onMouseMove(e){
            var rect = wrap.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var width = rect.width;
            /* Normalize position: -1 (far left) to +1 (far right), 0 = center */
            var offset = (x / width) * 2 - 1;

            if (Math.abs(offset) < DEAD_ZONE) {
                scrollVelocity = 0;
            } else {
                /* Easing curve: slow near center, accelerates toward edges */
                var sign = offset < 0 ? -1 : 1;
                var magnitude = (Math.abs(offset) - DEAD_ZONE) / (1 - DEAD_ZONE);
                /* Linear easing for responsive feel — closer to edges = much faster */
                scrollVelocity = sign * MAX_SPEED * magnitude;
            }

            if (scrollVelocity !== 0 && rafId === null) {
                rafId = requestAnimationFrame(animateScroll);
            }
        }

        function onMouseLeave(){
            scrollVelocity = 0;
        }

        wrap.addEventListener('mousemove', onMouseMove);
        wrap.addEventListener('mouseleave', onMouseLeave);

        /* Initial shadow state */
        updateShadows();
        window.addEventListener('resize', updateShadows);

        /* ── Figma Connect Modal (reuses the request-modal styling) ── */
        (function(){
            var fig = document.getElementById('nw-fig-modal');
            if (!fig) return;
            function figOpen(e){ if(e){e.preventDefault();} fig.setAttribute('aria-hidden','false'); fig.classList.add('is-open'); document.body.style.overflow='hidden'; }
            function figClose(){ fig.setAttribute('aria-hidden','true'); fig.classList.remove('is-open'); document.body.style.overflow=''; }
            document.querySelectorAll('.nw-fig-connect-open').forEach(function(a){ a.addEventListener('click', figOpen); });
            var c = document.getElementById('nw-fig-modal-close');
            var b = document.getElementById('nw-fig-modal-backdrop');
            if (c) c.addEventListener('click', figClose);
            if (b) b.addEventListener('click', figClose);
            document.addEventListener('keydown', function(e){ if(e.key==='Escape' && fig.classList.contains('is-open')) figClose(); });
            /* Method tabs */
            fig.querySelectorAll('.nw-fig-tab').forEach(function(tab){
                tab.addEventListener('click', function(){
                    var key = tab.getAttribute('data-fig-tab');
                    fig.querySelectorAll('.nw-fig-tab').forEach(function(t){ t.classList.toggle('is-active', t === tab); });
                    fig.querySelectorAll('.nw-fig-pane').forEach(function(p){ p.classList.toggle('is-active', p.getAttribute('data-fig-pane') === key); });
                });
            });
            /* Re-open after a connect/disconnect round-trip so the result shows in place */
            if (new URLSearchParams(location.search).has('figma_modal')) figOpen();
        })();

        /* ── Custom Integration Request Modal ── */
        var modal = document.getElementById('nw-req-modal');
        var openBtn = document.getElementById('nw-request-integration-btn');
        var closeBtn = document.getElementById('nw-req-modal-close');
        var backdrop = document.getElementById('nw-req-modal-backdrop');
        var nextBtn = document.getElementById('nw-req-next');
        var backBtn = document.getElementById('nw-req-back');
        var submitBtn = document.getElementById('nw-req-submit');
        var form = document.getElementById('nw-req-form');
        var steps = modal ? modal.querySelectorAll('.nw-req-step') : [];
        var panes = modal ? modal.querySelectorAll('.nw-req-pane') : [];
        var currentStep = 1;
        var maxStep = 3;

        function openModal(){ modal.setAttribute('aria-hidden','false'); modal.classList.add('is-open'); document.body.style.overflow = 'hidden'; }
        function closeModal(){ modal.setAttribute('aria-hidden','true'); modal.classList.remove('is-open'); document.body.style.overflow = ''; }
        function showStep(n){
            currentStep = n;
            steps.forEach(function(s){
                var stepNum = parseInt(s.getAttribute('data-step'), 10);
                s.classList.toggle('is-active', stepNum === n);
                s.classList.toggle('is-complete', stepNum < n);
            });
            panes.forEach(function(p){
                p.classList.toggle('is-active', p.getAttribute('data-pane') === String(n));
            });
            backBtn.disabled = (n <= 1);
            if(n >= maxStep){
                nextBtn.style.display = 'none';
                submitBtn.style.display = 'inline-flex';
            } else {
                nextBtn.style.display = 'inline-flex';
                submitBtn.style.display = 'none';
            }
        }
        function showSuccess(){
            steps.forEach(function(s){ s.classList.add('is-complete'); s.classList.remove('is-active'); });
            panes.forEach(function(p){ p.classList.toggle('is-active', p.getAttribute('data-pane') === 'success'); });
            nextBtn.style.display = 'none';
            submitBtn.style.display = 'none';
            backBtn.style.display = 'none';
            /* Add a close button replacement */
            var doneBtn = document.getElementById('nw-req-done');
            if(!doneBtn){
                doneBtn = document.createElement('button');
                doneBtn.type = 'button';
                doneBtn.id = 'nw-req-done';
                doneBtn.className = 'nw-req-btn nw-req-btn-primary';
                doneBtn.textContent = nibwpIntegrationsI18n.request_done;
                doneBtn.addEventListener('click', closeModal);
                submitBtn.parentNode.appendChild(doneBtn);
            }
        }
        function validateStep(n){
            var pane = modal.querySelector('.nw-req-pane[data-pane="' + n + '"]');
            if(!pane) return true;
            var required = pane.querySelectorAll('[required]');
            for(var i = 0; i < required.length; i++){
                if(!required[i].checkValidity()){ required[i].reportValidity(); return false; }
            }
            if(n === 1){
                var picked = pane.querySelector('input[name="request_type"]:checked');
                if(!picked){ alert(nibwpIntegrationsI18n.request_choose); return false; }
            }
            return true;
        }
        if(openBtn) openBtn.addEventListener('click', function(){ showStep(1); openModal(); });
        if(closeBtn) closeBtn.addEventListener('click', closeModal);
        if(backdrop) backdrop.addEventListener('click', closeModal);
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && modal && modal.classList.contains('is-open')) closeModal(); });
        if(nextBtn) nextBtn.addEventListener('click', function(){ if(validateStep(currentStep)) showStep(currentStep + 1); });
        if(backBtn) backBtn.addEventListener('click', function(){ if(currentStep > 1) showStep(currentStep - 1); });
        var errorBox = document.getElementById('nw-req-error');

        function showError(message){
            if(!errorBox) return;
            errorBox.textContent = message;
            errorBox.style.display = 'block';
        }

        if(form) form.addEventListener('submit', function(e){
            e.preventDefault();
            if(!validateStep(currentStep)) return;
            if(errorBox) errorBox.style.display = 'none';

            var payload = new FormData(form);
            payload.append('action', 'nibwp_request_integration');

            var label = submitBtn ? submitBtn.innerHTML : '';
            if(submitBtn){ submitBtn.disabled = true; submitBtn.textContent = nibwpIntegrationsI18n.request_sending; }

            function restore(){
                if(submitBtn){ submitBtn.disabled = false; submitBtn.innerHTML = label; }
            }

            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: payload })
                .then(function(r){ return r.json().catch(function(){ return null; }); })
                .then(function(data){
                    /* Success is what the server said, not what the form did.
                       This modal used to congratulate people on a request that
                       was never sent anywhere. */
                    if(data && data.success){ showSuccess(); return; }
                    restore();
                    showError((data && data.data && data.data.message) || nibwpIntegrationsI18n.request_failed);
                })
                .catch(function(){
                    restore();
                    showError(nibwpIntegrationsI18n.request_offline);
                });
        });
    })();
    </script>

    <?php
    nibwp_render_admin_footer();
}
