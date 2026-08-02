<?php
/**
 * Generate the Old-FQCN => New-FQCN mapping for the modular refactor.
 *
 * Strategy: scan every PHP file in includes/, extract its namespace + class
 * name (we need the pair to form the old FQCN), then classify each file
 * into a module using an ordered list of path-prefix rules. The first rule
 * that matches wins. Files that don't match any rule are listed as
 * "unclassified" so we can review them before moving anything.
 *
 * Output:
 *   tools/refactor/class-map.generated.php
 *       array(
 *         'OldFQCN' => array(
 *           'new'    => 'NewFQCN',
 *           'module' => 'contacts'|'deals'|...,
 *           'public' => bool,    // true => emit class_alias shim
 *           'old_path' => string,
 *           'new_path' => string,
 *         ),
 *       )
 *
 * Running: `php tools/refactor/build-class-map.php` from the plugin root.
 *
 * @package DoubleScale\Pro\Pro
 */

declare(strict_types=1);

$plugin_root = dirname( __DIR__, 2 );
$includes    = $plugin_root . '/includes';

if ( ! is_dir( $includes ) ) {
	fwrite( STDERR, "includes/ not found at {$includes}\n" );
	exit( 1 );
}

/**
 * Rule list: each rule is [ regex against relative path under includes/,
 *   target module slug, sub-path template under includes/Modules/{module}/ ].
 *
 * The first rule that matches wins. Keep more-specific rules above general
 * ones. Set target module to null for Core (=> includes/Core/...).
 */
