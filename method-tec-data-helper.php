<?php
/**
 * Plugin Name: Method - TEC Data Helper
 * Plugin URI: https://github.com/pixelwatt/method-tec-data-helper
 * Description: This plugin provides a simple block for retrieving event data created by The Events Calendar plugin to aid in custom event templating.
 * Version: 1.0.2
 * Author: Rob Clark
 * Author URI: https://robclark.io
 * License: GPLv2 or later
 * Text Domain: method-tec-data-helper
 * GitHub Plugin URI: https://github.com/pixelwatt/method-tec-data-helper
 * Primary Branch: main
 */

register_block_type( 'method/tec-data-helper', [
	'attributes' => [
		'field'  => [ 'type' => 'string', 'default' => 'start' ],
		'format' => [ 'type' => 'string', 'default' => 'F j, Y' ],
		'mapWidth'  => [ 'type' => [ 'string', 'number' ], 'default' => '' ],
		'mapHeight' => [ 'type' => [ 'string', 'number' ], 'default' => '' ],
        'wrapper' => [ 'type' => 'string', 'default' => '' ],
        'wrapperClass' => [ 'type' => 'string', 'default' => '' ],
		'wrapperId' => [ 'type' => 'string', 'default' => '' ],
        'wrapperStyle' => [ 'type' => 'string', 'default' => '' ],
		'responsiveData' => [ 'type' => 'object', 'default' => [] ],
	],
	'render_callback' => function ( $attrs, $content, $block ) {
        if ( ! function_exists( 'tribe_get_event' ) ) {
		    return '';
	    }
		$id    = $block->context['postId'] ?? get_the_ID();
		$event = tribe_get_event( $id );
        $output = '';
		if ( ! $event || Tribe__Events__Main::POSTTYPE !== $event->post_type ) {
			if ( 'map' !== $attrs['field'] ) {
				return '';
			}
		}
        if ( ! empty( $attrs['wrapper'] ) ) {
            $output .= '<' . $attrs['wrapper'] . ( ! empty( $attrs['wrapperId'] ) ? ' id="' . $attrs['wrapperId'] . '"' : '' ) . ( ! empty( $attrs['wrapperClass'] ) ? ' class="' . $attrs['wrapperClass'] . '"' : '' ) . ( ! empty( $attrs['wrapperStyle'] ) ? ' style="' . $attrs['wrapperStyle'] . '"' : '' ) . '>';
			if ( ! empty( $attrs['wrapperClass'] ) ) {
				if ( ( function_exists( 'method_check_array_key' ) ) && ( function_exists( 'method_get_block_responsive_styles' ) ) && ( function_exists( 'method_collect_css' ) ) ) {
					if ( method_check_array_key( $attrs, 'responsiveData' ) ) {
						if ( ( method_check_array_key( $attrs['responsiveData'], 'cssArgs' ) ) && ( method_check_array_key( $attrs['responsiveData'], 'responsiveSettings' ) ) ) {
							$responsive = method_get_block_responsive_styles( $attrs['responsiveData'], $attrs['responsiveData']['cssArgs'], array( 'base', 'mobile', 'tablet', 'wide' ), false );
							method_collect_css( $responsive, '.' . $attrs['wrapperClass'], 10);
						}
					}
				}
			}
        }
		switch ( $attrs['field'] ) {
			case 'start':
				$output .= esc_html( tribe_get_start_date( $event, false, $attrs['format'] ?: null ) );
                break;
			case 'end':
				$output .= esc_html( tribe_get_end_date( $event, false, $attrs['format'] ?: null ) );
                break;
            case 'range':
                // l, F j
                // g:ia
                $startDate = tribe_get_start_date( $event, false, $attrs['format'] . ' |' );
                $startTime = tribe_get_start_date( $event, false, ' g:ia' );

                $endDate = tribe_get_end_date( $event, false, $attrs['format'] . ' |' );
                $endTime = tribe_get_end_date( $event, false, ' g:ia' );

                $output .= esc_html( $startDate . $startTime . ' - ' . ( $startDate != $endDate ? $endDate : '' ) . $endTime );
                break;
			case 'venue':
				$output .= esc_html( tribe_get_venue( $event ) );
                break;
			case 'address':
				$output .= tribe_get_full_address( $event ); // already escaped HTML
                break;
			case 'cost':
				$output .= esc_html( tribe_get_cost( $event, true ) );
                break;
			case 'gcal':
				$output .= esc_url( tribe_get_gcal_link( $event ) );
                break;
            case 'tickets':
                $output .= method_render_event_tickets( $id );
                break;
            case 'tickets-button':
                $output .= method_event_tickets_modal_button( $id );
                break;
            case 'map':
                $map = method_render_event_map( $id, (string) $attrs['mapWidth'], (string) $attrs['mapHeight'] );
                if ( '' === $map ) {
                    return ''; // No map to show, so no wrapper either.
                }
                $output .= $map;
                break;
		}
        if ( ! empty( $attrs['wrapper'] ) ) {
            $output .= '</' . $attrs['wrapper'] . '>';
        }
		return $output;
	},
	'uses_context' => [ 'postId' ],
] );

