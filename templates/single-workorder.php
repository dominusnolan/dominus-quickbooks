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
// Normalize _x000D_ newlines to whitespace
if (is_string($private_comments) && $private_comments !== '') {
    $private_comments = str_replace('_x000D_', "\n", $private_comments);
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
    
    #footer-page{display:none !important}
</style>
<main id="primary" class="site-main dqqb-single-workorder" style="max-width:95%;margin:0 auto">
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <header class="entry-header" style="margin:24px 0;">
            <h1 class="entry-title"><?php echo esc_html( get_the_title() ); ?></h1>
            <?php if ( has_excerpt() ): ?>
                <p class="entry-subtitle" style="color:#555;margin-top:6px;"><?php echo esc_html( get_the_excerpt() ); ?></p>
            <?php endif; ?>
        </header>

        <section class="wo-meta" style="margin:26px 0;">
            <h3 style="font-size:18px;font-weight:700;margin:0 0 10px;">Summary</h3>
            Field Engineer:
            <div class="wo-meta-engineer">
                <img class="wo-meta-engineer-img" src="<?php echo $profile_img_url; ?>" alt="Engineer photo" />
                <span class="wo-meta-engineer-name"><?php echo esc_html( $engineer_name ); ?></span>
            </div>
            <div class="wo-meta-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                <?php
                // A few handy bits users often want under the timeline; adjust/extend as needed.
                $pairs = [
                    'Product ID'             => $val('installed_product_id'),
                    'State'                  => $val('wo_state'),
                    'Location'               => $val('wo_location'),
                    'City'                   => $val('wo_city'),
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
        </section>

        <?php if ( $private_comments && trim((string)$private_comments) !== '' ) : ?>
            <section class="wo-private-comments">
                <h3>Private Comments</h3>
                <div>
                    <?php
                        // Supports basic line breaks, but escapes HTML
                        echo nl2br( esc_html( (string)$private_comments ) );
                    ?>
                </div>
            </section>
        <?php endif; ?>
        
        <section class="wo-process" aria-labelledby="wo-process-heading" style="margin:20px 0 10px;">
           <H3 style="margin-top:30px; font-face:uppercase; text-align: center: font-size: 25px">Work Order Progress</H3>

            <?php
            // Render the infographic timeline (loads its own CSS)
            echo do_shortcode('[workorder_timeline descriptions="1"]');
            ?>
        </section>

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