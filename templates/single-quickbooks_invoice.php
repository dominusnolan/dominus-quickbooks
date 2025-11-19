<?php
/**
 * Default single template for CPT 'quickbooks_invoice'.
 * Loaded if the active theme does not supply single-quickbooks_invoice.php.
 *
 * You may copy this file into your theme as:
 *   /single-quickbooks_invoice.php
 * or:
 *   /dqqb/single-quickbooks_invoice.php
 * for overrides.
 */

if (!defined('ABSPATH')) exit;

get_header();
the_post();
$post_id = get_the_ID();

// Helper for getting ACF or meta
$acfval = function($key) use($post_id) {
    if(function_exists('get_field')) $v = get_field($key, $post_id);
    else $v = get_post_meta($post_id, $key, true);
    return $v;
};
$acftext = function($key) use($acfval) {
    $v = $acfval($key);
    if(is_array($v)) return implode(', ', array_filter($v));
    return (string)$v;
};

// Work Orders
$work_orders = $acfval('qi_wo_number');
if (!is_array($work_orders)) {
    if ($work_orders instanceof WP_Post) $work_orders = [ $work_orders ];
    elseif (is_numeric($work_orders)) $work_orders = [ intval($work_orders) ];
    elseif (is_string($work_orders) && $work_orders !== '') $work_orders = [ $work_orders ];
    else $work_orders = [];
}

// Field Engineers for Work Orders
$engineers = [];
foreach ($work_orders as $wo) {
    $wo_id = ($wo instanceof WP_Post) ? $wo->ID : (is_array($wo)&&isset($wo['ID'])?$wo['ID']:intval($wo));
    if (!$wo_id) $wo_id = intval($wo);
    if (!$wo_id) continue;
    $eng_id = get_post_field('post_author', $wo_id);
    if($eng_id && !array_key_exists($eng_id, $engineers)) {
        $eng_name = get_the_author_meta('display_name', $eng_id);
        $profile_img = '';
        if(function_exists('get_field')) {
            $acf_img = get_field('profile_picture', 'user_'.$eng_id);
            if(is_array($acf_img) && !empty($acf_img['url'])) $profile_img = esc_url($acf_img['url']);
            elseif(is_string($acf_img) && filter_var($acf_img, FILTER_VALIDATE_URL)) $profile_img = esc_url($acf_img);
        }
        if(!$profile_img) $profile_img = get_avatar_url($eng_id, ['size'=>54]);
        $engineers[$eng_id] = [
            'name'=>$eng_name,
            'img'=>$profile_img
        ];
    }
}

// Invoice status
$balance = (float)$acfval('qi_balance_due');
$total = (float)$acfval('qi_total_billed');
$status = ($balance > 0 ? 'UNPAID' : 'PAID');

// Invoice repeater table
function render_invoice_table($post_id, $acf_field='qi_invoice') {
    if (!function_exists('have_rows')) return;
    if (!have_rows($acf_field, $post_id)) return;
    echo '<table class="qi-invoice-table" style="width:100%;border-collapse:separate;border-spacing:0;margin:20px auto 30px;background:#fff;box-shadow:0 2px 18px rgba(112,146,183,0.07);border-radius:14px;overflow:hidden">';
    echo '<thead><tr>
        <th>Activity</th>
        <th>Description</th>
        <th>Quantity</th>
        <th>Rate</th>
        <th>Amount</th>
    </tr></thead><tbody>';
    $row_idx = 0;
    while(have_rows($acf_field, $post_id)) {
        the_row();
        printf('<tr style="background:%s">',
            $row_idx % 2 ? "#f4f8fd" : "#f8fbff"
        );
        foreach(['activity','description','quantity','rate','amount'] as $i=>$sub) {
            $val = get_sub_field($sub);
            $td_class = '';
            if ($sub === 'amount') $td_class = ' style="font-weight:600;color:#0b4963;text-align:right;"';
            elseif ($i === 4) $td_class = ' style="text-align:right;"';
            echo '<td'.$td_class.'>'.esc_html(is_numeric($val)?number_format((float)$val,2):$val).'</td>';
        }
        echo '</tr>';
        $row_idx++;
    }
    echo '</tbody></table>';
}

