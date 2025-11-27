<?php
/**
 * Plugin Name: Dominus QuickBooks
 * Plugin URI:  https://example.com
 * Description: Sync Work Orders (CPT) with QuickBooks Online via OAuth2.
 * Version:     0.2.0
 * Author:      Nolan Tan
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------
define( 'DQQB_VERSION', '0.2.0' );
define( 'DQQB_PATH', plugin_dir_path( __FILE__ ) );
define( 'DQQB_URL', plugin_dir_url( __FILE__ ) );

// -----------------------------------------------------------------------------
// Includes
// -----------------------------------------------------------------------------
require_once DQQB_PATH . 'includes/helpers.php';
require_once DQQB_PATH . 'includes/class-dq-logger.php';
require_once DQQB_PATH . 'includes/class-dq-settings.php';
require_once DQQB_PATH . 'includes/class-dq-auth.php';
require_once DQQB_PATH . 'includes/class-dq-api.php';
require_once DQQB_PATH . 'includes/class-dq-invoice.php';
require_once DQQB_PATH . 'includes/acf-invoice-addresses.php';
require_once DQQB_PATH . 'includes/class-dq-metabox.php';
require_once DQQB_PATH . 'includes/class-dq-csv-importer.php';
require_once DQQB_PATH . 'includes/class-dq-qi-sync.php';
require_once DQQB_PATH . 'includes/class-dq-qi-metabox.php';
require_once DQQB_PATH . 'includes/class-dq-qi-csv-import.php';
require_once DQQB_PATH . 'includes/class-dq-qi-admin-table.php';
// Financial Reports
require_once DQQB_PATH . 'includes/class-dq-financial-report.php';
// Payroll CPT and Management
require_once DQQB_PATH . 'includes/class-dq-payroll.php';
// Work Order Reports
require_once DQQB_PATH . 'includes/class-dq-workorder-report.php';
// NEW: Front-end Workorder Timeline
require_once DQQB_PATH . 'includes/class-dq-workorder-timeline.php';
// NEW: Workorder single template fallback (adds single-workorder.php front-end)
require_once DQQB_PATH . 'includes/class-dq-workorder-template.php';

// NEW: Workorder single template fallback (adds single-invoice.php front-end)
require_once DQQB_PATH . 'includes/class-qi-invoice-template.php';

// NEW: Invoice list shortcode with AJAX pagination
require_once DQQB_PATH . 'includes/class-dq-invoice-list.php';

// NEW: Workorder table shortcode with AJAX pagination
require_once DQQB_PATH . 'includes/class-dq-workorder-table.php';

// NEW: Front-end admin dashboard
require_once DQQB_PATH . 'includes/class-dq-dashboard.php';

// -----------------------------------------------------------------------------
// Initialize Plugin
// -----------------------------------------------------------------------------
add_action( 'plugins_loaded', function() {

    if ( ! function_exists( 'acf_get_field_groups' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Dominus QuickBooks</strong> requires Advanced Custom Fields (ACF) plugin to be installed and active.</p></div>';
        });
        return;
    }

    // Defer CPT check until init
    add_action( 'init', function() {
        if ( ! post_type_exists( 'workorder' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-warning"><p><strong>Dominus QuickBooks:</strong> Custom post type <code>workorder</code> not found. Please register it before syncing.</p></div>';
            });
        }
        if ( ! post_type_exists( 'quickbooks_invoice' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-warning"><p><strong>Dominus QuickBooks:</strong> Custom post type <code>quickbooks_invoice</code> not found. Please register it before syncing.</p></div>';
            });
        }
    });

    if ( class_exists( 'DQ_Settings' ) ) DQ_Settings::init();
    if ( class_exists( 'DQ_Auth' ) ) DQ_Auth::init();
    if ( class_exists( 'DQ_API' ) ) DQ_API::init();
    if ( class_exists( 'DQ_Metabox' ) ) DQ_Metabox::init();
    if ( class_exists( 'DQ_QI_Metabox' ) ) DQ_QI_Metabox::init();
    if ( class_exists( 'DQ_Payroll' ) ) DQ_Payroll::init(); // Payroll CPT
    if ( class_exists( 'DQ_Financial_Report' ) ) DQ_Financial_Report::init(); // NEW
    
    if ( class_exists( 'DQ_Workorder_Timeline' ) ) DQ_Workorder_Timeline::init(); // NEW
    if ( class_exists( 'DQ_Invoice_List' ) ) DQ_Invoice_List::init(); // NEW
    if ( class_exists( 'DQ_Workorder_Table' ) ) DQ_Workorder_Table::init(); // NEW
    if ( class_exists( 'DQ_Dashboard' ) ) DQ_Dashboard::init(); // NEW: Front-end dashboard
    
    if ( class_exists( 'DQ_Workorder_Template' ) ) DQ_Workorder_Template::init(); // NEW
});

// -----------------------------------------------------------------------------
// Activation / Deactivation
// -----------------------------------------------------------------------------
register_activation_hook( __FILE__, function() {
    // Ensure uploads directory exists for logs
    $upload = wp_upload_dir();
    $logfile = trailingslashit( $upload['basedir'] ) . 'dq-log.txt';
    if ( ! file_exists( $logfile ) ) {
        file_put_contents( $logfile, '[' . date('c') . "] Log initialized\n" );
    }

    // Add custom role 'engineer' if not present
    if ( ! get_role( 'engineer' ) ) {
        add_role(
            'engineer',
            'Engineer',
            [
                'read' => true,
            ]
        );
    }
    // Ensure capability for viewing reports
    $admin = get_role( 'administrator' );
    if ( $admin && ! $admin->has_cap( 'view_financial_reports' ) ) {
        $admin->add_cap( 'view_financial_reports' );
    }
    $eng = get_role( 'engineer' );
    if ( $eng && ! $eng->has_cap( 'view_financial_reports' ) ) {
        $eng->add_cap( 'view_financial_reports' );
    }
});

register_deactivation_hook( __FILE__, function() {
    delete_transient( 'dq_oauth_state' );
    // (Optional) Remove capability from roles on deactivation:
    $admin = get_role( 'administrator' );
    if ( $admin ) $admin->remove_cap( 'view_financial_reports' );
    $eng = get_role( 'engineer' );
    if ( $eng ) $eng->remove_cap( 'view_financial_reports' );
});

// -----------------------------------------------------------------------------
// Admin Footer Debug (optional)
// -----------------------------------------------------------------------------
if ( defined('WP_DEBUG' ) && WP_DEBUG ) {
    add_action( 'admin_footer', function() {
        echo '<!-- Dominus QuickBooks v' . esc_html( DQQB_VERSION ) . ' loaded -->';
    });
}