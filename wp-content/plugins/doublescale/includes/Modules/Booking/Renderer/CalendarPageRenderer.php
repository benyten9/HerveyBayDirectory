<?php

/**
 * Calendar Page Renderer
 */

namespace DoubleScale\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

class CalendarPageRenderer extends BaseTemplateRenderer {

	private string $calendarModelClass;

	public function __construct( string $calendarModelClass ) {
		parent::__construct();
		$this->calendarModelClass = $calendarModelClass;
	}

	public function render( string $slug ) {
		if ( ! $slug ) {
			return false;
		}

		$calendar = $this->calendarModelClass::where( 'slug', $slug )
			->with( 'user', 'events' )
			->first();

		if ( ! $calendar ) {
			return false;
		}

		if ( 'active' !== $calendar->status ) {
			return $this->render_unavailable();
		}

		$template_path = __DIR__ . '/templates/calendar.php';

		return $this->render_template_page(
			$template_path,
			array(
				'calendar' => $calendar,
				'title'    => $calendar->name ?? __( 'Calendar', 'doublescale' ),
			)
		);
	}
}
