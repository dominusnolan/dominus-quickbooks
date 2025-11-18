<?php
/**
 * Single Workorder Template
 * 
 * Fallback template for displaying single workorder posts.
 * Shows the timeline, summary section, and post content.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">

        <?php
        while ( have_posts() ) :
            the_post();
            $post_id = get_the_ID();
            ?>

            <article id="post-<?php echo esc_attr( $post_id ); ?>" <?php post_class(); ?>>

                <header class="entry-header">
                    <h1 class="entry-title"><?php the_title(); ?></h1>
                </header>

                <div class="entry-content">

                    <!-- Workorder Process Timeline -->
                    <section class="workorder-timeline-section" style="margin: 2rem 0; padding: 1.5rem; background: #f9f9f9; border-radius: 8px;">
                        <h2 style="margin-top: 0; color: #333; font-size: 1.5rem;">Workorder Process</h2>
                        <?php echo do_shortcode( '[workorder_timeline descriptions="1"]' ); ?>
                    </section>

                    <!-- Workorder Summary -->
                    <section class="workorder-summary" style="margin: 2rem 0; padding: 1.5rem; background: #fff; border: 1px solid #ddd; border-radius: 8px;">
                        <h2 style="margin-top: 0; color: #333; font-size: 1.5rem;">Summary</h2>
                        
                        <?php
                        // Helper to safely get field value
                        $get_field_value = function( $key, $default = '' ) use ( $post_id ) {
                            if ( function_exists( 'get_field' ) ) {
                                $value = get_field( $key, $post_id );
                                return $value ?: $default;
                            }
                            return get_post_meta( $post_id, $key, true ) ?: $default;
                        };

                        // Get summary data
                        $state = '';
                        $state_terms = get_the_terms( $post_id, 'state' );
                        if ( $state_terms && ! is_wp_error( $state_terms ) ) {
                            $state = esc_html( $state_terms[0]->name );
                        }

                        $scheduled_service = $get_field_value( 'schedule_date_time', 'Not scheduled' );
                        $engineer_id = $get_field_value( 'wo_engineer_author' );
                        $engineer_name = '';
                        if ( $engineer_id ) {
                            $engineer = get_userdata( $engineer_id );
                            if ( $engineer ) {
                                $engineer_name = esc_html( $engineer->display_name );
                            }
                        }

                        $invoice_no = $get_field_value( 'wo_invoice_no', 'N/A' );
                        $total_billed = $get_field_value( 'wo_total_billed', 0 );
                        $balance_due = $get_field_value( 'wo_balance_due', 0 );
                        ?>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            
                            <?php if ( $state ) : ?>
                            <div>
                                <strong>State:</strong><br>
                                <?php echo esc_html( $state ); ?>
                            </div>
                            <?php endif; ?>

                            <?php if ( $scheduled_service ) : ?>
                            <div>
                                <strong>Scheduled Service:</strong><br>
                                <?php echo esc_html( $scheduled_service ); ?>
                            </div>
                            <?php endif; ?>

                            <?php if ( $engineer_name ) : ?>
                            <div>
                                <strong>Engineer:</strong><br>
                                <?php echo $engineer_name; ?>
                            </div>
                            <?php endif; ?>

                            <div>
                                <strong>Invoice No:</strong><br>
                                <?php echo esc_html( $invoice_no ); ?>
                            </div>

                            <?php if ( $total_billed > 0 ) : ?>
                            <div>
                                <strong>Total Billed:</strong><br>
                                $<?php echo number_format( floatval( $total_billed ), 2 ); ?>
                            </div>
                            <?php endif; ?>

                            <?php if ( $balance_due > 0 ) : ?>
                            <div>
                                <strong>Balance Due:</strong><br>
                                $<?php echo number_format( floatval( $balance_due ), 2 ); ?>
                            </div>
                            <?php endif; ?>

                        </div>
                    </section>

                    <!-- Post Content -->
                    <?php if ( get_the_content() ) : ?>
                    <section class="workorder-content" style="margin: 2rem 0;">
                        <h2 style="color: #333; font-size: 1.5rem;">Details</h2>
                        <?php the_content(); ?>
                    </section>
                    <?php endif; ?>

                </div><!-- .entry-content -->

            </article>

        <?php endwhile; ?>

    </main>
</div>

<?php
get_footer();
