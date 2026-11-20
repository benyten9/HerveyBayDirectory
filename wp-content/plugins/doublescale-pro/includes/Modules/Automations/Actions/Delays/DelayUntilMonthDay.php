<?php

/**
 * Delay Until Day of the Month Action
 *
 * Waits until a day of the month, e.g. "the 25th of next month" — the day
 * many customers are paid, which is when a follow-up offer lands best.
 *
 * @since 1.3.1
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Delays;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\PluginKernel;

/**
 * Delay Until Day of the Month Action
 */
class DelayUntilMonthDay extends Action
{
	/**
	 * Time of day used when the step has none saved.
	 */
	const DEFAULT_TIME = '09:00';

	/**
	 * Always the month after the one the contact reaches this step in.
	 */
	const MONTH_NEXT = 'next';

	/**
	 * This month when the day is still ahead, otherwise next month.
	 */
	const MONTH_UPCOMING = 'upcoming';

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Delay Until Day of the Month';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'delay-until-month-day';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will delay the automation until a day of the month, for example the 25th of next month.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Auto enqueue step
	 *
	 * @var bool
	 */
	public $auto_enqueue = false;

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'delay';

	/**
	 * Process Action
	 *
	 * @param AutomationModel        $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return bool|array
	 */
	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		$next_step = $automation->get_next_step($step);
		if (! $next_step) {
			return false;
		}

		$day = (int) $step->get_setting('day');
		if ($day < 1 || $day > 31) {
			return array(
				'success' => false,
				'message' => __('The delay has no day of the month set. Open the step and pick a day.', 'doublescale'),
			);
		}

		$timestamp = self::compute_timestamp(
			new \DateTimeImmutable('now', wp_timezone()),
			$day,
			(string) $step->get_setting('time', self::DEFAULT_TIME),
			(string) $step->get_setting('month', self::MONTH_NEXT)
		);

		PluginKernel::instance()->automations_tasks->schedule_single($timestamp, 'process_automation_step', $automation->id, $step->id, $next_step->id, $automation_contact->id);
		return true;
	}

	/**
	 * When the contact should move on.
	 *
	 * The day is clamped to the month's length, so "31" in a 30-day month
	 * means the 30th rather than spilling into the month after.
	 *
	 * @param \DateTimeImmutable $now   Now, in the site's timezone.
	 * @param int                $day   Day of the month, 1-31.
	 * @param string             $time  "HH:MM"; anything else uses DEFAULT_TIME.
	 * @param string             $month MONTH_NEXT or MONTH_UPCOMING.
	 *
	 * @return int Unix timestamp.
	 */
	public static function compute_timestamp(\DateTimeImmutable $now, int $day, string $time, string $month): int
	{
		if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($time), $parts)) {
			preg_match('/^(\d+):(\d+)$/', self::DEFAULT_TIME, $parts);
		}
		$hour   = (int) $parts[1];
		$minute = (int) $parts[2];
		$day    = max(1, min(31, $day));

		$year      = (int) $now->format('Y');
		$month_num = (int) $now->format('n');

		$at = static function (int $year, int $month_num) use ($now, $day, $hour, $minute): \DateTimeImmutable {
			// setDate() rolls month 13 over into January of the next year.
			$first = $now->setDate($year, $month_num, 1);
			$last  = (int) $first->format('t');

			return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last))
				->setTime($hour, $minute, 0);
		};

		if (self::MONTH_UPCOMING === $month) {
			$candidate = $at($year, $month_num);
			if ($candidate > $now) {
				return $candidate->getTimestamp();
			}
		}

		return $at($year, $month_num + 1)->getTimestamp();
	}

	/**
	 * Get fields.
	 *
	 * @return array
	 */
	public function get_fields()
	{
		$days = array();
		for ($i = 1; $i <= 31; $i++) {
			$days[ (string) $i ] = (string) $i;
		}

		$times = array();
		for ($h = 0; $h < 24; $h++) {
			foreach (array(0, 30) as $m) {
				$value           = sprintf('%02d:%02d', $h, $m);
				$times[ $value ] = $value;
			}
		}

		return array(
			'month' => array(
				// A radio shows its default as chosen; a select would look empty.
				'type'          => 'radio',
				'label'         => __('Month', 'doublescale'),
				'default-value' => self::MONTH_NEXT,
				'options'       => array(
					self::MONTH_NEXT     => __('Next month', 'doublescale'),
					self::MONTH_UPCOMING => __('This month, or next month if the day has passed', 'doublescale'),
				),
			),
			'day'   => array(
				'type'       => 'select',
				'label'      => __('Day of the month', 'doublescale'),
				'options'    => $days,
				'helperText' => __('When the month is shorter, the last day of that month is used.', 'doublescale'),
			),
			'time'  => array(
				'type'          => 'select',
				'label'         => __('Time', 'doublescale'),
				'placeholder'   => self::DEFAULT_TIME,
				'options'       => $times,
				'helperText'    => __('In the site timezone set in WordPress Settings > General.', 'doublescale'),
			),
		);
	}

	/**
	 * Get Attributes Schema
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'month' => array(
					'type'    => 'string',
					'title'   => 'Month',
					'default' => self::MONTH_NEXT,
					'enum'    => array(self::MONTH_NEXT, self::MONTH_UPCOMING),
				),
				'day'   => array(
					'type'  => 'string',
					'title' => 'Day of the month (1-31)',
				),
				'time'  => array(
					'type'    => 'string',
					'title'   => 'Time (HH:MM)',
					'default' => self::DEFAULT_TIME,
				),
			),
		);
	}
}
