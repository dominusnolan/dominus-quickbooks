<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers the 'qbo_customers' taxonomy for CPT 'quickbooks_invoice'
 * and adds an ACF field 'qi_customer' (taxonomy select).
 * 
 * The field returns the term name (not ID) to match against QBO customer DisplayName.
 */
class DQ_QI_QBO_Customers_Taxonomy {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
        add_action( 'acf/init', [ __CLASS__, 'register_acf_field_group' ] );
    }

    /**
     * Register qbo_customers taxonomy.
     */
    public static function register_taxonomy() {
        // Don't re-register if already present
        if ( taxonomy_exists( 'qbo_customers' ) ) return;

        $labels = [
            'name'                       => _x( 'QBO Customers', 'taxonomy general name', 'dqqb' ),
            'singular_name'              => _x( 'QBO Customer', 'taxonomy singular name', 'dqqb' ),
            'search_items'               => __( 'Search QBO Customers', 'dqqb' ),
            'popular_items'              => __( 'Popular QBO Customers', 'dqqb' ),
            'all_items'                  => __( 'All QBO Customers', 'dqqb' ),
            'edit_item'                  => __( 'Edit QBO Customer', 'dqqb' ),
            'view_item'                  => __( 'View QBO Customer', 'dqqb' ),
            'update_item'                => __( 'Update QBO Customer', 'dqqb' ),
            'add_new_item'               => __( 'Add New QBO Customer', 'dqqb' ),
            'new_item_name'              => __( 'New QBO Customer Name', 'dqqb' ),
            'separate_items_with_commas' => __( 'Separate QBO customers with commas', 'dqqb' ),
            'add_or_remove_items'        => __( 'Add or remove QBO customers', 'dqqb' ),
            'choose_from_most_used'      => __( 'Choose from the most used QBO customers', 'dqqb' ),
            'not_found'                  => __( 'No QBO customers found.', 'dqqb' ),
            'menu_name'                  => __( 'QBO Customers', 'dqqb' ),
        ];

        $args = [
            'labels'            => $labels,
            'public'            => false,          // Internal usage; set true if you need archives
            'show_ui'           => true,           // Show in admin
            'show_in_menu'      => true,
            'show_in_nav_menus' => false,
            'show_tagcloud'     => false,
            'show_admin_column' => true,
            'hierarchical'      => false,          // Change to true if you want a hierarchy
            'rewrite'           => false,          // Disable front-end rewrite unless needed
            'query_var'         => true,
            'meta_box_cb'       => null,           // ACF will handle selection; hide default box
        ];

        register_taxonomy( 'qbo_customers', [ 'quickbooks_invoice' ], $args );
    }

    /**
     * Register ACF field group for selecting QBO Customer.
     */
    public static function register_acf_field_group() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        // Prevent duplicate group creation
        if ( function_exists( 'acf_get_field_group' ) ) {
            $existing = acf_get_field_group( 'group_dq_qi_customer' );
            if ( $existing ) return;
        }

        acf_add_local_field_group( [
            'key'    => 'group_dq_qi_customer',
            'title'  => 'Invoice: QBO Customer',
            'fields' => [
                [
                    'key'               => 'field_dq_qi_customer',
                    'label'             => 'Customer',
                    'name'              => 'qi_customer',
                    'type'              => 'taxonomy',
                    'taxonomy'          => 'qbo_customers',
                    'field_type'        => 'select',     // could be 'radio' or 'multi_select'
                    'allow_null'        => 1,
                    'add_term'          => 1,            // allow creating new terms in selector
                    'save_terms'        => 1,            // link term to post
                    'load_terms'        => 1,
                    'return_format'     => 'object',     // Return term object so we can access the name
                    'instructions'      => 'Select a QBO Customer for this invoice. The customer name must match exactly with the DisplayName in QuickBooks Online.',
                    'wrapper'           => [
                        'width' => '',
                        'class' => '',
                        'id'    => '',
                    ],
                    'conditional_logic' => 0,
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'quickbooks_invoice',
                    ],
                ],
            ],
            'position'              => 'normal',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'active'                => true,
            'description'           => '',
        ] );
    }
}

DQ_QI_QBO_Customers_Taxonomy::init();
