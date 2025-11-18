<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_Workorder_Timeline
 * 
 * Provides a reusable timeline component for displaying Workorder process dates
 * as an infographic-style horizontal timeline.
 * 
 * Shortcode: [workorder_timeline id="123" descriptions="1"]
 * - id: Optional. Workorder post ID. Auto-detects current post if omitted.
 * - descriptions: Optional. 1 to show field help texts, 0 to hide. Default 1.
 */
class DQ_Workorder_Timeline {

    /**
     * Initialize hooks
     */
    public static function init() {
        add_shortcode( 'workorder_timeline', [ __CLASS__, 'render_shortcode' ] );
    }

    /**
     * Shortcode handler
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'id'           => 0,
            'descriptions' => '1',
        ], $atts, 'workorder_timeline' );

        $post_id = intval( $atts['id'] );
        
        // Auto-detect current post if ID not provided
        if ( ! $post_id ) {
            global $post;
            if ( $post && $post->post_type === 'workorder' ) {
                $post_id = $post->ID;
            }
        }

        if ( ! $post_id || get_post_type( $post_id ) !== 'workorder' ) {
            return '<p><em>Timeline unavailable: Invalid or missing Workorder.</em></p>';
        }

        $show_descriptions = ( $atts['descriptions'] === '1' );

        // Enqueue CSS
        self::enqueue_styles();

        // Get timeline data
        $timeline_data = self::get_timeline_data( $post_id );

        // Render timeline
        return self::render_timeline( $timeline_data, $show_descriptions );
    }

    /**
     * Enqueue timeline CSS
     */
    private static function enqueue_styles() {
        static $enqueued = false;
        
        if ( ! $enqueued ) {
            wp_enqueue_style(
                'dq-workorder-timeline',
                DQQB_URL . 'assets/dq-workorder-timeline.css',
                [],
                DQQB_VERSION
            );
            $enqueued = true;
        }
    }

    /**
     * Get field map for timeline
     * 
     * @return array Field configuration
     */
    private static function get_field_map() {
        $default_map = [
            [
                'label'    => 'Date Received',
                'key'      => 'date_received',
                'help'     => 'Initial date workorder was received',
                'color'    => '#4CAF50',
            ],
            [
                'label'    => 'FSC Contact Date',
                'key'      => 'fsc_contact_date',
                'help'     => 'Date FSC contacted customer',
                'color'    => '#2196F3',
            ],
            [
                'label'    => 'Scheduled Service',
                'key'      => 'schedule_date_time',
                'help'     => 'Service appointment scheduled',
                'color'    => '#FF9800',
            ],
            [
                'label'    => 'Service Completed',
                'key'      => 'service_completed_date',
                'help'     => 'Date service completed by engineer',
                'color'    => '#9C27B0',
            ],
            [
                'label'    => 'Completed Date',
                'key'      => 'closed_on',
                'help'     => 'Workorder completion date',
                'color'    => '#F44336',
            ],
            [
                'label'    => 'Reports Sent',
                'key'      => 'reports_sent_date',
                'help'     => 'FSR and DIA reports sent to customer',
                'color'    => '#607D8B',
            ],
        ];

        /**
         * Filter timeline field map
         * 
         * @param array $default_map Default field configuration
         */
        return apply_filters( 'dq_workorder_timeline_field_map', $default_map );
    }

    /**
     * Get timeline data for a workorder
     * 
     * @param int $post_id Workorder post ID
     * @return array Timeline steps with dates
     */
    private static function get_timeline_data( $post_id ) {
        $field_map = self::get_field_map();
        $timeline_data = [];

        foreach ( $field_map as $field ) {
            $raw_value = function_exists( 'get_field' ) 
                ? get_field( $field['key'], $post_id ) 
                : get_post_meta( $post_id, $field['key'], true );

            $normalized_date = self::normalize_date( $raw_value );

            $timeline_data[] = [
                'label'          => $field['label'],
                'key'            => $field['key'],
                'help'           => $field['help'],
                'color'          => $field['color'],
                'raw_value'      => $raw_value,
                'normalized'     => $normalized_date,
                'display_date'   => $normalized_date ? date( 'M d, Y', strtotime( $normalized_date ) ) : '',
                'has_date'       => ! empty( $normalized_date ),
            ];
        }

        return $timeline_data;
    }

    /**
     * Normalize various date formats to Y-m-d
     * 
     * Supports:
     * - Y-m-d
     * - m/d/Y
     * - d-m-Y
     * - Y-m-d H:i:s (datetime)
     * 
     * @param mixed $raw_date Raw date value
     * @return string|null Normalized date in Y-m-d format or null
     */
    private static function normalize_date( $raw_date ) {
        if ( empty( $raw_date ) || ! is_scalar( $raw_date ) ) {
            return null;
        }

        $raw_date = trim( (string) $raw_date );

        // Try standard strtotime first
        $timestamp = strtotime( $raw_date );
        if ( $timestamp !== false ) {
            return date( 'Y-m-d', $timestamp );
        }

        // Try common formats manually
        $formats = [
            'Y-m-d',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'm/d/Y',
            'd-m-Y',
            'd/m/Y',
            'n/j/Y',
        ];

        foreach ( $formats as $format ) {
            $date = DateTime::createFromFormat( $format, $raw_date );
            if ( $date && $date->format( $format ) === $raw_date ) {
                return $date->format( 'Y-m-d' );
            }
        }

        return null;
    }

    /**
     * Render timeline HTML
     * 
     * @param array $timeline_data Timeline step data
     * @param bool $show_descriptions Show help text
     * @return string HTML output
     */
    private static function render_timeline( $timeline_data, $show_descriptions ) {
        $output = '<div class="dq-timeline-wrapper">';
        $output .= '<div class="dq-timeline">';

        $position_toggle = true; // Alternate top/bottom

        foreach ( $timeline_data as $index => $step ) {
            $position_class = $position_toggle ? 'top' : 'bottom';
            $has_date_class = $step['has_date'] ? 'has-date' : 'no-date';

            $output .= sprintf(
                '<div class="dq-timeline-step %s %s" data-key="%s">',
                esc_attr( $position_class ),
                esc_attr( $has_date_class ),
                esc_attr( $step['key'] )
            );

            // Card (callout)
            $output .= '<div class="dq-timeline-card">';
            $output .= '<div class="dq-timeline-label">' . esc_html( $step['label'] ) . '</div>';
            
            if ( $step['has_date'] ) {
                $output .= '<div class="dq-timeline-date">' . esc_html( $step['display_date'] ) . '</div>';
            } else {
                $output .= '<div class="dq-timeline-date empty">Pending</div>';
            }

            if ( $show_descriptions && ! empty( $step['help'] ) ) {
                $output .= '<div class="dq-timeline-help">' . esc_html( $step['help'] ) . '</div>';
            }
            
            $output .= '</div>'; // .dq-timeline-card

            // Connector line and dot
            $output .= '<div class="dq-timeline-connector">';
            $output .= '<div class="dq-timeline-line"></div>';
            $output .= sprintf(
                '<div class="dq-timeline-dot" style="background-color: %s;"></div>',
                esc_attr( $step['color'] )
            );
            $output .= '</div>'; // .dq-timeline-connector

            $output .= '</div>'; // .dq-timeline-step

            $position_toggle = ! $position_toggle;
        }

        $output .= '</div>'; // .dq-timeline
        $output .= '</div>'; // .dq-timeline-wrapper

        return $output;
    }
}