/**
 * Render the Event Tickets purchase form for an event.
 *
 * Call this while the template is rendering (inside the loop, which in a block
 * theme happens before wp_head) so Event Tickets can enqueue its scripts,
 * styles and localized data at the right time.
 */
function method_render_event_tickets( int $event_id = 0 ): string {
	if ( ! class_exists( 'Tribe__Tickets__Tickets_View' ) ) {
		return '';
	}

	$event_id = $event_id ?: get_the_ID();

	if ( ! $event_id || ! tribe_events_has_tickets( $event_id ) ) {
		return '';
	}

	return Tribe__Tickets__Tickets_View::instance()->get_tickets_block( $event_id, false );
}

/**
 * Render the map for an event's venue.
 *
 * This goes through The Events Calendar's own embed routine, so the result is
 * whatever the site is set up to show: TEC's Google map, the Mapbox map that
 * method-tec-mapbox swaps in, or nothing when Mapbox is opted into without a
 * token. TEC's "Enable Maps" setting and the event's "Show Map" checkbox are
 * honoured, as they are in TEC's own templates.
 *
 * Call this while the template is rendering, like the ticket form, so the
 * map's scripts and styles are enqueued in time.
 *
 * @param string $width  Any CSS length; a bare number is pixels. Empty for TEC's default (100%).
 * @param string $height As $width. Empty for TEC's default (350px).
 */
function method_render_event_map( int $event_id = 0, string $width = '', string $height = '' ): string {
	if ( ! function_exists( 'tribe_get_embedded_map' ) ) {
		return '';
	}

	$event_id = $event_id ?: get_the_ID();

	if ( ! $event_id || ! tribe_embed_google_map( $event_id ) ) {
		return '';
	}

	return trim( (string) tribe_get_embedded_map( $event_id, '' === $width ? null : $width, '' === $height ? null : $height ) );
}

/**
 * Output a "Tickets" button that opens the event's ticket form in a Bootstrap modal.
 *
 * The form is rendered right here, during block rendering, so Event Tickets'
 * asset enqueues land before wp_head / wp_print_footer_scripts. Only the modal
 * *markup* is deferred to wp_footer, so it ends up directly under <body>
 * instead of inside the section block's z-indexed stacking context (where the
 * modal backdrop paints over it).
 */
function method_event_tickets_modal_button( int $event_id ): string {
	static $queued = [];

	if ( ! class_exists( 'Tribe__Tickets__Tickets_View' ) || ! $event_id || ! tribe_events_has_tickets( $event_id ) ) {
		return '';
	}

	$modal_id = 'eventModal' . $event_id;
	$label_id = $modal_id . 'Label';

	if ( ! isset( $queued[ $event_id ] ) ) {
		$form = method_render_event_tickets( $event_id );
		if ( '' === $form ) {
			return '';
		}
		$queued[ $event_id ] = true;

		$modal = '
			<div class="modal fade eventTicketModal" id="' . esc_attr( $modal_id ) . '" tabindex="-1" aria-labelledby="' . esc_attr( $label_id ) . '" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<div class="modal-header">
							<h3 class="modal-title" id="' . esc_attr( $label_id ) . '">Purchase Tickets for: <br/>' . get_the_title( $event_id ) . '</h3>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body">' . $form . '</div>
					</div>
				</div>
			</div>';

		add_action( 'wp_footer', function () use ( $modal ) {
			echo $modal;
		} );
	}

	return '<a href="#' . esc_attr( $modal_id ) . '" role="button" style="margin-top:1.5rem" class="is-style-theme-button" data-bs-toggle="modal" data-bs-target="#' . esc_attr( $modal_id ) . '">Tickets<span class="screen-reader-text">: ' . esc_html( get_the_title( $event_id ) ) . '</span></a>';
}
