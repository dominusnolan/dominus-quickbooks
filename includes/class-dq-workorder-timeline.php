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
        add_action( 'wp_ajax_dq_update_timeline_date', [ __CLASS__, 'ajax_update_date' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_edit_scripts' ] );
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
        $is_editable = is_user_logged_in() && current_user_can('edit_post', $post_id);

        // Enqueue CSS and JS if editable
        self::enqueue_styles();
        if ($is_editable) {
            wp_enqueue_script('dq-workorder-timeline-edit');
        }

        // Get timeline data
        $timeline_data = self::get_timeline_data( $post_id );

        // Render timeline
        return self::render_timeline( $timeline_data, $show_descriptions, $is_editable, $post_id );
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
     * Enqueue edit JS
     */
    public static function enqueue_edit_scripts() {
        // Only on non-admin, when script is enqueued above
        if ( wp_script_is('dq-workorder-timeline-edit', 'enqueued') ) return;

        wp_register_script(
            'dq-workorder-timeline-edit',
            false,
            ['jquery'],
            DQQB_VERSION
        );
        // Inline script for date editing, AJAX, nonce
        wp_add_inline_script( 'dq-workorder-timeline-edit', '
        jQuery(function($){
            $(".dq-timeline-dot[data-editable=\'1\']").css("cursor","pointer").on("click", function(e){
                var $dot = $(this), field = $dot.data("field"), post = $dot.data("post");
                var oldDate = $dot.find(".dq-timeline-dot-date").text().trim();
                if ($dot.find(".dq-tl-edit-ui").length) return;

                var $input = $("<input type=\'date\' class=\'dq-tl-input\' style=\'font-size:16px;padding:2px 6px;border-radius:6px;border:1px solid #aaa;\'>").val(oldDate);
                var $save = $("<button type=\'button\' style=\'margin-left:7px;\'>Save</button>");
                var $cancel = $("<button type=\'button\' style=\'margin-left:5px;\'>Cancel</button>");
                var $ui = $("<div class=\'dq-tl-edit-ui\' style=\'margin-top:8px;\'></div>");
                $ui.append($input, $save, $cancel);

                $dot.find(".dq-timeline-dot-date").hide();
                $dot.append($ui);

                $cancel.on("click", function(){ $ui.remove(); $dot.find(".dq-timeline-dot-date").show(); });
                $save.on("click", function(){
                    var newDate = $input.val();
                    $save.prop("disabled", true);
                    $.post(ajaxurl || "/wp-admin/admin-ajax.php", {
                        action:"dq_update_timeline_date",
                        nonce: window.dqTimelineNonce || "",
                        post_id: post,
                        field_key: field,
                        date: newDate
                    }, function(resp){
                        if (resp.success) {
                            $dot.find(".dq-timeline-dot-date").text(newDate).show();
                            $ui.remove();
                        } else {
                            alert("Error: "+(resp.data||"Failed"));
                            $save.prop("disabled", false);
                        }
                    });
                });
            });
        });
        ' );
    }

    /**
     * Get field map for timeline (field keys to meta/ACF)
     *
     * @return array Field configuration
     */
    private static function get_field_map() {
        // Uses your field keys and improved help text for clarity/design
        return [
            [
                'label'    => 'Date Received',
                'key'      => 'wo_date_received',
                'help'     => 'The date the workorder was received by the FSC.',
                'color'    => '#4895f8',
            ],
            [
                'label'    => 'FSC Contact Date',
                'key'      => 'wo_fsc_contact_date',
                'help'     => 'The date the FSC contacted the customer to schedule service.',
                'color'    => '#27c7de',
            ],
            [
                'label'    => 'Scheduled Service',
                'key'      => 'schedule_date_time',
                'help'     => 'The date the service was scheduled with the customer.',
                'color'    => '#ffae23',
            ],
            [
                'label'    => 'Service Completed',
                'key'      => 'date_service_completed_by_fse',
                'help'     => 'The date service was completed by the engineer.',
                'color'    => '#b04dff',
            ],
            [
                'label'    => 'Completed Date',
                'key'      => 'closed_on',
                'help'     => 'Date the workorder was closed/completed.',
                'color'    => '#f33b3b',
            ],
            [
                'label'    => 'Reports Sent',
                'key'      => 'date_fsr_and_dia_reports_sent_to_customer',
                'help'     => 'FSR and DIA reports sent to customer.',
                'color'    => '#607d8b',
            ],
        ];
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
                'display_date'   => $normalized_date ? $normalized_date : '', // For editing
                'has_date'       => ! empty( $normalized_date ),
            ];
        }

        return $timeline_data;
    }

    /**
     * Normalize various date formats to Y-m-d
     *
     * @param mixed $raw_date Raw date value
     * @return string|null Normalized date in Y-m-d format or null
     */
    private static function normalize_date( $raw_date ) {
        if ( empty( $raw_date ) || ! is_scalar( $raw_date ) ) {
            return null;
        }

        $raw_date = trim( (string) $raw_date );

        $timestamp = strtotime( $raw_date );
        if ( $timestamp !== false ) {
            return date( 'Y-m-d', $timestamp );
        }

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
     * @param bool $is_editable Allow in-place date editing via dot click
     * @param int $post_id Workorder post ID
     * @return string HTML output
     */
    private static function render_timeline( $timeline_data, $show_descriptions, $is_editable, $post_id ) {
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

            // Card
            $output .= '<div class="dq-timeline-card">';
            $output .= '<div class="dq-timeline-label">' . esc_html( $step['label'] ) . '</div>';
            if ( $show_descriptions && ! empty( $step['help'] ) ) {
                $output .= '<div class="dq-timeline-help">' . esc_html( $step['help'] ) . '</div>';
            }
            $output .= '</div>'; // .dq-timeline-card

            // Connector + Dot + Date
            $output .= '<div class="dq-timeline-connector">';
            $output .= '<div class="dq-timeline-line"></div>';
            $output .= sprintf(
                '<div class="dq-timeline-dot" style="background-color:%s;" data-field="%s" data-post="%d"%s>',
                esc_attr( $step['color'] ),
                esc_attr( $step['key'] ),
                intval($post_id),
                $is_editable ? ' data-editable="1"' : ''
            );
            if ( $step['has_date'] ) {
                $output .= '<span class="dq-timeline-dot-date">' . esc_html( $step['normalized'] ) . '</span>';
            }
            $output .= '</div>'; // .dq-timeline-dot
            $output .= '</div>'; // .dq-timeline-connector

            $output .= '</div>'; // .dq-timeline-step

            $position_toggle = ! $position_toggle;
        }

        $output .= '</div>'; // .dq-timeline
        $output .= '</div>'; // .dq-timeline-wrapper

        if ($is_editable) {
            $output .= "<script>window.dqTimelineNonce = '" . esc_js(wp_create_nonce('dq_timeline_edit')) . "';</script>";
        }
        return $output;
    }

    /**
     * AJAX handler: update timeline date fields
     */
    public static function ajax_update_date() {
        check_ajax_referer('dq_timeline_edit', 'nonce');
        if ( !is_user_logged_in() ) wp_send_json_error('Not logged in.');
        $post_id = intval($_POST['post_id'] ?? 0);
        $field_key = sanitize_text_field($_POST['field_key'] ?? '');
        $date = sanitize_text_field($_POST['date'] ?? '');
        if ( !$post_id || !$field_key || !$date ) wp_send_json_error('Missing params.');

        if ( !current_user_can('edit_post', $post_id) ) wp_send_json_error('No permission.');
        if ( !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) wp_send_json_error('Date format invalid.');

        if ( function_exists('update_field') ) {
            update_field($field_key, $date, $post_id);
        } else {
            update_post_meta($post_id, $field_key, $date);
        }
        wp_send_json_success(['date'=>$date]);
    }

}