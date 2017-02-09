<?php
/*
  Admin page for getting Basecamp settings
*/

// Init admin page
add_action('admin_init', 'lsp_b2w_admin_init');

/**
 * Init admin page, registering settings
 */
function lsp_b2w_admin_init() {
	register_setting( 'lsp_settings_group', 'lsp_basecamp_user' );
	register_setting( 'lsp_settings_group', 'lsp_basecamp_password' );
	register_setting( 'lsp_settings_group', 'lsp_basecamp_id' );
}



// Create custom plugin settings menu
add_action('admin_menu', 'lsp_b2w_create_menu');

/**
 * Creates admin page
 */
function lsp_b2w_create_menu() {
  global $ic_fc_admin_page;
  $ic_fc_admin_page = add_plugins_page('Basecamp2WP', 'Basecamp2WP', 'administrator', 'basecamp2WP', 'lsp_b2w_settings_page');
}



/**
 * Displays admin page
 */
function lsp_b2w_settings_page() {
	if (isset($_GET['basecamp2WP']) && $_GET['basecamp2WP'] == 'start') {
		$bc_wp = new Basecamp2WP();
		return;
	}
?>
<div class="wrap lsp_wifi">
<h2><?php _e('Basecamp2WP', 'displaynone'); ?></h2>
<?php if( isset($_GET['settings-updated']) ) { ?>
    <div id="message" class="updated">
        <p><strong><?php _e('Options updated.', 'displaynone'); ?></strong></p>
    </div>
<?php } ?>
<form method="post" action="options.php">
    <?php settings_fields( 'lsp_settings_group' ); ?>
    <?php do_settings_sections( 'lsp_settings_page' ); ?>
    <h3><?php _e('Basecamps settings', 'displaynone'); ?></h3>
    <table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('User', 'displaynone'); ?></th>
				<td><input type="text" name="lsp_basecamp_user" value="<?php echo get_option('lsp_basecamp_user'); ?>" class="regular-text" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Password', 'displaynone'); ?></th>
				<td><input type="password" name="lsp_basecamp_password" value="<?php echo get_option('lsp_basecamp_password'); ?>" class="regular-text" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Basecamp ID', 'displaynone'); ?></th>
				<td>
					<input type="text" name="lsp_basecamp_id" value="<?php echo get_option('lsp_basecamp_id'); ?>" class="regular-text" />
					<br />
					<small><?php _e('Write the ID: p.e. https://basecamp.com/<strong>XXXXXX</strong>', 'displaynone'); ?></small>
				</td>
			</tr>

    </table>

    <?php submit_button(); ?>

</form>

<hr />
<?php if (!class_exists('WeDevs_CPM')) { ?>
	<div class="error"><p><?php _e('Remember that it\'s necessary to have installed <a href="https://wedevs.com/products/plugins/wp-project-manager-pro/" target="_blank">WP Project Manager</a> to make it works', 'displaynone'); ?></p></div>
	</div>
<?php } else if(!get_option('lsp_basecamp_id') || !get_option('lsp_basecamp_user') || !get_option('lsp_basecamp_password')) { ?>
	<div class="error"><p><?php _e('Save Basecamp settings before start data import', 'displaynone'); ?></p></div>
	</div>
<?php } else { ?>
	<h3><?php _e('Import data', 'displaynone'); ?></h3>
	<p><?php _e('Wait until it finish...', 'displaynone'); ?></p>
	<p><a href="<?php echo admin_url('plugins.php?page=basecamp2WP&basecamp2WP=start'); ?>" class="button button-primary"><?php _e('Start', 'displaynone'); ?></a></p>
<?php }

}
