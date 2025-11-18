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
            <div class="wo-meta-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                <?php
                // A few handy bits users often want under the timeline; adjust/extend as needed.
                $pairs = [
                    'Product ID'             => $val('installed_product_id'),
                    'State'                  => $val('wo_state'),
                    'Location'               => $val('wo_location'),
                    'Engineer'               => get_the_author_meta('display_name', get_post_field('post_author', $post_id)),
                    'State'                  => $val('wo_state'),
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