<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Class DQ_Gmail_Emailshot
 *
 * - Finds the Gmail message that contains a given invoice number (subject OR body)
 * - Renders a Gmail-like screenshot (subject + Inbox, time top-right, avatar+sender line,
 *   optional "invoice card", body, and optional attachments strip with thumbnail)
 * - HTML -> PDF via Dompdf, PDF -> PNG via Imagick
 * - Uploads PNG to Media Library and stores in ACF field: wo_email_screenshot (Image, return ID)
 *
 * Requirements:
 * - Settings class: DQ_Gmail_Settings (provides OAuth access token)
 * - Composer: dompdf/dompdf (vendor/autoload.php present)
 * - PHP 7.4, Imagick enabled (+ Ghostscript for PDF rendering)
 */
class DQ_Gmail_Emailshot {

    public static function init() {
        add_action('wp_ajax_dq_fetch_gmail_emailshot', [__CLASS__, 'ajax_fetch']);
    }

    /** ========== AJAX ========== */
    public static function ajax_fetch() {
        check_ajax_referer('dq_fetch_gmail_emailshot');
        if ( ! current_user_can('edit_posts') ) wp_send_json_error('no_perm');

        $post_id   = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ( ! $post_id ) wp_send_json_error('no_post');

        $invoice_no = get_post_meta($post_id, 'wo_invoice_no', true);
        if ( ! $invoice_no ) wp_send_json_error('No ACF wo_invoice_no found.');

        $r = self::render_and_attach($post_id, (string)$invoice_no);
        if ( is_wp_error($r) ) wp_send_json_error($r->get_error_message());
        wp_send_json_success($r);
    }

