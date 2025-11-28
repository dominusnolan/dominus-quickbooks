<?php
/**
 * Default single template for CPT 'quickbooks_invoice'.
 * Loaded if the active theme does not supply single-quickbooks_invoice.php.
 */

if (!defined('ABSPATH')) exit;

get_header();
the_post();
$post_id = get_the_ID();

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

$work_orders = $acfval('qi_wo_number');
if (!is_array($work_orders)) {
    if ($work_orders instanceof WP_Post) $work_orders = [ $work_orders ];
    elseif (is_numeric($work_orders)) $work_orders = [ intval($work_orders) ];
    elseif (is_string($work_orders) && $work_orders !== '') $work_orders = [ $work_orders ];
    else $work_orders = [];
}
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
$balance = (float)$acfval('qi_balance_due');
$total = (float)$acfval('qi_total_billed');
$status = ($balance > 0 ? 'UNPAID' : 'PAID');

function render_invoice_table($post_id, $acf_field='qi_invoice') {
    if (!function_exists('have_rows')) return;
    if (!have_rows($acf_field, $post_id)) return;
    echo '<table class="qi-invoice-table">';
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

function render_other_expenses_table($post_id, $acf_field='qi_other_expenses') {
    if (!function_exists('have_rows')) return;
    if (!have_rows($acf_field, $post_id)) return;
    echo '<table class="qi-invoice-table">';
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
        $activity    = get_sub_field('activity');
        $description = get_sub_field('description');
        $quantity    = get_sub_field('quantity');
        $rate        = get_sub_field('rate');
        $amount      = get_sub_field('amount');
        if (is_null($activity))    $activity    = '';
        if (is_null($description)) $description = '';
        if (is_null($quantity))    $quantity    = '';
        if (is_null($rate))        $rate        = '';
        if (is_null($amount))      $amount      = '';
        foreach([
            'activity'    => $activity,
            'description' => $description,
            'quantity'    => $quantity,
            'rate'        => $rate,
            'amount'      => $amount
        ] as $i=>$val) {
            $td_class = '';
            if ($i === 'amount') $td_class = ' style="font-weight:600;color:#0b4963;text-align:right;"';
            elseif ($i === 'quantity' || $i === 'rate' || $i === 'amount') $td_class .= ' style="text-align:right;"';
            echo '<td'.$td_class.'>';
            echo esc_html(is_numeric($val)?number_format((float)$val,2):$val);
            echo '</td>';
        }
        echo '</tr>';
        $row_idx++;
    }
    echo '</tbody></table>';
}

$email_snippet = $acfval('qi_invoice_email_snippet');
$snippet_img_url = '';
if(is_array($email_snippet) && !empty($email_snippet['url'])) $snippet_img_url = esc_url($email_snippet['url']);
elseif(is_string($email_snippet) && filter_var($email_snippet, FILTER_VALIDATE_URL)) $snippet_img_url = esc_url($email_snippet);