$rules = array(
	// --- Already-relocated scaffold (skip) ---------------------------------
	array( '#^Core/#', '__skip__', null ),
	array( '#^Api/#', '__skip__', null ),
	array( '#^Admin/MenuRegistry\.php$#', '__skip__', null ),

	// --- Integrations module (most specific: per-vendor) -------------------
	array( '#^Automations/Integrations/(?<vendor>[^/]+)/(?<sub>.+/)?(?=[^/]+\.php$)#', 'integrations', 'Modules/Integrations/{vendor}/{sub}' ),

	// --- Contacts module ---------------------------------------------------
	array( '#^Models/Contact(Model|MetaModel|UnsubscribeModel)\.php$#', 'contacts', 'Modules/Contacts/Models/' ),
	array( '#^Models/(List|Tag)Model\.php$#', 'contacts', 'Modules/Contacts/Models/' ),
	array( '#^Models/LeadScoringRule(Level)?Model\.php$#', 'contacts', 'Modules/Contacts/Models/' ),
	array( '#^ContactFilters/(?<sub>.+/)?(?=[^/]+\.php$)#', 'contacts', 'Modules/Contacts/Filters/{sub}' ),
	array( '#^LeadScoring/#', 'contacts', 'Modules/Contacts/LeadScoring/' ),
	array( '#^ImportExport/(?<sub>.+/)?(?=[^/]+\.php$)#', 'contacts', 'Modules/Contacts/ImportExport/{sub}' ),
	array( '#^UnsubscribePage\.php$#', 'contacts', 'Modules/Contacts/' ),
	array( '#^Managers/FiltersManager\.php$#', 'contacts', 'Modules/Contacts/Filters/' ),
	array( '#^Database/Migrations/Contact[a-zA-Z]*Table\.php$#', 'contacts', 'Modules/Contacts/Migrations/' ),
	array( '#^Database/Migrations/(Lists|Tags)Table\.php$#', 'contacts', 'Modules/Contacts/Migrations/' ),
	array( '#^Database/Migrations/LeadScoringRules(Levels)?Table\.php$#', 'contacts', 'Modules/Contacts/Migrations/' ),
	array( '#^RestApi/Controllers/V1/Rest(Contact|List|Tag|LeadScoringRule(Level)?|ImportExport)Controller\.php$#', 'contacts', 'Modules/Contacts/Rest/Controllers/' ),

	// --- Deals module ------------------------------------------------------
	array( '#^Models/(Pipeline|PipelineStage|Deal)Model\.php$#', 'deals', 'Modules/Deals/Models/' ),
	array( '#^Managers/(Pipeline|Deal)Manager\.php$#', 'deals', 'Modules/Deals/Services/' ),
	array( '#^Database/Migrations/(Pipelines|PipelineStages|Deals)Table\.php$#', 'deals', 'Modules/Deals/Migrations/' ),
	array( '#^RestApi/Controllers/V1/Rest(Pipeline|Deal|Stage)Controller\.php$#', 'deals', 'Modules/Deals/Rest/Controllers/' ),

	// --- Campaigns module --------------------------------------------------
	array( '#^Campaign/(?<sub>.+/)?(?=[^/]+\.php$)#', 'campaigns', 'Modules/Campaigns/Campaign/{sub}' ),
	array( '#^Emails/(?<sub>.+/)?(?=[^/]+\.php$)#', 'emails', 'Modules/Emails/{sub}' ),
	array( '#^Ai/#', 'campaigns', 'Modules/Campaigns/Ai/' ),
	array( '#^Modules/Campaigns/Sequences/EmailSequencesManager\.php$#', 'campaigns', 'Modules/Campaigns/Sequences/' ),
	array( '#^Modules/Campaigns/Sequences/RestEmailSequenceController\.php$#', 'campaigns', 'Modules/Campaigns/Sequences/' ),
	array( '#^Managers/CampaignStatusManager\.php$#', 'campaigns', 'Modules/Campaigns/Services/' ),
	array( '#^Services/(Campaign|Template)[a-zA-Z]*\.php$#', 'campaigns', 'Modules/Campaigns/Services/' ),
	array( '#^Services/(WhatsappConversationWindow|MetaTemplateSaver|MetaTemplateFetcher|TemplateDataPreparer|TemplateFieldMapper|CampaignTemplateFactory)\.php$#', 'campaigns', 'Modules/Campaigns/Services/' ),
	array( '#^Models/(Campaign|Template|WcOrder|EddOrder)Model\.php$#', 'campaigns', 'Modules/Campaigns/Models/' ),
	array( '#^Database/Migrations/(Campaigns|CampaignEvents|Templates)Table\.php$#', 'campaigns', 'Modules/Campaigns/Migrations/' ),
	array( '#^RestApi/Controllers/V1/Rest(Campaign|Template|AiEmailBuilder)Controller\.php$#', 'campaigns', 'Modules/Campaigns/Rest/Controllers/' ),

	// --- Automations module ------------------------------------------------
	array( '#^Modules/Automations/AbandonedCart/#', 'automations', 'Modules/Automations/AbandonedCart/' ),
	array( '#^Modules/Automations/Models/AbandonedCartModel\.php$#', 'automations', 'Modules/Automations/Models/' ),
	array( '#^Modules/Automations/Migrations/AbandonedCartsTable\.php$#', 'automations', 'Modules/Automations/Migrations/' ),
	array( '#^Modules/Automations/Rest/Controllers/RestAbandonedCartController\.php$#', 'automations', 'Modules/Automations/Rest/Controllers/' ),
	array( '#^Modules/Automations/MergeTags/Woocommerce/AbandonedCart/#', 'automations', 'Modules/Automations/MergeTags/Woocommerce/AbandonedCart/' ),
	array( '#^Automations/(?<sub>.+/)?(?=[^/]+\.php$)#', 'automations', 'Modules/Automations/{sub}' ),
	array( '#^Managers/(Triggers|Actions|Goals|Rules)Manager\.php$#', 'automations', 'Modules/Automations/Services/' ),
	array( '#^Models/Automation(Model|StepModel|ContactModel|ContactProcessesModel)\.php$#', 'automations', 'Modules/Automations/Models/' ),
	array( '#^Database/Migrations/Automation(s|Steps|Contacts|ContactProcesses)Table\.php$#', 'automations', 'Modules/Automations/Migrations/' ),
	array( '#^RestApi/Controllers/V1/RestAutomation(Step|Contact)?Controller\.php$#', 'automations', 'Modules/Automations/Rest/Controllers/' ),

	// --- Forms module ------------------------------------------------------
	array( '#^Forms/(?<sub>.+/)?(?=[^/]+\.php$)#', 'forms', 'Modules/Forms/{sub}' ),
	array( '#^Managers/FormsManager\.php$#', 'forms', 'Modules/Forms/Services/' ),
	array( '#^Models/Form(Model|SubmissionModel)\.php$#', 'forms', 'Modules/Forms/Models/' ),
	array( '#^Database/Migrations/(Forms|FormSubmissions)Table\.php$#', 'forms', 'Modules/Forms/Migrations/' ),
	array( '#^RestApi/Controllers/V1/RestFormController\.php$#', 'forms', 'Modules/Forms/Rest/Controllers/' ),

	// --- Inbox module ------------------------------------------------------
	array( '#^MessageProviders/#', 'inbox', 'Modules/Inbox/MessageProviders/' ),
	array( '#^Managers/(MessageProviderRegistry|BounceHandlerManager)\.php$#', 'inbox', 'Modules/Inbox/Services/' ),
	array( '#^IndividualMessaging/#', 'inbox', 'Modules/Inbox/IndividualMessaging/' ),
	array( '#^BounceHandlers/#', 'inbox', 'Modules/Inbox/BounceHandlers/' ),
	array( '#^Tracking/(Messaging|Email)Incoming\.php$#', 'inbox', 'Modules/Inbox/Incoming/' ),
	array( '#^Tracking/(EmailOauth|UserEmailOauth|UserEmailPoller)\.php$#', 'inbox', 'Modules/Inbox/Oauth/' ),
	array( '#^RestApi/Controllers/V1/Rest(Messaging|WhatsappTemplates|MetaWhatsappTemplates|UserEmail|Inbox)Controller\.php$#', 'inbox', 'Modules/Inbox/Rest/Controllers/' ),

	// --- Tracking module ---------------------------------------------------
	array( '#^Tracking/(?<sub>.+/)?(?=[^/]+\.php$)#', 'tracking', 'Modules/Tracking/{sub}' ),
	array( '#^Models/(PageVisit|LinkTrigger|CommunicationTracking|CommunicationTrackingMeta)Model\.php$#', 'tracking', 'Modules/Tracking/Models/' ),
	array( '#^Database/Migrations/(PageVisits|LinkTriggers|CommunicationTracking|CommunicationTrackingMeta)Table\.php$#', 'tracking', 'Modules/Tracking/Migrations/' ),
	array( '#^RestApi/Controllers/V1/Rest(PageVisit|LinkTrigger)Controller\.php$#', 'tracking', 'Modules/Tracking/Rest/Controllers/' ),

	// --- Tasks module ------------------------------------------------------
	array( '#^Tasks\.php$#', 'tasks', 'Modules/Tasks/' ),
	array( '#^TaskReminders/#', 'tasks', 'Modules/Tasks/Reminders/' ),
	array( '#^Models/TaskModel\.php$#', 'tasks', 'Modules/Tasks/Models/' ),
	array( '#^Database/Migrations/Tasks?Table\.php$#', 'tasks', 'Modules/Tasks/Migrations/' ),
	array( '#^RestApi/Controllers/V1/RestTaskController\.php$#', 'tasks', 'Modules/Tasks/Rest/Controllers/' ),

	// --- Activities module -------------------------------------------------
	array( '#^Managers/ActivityManager\.php$#', 'activities', 'Modules/Activities/Services/' ),
	array( '#^Models/Activity(Model|CommentModel|AssociationModel)\.php$#', 'activities', 'Modules/Activities/Models/' ),
	array( '#^Database/Migrations/(Activities|ActivityComments|ActivityAssociations)Table\.php$#', 'activities', 'Modules/Activities/Migrations/' ),
	array( '#^RestApi/Controllers/V1/RestActivityController\.php$#', 'activities', 'Modules/Activities/Rest/Controllers/' ),

	// --- Analytics module --------------------------------------------------
	array( '#^RestApi/Controllers/V1/Rest(Reports|AutomationReports)Controller\.php$#', 'analytics', 'Modules/Analytics/Rest/Controllers/' ),

	// --- Notifications module ----------------------------------------------
	array( '#^Notifications/(?<sub>.+/)?(?=[^/]+\.php$)#', 'notifications', 'Modules/Notifications/{sub}' ),
	array( '#^Services/Notification[^/]*\.php$#', 'notifications', 'Modules/Notifications/Services/' ),
	array( '#^Services/(PushNotificationService|DeviceTokenService)\.php$#', 'notifications', 'Modules/Notifications/Services/' ),
	array( '#^NotificationHeartbeat\.php$#', 'notifications', 'Modules/Notifications/' ),
	array( '#^Models/NotificationModel\.php$#', 'notifications', 'Modules/Notifications/Models/' ),
	array( '#^Database/Migrations/NotificationsTable\.php$#', 'notifications', 'Modules/Notifications/Migrations/' ),
	array( '#^RestApi/Controllers/V1/RestNotification(s|Preferences)Controller\.php$#', 'notifications', 'Modules/Notifications/Rest/Controllers/' ),

	// --- Core: custom fields (shared primitive) ----------------------------
	array( '#^Fields/#', '__core__', 'Core/CustomFields/' ),
	array( '#^Managers/CustomFieldsManager\.php$#', '__core__', 'Core/CustomFields/' ),
	array( '#^Models/(CustomField|CustomFieldsGroup)Model\.php$#', '__core__', 'Core/CustomFields/Models/' ),
	array( '#^Database/Migrations/CustomField(s|sGroups|Relationship)Table\.php$#', '__core__', 'Core/CustomFields/Migrations/' ),
	array( '#^RestApi/Controllers/V1/RestCustomField(s?Group)?Controller\.php$#', '__core__', 'Core/CustomFields/Rest/' ),

	// --- Core: merge tags --------------------------------------------------
	array( '#^MergeTags/(?<sub>.+/)?(?=[^/]+\.php$)#', '__core__', 'Core/MergeTags/{sub}' ),
	array( '#^Managers/MergeTagsManager\.php$#', '__core__', 'Core/MergeTags/' ),

	// --- Core: integrations manager, addons --------------------------------
	array( '#^Managers/IntegrationsManager\.php$#', '__core__', 'Core/Integrations/' ),
	array( '#^Managers/AddonsManager\.php$#', '__core__', 'Core/Addon/' ),
	array( '#^Managers/LeadScoringManager\.php$#', 'contacts', 'Modules/Contacts/LeadScoring/' ),
	array( '#^RestApi/Controllers/V1/RestIntegrationController\.php$#', 'integrations', 'Modules/Integrations/Rest/' ),

	// --- Core: logger / settings / tasks / roles / utils -------------------
	array( '#^Logger\.php$#', '__core__', 'Core/Logger/' ),
	array( '#^LogHandlers/#', '__core__', 'Core/Logger/Handlers/' ),
	array( '#^Models/LogModel\.php$#', '__core__', 'Core/Logger/Models/' ),
	array( '#^Database/Migrations/LogsTable\.php$#', '__core__', 'Core/Logger/Migrations/' ),
	array( '#^RestApi/Controllers/V1/RestLogController\.php$#', '__core__', 'Core/Logger/Rest/' ),
	array( '#^Settings\.php$#', '__core__', 'Core/Settings/' ),
	array( '#^RestApi/Controllers/V1/RestSettingsController(Pro)?\.php$#', '__core__', 'Core/Settings/Rest/' ),
	array( '#^UserRoles/(?<sub>.+/)?(?=[^/]+\.php$)#', '__core__', 'Core/UserRoles/{sub}' ),
	array( '#^PermissionsCompat\.php$#', '__core__', 'Core/UserRoles/' ),
	array( '#^RestApi/Controllers/V1/RestUserManagementController\.php$#', '__core__', 'Core/UserRoles/Rest/' ),
	array( '#^Utils\.php$#', '__core__', 'Core/Support/' ),
	array( '#^Utils/#', '__core__', 'Core/Support/' ),
	array( '#^Traits/#', '__core__', 'Core/Support/Traits/' ),
	array( '#^Abstracts/#', '__core__', 'Core/Support/Abstracts/' ),
	array( '#^Interfaces/#', '__core__', 'Core/Support/Interfaces/' ),
	array( '#^Constants/#', '__core__', 'Core/Support/Constants/' ),
	array( '#^Models/(User|Usermeta)Model\.php$#', '__core__', 'Core/Models/' ),
	array( '#^Addon/#', '__core__', 'Core/Addon/' ),
	array( '#^Firebase/#', '__core__', 'Core/Firebase/' ),
	array( '#^SubscriptionManage/#', '__core__', 'Core/SubscriptionManage/' ),
	array( '#^Site/#', '__core__', 'Site/' ),

	// --- Core: database infrastructure -------------------------------------
	array( '#^Database/Install\.php$#', '__core__', 'Core/Database/' ),
	array( '#^Database/Migrations/Migration\.php$#', '__core__', 'Core/Database/' ),
	array( '#^Database/Migrations/RenameLegacyDoublescale\.php$#', '__core__', 'Core/Database/Migrations/' ),

	// --- Core: REST api bootstrap ------------------------------------------
	array( '#^RestApi/RestApi\.php$#', '__skip__', null ), // deleted in step 8
	array( '#^RestApi/Controllers/V1/Rest(General|Plugins|License|SiteVerification|Device)Controller\.php$#', '__core__', 'Core/Rest/Controllers/' ),

	// --- Admin -------------------------------------------------------------
	array( '#^Admin/#', '__admin__', 'Admin/' ),
	array( '#^CustomMetabox\.php$#', '__admin__', 'Admin/Metabox/' ),

	// --- Plugin / Core / autoload / functions (special-cased) --------------
	array( '#^Plugin\.php$#', '__skip__', null ),           // deleted in step 12
	array( '#^Core\.php$#', '__skip__', null ),             // deleted in step 12
	array( '#^autoload\.php$#', '__skip__', null ),         // stays at includes/autoload.php
	array( '#^functions\.php$#', '__skip__', null ),        // stays at includes/functions.php
);

