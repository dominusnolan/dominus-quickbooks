<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers the 'purchase_order' taxonomy for CPT 'quickbooks_invoice'
 * and adds an ACF field 'qi_purchase_order' (taxonomy select).
 */
class DQ_QI_Purchase_Order_Taxonomy {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
        add_action( 'acf/init', [ __CLASS__, 'register_acf_field_group' ] );
    }

    /**
     * Register purchase_order taxonomy.
     */
    public static function register_taxonomy() {
        // Don't re-register if already present
        if ( taxonomy_exists( 'purchase_order' ) ) return;

        $labels = [
            'name'                       => _x( 'Purchase Orders', 'taxonomy general name', 'dqqb' ),
            'singular_name'              => _x( 'Purchase Order', 'taxonomy singular name', 'dqqb' ),
            'search_items'               => __( 'Search Purchase Orders', 'dqqb' ),
            'popular_items'              => __( 'Popular Purchase Orders', 'dqqb' ),
            'all_items'                  => __( 'All Purchase Orders', 'dqqb' ),
            'edit_item'                  => __( 'Edit Purchase Order', 'dqqb' ),
            'view_item'                  => __( 'View Purchase Order', 'dqqb' ),
            'update_item'                => __( 'Update Purchase Order', 'dqqb' ),
            'add_new_item'               => __( 'Add New Purchase Order', 'dqqb' ),
            'new_item_name'              => __( 'New Purchase Order Name', 'dqqb' ),
            'separate_items_with_commas' => __( 'Separate purchase orders with commas', 'dqqb' ),
            'add_or_remove_items'        => __( 'Add or remove purchase orders', 'dqqb' ),
            'choose_from_most_used'      => __( 'Choose from the most used purchase orders', 'dqqb' ),
            'not_found'                  => __( 'No purchase orders found.', 'dqqb' ),
            'menu_name'                  => __( 'Purchase Orders', 'dqqb' ),
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

        register_taxonomy( 'purchase_order', [ 'quickbooks_invoice' ], $args );
    }

    /**
     * Register ACF field group for selecting Purchase Order.
     */
    public static function register_acf_field_group() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        // Prevent duplicate group creation
        if ( function_exists( 'acf_get_field_group' ) ) {
            $existing = acf_get_field_group( 'group_dq_qi_purchase_order' );
            if ( $existing ) return;
        }

        acf_add_local_field_group( [
            'key'    => 'group_dq_qi_purchase_order',
            'title'  => 'Invoice: Purchase Order',
            'fields' => [
                [
                    'key'               => 'field_dq_qi_purchase_order',
                    'label'             => 'Purchase Order',
                    'name'              => 'qi_purchase_order',
                    'type'              => 'taxonomy',
                    'taxonomy'          => 'purchase_order',
                    'field_type'        => 'select',     // could be 'radio' or 'multi_select'
                    'allow_null'        => 1,
                    'add_term'          => 1,            // allow creating new terms in selector
                    'save_terms'        => 1,            // link term to post
                    'load_terms'        => 1,
                    'return_format'     => 'id',         // 'id', 'object' or 'name'
                    'instructions'      => 'Select a Purchase Order for this invoice.',
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

DQ_QI_Purchase_Order_Taxonomy::init();