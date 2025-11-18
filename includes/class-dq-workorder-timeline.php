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
     * PATCH: Remove Cancel button and beautify the date editor UI per image1.
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

        // Beautiful floating date editor UI with removed Cancel button
        wp_add_inline_script( 'dq-workorder-timeline-edit', '
        jQuery(function($){
            $(".dq-timeline-dot[data-editable=\'1\']").css("cursor","pointer").on("click", function(e){
                var $dot = $(this), field = $dot.data("field"), post = $dot.data("post");
                var oldDateText = $dot.find(".dq-timeline-dot-date").length
                    ? $dot.find(".dq-timeline-dot-date").text().trim()
                    : "";
                var formattedDate = "";
                var mdyMatch = oldDateText.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
                if (mdyMatch) {
                    formattedDate = mdyMatch[3] + "-" + ("0"+mdyMatch[1]).slice(-2) + "-" + ("0"+mdyMatch[2]).slice(-2);
                } else if (oldDateText.match(/^\d{4}-\d{2}-\d{2}$/)) {
                    formattedDate = oldDateText;
                } else {
                    formattedDate = "";
                }
                if ($dot.find(".dq-tl-edit-ui").length) return;

                // Remove any previous UI
                $dot.find(".dq-tl-edit-ui").remove();

                // floating white card (centered below dot)
                var $ui = $("<div class=\'dq-tl-edit-ui\' style=\'" + 
                    "position:absolute;left:50%;transform:translateX(-50%);z-index:11;" +
                    "background:#fff;border-radius:13px;box-shadow:0 8px 32px rgba(20,40,60,0.12);" +
                    "border:2px solid #337cff;padding:18px 26px 22px 26px;display:flex;" +
                    "flex-direction:column;align-items:center;min-width:220px;" +
                    "margin-top:28px;" +
                    "\'></div>");

                // Label
                var $label = $("<div style=\'font-size:15px;font-weight:600;margin-bottom:9px;color:#337cff;letter-spacing:.02em\'>Edit Date</div>");

                // Date input, styled
                var $input = $("<input type=\'date\' class=\'dq-tl-input\' style=\'" +
                    "font-size:16px;padding:7px 15px;border-radius:9px;border:2px solid #b1cfff;" +
                    "background:#fafdff;outline:none;box-shadow:0 2px 9px rgba(30,80,220,.04);" +
                    "transition:border-color .2s;width:140px;margin-right:7px;" +
                    "\' placeholder=\'mm/dd/yyyy\' autocomplete=\'off\'>").val(formattedDate);

                var $calendar = $("<span style=\'display:inline-block;width:25px;height:25px;background:#eef6ff;" + 
                    "border-radius:6px;text-align:center;line-height:25px;font-size:18px;color:#337cff;" +
                    "vertical-align:middle;margin-left:6px;\' title=\'Pick date\'>&#128197;</span>");

                // Save button
                var $save = $("<button type=\'button\' class=\'dq-tl-save-btn\' style=\'" +
                    "background:linear-gradient(90deg,#337cff,#59a5f7);color:#fff;" +
                    "border:none;padding:7px 23px;border-radius:8px;font-weight:600;font-size:15px;" +
                    "box-shadow:0 2px 9px rgba(30,80,240,0.08);cursor:pointer;" +
                    "transition:background .2s;margin-top:10px;margin-bottom:2px;" +
                    "\' >Save</button>");

                $ui.append($label);
                var $inputRow = $("<div style=\'width:100%;display:flex;align-items:center;gap:8px;margin-bottom:8px;\'></div>")
                                .append($input)
                                .append($calendar);
                $ui.append($inputRow);
                $ui.append($save);

                $dot.find(".dq-timeline-dot-date").hide();
                $dot.append($ui);

                // Focus effect
                $input.on("focus", function(){ $(this).css("border-color","#337cff"); });
                $input.on("blur", function(){ $(this).css("border-color","#b1cfff"); });

                // Dismiss editor if clicking outside
                setTimeout(function() {
                    $(document).on("mousedown.dqtl", function(ev) {
                        if ($ui[0] && !$.contains($ui[0], ev.target) && ev.target !== $ui[0]) {
                            $ui.remove();
                            $dot.find(".dq-timeline-dot-date").show();
                            $(document).off("mousedown.dqtl");
                        }
                    });
                }, 10);

                $save.on("click", function(){
                    var newDate = $input.val();
                    $save.prop("disabled", true);
                    $.post(window.ajaxurl || "/wp-admin/admin-ajax.php", {
                        action:"dq_update_timeline_date",
                        nonce: window.dqTimelineNonce || "",
                        post_id: post,
                        field_key: field,
                        date: newDate
                    }, function(resp){
                        if (resp.success) {
                            var bubbleVal = "";
                            if (newDate.match(/^\d{4}-\d{2}-\d{2}$/)) {
                                var d = new Date(newDate);
                                var m = d.getMonth()+1, day = d.getDate(), y = d.getFullYear();
                                bubbleVal = (m<10?"0":"")+m + "/" + (day<10?"0":"")+day + "/" + y;
                            } else {
                                bubbleVal = newDate;
                            }

                            if ($dot.find(".dq-timeline-dot-date").length) {
                                $dot.find(".dq-timeline-dot-date").text(bubbleVal).show();
                            } else {
                                $("<span class=\'dq-timeline-dot-date\'>"+bubbleVal+"</span>").appendTo($dot);
                            }
                            $ui.remove();
                            $(document).off("mousedown.dqtl");
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
    private static function get_field_map( $post_id = 0 ) {
        $fields = [
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
            ]
            // Re-Scheduled Service will be conditionally added below
        ];

        // Only add the "Re-Scheduled Service" node if ACF field 're-schedule' is set for this post
        $should_add_reschedule = false;
        if ( $post_id ) {
            if ( function_exists('get_field') ) {
                $reschedule_val = get_field('re-schedule', $post_id);
                if ( !empty($reschedule_val) ) { $should_add_reschedule = true; }
            } else {
                $reschedule_val = get_post_meta($post_id, 're-schedule', true);
                if ( !empty($reschedule_val) ) { $should_add_reschedule = true; }
            }
        }
        if ( $should_add_reschedule ) {
            $fields[] = [
                'label'    => 'Re-Scheduled Service',
                'key'      => 're-schedule',
                'help'     => 'The date the service was re-scheduled with the customer.',
                'color'    => '#d77f13', // a distinct color
            ];
        }

        $fields = array_merge($fields, [
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
        ]);
        return $fields;
    }

    /**
     * Get timeline data for a workorder
     *
     * @param int $post_id Workorder post ID
     * @return array Timeline steps with dates
     */
    private static function get_timeline_data( $post_id ) {
        // Pass post_id to field map so we can conditionally add "Re-Scheduled Service"
        $field_map = self::get_field_map($post_id);
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
            // Render date bubble only if a date is present
            if ( $step['has_date'] ) {
                $formatted = date('m/d/Y', strtotime($step['normalized']));
                $output .= '<span class="dq-timeline-dot-date">' . esc_html( $formatted ) . '</span>';
            }
            // If no date, leave as is (dot is always editable if allowed)
            $output .= '</div>'; // .dq-timeline-dot
            $output .= '</div>'; // .dq-timeline-connector

            $output .= '</div>'; // .dq-timeline-step

            $position_toggle = ! $position_toggle;
        }

        $output .= '</div>'; // .dq-timeline
        $output .= '</div>'; // .dq-timeline-wrapper

        // Patch: Add ajaxurl and nonce automatically when editable!
        if ($is_editable) {
            $output .= "<script>window.ajaxurl = '" . esc_url(admin_url('admin-ajax.php')) . "';</script>";
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