// Classes that are reachable via the public hook API (doublescale_automation_triggers, doublescale_automation_actions,
// doublescale_forms, doublescale_automation_goals, doublescale_mail_merge_tag_groups, Manager registries), subclassed
// by third-party plugins, or referenced in user-space docs. These get BC
// class_alias() shims. Everything else is considered internal.
$public_patterns = array(
	// Automations - triggers/actions/goals are registered by slug in a
	// filter, so extenders can ship their own and must reference our base
	// classes by FQCN.
	'#^Automations/Triggers/#',
	'#^Automations/Actions/#',
	'#^Automations/Goals/#',
	// Forms vendor integrations.
	'#^Forms/[A-Z][a-zA-Z]+/Form\.php$#',
	// Every Manager in Managers/ - all are singletons that user code
	// reaches via `::instance()`.
	'#^Managers/[A-Z][a-zA-Z]+\.php$#',
	// Email block base classes + shipped blocks.
	'#^Emails/Blocks/#',
	'#^Emails/BlockRegistry\.php$#',
	// Messaging providers and merge-tag base classes.
	'#^MessageProviders/#',
	'#^MergeTags/#',
	// Contact filters - registered via a filter and often subclassed.
	'#^ContactFilters/#',
	// Domain models - canonical API surface for data access.
	'#^Models/[A-Z][a-zA-Z]+Model\.php$#',
	// REST controllers - extenders subclass the abstract, but several
	// integrations also touch concrete controllers by name.
	'#^RestApi/Controllers/V1/Rest[A-Z][a-zA-Z]+Controller\.php$#',
	// Abstract base classes - every extender uses these.
	'#^Abstracts/#',
	'#^Interfaces/#',
	'#^Traits/#',
	// Tasks scheduler - singleton used by third-party cron hooks.
	'#^Tasks\.php$#',
	// Top-level top-of-plugin classes that were directly reachable.
	'#^UnsubscribePage\.php$#',
	'#^NotificationHeartbeat\.php$#',
	'#^CustomMetabox\.php$#',
);

