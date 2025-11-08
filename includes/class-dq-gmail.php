<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * DQ_Gmail
 * - Searches Gmail for "Invoice Number {wo_invoice_no}"
 * - Downloads first attachment
 * - If PDF, renders first page to PNG via Imagick
 * - Saves image to Media Library and updates ACF field: wo_invoice_screenshot (image/file)
 */
class DQ_Gmail {

    public static function init() {
        add_action('wp_ajax_dq_fetch_gmail_invoice', [__CLASS__, 'ajax_fetch']);
        // Optional: auto-fetch when wo_invoice_no is saved
        add_action('updated_post_meta', [__CLASS__, 'maybe_autofetch'], 10, 4);
    }

    public static function maybe_autofetch($meta_id, $object_id, $meta_key, $_meta_value) {
        if ( $meta_key !== 'wo_invoice_no' ) return;
        // enqueue a background task soon after update
        wp_schedule_single_event( time()+10, 'dq_cron_fetch_gmail_invoice', [$object_id] );
    }

    // Cron handler
    public static function cron_hook($post_id) {
        self::process($post_id);
    }

    public static function ajax_fetch() {
        check_ajax_referer('dq_fetch_gmail');
        if ( ! current_user_can('edit_posts') ) wp_send_json_error('no_perm');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ( ! $post_id ) wp_send_json_error('no_post');
        $result = self::process($post_id);
        if ( is_wp_error($result) ) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }

    public static function process($post_id) {
        $invoice_no = get_post_meta($post_id, 'wo_invoice_no', true);
        if ( ! $invoice_no ) return new WP_Error('no_invoice', 'No ACF wo_invoice_no found.');

        $q = 'subject:"Invoice Number '.$invoice_no.'"';
        $message = self::search_message($q);
        if ( is_wp_error($message) ) return $message;
        if ( ! $message ) return new WP_Error('not_found', 'No Gmail message found for that invoice number.');

        $attachment = self::get_first_attachment($message['id']);
        if ( is_wp_error($attachment) ) return $attachment;
        if ( ! $attachment ) return new WP_Error('no_attach', 'No attachment found in message.');

        // Save original to tmp
        $tmp = wp_tempnam( $attachment['filename'] ?: ('invoice-'.$invoice_no) );
        file_put_contents($tmp, $attachment['data']);

        // Convert to PNG if PDF
        $file_for_media = $tmp;
        $mime = $attachment['mimeType'];
        if ( stripos($mime, 'pdf') !== false ) {
            if ( class_exists('Imagick') ) {
                $im = new Imagick();
                $im->setResolution(150,150);
                $im->readImage($tmp.'[0]');
                $im->setImageFormat('png');
                $png = $tmp.'.png';
                $im->writeImage($png);
                $im->clear();
                $im->destroy();
                $file_for_media = $png;
            }
        }

        // Add to Media Library
        // --- Build a safe filename with extension ---
        $ext = '';
        if ( stripos($mime, 'pdf') !== false ) {
            $ext = 'pdf';
        } elseif ( stripos($mime, 'png') !== false ) {
            $ext = 'png';
        } elseif ( stripos($mime, 'jpeg') !== false || stripos($mime, 'jpg') !== false ) {
            $ext = 'jpg';
        }
        
        // If we converted a PDF to PNG, force PNG extension
        if ( $file_for_media !== $tmp ) {
            $ext = 'png';
        }
        
        // If Gmail gave no filename/extension, synthesize one
        if ( ! $ext ) {
            // Try to detect from the path we’re about to upload
            $pi = pathinfo($file_for_media);
            if ( ! empty($pi['extension']) ) {
                $ext = strtolower($pi['extension']);
            } else {
                // fallback
                $ext = 'png';
            }
        }
        
        $final_name = 'invoice-'.$invoice_no.'.'.$ext;
        
        // --- Upload with proper filename so WP can detect type ---
        $contents = file_get_contents($file_for_media);
        if ( $contents === false ) return new WP_Error('read_fail', 'Could not read converted file.');
        
        $upload = wp_upload_bits( $final_name, null, $contents );
        if ( ! empty($upload['error']) ) return new WP_Error('upload_error', $upload['error']);
        
        // --- Ensure WP sees a valid mime/type ---
        $ft = wp_check_filetype_and_ext( $upload['file'], $final_name );
        $mime_for_wp = $ft['type'] ?: (
            ($ext === 'png') ? 'image/png' :
            ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'application/pdf')
        );
        
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $mime_for_wp,
            'post_title'     => 'Invoice '.$invoice_no.' Preview',
            'post_content'   => '',
            'post_status'    => 'inherit'
        ], $upload['file'], $post_id);
        
        if ( is_wp_error($attachment_id) || ! $attachment_id ) {
            return new WP_Error('attach_fail', 'Failed to create attachment.');
        }
        
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);
        
        // Save to ACF field
        update_field('wo_invoice_screenshot', $attachment_id, $post_id);
        
        return [
            'message_id'     => $message['id'],
            'attachment_id'  => $attachment_id,
            'file_url'       => wp_get_attachment_url($attachment_id),
        ];

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        // Save to ACF (image/file) field: wo_invoice_screenshot
        update_field('wo_invoice_screenshot', $attachment_id, $post_id);

        return [
            'message_id' => $message['id'],
            'attachment_id' => $attachment_id,
            'file_url' => wp_get_attachment_url($attachment_id),
        ];
    }

    // ===== Gmail low-level helpers =====

    protected static function api_get($path, $params = []) {
        $token = DQ_Gmail_Settings::get_access_token();
        if ( ! $token ) return new WP_Error('no_token', 'Gmail is not connected.');
        $url = add_query_arg($params, 'https://gmail.googleapis.com/gmail/v1/users/me/'.$path);
        $resp = wp_remote_get($url, ['headers' => ['Authorization' => 'Bearer '.$token], 'timeout'=>30]);
        if ( is_wp_error($resp) ) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        if ( $code >= 300 ) {
            return new WP_Error('gmail_http', 'Gmail API error ('.$code.'): '.wp_remote_retrieve_body($resp));
        }
        return json_decode( wp_remote_retrieve_body($resp), true );
    }

    protected static function search_message($query) {
        $list = self::api_get('messages', ['q'=>$query, 'maxResults'=>1]);
        if ( is_wp_error($list) ) return $list;
        if ( empty($list['messages'][0]['id']) ) return null;
        $id = $list['messages'][0]['id'];
        $msg = self::api_get('messages/'.$id, ['format'=>'full']);
        if ( is_wp_error($msg) ) return $msg;
        return ['id'=>$id, 'payload'=>$msg['payload']];
    }

    protected static function get_first_attachment($message_id) {
        $msg = self::api_get('messages/'.$message_id, ['format'=>'full']);
        if ( is_wp_error($msg) ) return $msg;

        $parts = isset($msg['payload']['parts']) ? $msg['payload']['parts'] : [];
        foreach ( $parts as $p ) {
            if ( ! empty($p['filename']) && ! empty($p['body']['attachmentId']) ) {
                $aid = $p['body']['attachmentId'];
                $att = self::api_get('messages/'.$message_id.'/attachments/'.$aid);
                if ( is_wp_error($att) ) return $att;
                return [
                    'filename' => $p['filename'],
                    'mimeType' => $p['mimeType'] ?? 'application/octet-stream',
                    'data'     => base64_decode(strtr($att['data'], '-_', '+/')),
                ];
            }
        }
        return null;
    }
}

// Register cron hook
add_action('dq_cron_fetch_gmail_invoice', ['DQ_Gmail','cron_hook'], 10, 1);
