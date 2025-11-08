<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * DQ_Gmail_Emailshot
 * - Builds a Gmail-like HTML (subject, from, time, body)
 * - Renders to PDF via Dompdf
 * - Converts PDF -> PNG via Imagick
 * - Saves to Media and ACF field 'wo_email_screenshot'
 */
class DQ_Gmail_Emailshot {

    public static function init() {
        add_action('wp_ajax_dq_fetch_gmail_emailshot', [__CLASS__, 'ajax_fetch']);
    }

    public static function ajax_fetch() {
        check_ajax_referer('dq_fetch_gmail_emailshot');
        if ( ! current_user_can('edit_posts') ) wp_send_json_error('no_perm');

        $post_id   = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ( ! $post_id ) wp_send_json_error('no_post');

        $invoice_no = get_post_meta($post_id, 'wo_invoice_no', true);
        if ( ! $invoice_no ) wp_send_json_error('No ACF wo_invoice_no found.');

        $q = 'subject:"Invoice Number '.$invoice_no.'"';
        $message = self::search_message($q);
        if ( is_wp_error($message) ) wp_send_json_error($message->get_error_message());
        if ( ! $message ) wp_send_json_error('No Gmail message found for that invoice number.');

        $r = self::render_and_attach($post_id, $message);
        if ( is_wp_error($r) ) wp_send_json_error($r->get_error_message());
        wp_send_json_success($r);
    }