$map = array();
$unclassified = array();

$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $includes, RecursiveDirectoryIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$abs = $file->getPathname();
	$rel = ltrim( substr( $abs, strlen( $includes ) ), '/\\' );
	$rel = str_replace( '\\', '/', $rel );

	// Parse namespace + class/interface/trait.
	$src = file_get_contents( $abs );
	if ( false === $src ) {
		continue;
	}
	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $src, $ns ) ) {
		continue;
	}
	if ( ! preg_match( '/^\s*(?:final\s+|abstract\s+)?(class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $cls ) ) {
		continue;
	}
	$namespace = trim( $ns[1] );
	$classname = $cls[2];
	$old_fqcn  = $namespace . '\\' . $classname;

	// Apply rule list.
	$matched = false;
	foreach ( $rules as $rule ) {
		list( $pattern, $target, $subpath ) = $rule;
		if ( ! preg_match( $pattern, $rel, $m ) ) {
			continue;
		}
		$matched = true;

		if ( '__skip__' === $target ) {
			break;
		}

		// Interpolate capture groups into subpath. Named captures map 1:1;
		// `vendor` specifically gets PascalCased because vendor folders on
		// disk are PascalCase. Missing / empty optional captures collapse
		// to the empty string so the placeholder disappears.
		$resolved_subpath = $subpath;
		if ( preg_match_all( '/\{(\w+)\}/', $subpath, $placeholders ) ) {
			foreach ( $placeholders[1] as $name ) {
				$value = isset( $m[ $name ] ) ? (string) $m[ $name ] : '';
				if ( 'vendor' === $name && '' !== $value ) {
					$value = ucfirst( $value );
				}
				$resolved_subpath = str_replace( '{' . $name . '}', $value, $resolved_subpath );
			}
		}

		// Build new path + FQCN.
		$basename = basename( $abs );
		switch ( $target ) {
			case '__core__':
				$new_path  = 'includes/' . $resolved_subpath . $basename;
				$ns_suffix = rtrim( str_replace( '/', '\\', $resolved_subpath ), '\\' );
				$new_ns    = 'DoubleScale\\Pro\\Pro\\' . $ns_suffix;
				break;
			case '__admin__':
				$new_path  = 'includes/' . $resolved_subpath . $basename;
				$ns_suffix = rtrim( str_replace( '/', '\\', $resolved_subpath ), '\\' );
				$new_ns    = 'DoubleScale\\Pro\\Pro\\' . $ns_suffix;
				break;
			default:
				// Module slug.
				$new_path  = 'includes/' . $resolved_subpath . $basename;
				$ns_suffix = rtrim( str_replace( '/', '\\', $resolved_subpath ), '\\' );
				$new_ns    = 'DoubleScale\\Pro\\Pro\\' . $ns_suffix;
				break;
		}
		$new_fqcn = $new_ns . '\\' . $classname;

		// Determine public-ness.
		$is_public = false;
		foreach ( $public_patterns as $pp ) {
			if ( preg_match( $pp, $rel ) ) {
				$is_public = true;
				break;
			}
		}

		$map[ $old_fqcn ] = array(
			'new'      => $new_fqcn,
			'module'   => '__core__' === $target ? 'core' : ( '__admin__' === $target ? 'admin' : $target ),
			'public'   => $is_public,
			'old_path' => 'includes/' . $rel,
			'new_path' => $new_path,
		);
		break;
	}

	if ( ! $matched ) {
		$unclassified[] = $rel;
	}
}

