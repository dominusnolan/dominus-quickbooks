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

// Check if user can edit this post (for inline editing)
$can_edit = is_user_logged_in() && current_user_can( 'edit_post', $post_id );

// Fields that should always be displayed (even when empty) and which are editable
$always_show_fields = [
    'installed_product_id' => 'Product ID',
    'work_order_number'    => 'Work Order ID',
    'wo_type_of_work'      => 'Type of Work',
    'wo_state'             => 'State',
    'wo_city'              => 'City',
    'wo_location'          => 'Location',
];
$editable_fields = [ 'installed_product_id', 'wo_type_of_work', 'wo_state', 'wo_city', 'wo_location' ];
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
    /* Inline edit controls */
    .dqqb-inline-display {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .dqqb-inline-value {
        color: #333;
        flex: 1;
    }
    .dqqb-inline-edit-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 2px 6px;
        color: #666;
        font-size: 14px;
        opacity: 0.7;
        transition: opacity 0.2s;
    }
    .dqqb-inline-edit-btn:hover {
        opacity: 1;
        color: #0073aa;
    }
    .dqqb-inline-editor {
        display: none;
    }
    .dqqb-inline-input {
        width: 100%;
        padding: 6px 8px;
        border: 1px solid #0073aa;
        border-radius: 4px;
        font-size: 14px;
        margin-bottom: 6px;
        box-sizing: border-box;
    }
    textarea.dqqb-inline-input {
        min-height: 60px;
        resize: vertical;
        font-family: inherit;
    }
    select.dqqb-inline-input {
        background: #fff;
        cursor: pointer;
    }
    .dqqb-inline-input:focus {
        outline: none;
        border-color: #005177;
        box-shadow: 0 0 0 1px #005177;
    }
    .dqqb-inline-actions {
        display: flex;
        gap: 6px;
        margin-bottom: 4px;
    }
    .dqqb-inline-save,
    .dqqb-inline-cancel {
        padding: 4px 10px;
        font-size: 12px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
    }
    .dqqb-inline-save {
        background: #0073aa;
        color: #fff;
    }
    .dqqb-inline-save:hover {
        background: #005177;
    }
    .dqqb-inline-save:disabled {
        background: #ccc;
        cursor: not-allowed;
    }
    .dqqb-inline-cancel {
        background: #f0f0f0;
        color: #333;
    }
    .dqqb-inline-cancel:hover {
        background: #ddd;
    }
    .dqqb-inline-cancel:disabled {
        background: #eee;
        color: #999;
        cursor: not-allowed;
    }
    .dqqb-inline-status {
        font-size: 12px;
        min-height: 16px;
    }
    .dqqb-status-saving {
        color: #666;
    }
    .dqqb-status-success {
        color: #46b450;
    }
    .dqqb-status-error {
        color: #cc3b3b;
    }
    /* Rich-text editor styles */
    .dqqb-rich-editor-wrapper {
        margin-top: 10px;
    }
    .dqqb-rich-toolbar {
        display: flex;
        gap: 4px;
        margin-bottom: 6px;
        padding: 4px;
        background: #f8f9fa;
        border: 1px solid #e5e7eb;
        border-radius: 4px 4px 0 0;
        border-bottom: none;
    }
    .dqqb-rich-toolbar button {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 3px;
        padding: 4px 8px;
        cursor: pointer;
        font-size: 13px;
        min-width: 28px;
        transition: background 0.15s;
    }
    .dqqb-rich-toolbar button:hover {
        background: #e9ecef;
    }
    .dqqb-rich-toolbar button:active {
        background: #dee2e6;
    }
    .dqqb-rich-editor {
        min-height: 100px;
        max-height: 300px;
        overflow-y: auto;
        padding: 10px 12px;
        border: 1px solid #0073aa;
        border-radius: 0 0 4px 4px;
        background: #fff;
        font-size: 14px;
        line-height: 1.6;
        outline: none;
    }
    .dqqb-rich-editor:focus {
        border-color: #005177;
        box-shadow: 0 0 0 1px #005177;
    }
    .dqqb-rich-editor a {
        color: #0073aa;
        text-decoration: underline;
    }
    .dqqb-private-comments-display {
        display: block;
    }
    .dqqb-private-comments-edit-btn {
        vertical-align: middle;
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
                    // Get US states from the template class for state dropdown
                    $us_states = class_exists( 'DQ_Workorder_Template' ) ? DQ_Workorder_Template::get_us_states() : [];

                    // Build the meta fields array with field keys for inline editing
                    $meta_fields = [
                        'installed_product_id' => [ 'label' => 'Product ID', 'value' => $val('installed_product_id') ],
                        'work_order_number'    => [ 'label' => 'Work Order ID', 'value' => $val('work_order_number') ],
                        'wo_type_of_work'      => [ 'label' => 'Type of Work', 'value' => $val('wo_type_of_work') ],
                        'wo_state'             => [ 'label' => 'State', 'value' => $val('wo_state') ],
                        'wo_city'              => [ 'label' => 'City', 'value' => $val('wo_city') ],
                        'wo_location'          => [ 'label' => 'Location', 'value' => $val('wo_location') ],
                    ];

                    foreach ( $meta_fields as $field_key => $field_data ) {
                        $label = $field_data['label'];
                        $value = $field_data['value'];
                        $is_always_show = array_key_exists( $field_key, $always_show_fields );
                        $is_editable = in_array( $field_key, $editable_fields, true );

                        // Skip if value is empty and not in the always-show list
                        if ( $value === '' && ! $is_always_show ) {
                            continue;
                        }

                        // For state field, display full state name; otherwise show raw value
                        if ( $field_key === 'wo_state' && $value !== '' && isset( $us_states[ strtoupper( $value ) ] ) ) {
                            $display_value = $us_states[ strtoupper( $value ) ];
                        } else {
                            $display_value = $value !== '' ? (string)$value : '';
                        }

                        // Add data attributes for editable fields
                        $card_attrs = '';
                        if ( $is_editable && $can_edit ) {
                            $card_attrs = ' data-field="' . esc_attr( $field_key ) . '" data-post-id="' . esc_attr( $post_id ) . '"';
                        }

                        echo '<div class="wo-meta-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;"' . $card_attrs . '>';
                        echo '<div style="font-weight:600;color:#222;margin-bottom:4px;">' . esc_html( $label ) . '</div>';

                        if ( $is_editable && $can_edit ) {
                            // Display with edit button
                            echo '<div class="dqqb-inline-display">';
                            echo '<span class="dqqb-inline-value">' . ( $display_value !== '' ? esc_html( $display_value ) : '—' ) . '</span>';
                            echo '<button type="button" class="dqqb-inline-edit-btn" title="Edit">&#9998;</button>';
                            echo '</div>';

                            // Hidden editor with data-original to preserve raw value
                            echo '<div class="dqqb-inline-editor" data-original="' . esc_attr( $value ) . '">';

                            // Use select dropdown for state field
                            if ( $field_key === 'wo_state' && ! empty( $us_states ) ) {
                                echo '<select class="dqqb-inline-input">';
                                echo '<option value="">— Select —</option>';
                                foreach ( $us_states as $state_code => $state_name ) {
                                    $selected = ( strtoupper( $value ) === $state_code ) ? ' selected' : '';
                                    echo '<option value="' . esc_attr( $state_code ) . '"' . $selected . '>' . esc_html( $state_name ) . '</option>';
                                }
                                echo '</select>';
                            } else {
                                echo '<input type="text" class="dqqb-inline-input" value="' . esc_attr( $value ) . '" />';
                            }

                            echo '<div class="dqqb-inline-actions">';
                            echo '<button type="button" class="dqqb-inline-save">Save</button>';
                            echo '<button type="button" class="dqqb-inline-cancel">Cancel</button>';
                            echo '</div>';
                            echo '<div class="dqqb-inline-status"></div>';
                            echo '</div>';
                        } else {
                            // Just display the value
                            echo '<div style="color:#333;">' . ( $display_value !== '' ? esc_html( $display_value ) : '—' ) . '</div>';
                        }

                        echo '</div>';
                    }
                    ?>
                </div>
                <div class="wo-private-comments" data-field="private_comments" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                    <h3>Private Comments<?php if ( $can_edit ) : ?><button type="button" class="dqqb-inline-edit-btn dqqb-private-comments-edit-btn" title="Edit" style="margin-left:8px;">&#9998;</button><?php endif; ?></h3>
                    <div class="dqqb-inline-display dqqb-private-comments-display">
                        <?php if ( $private_comments && trim((string)$private_comments) !== '' ) : ?>
                            <?php
                                // Display stored HTML content safely using wp_kses_post
                                echo wp_kses_post( $private_comments );
                            ?>
                        <?php else : ?>
                            <span class="dqqb-inline-value">—</span>
                        <?php endif; ?>
                    </div>
                    <?php if ( $can_edit ) : ?>
                    <div class="dqqb-inline-editor dqqb-rich-editor-wrapper">
                        <textarea class="dqqb-original-value" style="display:none;"><?php echo esc_textarea( $private_comments ); ?></textarea>
                        <div class="dqqb-rich-toolbar">
                            <button type="button" data-command="bold" title="Bold"><b>B</b></button>
                            <button type="button" data-command="italic" title="Italic"><i>I</i></button>
                            <button type="button" data-command="underline" title="Underline"><u>U</u></button>
                            <button type="button" data-command="createLink" title="Link">&#128279;</button>
                            <button type="button" data-command="removeFormat" title="Clear Formatting">&#10006;</button>
                        </div>
                        <div class="dqqb-rich-editor dqqb-inline-input" contenteditable="true"><?php echo wp_kses_post( $private_comments ); ?></div>
                        <div class="dqqb-inline-actions">
                            <button type="button" class="dqqb-inline-save">Save</button>
                            <button type="button" class="dqqb-inline-cancel">Cancel</button>
                        </div>
                        <div class="dqqb-inline-status"></div>
                    </div>
                    <?php endif; ?>
                </div>
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
                    // Lead detail fields - always shown, editable with dropdown when user can edit
                    $lead_fields = [
                        'wo_leads'         => [ 'label' => 'Leads', 'value' => $val('wo_leads') ],
                        'wo_lead_category' => [ 'label' => 'Category', 'value' => $val('wo_lead_category') ],
                    ];

                    foreach ( $lead_fields as $field_key => $field_data ) {
                        $label = $field_data['label'];
                        $value = $field_data['value'];

                        // Get ACF field choices if available
                        $choices = [];
                        if ( function_exists( 'get_field_object' ) ) {
                            $field_object = get_field_object( $field_key, $post_id );
                            if ( $field_object && ! empty( $field_object['choices'] ) ) {
                                $choices = $field_object['choices'];
                            }
                        }

                        // Display label (from ACF choices) or raw value
                        $display_value = $value;
                        if ( $value !== '' && ! empty( $choices ) && isset( $choices[ $value ] ) ) {
                            $display_value = $choices[ $value ];
                        }
                        $display_value = $display_value !== '' ? esc_html( (string) $display_value ) : '—';

                        // Add data attributes for editable fields when user can edit
                        $card_attrs = '';
                        if ( $can_edit ) {
                            $card_attrs = ' data-field="' . esc_attr( $field_key ) . '" data-post-id="' . esc_attr( $post_id ) . '"';
                        }

                        echo '<div class="wo-meta-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;"' . $card_attrs . '>';
                        echo '<div style="font-weight:600;color:#222;margin-bottom:4px;">' . esc_html( $label ) . '</div>';

                        if ( $can_edit ) {
                            // Display with edit button
                            echo '<div class="dqqb-inline-display">';
                            echo '<span class="dqqb-inline-value">' . $display_value . '</span>';
                            echo '<button type="button" class="dqqb-inline-edit-btn" title="Edit">&#9998;</button>';
                            echo '</div>';

                            // Hidden editor with select dropdown
                            echo '<div class="dqqb-inline-editor" data-original="' . esc_attr( $value ) . '">';
                            if ( ! empty( $choices ) ) {
                                echo '<select class="dqqb-inline-input">';
                                echo '<option value="">— Select —</option>';
                                foreach ( $choices as $choice_key => $choice_label ) {
                                    $selected = ( $value === $choice_key ) ? ' selected' : '';
                                    echo '<option value="' . esc_attr( $choice_key ) . '"' . $selected . '>' . esc_html( $choice_label ) . '</option>';
                                }
                                echo '</select>';
                            } else {
                                // Fallback to text input if no choices available
                                echo '<input type="text" class="dqqb-inline-input" value="' . esc_attr( $value ) . '" />';
                            }
                            echo '<div class="dqqb-inline-actions">';
                            echo '<button type="button" class="dqqb-inline-save">Save</button>';
                            echo '<button type="button" class="dqqb-inline-cancel">Cancel</button>';
                            echo '</div>';
                            echo '<div class="dqqb-inline-status"></div>';
                            echo '</div>';
                        } else {
                            // Just display the value
                            echo '<div style="color:#333;">' . $display_value . '</div>';
                        }

                        echo '</div>';
                    }
                    ?>
                </div>

                <hr>
                <h3 style="display:block">Customer Details:</h3>
                <div class="wo-meta-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                    
                    <?php
                    // Customer detail fields - always shown, editable when user can edit
                    // Define input types for each field
                    $customer_fields = [
                        'wo_contact_name'           => [ 'label' => 'Name', 'value' => $val('wo_contact_name'), 'type' => 'text' ],
                        'wo_contact_address'        => [ 'label' => 'Address', 'value' => $val('wo_contact_address'), 'type' => 'textarea' ],
                        'wo_contact_email'          => [ 'label' => 'Email', 'value' => $val('wo_contact_email'), 'type' => 'email' ],
                        'wo_service_contact_number' => [ 'label' => 'Number', 'value' => $val('wo_service_contact_number'), 'type' => 'tel' ],
                    ];

                    foreach ( $customer_fields as $field_key => $field_data ) {
                        $label = $field_data['label'];
                        $value = $field_data['value'];
                        $input_type = $field_data['type'];
                        $display_value = $value !== '' ? esc_html( (string)$value ) : '—';

                        // Add data attributes for editable fields when user can edit
                        $card_attrs = '';
                        if ( $can_edit ) {
                            $card_attrs = ' data-field="' . esc_attr( $field_key ) . '" data-post-id="' . esc_attr( $post_id ) . '"';
                        }

                        echo '<div class="wo-meta-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;"' . $card_attrs . '>';
                        echo '<div style="font-weight:600;color:#222;margin-bottom:4px;">' . esc_html( $label ) . '</div>';

                        if ( $can_edit ) {
                            // Display with edit button
                            echo '<div class="dqqb-inline-display">';
                            echo '<span class="dqqb-inline-value">' . $display_value . '</span>';
                            echo '<button type="button" class="dqqb-inline-edit-btn" title="Edit">&#9998;</button>';
                            echo '</div>';

                            // Hidden editor with appropriate input type
                            echo '<div class="dqqb-inline-editor" data-original="' . esc_attr( $value ) . '">';
                            if ( $input_type === 'textarea' ) {
                                echo '<textarea class="dqqb-inline-input">' . esc_textarea( $value ) . '</textarea>';
                            } else {
                                echo '<input type="' . esc_attr( $input_type ) . '" class="dqqb-inline-input" value="' . esc_attr( $value ) . '" />';
                            }
                            echo '<div class="dqqb-inline-actions">';
                            echo '<button type="button" class="dqqb-inline-save">Save</button>';
                            echo '<button type="button" class="dqqb-inline-cancel">Cancel</button>';
                            echo '</div>';
                            echo '<div class="dqqb-inline-status"></div>';
                            echo '</div>';
                        } else {
                            // Just display the value
                            echo '<div style="color:#333;">' . $display_value . '</div>';
                        }

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
                                Email to customer
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