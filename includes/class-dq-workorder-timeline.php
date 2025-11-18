<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_Workorder_Timeline
 * Vertical timeline (see image3), emoji icons for each step, alternating left/right cards.
 */
class DQ_Workorder_Timeline {

    public static function init() {
        add_shortcode( 'workorder_timeline', [ __CLASS__, 'render_shortcode' ] );
        add_action( 'wp_ajax_dq_update_timeline_date', [ __CLASS__, 'ajax_update_date' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_edit_scripts' ] );
    }

    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'id'           => 0,
            'descriptions' => '1',
        ], $atts, 'workorder_timeline' );

        $post_id = intval( $atts['id'] );
        if ( ! $post_id ) {
            global $post;
            if ( $post && $post->post_type === 'workorder' ) $post_id = $post->ID;
        }
        if ( ! $post_id || get_post_type( $post_id ) !== 'workorder' ) {
            return '<p><em>Timeline unavailable: Invalid or missing Workorder.</em></p>';
        }

        $show_descriptions = ( $atts['descriptions'] === '1' );
        $is_editable = is_user_logged_in() && current_user_can('edit_post', $post_id);

        self::enqueue_styles();
        if ($is_editable) wp_enqueue_script('dq-workorder-timeline-edit');
        $timeline_data = self::get_timeline_data( $post_id );

