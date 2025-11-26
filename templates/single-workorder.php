<?php
/**
 * Default single template for CPT 'workorder' (bundled by Dominus QuickBooks).
 * Loaded only if the active theme does not provide single-workorder.php.
 *
 * You can copy this file into your theme as:
 *   /single-workorder.php
 * or:
 *   /dqqb/single-workorder.php
 * to customize it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

the_post();
$post_id = get_the_ID();

// Simple helpers (safe with/without ACF)
$val = function( $key ) use ( $post_id ) {
    if ( function_exists('get_field') ) {
        $v = get_field( $key, $post_id );
        if ( is_array($v) ) return ''; // keep scalar display only here
        return (string) $v;
    }
    $v = get_post_meta( $post_id, $key, true );
    return is_array($v) ? '' : (string) $v;
};

// Engineer profile image from ACF (user_{ID}.profile_picture), fallback to WP avatar
$author_id = get_post_field('post_author', $post_id);
$engineer_name = get_the_author_meta('display_name', $author_id);

$profile_img_url = '';
if ( function_exists('get_field') ) {
    $acf_img = get_field('profile_picture', 'user_' . $author_id);
    if ( is_array($acf_img) && !empty($acf_img['url']) ) {
        $profile_img_url = esc_url($acf_img['url']);
    } elseif ( is_string($acf_img) && filter_var($acf_img, FILTER_VALIDATE_URL) ) {
        $profile_img_url = esc_url($acf_img);
    }
}
if ( !$profile_img_url ) {
    $profile_img_url = get_avatar_url($author_id, ['size' => 80]);
}

// Private comments (ACF field: private_comments or meta field: private_comments)
$private_comments = '';
if ( function_exists('get_field') ) {
    $private_comments = get_field('private_comments', $post_id);
}
if ($private_comments === '' || $private_comments === null) {
    $private_comments = get_post_meta($post_id, 'private_comments', true);
}
// Replace _x000D_ with whitespace only (not newline)
if (is_string($private_comments) && $private_comments !== '') {
    $private_comments = str_replace('_x000D_', ' ', $private_comments);
}
?>

<style>
    .wo-process{
        margin: 20px 0 10px;
        border: 2px solid lightskyblue;
        padding: 20px;
    }
    .wo-process h3{
        margin-top: 30px;
        font-stretch: expanded;
        text-align: center;
        font-size: 35px;
    }
    .wo-meta-engineer {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 10px;
    }
    .wo-meta-engineer-img {
        width: 54px;
        height: 54px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #dbeeff;
        background: #f6faff;
        flex-shrink: 0;
    }
    .wo-meta-engineer-name {
        font-size: 16px;
        font-weight: 600;
        color: #222;
    }
    .wo-private-comments {
        background: #fff5e6;
        border: 1px solid #f0e1c7;
        border-radius: 8px;
        padding: 14px 18px;
        margin: 20px 0 10px 0;
        font-size: 15px;
        color: #634d2c;
        line-height: 1.6;
        font-family: "Segoe UI", Arial, sans-serif;
    }
    .wo-private-comments h3 {
        margin-top: 0;
        margin-bottom: 8px;
        font-size: 16px;
        font-weight: 700;
        color: #846127;
        letter-spacing: 0.02em;
    }
    
    .wo-meta-engineer-name > span{
        display: block;
        font-size: 14px;
        font-weight: normal;
        font-style: italic;
    }
    
    #footer-page{display:none !important}

    .dqqb-notice {
        border-left: 4px solid #46b450;
        background: #f0fff4;
        padding: 10px 14px;
        margin: 10px 0;
        border-radius: 4px;
        color: #094d36;
    }
    .dqqb-error {
        border-left: 4px solid #cc3b3b;
        background: #fff5f5;
        padding: 10px 14px;
        margin: 10px 0;
        border-radius: 4px;
        color: #7a1f1f;
    }
</style>
<main id="primary" class="site-main dqqb-single-workorder" style="max-width:95%;margin:0 auto">
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <header class="entry-header" style="margin:24px 0;">
            <?php if ( has_excerpt() ): ?>
                <p class="entry-subtitle" style="color:#555;margin-top:6px;"><?php echo esc_html( get_the_excerpt() ); ?></p>
            <?php endif; ?>
        </header>

        <div class="entry-content-wrapper clearfix">
            <h3 style="font-size:30px;font-weight:700;text-align:center;margin:40px 0">Summary</h3>
            
            

            <div class="flex_column av-4t6g44-f63431ee47f87602bdd4dcb7a3f161e8 av_two_fifth  avia-builder-el-3  el_after_av_three_fifth  avia-builder-el-last  flex_column_div  column-top-margin">
                <div class="wo-meta-engineer">
                    <img class="wo-meta-engineer-img" src="<?php echo $profile_img_url; ?>" alt="Engineer photo" />
                    <span class="wo-meta-engineer-name"><?php echo esc_html( $engineer_name ); ?><span>Field Engineer</span></span>
                </div>
                <div class="wo-meta-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                    <?php
                    // A few handy bits users often want under the timeline; adjust/extend as needed.
                    $pairs = [
                        'Product ID'             => $val('installed_product_id'),
                        'Work Order ID'          => $val('work_order_number'),
                        'State'                  => $val('wo_state'),
                        'City'                   => $val('wo_city'),
                        'Account'               => $val('wo_location'),

                    ];
                    foreach ( $pairs as $label => $value ) {
                        if ( $value === '' ) continue;
                        echo '<div class="wo-meta-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;">';
                        echo '<div style="font-weight:600;color:#222;margin-bottom:4px;">' . esc_html( $label ) . '</div>';
                        echo '<div style="color:#333;">' . esc_html( is_numeric($value) ? number_format( (float)$value, 2 ) : (string)$value ) . '</div>';
                        echo '</div>';
                    }
                    ?>
                </div>
                <?php if ( $private_comments && trim((string)$private_comments) !== '' ) : ?>
                    <div class="wo-private-comments">
                        <h3>Private Comments</h3>
                        <div>
                            <?php
                                // Supports basic line breaks, but escapes HTML
                                echo nl2br( esc_html( (string)$private_comments ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
                
            <div class="flex_column av-4t6g44-f63431ee47f87602bdd4dcb7a3f161e8 av_two_fifth  avia-builder-el-3  el_after_av_three_fifth  avia-builder-el-last  flex_column_div  column-top-margin">
                <h3 style="display:block">Invoice Details:</h3>
                <div class="wo-meta-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                    
                    <?php
                    // A few handy bits users often want under the timeline; adjust/extend as needed.
                    $pairs = [
                        'Invoice No'             => $val('wo_invoice_no'),
                        'Total Billed'           => $val('wo_total_billed'),
                        'Balance Due'            => $val('wo_balance_due'),
                    ];
                    foreach ( $pairs as $label => $value ) {
                        if ( $value === '' ) continue;
                        echo '<div class="wo-meta-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;">';
                        echo '<div style="font-weight:600;color:#222;margin-bottom:4px;">' . esc_html( $label ) . '</div>';
                        echo '<div style="color:#333;">' . esc_html( is_numeric($value) ? number_format( (float)$value, 2 ) : (string)$value ) . '</div>';
                        echo '</div>';
                    }
                    ?>
                </div>
                <hr>
                <h3 style="display:block">Lead Details:</h3>
                <div class="wo-meta-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                    
                    <?php
                    // A few handy bits users often want under the timeline; adjust/extend as needed.
                    $pairs = [
                        'Leads'             => $val('wo_leads'),
                        'Category'           => $val('wo_lead_category'),
                    ];
                    foreach ( $pairs as $label => $value ) {
                        if ( $value === '' ) continue;
                        echo '<div class="wo-meta-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;">';
                        echo '<div style="font-weight:600;color:#222;margin-bottom:4px;">' . esc_html( $label ) . '</div>';
                        echo '<div style="color:#333;">' . esc_html( is_numeric($value) ? number_format( (float)$value, 2 ) : (string)$value ) . '</div>';
                        echo '</div>';
                    }
                    ?>
                </div>

                <hr>
                <h3 style="display:block">Customer Details:</h3>
                <div class="wo-meta-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                    
                    <?php
                    $pairs = [
                        'Name'             => $val('wo_contact_name'),
                        'Address'           => $val('wo_contact_address'),
                        'Email'             => $val('wo_contact_email'),
                        'Number'           => $val('wo_service_contact_number'),
                    ];
                    foreach ( $pairs as $label => $value ) {
                        if ( $value === '' ) continue;
                        echo '<div class="wo-meta-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;">';
                        echo '<div style="font-weight:600;color:#222;margin-bottom:4px;">' . esc_html( $label ) . '</div>';
                        echo '<div style="color:#333;">' . esc_html( is_numeric($value) ? number_format( (float)$value, 2 ) : (string)$value ) . '</div>';
                        echo '</div>';
                    }
                    ?>
                </div>

                   <div style="margin: 15px 0;">
                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                            <?php wp_nonce_field( 'dqqb_send_quotation', 'dqqb_send_quotation_nonce' ); ?>
                            <input type="hidden" name="action" value="dqqb_send_quotation_nonajax" />
                            <input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
                            <button type="submit" style="background:#0073aa;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:600;cursor:pointer;">
                                Email Customer Quotation
                            </button>
                        </form>
                    </div>
            </div>
            
       

            <div class="flex_column av-4hfcms-f9d3836b86fa268345cf372c3ccd7dd8 av_one_full  avia-builder-el-5  el_after_av_two_fifth  avia-builder-el-last  first flex_column_div  ">

                <section class="wo-process" aria-labelledby="wo-process-heading" style="margin:20px 0 10px;">

                    <?php
                    // Render the infographic timeline (loads its own CSS)
                    echo do_shortcode('[workorder_timeline descriptions="1"]');
                    ?>
                </section>
            </div>
        </div>
        <div class="entry-content" style="margin:30px 0;">
            <?php
            // Show the post content if you use the editor to add additional information
            the_content();
            ?>
        </div>
    </article>
</main>
<?php
get_footer();