    /** ------- Gmail helpers (reuse token from settings) ------- */
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
        $id  = $list['messages'][0]['id'];
        $msg = self::api_get('messages/'.$id, ['format'=>'full']);
        if ( is_wp_error($msg) ) return $msg;
        return $msg; // full payload
    }

    /** ------- Render flow ------- */
    protected static function extract_headers($payload) {
        $h = ['Subject'=>'', 'From'=>'', 'To'=>'', 'Date'=>''];
        if ( empty($payload['payload']['headers']) ) return $h;
        foreach ($payload['payload']['headers'] as $hdr) {
            $name = $hdr['name'] ?? '';
            $val  = $hdr['value'] ?? '';
            if ( isset($h[$name]) ) $h[$name] = $val;
        }
        return $h;
    }

    protected static function get_body_html($payload) {
        // Try to find text/html part; fall back to text/plain
        $html = '';
        $plain = '';
        $stack = [ $payload['payload'] ];
        while ($stack) {
            $p = array_pop($stack);
            if ( !empty($p['parts']) ) {
                foreach ($p['parts'] as $pp) $stack[] = $pp;
            }
            $mime = $p['mimeType'] ?? '';
            $data = $p['body']['data'] ?? '';
            if ( $data ) {
                $decoded = base64_decode(strtr($data, '-_', '+/'));
                if ( stripos($mime, 'text/html') !== false ) $html = $decoded;
                if ( stripos($mime, 'text/plain') !== false ) $plain = nl2br(esc_html($decoded));
            }
        }
        if ( ! $html && $plain ) $html = '<div style="white-space:pre-wrap;font-family:Arial,Helvetica,sans-serif">'.$plain.'</div>';
        // Basic sanitation
        $allowed = [
            'a'=>['href'=>[], 'target'=>[], 'rel'=>[]],
            'p'=>[], 'br'=>[], 'strong'=>[], 'em'=>[], 'span'=>['style'=>[]], 'div'=>['style'=>[]],
            'ul'=>[], 'ol'=>[], 'li'=>[], 'table'=>['border'=>[], 'cellpadding'=>[], 'cellspacing'=>[], 'style'=>[]],
            'thead'=>[], 'tbody'=>[], 'tr'=>[], 'td'=>['style'=>[]], 'th'=>['style'=>[]], 'img'=>['src'=>[], 'alt'=>[], 'width'=>[], 'height'=>[], 'style'=>[]],
            'h1'=>[], 'h2'=>[], 'h3'=>[], 'h4'=>[], 'h5'=>[], 'h6'=>[]
        ];
        $html = wp_kses($html, $allowed);
        return $html ?: '<p>(No content)</p>';
    }

    protected static function format_time_with_relative($dateHeader) {
        // Gmail Date header is RFC2822 in sender timezone; parse to timestamp
        $ts = strtotime($dateHeader);
        if ( ! $ts ) $ts = time();
        $wp_tz = wp_timezone(); // site timezone
        $dt = new DateTime('@'.$ts);
        $dt->setTimezone($wp_tz);
        $time_str = $dt->format('g:i A');
        // relative
        $diff = time() - $dt->getTimestamp();
        $rel  = human_time_diff($dt->getTimestamp(), time());
        return $time_str.' ('.$rel.' ago)';
    }

    protected static function build_html($headers, $body_html, $hasAttachment = false) {
    $subject = esc_html($headers['Subject'] ?: '(No subject)');
    $fromRaw = $headers['From'] ?: '';
    $toRaw   = $headers['To']   ?: '';
    $when    = esc_html(self::format_time_with_relative($headers['Date'] ?: 'now'));

    // Parse "Name <email>"
    $fromName = trim($fromRaw);
    if (preg_match('/^"?([^"<]+)"?\s*<([^>]+)>$/', $fromRaw, $m)) { $fromName = trim($m[1]); }
    $toLabel = 'to me';
    if ($toRaw && preg_match('/^"?([^"<]+)"?\s*<([^>]+)>$/', $toRaw, $m)) $toLabel = 'to '.esc_html(trim($m[1]));

    // Avatar initial
    $avatarInitial = strtoupper(substr(preg_replace('/[^a-z]/i', '', $fromName) ?: 'n', 0, 1));
    $paperclip = $hasAttachment ? '📎 ' : '';

    // NOTE: Use tables + inline styles (best compatibility with Dompdf)
    $css = '
      body{margin:0;background:#fff;color:#202124;font-family:Arial,Helvetica,sans-serif}
      .wrap{width:980px;margin:20px auto}
      .subject{font-size:28px;font-weight:700;letter-spacing:.2px}
      .chip{font-size:11px;background:#e8f0fe;color:#174ea6;border-radius:12px;padding:2px 8px;display:inline-block}
      .time{color:#5f6368;font-size:12px;text-align:right;white-space:nowrap}
      .avatar{width:32px;height:32px;border-radius:16px;background:#1e8e3e;color:#fff;
              text-align:center;line-height:32px;font-weight:700;display:inline-block}
      .from{font-weight:700;font-size:14px}
      .to{color:#5f6368;font-size:12px}
      .divider{border:none;border-top:1px dotted #e0e0e0;height:0;margin:14px 0}
      .body{font-size:14px;line-height:1.6}
      img{max-width:100%;height:auto}
    ';

    $html  = '<!doctype html><html><head><meta charset="utf-8"><style>'.$css.'</style></head><body>';
    $html .= '<div class="wrap">';

    // Header row: Subject left, Time right (table ensures Dompdf keeps them on one line)
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

    // Sender row: Avatar left, Name + "to me" right
    $html .= '
      <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:10px;">
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

    $html .= '</div></body></html>';
    return $html;
}




    protected static function render_and_attach($post_id, $msg) {
        // Require Dompdf
        $autoload = dirname(__DIR__).'/vendor/autoload.php';
        if ( ! file_exists($autoload) ) {
            return new WP_Error('dompdf_missing', 'Dompdf is not installed. Run composer require dompdf/dompdf.');
        }
        require_once $autoload;

        $headers = self::extract_headers($msg);
        $body    = self::get_body_html($msg);
        $hasAtt  = self::has_attachment($msg);
        $html    = self::build_html($headers, $body, $hasAtt);

        // Render HTML -> PDF
        try {
            $options = new Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $dompdf = new Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            // large canvas similar to desktop Gmail
            $dompdf->setPaper([0,0,900,1400]); // width x height points
            $dompdf->render();
            $pdfBytes = $dompdf->output();
        } catch (\Throwable $e) {
            return new WP_Error('dompdf_error', 'Failed to render PDF: '.$e->getMessage());
        }

        // Write PDF temp
        $invoice_no = get_post_meta($post_id, 'wo_invoice_no', true) ?: 'email';
        $tmpPdf = wp_tempnam('emailshot-'.$invoice_no.'.pdf');
        file_put_contents($tmpPdf, $pdfBytes);

        // Convert first page -> PNG
        if ( ! class_exists('Imagick') ) {
            return new WP_Error('imagick_missing', 'Imagick is required to convert PDF to PNG.');
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

        // Upload to Media with safe name
        $final_name = 'emailshot-'.$invoice_no.'.png';
        $upload = wp_upload_bits($final_name, null, file_get_contents($pngPath));
        if ( ! empty($upload['error']) ) return new WP_Error('upload_error', $upload['error']);

        // Ensure MIME allowed
        $ft = wp_check_filetype_and_ext($upload['file'], $final_name);
        $mime = $ft['type'] ?: 'image/png';

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title'     => 'Email Screenshot '.$invoice_no,
            'post_content'   => '',
            'post_status'    => 'inherit'
        ], $upload['file'], $post_id);
        if ( is_wp_error($attachment_id) || ! $attachment_id ) {
            return new WP_Error('attach_fail', 'Failed to create attachment.');
        }

        require_once ABSPATH.'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $meta);

        // Save to ACF field
        update_field('wo_email_screenshot', $attachment_id, $post_id);

        return [
            'message_id' => $msg['id'],
            'attachment_id' => $attachment_id,
            'file_url' => wp_get_attachment_url($attachment_id),
        ];
    }
    
    protected static function has_attachment($payload) {
        if (empty($payload['payload'])) return false;
        $stack = [$payload['payload']];
        while ($stack) {
            $p = array_pop($stack);
            if (!empty($p['parts'])) foreach ($p['parts'] as $pp) $stack[] = $pp;
            if (!empty($p['filename']) && !empty($p['body']['attachmentId'])) return true;
        }
        return false;
    }
}