        return self::render_timeline( $timeline_data, $show_descriptions, $is_editable, $post_id );
    }

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

    public static function enqueue_edit_scripts() {
        if ( wp_script_is('dq-workorder-timeline-edit', 'enqueued') ) return;
        wp_register_script('dq-workorder-timeline-edit', false, ['jquery'], DQQB_VERSION);
        wp_add_inline_script( 'dq-workorder-timeline-edit', '
        jQuery(function($){
            $(".dq-vtl-dot[data-editable=\'1\']").css("cursor","pointer").on("click", function(e){
                var $dot = $(this), field = $dot.data("field"), post = $dot.data("post");
                var oldDateText = $dot.find(".dq-vtl-date").length ? $dot.find(".dq-vtl-date").text().trim() : "";
                var formattedDate="", mdyMatch = oldDateText.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
                if(mdyMatch) formattedDate = mdyMatch[3]+"-"+("0"+mdyMatch[1]).slice(-2)+"-"+("0"+mdyMatch[2]).slice(-2);
                else if(oldDateText.match(/^\d{4}-\d{2}-\d{2}$/)) formattedDate=oldDateText;
                if ($dot.find(".dq-tl-edit-ui").length) return;
                $dot.find(".dq-tl-edit-ui").remove();

                var $ui = $("<div class=\'dq-tl-edit-ui\' style=\'position:absolute;left:50%;transform:translateX(-50%);z-index:21;background:#fff;border-radius:13px;box-shadow:0 8px 32px rgba(20,40,60,0.12);border:2px solid #337cff;padding:18px 26px 22px 26px;display:flex;flex-direction:column;align-items:center;min-width:240px;margin-top:22px;\'></div>");
                $ui.append($("<div style=\'font-size:15px;font-weight:600;margin-bottom:9px;color:#337cff;text-align:center;letter-spacing:.02em\'>Edit Date</div>"));
                var $inputRow = $("<div style=\'width:100%;display:flex;align-items:center;gap:8px;margin-bottom:12px;justify-content:center;\'></div>");
                var $input = $("<input type=\'date\' style=\'font-size:17px;padding:7px 15px;border-radius:9px;border:2px solid #2e8af8;background:#fafdff;outline:none;box-shadow:0 2px 9px rgba(30,80,220,.09);transition:border-color .2s;width:145px;margin-right:7px;text-align:center;\' autocomplete=\'off\'>").val(formattedDate);
                var $calendar = $("<span style=\'display:inline-block;width:28px;height:28px;background:#eef6ff;border-radius:7px;text-align:center;line-height:28px;font-size:18px;color:#337cff;vertical-align:middle;margin-left:4px;\' title=\'Pick date\'>&#128197;</span>");
                $inputRow.append($input).append($calendar); $ui.append($inputRow);

                var $save = $("<button type=\'button\' style=\'background:linear-gradient(90deg,#337cff,#59a5f7);color:#fff;border:none;padding:8px 28px;border-radius:9px;font-weight:600;font-size:15px;box-shadow:0 2px 9px rgba(30,80,240,0.09);cursor:pointer;transition:background .2s;margin-top:10px;margin-bottom:3px;width:100%;text-align:center\'>Save</button>");
                $ui.append($save);

                $dot.find(".dq-vtl-date").hide(); $dot.append($ui);

                $input.on("focus", function(){ $(this).css("border-color","#337cff"); });
                $input.on("blur", function(){ $(this).css("border-color","#b1cfff"); });
                setTimeout(function() {
                    $(document).on("mousedown.dqtl", function(ev) {
                        if ($ui[0] && !$.contains($ui[0], ev.target) && ev.target !== $ui[0]) {
                            $ui.remove(); $dot.find(".dq-vtl-date").show();
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
                            var bubbleVal = ""; if (newDate.match(/^\d{4}-\d{2}-\d{2}$/)) {
                                var d = new Date(newDate);
                                var m = d.getMonth()+1, day = d.getDate(), y = d.getFullYear();
                                bubbleVal = (m<10?"0":"")+m + "/" + (day<10?"0":"")+day + "/" + y;
                            } else { bubbleVal = newDate; }
                            if ($dot.find(".dq-vtl-date").length) {
                                $dot.find(".dq-vtl-date").text(bubbleVal).show();
                            } else {
                                $("<span class=\'dq-vtl-date\'>"+bubbleVal+"</span>").appendTo($dot);
                            }
                            $ui.remove(); $(document).off("mousedown.dqtl");
                        } else { alert("Error: "+(resp.data||"Failed")); $save.prop("disabled", false); }
                    });
                });
            });
        });
        ' );
    }

    private static function get_field_map( $post_id = 0 ) {
        $fields = [
            [
                'label'    => 'Date Received',
                'key'      => 'wo_date_received',
                'help'     => 'The date the workorder was received by the FSC.',
                'color'    => '#b9c658',
                'emoji'    => '📥',
            ],
            [
                'label'    => 'FSC Contact Date',
                'key'      => 'wo_fsc_contact_date',
                'help'     => 'The date the FSC contacted the customer to schedule service.',
                'color'    => '#144477',
                'emoji'    => '☎️',
            ],
            [
                'label'    => 'Scheduled Service',
                'key'      => 'schedule_date_time',
                'help'     => 'The date the service was scheduled with the customer.',
                'color'    => '#2f9fa1',
                'emoji'    => '📅',
            ]
        ];
        // Conditionally add Re-Scheduled Service
        $should_add_reschedule = false;
        if ($post_id) {
            if (function_exists('get_field')) {
                $reschedule_val = get_field('re-schedule', $post_id);
                if (!empty($reschedule_val)) $should_add_reschedule = true;
            } else {
                $reschedule_val = get_post_meta($post_id, 're-schedule', true);
                if (!empty($reschedule_val)) $should_add_reschedule = true;
            }
        }
        if ($should_add_reschedule) {
            $fields[] = [
                'label'    => 'Re-Scheduled Service',
                'key'      => 're-schedule',
                'help'     => 'The date the service was re-scheduled with the customer.',
                'color'    => '#aa6e0b',
                'emoji'    => '📅',
            ];
        }
        $fields = array_merge($fields, [
            [
                'label'    => 'Date Service Completed by Engineer',
                'key'      => 'date_service_completed_by_fse',
                'help'     => 'The date service was completed by the engineer.',
                'color'    => '#8c3fed',
                'emoji'    => '🛠️',
            ],
            [
                'label'    => 'Completed Date',
                'key'      => 'closed_on',
                'help'     => 'Date the workorder was closed/completed.',
                'color'    => '#36a829',
                'emoji'    => '✅',
            ],
            [
                'label'    => 'Date FSR and DIA Reports Sent to Customer',
                'key'      => 'date_fsr_and_dia_reports_sent_to_customer',
                'help'     => 'FSR and DIA reports sent to customer.',
                'color'    => '#567da1',
                'emoji'    => '📧',
            ]
        ]);
        return $fields;
    }

    private static function get_timeline_data( $post_id ) {
        $field_map = self::get_field_map($post_id);
        $timeline_data = [];
        foreach ( $field_map as $field ) {
            $raw_value = function_exists( 'get_field' )
                ? get_field( $field['key'], $post_id )
                : get_post_meta( $post_id, $field['key'], true );

            $normalized_date = self::normalize_date( $raw_value );
            $timeline_data[] = [
                'label'        => $field['label'],
                'key'          => $field['key'],
                'help'         => $field['help'],
                'color'        => $field['color'],
                'emoji'        => $field['emoji'],
                'raw_value'    => $raw_value,
                'normalized'   => $normalized_date,
                'display_date' => $normalized_date ? $normalized_date : '',
                'has_date'     => ! empty( $normalized_date ),
            ];
        }
        return $timeline_data;
    }

    private static function normalize_date( $raw_date ) {
        if ( empty( $raw_date ) || ! is_scalar( $raw_date ) ) return null;
        $raw_date = trim( (string) $raw_date );
        $timestamp = strtotime( $raw_date );
        if ( $timestamp !== false ) return date( 'Y-m-d', $timestamp );
        $formats = ['Y-m-d','Y-m-d H:i:s','Y-m-d H:i','m/d/Y','d-m-Y','d/m/Y','n/j/Y'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat( $format, $raw_date );
            if ( $date && $date->format($format) === $raw_date ) return $date->format('Y-m-d');
        }
        return null;
    }

    private static function render_timeline( $timeline_data, $show_descriptions, $is_editable, $post_id ) {
        $output = '<div class="dq-timeline-vertical-wrapper">';
        $output .= '<h2 class="dq-vtl-title">WORK ORDER PROGRESS</h2>';
        $output .= '<div class="dq-timeline-vertical">';
        foreach ($timeline_data as $i => $step) {
            $side = $i % 2 == 0 ? 'right' : 'left';
            $color = $step['color'];
            $emoji = $step['emoji'];
            $date = $step['has_date'] ? date('m/d/Y', strtotime($step['normalized'])) : '';
            $output .= '<div class="dq-vtl-step '.$side.'">';
            if ($side == 'right') $output .= '<div class="dq-vtl-date">'.esc_html($date).'</div>';
            $output .= '<div class="dq-vtl-dot" style="background:'.$color.';" data-field="'.esc_attr($step['key']).'" data-post="'.esc_attr($post_id).'"'.($is_editable?' data-editable="1"':'').'>';
            $output .= '<span class="dq-vtl-emoji">'.$emoji.'</span>';
            $output .= '</div>';
            if ($side == 'left') $output .= '<div class="dq-vtl-date">'.esc_html($date).'</div>';
            $output .= '<div class="dq-vtl-card">';
            $output .= '<div class="dq-vtl-label">'.esc_html($step['label']).'</div>';
            if ($show_descriptions && !empty($step['help'])) {
                $output .= '<div class="dq-vtl-help">'.esc_html($step['help']).'</div>';
            }
            $output .= '</div></div>';
        }
        $output .= '</div></div>';
        if ($is_editable) {
            $output .= "<script>window.ajaxurl = '".esc_url(admin_url('admin-ajax.php'))."';</script>";
            $output .= "<script>window.dqTimelineNonce = '".esc_js(wp_create_nonce('dq_timeline_edit'))."';</script>";
        }
        return $output;
    }

    public static function ajax_update_date() {
        check_ajax_referer('dq_timeline_edit', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('Not logged in.');
        $post_id = intval($_POST['post_id'] ?? 0);
        $field_key = sanitize_text_field($_POST['field_key'] ?? '');
        $date = sanitize_text_field($_POST['date'] ?? '');
        if (!$post_id || !$field_key || !$date) wp_send_json_error('Missing params.');
        if (!current_user_can('edit_post', $post_id)) wp_send_json_error('No permission.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) wp_send_json_error('Date format invalid.');
        if (function_exists('update_field')) {
            update_field($field_key, $date, $post_id);
        } else {
            update_post_meta($post_id, $field_key, $date);
        }
        wp_send_json_success(['date' => $date]);
    }
}