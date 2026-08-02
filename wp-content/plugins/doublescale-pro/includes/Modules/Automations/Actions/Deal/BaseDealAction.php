<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Deal;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Deals\Models\PipelineModel;
use DoubleScale\Core\UserRoles\UserRoles;

/**
 * Base utilities for Deal automation actions.
 */
abstract class BaseDealAction extends Action
{

	/**
	 * Translate helper that is linter-safe in namespaced context.
	 *
	 * @param string $text
	 * @return string
	 */
	protected function t($text)
	{
		if (function_exists('\\__')) {
			return call_user_func('\\__', $text, 'doublescale');
		}
		return $text;
	}

	/**
	 * Build base query for targeted deals by effects and pipeline.
	 *
	 * @param array                    $settings Step settings array-like (must support array access via get_setting in caller).
	 * @param AutomationContactModel $automation_contact Automation contact model.
	 * @param string                   $pipelineKey Settings key for pipeline filter. Defaults to 'pipeline'.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	protected function build_target_deals_query($settings, AutomationContactModel $automation_contact, $pipelineKey = 'pipeline')
	{
		if ( ! $this->deal_storage_ready() ) {
			return DealModel::query()->whereRaw( '1 = 0' );
		}

		$effects  = is_array($settings) ? ($settings['affects'] ?? null) : null;
		$pipeline = is_array($settings) ? ($settings[$pipelineKey] ?? null) : null;
		$deals    = DealModel::query();

		if ($pipeline && $pipeline !== 'any-pipeline') {
			$deals = $deals->where('pipeline_id', $pipeline);
		}

		if ($effects === 'all-deals-contact') {
			$deals = $deals->where('contact_id', $automation_contact->contact->id);
		} elseif ($effects === 'all-open-deals-contact') {
			$deals = $deals->where('contact_id', $automation_contact->contact->id)->where('status', 'open');
		} elseif ($effects === 'all-won-deals-contact') {
			$deals = $deals->where('contact_id', $automation_contact->contact->id)->where('status', 'won');
		} elseif ($effects === 'all-lost-deals-contact') {
			$deals = $deals->where('contact_id', $automation_contact->contact->id)->where('status', 'lost');
		} elseif ($effects === 'most-recent-deal-contact') {
			$deals = $deals->where('contact_id', $automation_contact->contact->id)->orderBy('created_at', 'desc')->limit(1);
		} elseif ($effects === 'most-recently-updated-deal-contact') {
			$deals = $deals->where('contact_id', $automation_contact->contact->id)->orderBy('updated_at', 'desc')->limit(1);
		}

		return $deals;
	}

	/**
	 * Whether deal storage is safe to query (module on and pipelines table exists).
	 *
	 * @return bool
	 */
	protected function deal_storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'deals', PipelineModel::class );
	}

	/**
	 * Shared pipelines select options.
	 *
	 * @return array
	 */
	public function get_pipelines_options()
	{
		$options = array(
			'any-pipeline' => $this->t('Any Pipeline'),
		);

		if ( ! $this->deal_storage_ready() ) {
			return $options;
		}

		foreach ( PipelineModel::all() as $pipeline ) {
			$options[ $pipeline->id ] = $pipeline->name;
		}

		return $options;
	}

	/**
	 * Shared effects select options.
	 *
	 * @return array
	 */
	public function get_effects_options()
	{
		return array(
			'most-recent-deal-contact'           => $this->t('Most recent deal for this contact'),
			'most-recently-updated-deal-contact' => $this->t('Most recently updated deal for this contact'),
			'all-deals-contact'                  => $this->t('All deals for this contact'),
			'all-open-deals-contact'             => $this->t('All open deals for this contact'),
			'all-won-deals-contact'              => $this->t('All won deals for this contact'),
			'all-lost-deals-contact'             => $this->t('All lost deals for this contact'),
		);
	}

	/**
	 * Shared users select options (CRM users only).
	 *
	 * @return array
	 */
	public function get_users_options()
	{
		// Get only users with CRM roles or administrator role
		$users = get_users(
			array(
				'role__in' => array(
					UserRoles::CRM_MANAGER,
					UserRoles::SALES_REP,
					UserRoles::ADMINISTRATOR,
				),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$options = array();
		foreach ($users as $user) {
			$options[$user->ID] = $user->display_name;
		}
		return $options;
	}

	/**
	 * Parse deal title from a string that may contain merge tags
	 *
	 * Handles merge tags resolution for text fields like deal title.
	 *
	 * @since 1.2.0
	 *
	 * @param string                   $title The title string (may contain merge tags).
	 * @param AutomationContactModel $automation_contact The automation contact for merge tag resolution.
	 *
	 * @return string The parsed title with merge tags resolved.
	 */
	protected function parse_deal_title($title, AutomationContactModel $automation_contact)
	{
		if (empty($title)) {
			return '';
		}

		// Check if title contains merge tags (pattern {{group:slug}})
		if (preg_match('/{{.*?:.*?}}/', $title)) {
			// Resolve all merge tags in the title
			return \DoubleScale\Core\MergeTags\MergeTagsManager::instance()->process_merge_tags($title, $automation_contact);
		}

		// No merge tags - return as is
		return $title;
	}

	/**
	 * Parse deal value from a string that may contain merge tags
	 *
	 * Handles merge tags resolution and extracts numeric value from formatted strings.
	 *
	 * @since 1.0.0
	 *
	 * @param string                   $value The value string (may contain merge tags).
	 * @param AutomationContactModel $automation_contact The automation contact for merge tag resolution.
	 *
	 * @return float The parsed numeric value or 0.0
	 */
	protected function parse_deal_value($value, AutomationContactModel $automation_contact)
	{
		// Check if value contains merge tags (pattern {{group:slug}})
		if (preg_match('/{{.*?:.*?}}/', $value)) {
			// Get only the first merge tag from the string
			preg_match('/{{.*?:.*?}}/', $value, $first_match);
			$first_merge_tag = $first_match[0];

			// Resolve only the first merge tag
			$resolved_value = \DoubleScale\Core\MergeTags\MergeTagsManager::instance()->process_merge_tags($first_merge_tag, $automation_contact);

			// Extract numeric value from resolved string
			return $this->extract_numeric_value($resolved_value);
		}

		// No merge tags - extract numeric value from string
		return $this->extract_numeric_value($value);
	}

	/**
	 * Extract numeric value from a string
	 *
	 * Handles formatted currency values like "12,00&nbsp;EGP" or "1,234.56 USD"
	 * Returns the first numeric value found as a float, or 0.0 if none found.
	 *
	 * @since 1.0.0
	 *
	 * @param string $string The string to extract numeric value from.
	 *
	 * @return float The extracted numeric value or 0.0
	 */
	protected function extract_numeric_value($string)
	{
		// If already numeric, return as float
		if (is_numeric($string)) {
			return (float) $string;
		}

		// Decode HTML entities (e.g., &nbsp; to space)
		$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');

		// Remove currency symbols and non-numeric characters except digits, commas, periods, and minus
		$cleaned = preg_replace('/[^\d.,-]/', '', $string);

		// Handle different number formats:
		// - European format: 1.234,56 (comma as decimal separator)
		// - US format: 1,234.56 (period as decimal separator)

		// If the string has both comma and period, determine which is the decimal separator
		$last_comma = strrpos($cleaned, ',');
		$last_period = strrpos($cleaned, '.');

		if ($last_comma !== false && $last_period !== false) {
			// Both exist - the one that comes last is the decimal separator
			if ($last_comma > $last_period) {
				// European format: 1.234,56 -> remove periods, replace comma with period
				$cleaned = str_replace('.', '', $cleaned);
				$cleaned = str_replace(',', '.', $cleaned);
			} else {
				// US format: 1,234.56 -> just remove commas
				$cleaned = str_replace(',', '', $cleaned);
			}
		} elseif ($last_comma !== false) {
			// Only comma exists - check if it's a decimal separator
			// If there are exactly 2 digits after the comma, treat it as decimal
			$parts = explode(',', $cleaned);
			if (count($parts) === 2 && strlen($parts[1]) <= 2) {
				$cleaned = str_replace(',', '.', $cleaned);
			} else {
				// Thousand separator - remove it
				$cleaned = str_replace(',', '', $cleaned);
			}
		}
		// If only period exists, it's already in correct format

		// Extract the first valid number from the cleaned string
		if (preg_match('/-?\d+\.?\d*/', $cleaned, $matches)) {
			return (float) $matches[0];
		}

		return 0.0;
	}
}