?>
<style>
.qi-main-wrapper {
    display: flex;
    flex-direction: row;
    align-items: flex-start;
    gap:40px;
    max-width:1200px;
    margin: 0 auto 20px;
}
.qi-summary-section {
    flex:1 1 0;
    min-width:0;
}
.qi-sidebar-section {
    flex:0 0 340px;
    min-width:280px;
    max-width:370px;
    margin-top:2px;
        padding-top: 25px;
}
.qi-sidebar-content {background:#fff;border-radius:14px;box-shadow:0 2px 18px rgba(112,146,183,0.13);padding:18px 24px;margin-top:15px;}
.qi-sidebar-list {margin:0 0 0 0;padding:0;list-style:none;}
.qi-sidebar-list .label{font-weight:700;color:#226;margin-bottom:2px;display:block;}
.qi-sidebar-list .value{margin-bottom:11px;display:block;color:#333;font-size:16px;word-break:break-all;}
.qi-sidebar-list .status-paid {background:#e7f6ec;color:#22863a;padding:2px 10px;border-radius:3px;}
.qi-sidebar-list .status-unpaid {background:#fbeaea;color:#d63638;padding:2px 10px;border-radius:3px;}
.qi-email-snippet{margin:0 0 18px 0;text-align:center;}
.qi-email-snippet h3{font-size:20px;margin-bottom:12px;}
.qi-email-snippet img{max-width:330px;border-radius:10px;box-shadow:0 2px 10px rgba(80,100,120,0.16);cursor:pointer;transition: box-shadow 0.2s;}
.qi-email-snippet img:active,.qi-email-snippet img:focus {box-shadow:0 0 0 3px #2991de;}
.qi-email-lightbox {
    display:none;
    position:fixed;
    z-index:99999;
    left:0;top:0;right:0;bottom:0;
    width:100vw;height:100vh;
    background:rgba(0,0,0,0.7);
}
.qi-email-lightbox img {
    position:absolute;
    top:50%;left:50%;
    max-width:96vw;
    max-height:92vh;
    transform:translate(-50%,-50%);
    border-radius:13px;
    box-shadow:0 8px 24px #3338;
    background:#fff;
}
.qi-email-lightbox .qi-email-lb-close {
    position:absolute;
    top:32px;right:40px;
    font-size:44px;
    color:#fff;
    background:transparent;
    border:none;
    cursor:pointer;
    font-weight:700;
    z-index:100003;
    line-height:1;
}
@media (max-width:1024px){.qi-main-wrapper{flex-direction:column;gap:22px;}.qi-sidebar-section{max-width:100%;margin:0 auto;}}
@media (max-width:900px){.qi-sidebar-section{max-width:100%;margin:0 auto;}}
.qi-summary-section h2{font-size:30px;margin:18px 0 6px;font-weight:700;text-align:center}
.qi-summary-table{width:100%;max-width:680px;margin:0 auto 30px;background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(50,80,120,0.07);padding:26px 24px;display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
.qi-summary-table .label{font-weight:700;color:#144477;margin-bottom:4px}
.qi-summary-table .value{color:#333;font-size:17px}
.qi-engineer-card{display:flex;align-items:center;gap:15px;margin-top:9px}
.qi-engineer-img{width:45px;height:45px;border-radius:50%;object-fit:cover;background:#ececec;border:2px solid #cfe0ff}
.qi-section-title{font-size:22px;font-weight:700;color:#226;text-align:left;margin:30px 0 12px}
.qi-invoice-table {
    width: 100%;
 
    margin: 20px auto 30px;
    background: #fff;

    overflow: hidden;
}
.qi-invoice-table thead th {
    background: #0996a0;
    color: #fff;
    padding: 14px 16px;
    text-align: left;
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.qi-invoice-table th:last-child,
.qi-invoice-table td:last-child { text-align: right; }
.qi-invoice-table tbody tr {transition: background 0.20s;}
.qi-invoice-table tbody tr:nth-child(even) {background: #f4f8fd;}
.qi-invoice-table tbody tr:nth-child(odd) {background: #f8fbff;}
.qi-invoice-table tbody tr:hover {background: #e7f6fc;}
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
.qi-invoice-table tr:last-child td {border-bottom: none;}
.qi-direct-labor-title{font-weight:700;color:#a6320c;margin:18px 0 9px;font-size:19px;}
@media (max-width:700px){.qi-invoice-table thead th,.qi-invoice-table td { padding: 8px 5px; font-size: 13px;}}
@media (max-width:480px){.qi-invoice-table { font-size:11.5px;}}
.qi-details-section{max-width:95%;margin: 20px auto}
#footer-page{display:none !important}
</style>
<?php if ($snippet_img_url): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const img = document.getElementById("qi-email-snapshot-img");
    const lightbox = document.getElementById("qi-email-lightbox");
    const closeBtn = document.getElementById("qi-email-lb-close");
    if(img && lightbox && closeBtn) {
        img.addEventListener("click", function(e){
            e.preventDefault();
            lightbox.style.display = "block";
        });
        closeBtn.addEventListener("click", function(){
            lightbox.style.display = "none";
        });
        lightbox.addEventListener("click", function(ev){
            if(ev.target === lightbox){
                lightbox.style.display = "none";
            }
        });
        document.addEventListener("keydown", function(ev){
            if(ev.key === "Escape"){ lightbox.style.display = "none"; }
        });
    }
});
</script>
<?php endif; ?>
<main class="single-qi-invoice">
    <div class="qi-main-wrapper">
        <section class="qi-summary-section">
            <h2 style="margin-bottom:40px">Invoice Summary</h2>
            <div class="qi-summary-table">
                <div>
                    <div class="label">Work Order IDs</div>
                    <div class="value">
                    <?php
                    $workorder_labels = [];
                    foreach($work_orders as $wo){
                        $wo_id = ($wo instanceof WP_Post) ? $wo->ID : (is_array($wo)&&(isset($wo['ID'])?$wo['ID']:null));
                        if(!$wo_id) $wo_id = intval($wo);
                        if(!$wo_id) continue;
                        $url = get_permalink($wo_id);
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
            </div>

            <div class="qi-details-section">
                <div class="qi-section-title">Invoice Details</div>
                <?php render_invoice_table($post_id,'qi_invoice'); ?>

              
            </div>
        </section>
        <aside class="qi-sidebar-section">
            <?php if ($snippet_img_url): ?>
            <section class="qi-email-snippet">
                <h3>Email Snippet</h3>
                <a href="#" tabindex="0">
                    <img id="qi-email-snapshot-img" src="<?php echo esc_url($snippet_img_url); ?>" alt="Email Snippet" />
                </a>
            </section>
            <div id="qi-email-lightbox" class="qi-email-lightbox">
                <button id="qi-email-lb-close" class="qi-email-lb-close" type="button" aria-label="Close">&times;</button>
                <img src="<?php echo esc_url($snippet_img_url); ?>" alt="Email Snippet Large" />
            </div>
            <?php endif; ?>
            <div class="qi-sidebar-content">
                <ul class="qi-sidebar-list">
                    <li><span class="label">Invoice Number</span><span class="value"><?php echo esc_html($acftext('qi_invoice_no')); ?></span></li>
                    <li><span class="label">Total</span><span class="value">$<?php echo number_format((float)$acfval('qi_total_billed'),2); ?></span></li>
                    <li><span class="label">Paid</span><span class="value">$<?php echo number_format((float)$acfval('qi_total_paid'),2); ?></span></li>
                    <li><span class="label">Balance</span><span class="value">$<?php echo number_format((float)$acfval('qi_balance_due'),2); ?></span></li>
                    <li><span class="label">Status</span>
                        <span class="value">
                            <?php echo $status=='PAID'
                                ?'<span class="status-paid">PAID</span>'
                                :'<span class="status-unpaid">UNPAID</span>'; ?>
                        </span>
                    </li>
                    <li><span class="label">Invoice Date</span><span class="value"><?php echo esc_html($acftext('qi_invoice_date')); ?></span></li>
                    <li><span class="label">Due Date</span><span class="value"><?php echo esc_html($acftext('qi_due_date')); ?></span></li>
                    <li><span class="label">Terms</span><span class="value"><?php echo esc_html($acftext('qi_terms')); ?></span></li>
                    <li><span class="label">Bill To</span><span class="value"><?php echo nl2br(esc_html($acftext('qi_bill_to'))); ?></span></li>
                    <li><span class="label">Ship To</span><span class="value"><?php echo nl2br(esc_html($acftext('qi_ship_to'))); ?></span></li>
                    <li><span class="label">Customer</span><span class="value"><?php echo esc_html($acftext('qi_customer')); ?></span></li>
                </ul>
            </div>
        </aside>
    </div>
    <div class="entry-content" style="margin:30px 0;">
        <?php the_content(); ?>
    </div>
</main>
<?php get_footer(); ?>