    /** ========== Public: render & attach ========== */
    protected static function render_and_attach($post_id, $invoice_no) {
        // Find message by invoice in subject OR body
        $msg = self::find_message_by_invoice($invoice_no, 10);
        if ( is_wp_error($msg) ) return $msg;
        if ( ! $msg ) return new WP_Error('not_found', 'No Gmail message contains that invoice number.');

        // Require Dompdf
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if ( ! file_exists($autoload) ) {
            return new WP_Error('dompdf_missing', 'Dompdf not installed. Run: composer require dompdf/dompdf');
        }
        require_once $autoload;

        // Build HTML
        $headers = self::extract_headers($msg);
        $body    = self::get_body_html($msg);
        $hasAtt  = self::has_attachment($msg);
        $thumb   = self::first_attachment_thumb_data_uri($msg);
        $card    = self::extract_invoice_card_fields($msg); // (best effort)
        $html    = self::build_html($headers, $body, $hasAtt, $thumb, $card);

        // Render HTML -> PDF
        try {
            $options = new Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $dompdf = new Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            // Wide canvas similar to desktop Gmail: 980px width ≈ 735pt; allow height
            $dompdf->setPaper([0,0,980,1600]); // points
            $dompdf->render();
            $pdfBytes = $dompdf->output();
        } catch (\Throwable $e) {
            return new WP_Error('dompdf_error', 'Failed to render PDF: '.$e->getMessage());
        }

        // Temp PDF
        $safe_no = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)$invoice_no);
        $tmpPdf = wp_tempnam('emailshot-'.$safe_no.'.pdf');
        file_put_contents($tmpPdf, $pdfBytes);

        // PDF -> PNG
        if ( ! class_exists('Imagick') ) {
            return new WP_Error('imagick_missing', 'Imagick required for PDF → PNG.');
        }
        try {
            $im = new Imagick();
            $im->setResolution(150,150);
            $im->readImage($tmpPdf.'[0]');
            $im->setImageFormat('png');
            $pngPath = $tmpPdf.'.png';
            $im->writeImage($pngPath);
            $im->clear(); $im->destroy();
        } catch (\Throwable $e) {
            return new WP_Error('imagick_error', 'PDF→PNG failed: '.$e->getMessage());
        }

        // Upload to Media
        $final_name = 'emailshot-'.$safe_no.'.png';
        $bytes = file_get_contents($pngPath);
        if ($bytes === false) return new WP_Error('read_fail', 'Could not read PNG.');
        $upload = wp_upload_bits($final_name, null, $bytes);
        if ( ! empty($upload['error']) ) return new WP_Error('upload_error', $upload['error']);

        $ft = wp_check_filetype_and_ext($upload['file'], $final_name);
        $mime = $ft['type'] ?: 'image/png';

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title'     => 'Email Screenshot '.$safe_no,
            'post_content'   => '',
            'post_status'    => 'inherit'
        ], $upload['file'], $post_id);
        if ( is_wp_error($attachment_id) || ! $attachment_id ) {
            return new WP_Error('attach_fail', 'Failed to create attachment.');
        }

        require_once ABSPATH.'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $meta);

        // Save to ACF image field
        if ( function_exists('update_field') ) {
            update_field('wo_email_screenshot', $attachment_id, $post_id);
        } else {
            update_post_meta($post_id, 'wo_email_screenshot', $attachment_id);
        }

        return [
            'message_id'    => $msg['id'],
            'attachment_id' => $attachment_id,
            'file_url'      => wp_get_attachment_url($attachment_id),
        ];
    }

    /** ========== Gmail API helpers ========== */

    protected static function api_get($path, $params = []) {
        $token = DQ_Gmail_Settings::get_access_token();
        if ( ! $token ) return new WP_Error('no_token', 'Gmail is not connected.');
        $url = add_query_arg($params, 'https://gmail.googleapis.com/gmail/v1/users/me/'.$path);
        $resp = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer '.$token],
            'timeout' => 30
        ]);
        if ( is_wp_error($resp) ) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        if ( $code >= 300 ) {
            return new WP_Error('gmail_http', 'Gmail API error ('.$code.'): '.wp_remote_retrieve_body($resp));
        }
        return json_decode( wp_remote_retrieve_body($resp), true );
    }

    /**
     * Find the first message whose SUBJECT or BODY contains the invoice number.
     * Checks up to $maxResults candidates from a broad query.
     */
    protected static function find_message_by_invoice($invoice_no, $maxResults = 10) {
        $needle = trim((string)$invoice_no);
        // Build broad query that hits subject variants or anywhere string
        $q = sprintf(
            '(subject:"Invoice Number %1$s" OR subject:"Invoice #%1$s" OR subject:"Invoice %1$s" OR "%1$s") in:anywhere',
            $needle
        );
        $list = self::api_get('messages', ['q'=>$q, 'maxResults'=>(int)$maxResults]);
        if ( is_wp_error($list) ) return $list;
        if ( empty($list['messages']) ) return null;

        foreach ( $list['messages'] as $m ) {
            $msg = self::api_get('messages/'.$m['id'], ['format'=>'full']);
            if ( is_wp_error($msg) ) continue;

            // Subject check
            $subject = '';
            foreach ( ($msg['payload']['headers'] ?? []) as $h ) {
                if ( ($h['name'] ?? '') === 'Subject' ) { $subject = $h['value'] ?? ''; break; }
            }
            if ( stripos($subject, $needle) !== false ) return $msg;

            // Body check
            list($html,$plain) = self::get_bodies($msg);
            if ( stripos($html,  $needle) !== false || stripos($plain, $needle) !== false ) {
                return $msg;
            }
        }
        return null;
    }

    /** ========== Message parsing helpers ========== */

    protected static function extract_headers($msg) {
        $h = ['Subject'=>'', 'From'=>'', 'To'=>'', 'Date'=>''];
        foreach ( ($msg['payload']['headers'] ?? []) as $hdr ) {
            $name = $hdr['name'] ?? '';
            if ( isset($h[$name]) ) $h[$name] = $hdr['value'] ?? '';
        }
        return $h;
    }

    // Return (html, plain) decoded bodies
    protected static function get_bodies($msg) {
        $html = ''; $plain = '';
        $stack = [ $msg['payload'] ];
        while ( $stack ) {
            $p = array_pop($stack);
            if ( ! empty($p['parts']) ) foreach ( $p['parts'] as $pp ) $stack[] = $pp;
            $mime = $p['mimeType'] ?? '';
            $data = $p['body']['data'] ?? '';
            if ( $data ) {
                $decoded = base64_decode(strtr($data, '-_', '+/'));
                if ( stripos($mime, 'text/html')  !== false ) $html  = $decoded;
                if ( stripos($mime, 'text/plain') !== false ) $plain = $decoded;
            }
        }
        return [$html, $plain];
    }

    // Sanitized HTML body (falls back to plain text)
    protected static function get_body_html($msg) {
        list($html, $plain) = self::get_bodies($msg);
        if ( ! $html && $plain ) {
            $html = '<div style="white-space:pre-wrap;font-family:Arial,Helvetica,sans-serif">'.
                    nl2br(esc_html($plain)).'</div>';
        }
        if ( ! $html ) $html = '<p>(No content)</p>';

        // Sanitize
        $allowed = [
            'a'=>['href'=>[], 'target'=>[], 'rel'=>[]],
            'p'=>[], 'br'=>[], 'strong'=>[], 'em'=>[], 'span'=>['style'=>[]], 'div'=>['style'=>[]],
            'ul'=>[], 'ol'=>[], 'li'=>[], 'table'=>['border'=>[], 'cellpadding'=>[], 'cellspacing'=>[], 'style'=>[]],
            'thead'=>[], 'tbody'=>[], 'tr'=>[], 'td'=>['style'=>[]], 'th'=>['style'=>[]],
            'img'=>['src'=>[], 'alt'=>[], 'width'=>[], 'height'=>[], 'style'=>[]],
            'h1'=>[], 'h2'=>[], 'h3'=>[], 'h4'=>[], 'h5'=>[], 'h6'=>[]
        ];
        return wp_kses($html, $allowed);
    }

    protected static function has_attachment($msg) {
        if ( empty($msg['payload']) ) return false;
        $stack = [ $msg['payload'] ];
        while ( $stack ) {
            $p = array_pop($stack);
            if ( ! empty($p['parts']) ) foreach ( $p['parts'] as $pp ) $stack[] = $pp;
            if ( ! empty($p['filename']) && ! empty($p['body']['attachmentId']) ) return true;
        }
        return false;
    }

    /**
     * Returns data-URI PNG thumbnail for first attachment (image or PDF).
     */
    protected static function first_attachment_thumb_data_uri($msg, $maxWidth = 260) {
        if ( empty($msg['payload']) ) return '';
        $parts = [ $msg['payload'] ];
        $att = null;
        while ( $parts ) {
            $p = array_pop($parts);
            if ( ! empty($p['parts']) ) foreach ( $p['parts'] as $pp ) $parts[] = $pp;
            if ( ! empty($p['filename']) && ! empty($p['body']['attachmentId']) ) {
                $att = [
                    'id'   => $p['body']['attachmentId'],
                    'mime' => $p['mimeType'] ?? 'application/octet-stream'
                ];
                break;
            }
        }
        if ( ! $att ) return '';

        $token = DQ_Gmail_Settings::get_access_token();
        if ( ! $token ) return '';
        $url  = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.$msg['id'].'/attachments/'.$att['id'];
        $resp = wp_remote_get($url, ['headers'=>['Authorization'=>'Bearer '.$token], 'timeout'=>30]);
        if ( is_wp_error($resp) || wp_remote_retrieve_response_code($resp) >= 300 ) return '';
        $raw  = json_decode(wp_remote_retrieve_body($resp), true);
        if ( empty($raw['data']) ) return '';
        $bytes = base64_decode(strtr($raw['data'], '-_', '+/'));
        if ( $bytes === false ) return '';

        try {
            $im = new Imagick();
            if ( stripos($att['mime'], 'image/') === 0 ) {
                $im->readImageBlob($bytes);
            } elseif ( stripos($att['mime'], 'pdf') !== false ) {
                $tmp = wp_tempnam('emailshot.pdf');
                file_put_contents($tmp, $bytes);
                $im->setResolution(144,144);
                $im->readImage($tmp.'[0]');
            } else {
                return '';
            }
            $im->setImageFormat('png');
            $w = $im->getImageWidth();
            if ( $w > $maxWidth ) $im->resizeImage($maxWidth, 0, Imagick::FILTER_LANCZOS, 1);
            $png = $im->getImagesBlob();
            $im->clear(); $im->destroy();
            return 'data:image/png;base64,'.base64_encode($png);
        } catch (\Throwable $e) {
            return '';
        }
    }

    // Parse simple invoice summary fields (best effort for QuickBooks-like emails)
    protected static function extract_invoice_card_fields($msg) {
        list($html,$plain) = self::get_bodies($msg);
        $text = $plain ?: wp_strip_all_tags($html);

        $out = ['title'=>'', 'total'=>'', 'due'=>'', 'issuer'=>''];

        if ( preg_match('/Bill for\s+[^\r\n]+/i', $text, $m) ) $out['title'] = trim($m[0]);

        if ( preg_match('/Total amount due[:\s]*\$?\s*([0-9\.,]+(?:\s?[A-Z]{3})?)/i', $text, $m) ) {
            $val = trim(str_replace('USD', '', $m[1]));
            $out['total'] = (strpos($val, '$') === 0) ? $val : ('$'.$val);
        }

        if ( preg_match('/Due date[:\s]*([A-Za-z]{3,9}\s+\d{1,2},\s+\d{4}|\d{1,2}\/\d{1,2}\/\d{2,4})/i', $text, $m) ) {
            $out['due'] = trim($m[1]);
        }

        if ( preg_match('/Issuer[:\s]*([^\r\n]+)/i', $text, $m) ) {
            $out['issuer'] = trim($m[1]);
        } else {
            foreach ( ($msg['payload']['headers'] ?? []) as $h ) {
                if ( ($h['name'] ?? '') === 'From' ) { $out['issuer'] = trim($h['value'] ?? ''); break; }
            }
        }

        return $out;
    }

    protected static function format_time_with_relative($dateHeader) {
        $ts = strtotime($dateHeader);
        if ( ! $ts ) $ts = time();
        $wp_tz = wp_timezone();
        $dt = new DateTime('@'.$ts);
        $dt->setTimezone($wp_tz);
        $time_str = $dt->format('g:i A');
        $rel = human_time_diff($dt->getTimestamp(), time());
        return $time_str.' ('.$rel.' ago)';
    }

    /** ========== HTML builder (table-only; Dompdf-safe) ========== */
    protected static function build_html($headers, $body_html, $hasAttachment=false, $thumbDataUri='', $card=[]) {
        $subject = esc_html($headers['Subject'] ?: '(No subject)');
        $fromRaw = $headers['From'] ?: '';
        $toRaw   = $headers['To']   ?: '';
        $when    = esc_html(self::format_time_with_relative($headers['Date'] ?: 'now'));

        // Names
        $fromName = trim($fromRaw);
        if ( preg_match('/^"?([^"<]+)"?\s*<([^>]+)>$/', $fromRaw, $m) ) $fromName = trim($m[1]);
        $toLabel = 'to me';
        if ( $toRaw && preg_match('/^"?([^"<]+)"?\s*<([^>]+)>$/', $toRaw, $m) ) $toLabel = 'to '.esc_html(trim($m[1]));

        $avatarInitial = strtoupper(substr(preg_replace('/[^a-z]/i','',$fromName) ?: 'n', 0, 1));
        $paperclip = $hasAttachment ? '📎 ' : '';

        $css = '
          body{margin:0;background:#fff;color:#202124;font-family:Arial,Helvetica,sans-serif}
          .wrap{width:980px;margin:20px auto}
          .subject{font-size:26px;font-weight:700;letter-spacing:.2px}
          .chip{font-size:11px;background:#e8f0fe;color:#174ea6;border-radius:12px;padding:2px 8px;display:inline-block}
          .time{color:#5f6368;font-size:12px;text-align:right;white-space:nowrap}
          .avatar{width:32px;height:32px;border-radius:16px;background:#1e8e3e;color:#fff;text-align:center;line-height:32px;font-weight:700;display:inline-block}
          .from{font-weight:700;font-size:14px}
          .to{color:#5f6368;font-size:12px}
          .divider{border:none;border-top:1px dotted #e0e0e0;height:0;margin:14px 0}
          .body{font-size:14px;line-height:1.6}
          .attach-label{color:#5f6368;font-size:13px}
          .card{border:1px solid #e0e0e0;border-radius:8px;background:#fafafa}
          .card-title{font-weight:700}
          .icon-cell{width:22px}
          img{max-width:100%;height:auto}
        ';

        $html  = '<!doctype html><html><head><meta charset="utf-8"><style>'.$css.'</style></head><body>';
        $html .= '<div class="wrap">';

        // Subject + Inbox (left) | Time (right)
        $html .= '
          <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
            <tr>
              <td style="vertical-align:top;">
                <div class="subject">'.$subject.'</div>
                <div style="margin-top:6px;"><span class="chip">Inbox</span></div>
              </td>
              <td style="vertical-align:top;width:1%;text-align:right;">
                <div class="time">'.$paperclip.$when.'</div>
              </td>
            </tr>
          </table>
        ';

        // Optional invoice summary card
        $hasCard = !empty($card['title']) || !empty($card['total']) || !empty($card['due']) || !empty($card['issuer']);
        if ( $hasCard ) {
            $title = esc_html($card['title'] ?: 'Invoice details');
            $total = esc_html($card['total'] ?: '—');
            $due   = esc_html($card['due']   ?: '—');
            $iss   = esc_html($card['issuer']?: '—');

            $html .= '
            <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0 12px;margin-top:8px;">
              <tr><td>
                <table class="card" width="100%" cellspacing="0" cellpadding="10" style="border-collapse:separate;border-spacing:0 0;">
                  <tr>
                    <td colspan="4" style="border-bottom:1px solid #eaeaea;">
                      <div class="card-title">'.$title.'</div>
                    </td>
                  </tr>
                  <tr>
                    <td class="icon-cell">💳</td>
                    <td style="width:45%;vertical-align:top;">
                      <div style="color:#5f6368;font-size:12px">Total amount due</div>
                      <div style="font-size:16px;font-weight:700">'.$total.'</div>
                    </td>
                    <td class="icon-cell">🗓️</td>
                    <td style="vertical-align:top;">
                      <div style="color:#5f6368;font-size:12px">Due date</div>
                      <div style="font-size:16px;font-weight:700">'.$due.'</div>
                    </td>
                  </tr>
                  <tr>
                    <td class="icon-cell">🏷️</td>
                    <td colspan="3" style="vertical-align:top;">
                      <div style="color:#5f6368;font-size:12px">Issuer</div>
                      <div style="font-size:14px">'.$iss.'</div>
                    </td>
                  </tr>
                </table>
              </td></tr>
            </table>';
        }

        // Sender row
        $html .= '
          <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:12px;">
            <tr>
              <td style="width:40px;vertical-align:top;"><span class="avatar">'.esc_html($avatarInitial).'</span></td>
              <td style="vertical-align:top;">
                <div class="from">'.esc_html($fromName).'</div>
                <div class="to">'.esc_html($toLabel).' &#9662;</div>
              </td>
            </tr>
          </table>
        ';

        $html .= '<hr class="divider" />';
        $html .= '<div class="body">'.$body_html.'</div>';

        // Attachments strip
        if ( $hasAttachment ) {
            $thumb = $thumbDataUri ? '<img src="'.$thumbDataUri.'" alt="attachment" style="width:auto;max-width:260px;border:1px solid #eee;border-radius:4px;" />' : '';
            $html .= '
              <div style="margin-top:18px">
                <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                  <tr>
                    <td class="attach-label" style="padding:10px 0;border-bottom:1px dotted #e0e0e0;">
                      <strong>One attachment</strong> &nbsp; • &nbsp; Scanned by Gmail &#9432; &nbsp; • &nbsp; Add to Drive
                    </td>
                    <td style="text-align:right;padding:10px 0;border-bottom:1px dotted #e0e0e0;"></td>
                  </tr>
                  '.($thumb ? '<tr><td colspan="2" style="padding-top:12px">'.$thumb.'</td></tr>' : '').'
                </table>
              </div>';
        }

        $html .= '</div></body></html>';
        return $html;
    }
}