// Email snippet image
$email_snippet = $acfval('qi_invoice_email_snippet');
$snippet_img_url = '';
if(is_array($email_snippet) && !empty($email_snippet['url'])) $snippet_img_url = esc_url($email_snippet['url']);
elseif(is_string($email_snippet) && filter_var($email_snippet, FILTER_VALIDATE_URL)) $snippet_img_url = esc_url($email_snippet);

?>
<style>
.qi-summary-section h2{font-size:30px;margin:18px 0 6px;font-weight:700;text-align:center}
.qi-summary-table{width:100%;max-width:680px;margin:0 auto 30px;background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(50,80,120,0.07);padding:26px 24px;display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
.qi-summary-table .label{font-weight:700;color:#144477;margin-bottom:4px}
.qi-summary-table .value{color:#333;font-size:17px}
.qi-engineer-card{display:flex;align-items:center;gap:15px;margin-top:9px}
.qi-engineer-img{width:45px;height:45px;border-radius:50%;object-fit:cover;background:#ececec;border:2px solid #cfe0ff}
.qi-section-title{font-size:22px;font-weight:700;color:#226;text-align:left;margin:30px 0 12px}
/* BEAUTIFIED Invoice Details Table */
.qi-invoice-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin: 20px auto 30px;
    background: #fff;
    box-shadow: 0 2px 18px rgba(112,146,183,0.07);
    border-radius: 14px;
    overflow: hidden;
}

.qi-invoice-table thead th {
    background: linear-gradient(90deg, #e3f3fd 60%, #f8fbff 100%);
    color: #144477;
    font-weight: 600;
    font-size: 17px;
    padding: 15px 10px;
    border-bottom: 2px solid #e4e6ea;
    text-align: left;
}
.qi-invoice-table th:last-child,
.qi-invoice-table td:last-child {
    text-align: right;
}

.qi-invoice-table tbody tr {
    transition: background 0.20s;
}
.qi-invoice-table tbody tr:nth-child(even) {
    background: #f4f8fd;
}
.qi-invoice-table tbody tr:nth-child(odd) {
    background: #f8fbff;
}
.qi-invoice-table tbody tr:hover {
    background: #e7f6fc;
}

.qi-invoice-table td {
    padding: 13px 10px;
    font-size: 16px;
    color: #222d3a;
    border-bottom: 1px solid #e9eef2;
}
.qi-invoice-table td:last-child {
    font-weight: 600;
    color: #0b4963;
}

.qi-invoice-table tr:last-child td {
    border-bottom: none;
}
@media (max-width: 700px) {
    .qi-invoice-table thead th,.qi-invoice-table td { padding: 8px 5px; font-size: 13px;}
    .qi-invoice-table { border-radius: 7px;}
}
@media (max-width: 480px) {
    .qi-invoice-table { font-size:11.5px;}
}

/* If you want to highlight subtotal/footer, you can add a class,
   e.g., <tr class="qi-invoice-table-footer"> and style it */
.qi-invoice-table-footer td {
    background: #dcf0ff !important;
    font-weight: bold;
    color: #104080;
    font-size: 18px;
    border-top: 2px solid #bee4ff;
}

/* Section headings unchanged */
.qi-section-title{font-size:22px;font-weight:700;color:#226;text-align:left;margin:30px 0 12px}
.qi-email-snippet{margin:30px 0;text-align:center;}
.qi-email-snippet img{max-width:440px;border-radius:10px;box-shadow:0 2px 10px rgba(80,100,120,0.16)}
.qi-direct-labor-title{font-weight:700;color:#a6320c;margin:18px 0 9px;font-size:19px;}

.qi-details-section{max-width:95%;margin: 20px auto}
#footer-page{display:none !important}
</style>
<main class="single-qi-invoice">
    <section class="qi-summary-section">
        <h2>Invoice Summary</h2>
        <div class="qi-summary-table">
            <div>
                <div class="label">Invoice Number</div>
                <div class="value"><?php echo esc_html($acftext('qi_invoice_no')); ?></div>
            </div>
            <div>
                <div class="label">Work Order IDs</div>
                <div class="value">
                <?php
                $workorder_labels = [];
                foreach($work_orders as $wo){
                    $wo_id = ($wo instanceof WP_Post) ? $wo->ID : (is_array($wo)&&(isset($wo['ID'])?$wo['ID']:null));
                    if(!$wo_id) $wo_id = intval($wo);
                    if(!$wo_id) continue;
                    $url = get_edit_post_link($wo_id);
                    $label = get_the_title($wo_id);
                    $workorder_labels[] = $url ? '<a href="'.esc_url($url).'">'.esc_html($label).'</a>' : esc_html($label);
                }
                echo $workorder_labels ? implode(', ', $workorder_labels) : '<span style="color:#999;">—</span>';
                ?>
                </div>
            </div>
            <div>
                <div class="label">Field Engineer(s)</div>
                <div class="value">
                    <?php
                    foreach($engineers as $eng) {
                        echo '<span class="qi-engineer-card"><img class="qi-engineer-img" src="'.esc_url($eng['img']).'" alt="" /> '.esc_html($eng['name']).'</span>';
                    }
                    ?>
                </div>
            </div>
            <div>
                <div class="label">Total</div>
                <div class="value">$<?php echo number_format((float)$acfval('qi_total_billed'),2); ?></div>
            </div>
            <div>
                <div class="label">Paid</div>
                <div class="value">$<?php echo number_format((float)$acfval('qi_total_paid'),2); ?></div>
            </div>
            <div>
                <div class="label">Balance</div>
                <div class="value">$<?php echo number_format((float)$acfval('qi_balance_due'),2); ?></div>
            </div>
            <div>
                <div class="label">Status</div>
                <div class="value" style="font-weight:700;"><?php echo $status=='PAID'
                    ?'<span style="background:#e7f6ec;color:#22863a;padding:2px 10px;border-radius:3px;">PAID</span>'
                    :'<span style="background:#fbeaea;color:#d63638;padding:2px 10px;border-radius:3px;">UNPAID</span>'; ?></div>
            </div>
            <div>
                <div class="label">Invoice Date</div>
                <div class="value"><?php echo esc_html($acftext('qi_invoice_date')); ?></div>
            </div>
            <div>
                <div class="label">Due Date</div>
                <div class="value"><?php echo esc_html($acftext('qi_due_date')); ?></div>
            </div>
            <div>
                <div class="label">Terms</div>
                <div class="value"><?php echo esc_html($acftext('qi_terms')); ?></div>
            </div>
            <div>
                <div class="label">Bill To</div>
                <div class="value"><?php echo nl2br(esc_html($acftext('qi_bill_to'))); ?></div>
            </div>
            <div>
                <div class="label">Ship To</div>
                <div class="value"><?php echo nl2br(esc_html($acftext('qi_ship_to'))); ?></div>
            </div>
            <div>
                <div class="label">Customer</div>
                <div class="value"><?php echo esc_html($acftext('qi_customer')); ?></div>
            </div>
        </div>
    </section>
    <section class="qi-details-section">
        <div class="qi-section-title">Invoice Details</div>
        <?php render_invoice_table($post_id,'qi_invoice'); ?>
        
        <div class="qi-direct-labor-title">Direct Labor Cost</div>
        <?php render_invoice_table($post_id,'qi_invoice'); ?>
        <!-- To filter only direct labor rows, implement filtering here if required -->
    </section>

    <?php if ($snippet_img_url): ?>
    <section class="qi-email-snippet">
        <h3>Email Snippet</h3>
        <img src="<?php echo esc_url($snippet_img_url); ?>" alt="Email Snippet" />
    </section>
    <?php endif; ?>
    <div class="entry-content" style="margin:30px 0;">
        <?php the_content(); ?>
    </div>
</main>
<?php get_footer(); ?>