// Emit generated map.
$out  = "<?php\n";
$out .= "/**\n";
$out .= " * Generated by tools/refactor/build-class-map.php. Do not edit by hand.\n";
$out .= " *\n";
$out .= " * Timestamp: " . gmdate( 'c' ) . "\n";
$out .= " * Entries:   " . count( $map ) . "\n";
$out .= " *\n";
$out .= " * @package DoubleScale\Pro\\Pro\n";
$out .= " */\n\n";
$out .= "return " . var_export( $map, true ) . ";\n";

file_put_contents( $plugin_root . '/tools/refactor/class-map.generated.php', $out );

// Emit unclassified list for human review.
file_put_contents(
	$plugin_root . '/tools/refactor/unclassified.txt',
	implode( "\n", $unclassified ) . ( $unclassified ? "\n" : '' )
);

// Per-module counts report.
$by_module = array();
$public_count = 0;
foreach ( $map as $entry ) {
	$by_module[ $entry['module'] ] = ( $by_module[ $entry['module'] ] ?? 0 ) + 1;
	if ( $entry['public'] ) {
		$public_count++;
	}
}
ksort( $by_module );

fwrite( STDOUT, "Class map generated.\n" );
fwrite( STDOUT, sprintf( "  %-16s %s\n", 'Total entries:', count( $map ) ) );
fwrite( STDOUT, sprintf( "  %-16s %s\n", 'Public (BC):', $public_count ) );
fwrite( STDOUT, sprintf( "  %-16s %s\n", 'Unclassified:', count( $unclassified ) ) );
fwrite( STDOUT, "By module:\n" );
foreach ( $by_module as $m => $n ) {
	fwrite( STDOUT, sprintf( "  %-16s %s\n", $m . ':', $n ) );
}
