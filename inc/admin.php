<?php
/**
 * Admin functionality for Ads.txt.
 *
 * @package Ads_Txt_Manager
 */

namespace AdsTxt;

/**
 * Enqueue any necessary scripts.
 *
 * @param  string $hook Hook name for the current screen.
 *
 * @return void
 */
function admin_enqueue_scripts( $hook ) {
	if ( 'settings_page_adstxt-settings' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'adstxt',
		esc_url( plugins_url( '/js/admin.js', dirname( __FILE__ ) ) ),
		array( 'jquery', 'wp-backbone', 'wp-codemirror' ),
		ADS_TXT_MANAGER_VERSION,
		true
	);
	wp_enqueue_style( 'code-editor' );
	wp_enqueue_style(
		'adstxt',
		esc_url( plugins_url( '/css/admin.css', dirname( __FILE__ ) ) ),
		array(),
		ADS_TXT_MANAGER_VERSION
	);

	$strings = array(
		'saved_message' => esc_html__( 'Ads.txt saved', 'ads-txt' ),
		'error_message' => esc_html__( 'Your Ads.txt contains the following issues:', 'ads-txt' ),
		'unknown_error' => esc_html__( 'An unknown error occurred.', 'ads-txt' ),
	);

	wp_localize_script( 'adstxt', 'adstxt', $strings );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\admin_enqueue_scripts' );

/**
 * Output some CSS directly in the head of the document.
 *
 * Should there ever be more than ~25 lines of CSS, this should become a separate file.
 *
 * @return void
 */
function admin_head_css() {
?>
<style>
.CodeMirror {
	width: 100%;
	min-height: 60vh;
	height: calc( 100vh - 295px );
	border: 1px solid #ddd;
	box-sizing: border-box;
	}
</style>
<?php
}
add_action( 'admin_head-settings_page_adstxt-settings', __NAMESPACE__ . '\admin_head_css' );

/**
 * Appends a query argument to the edit url to make sure it is redirected to
 * the ads.txt screen.
 *
 * @since 1.5.2
 *
 * @param string $url Edit url.
 * @return string Edit url.
 */
function ads_txt_adjust_revisions_return_to_editor_link( $url ) {
	global $pagenow;

	if ( 'revision.php' !== $pagenow || ! isset( $_REQUEST['adstxt'] ) ) {
		return $url;
	}

	return admin_url( 'options-general.php?page=adstxt-settings' );
}
add_filter( 'get_edit_post_link',__NAMESPACE__ . '\ads_txt_adjust_revisions_return_to_editor_link' );

/**
 * Modifies revisions data to preserve adstxt argument used in determining
 * where to redirect user returning to editor.
 *
 * @since 1.9.0
 *
 * @param array $revisions_data The bootstrapped data for the revisions screen.
 * @return array Modified bootstrapped data for the revisions screen.
 */
function adstxt_revisions_restore( $revisions_data ) {
	if ( isset( $_REQUEST['adstxt'] ) ) {
		$revisions_data['restoreUrl'] = add_query_arg(
			'adstxt',
			$_REQUEST['adstxt'],
			$revisions_data['restoreUrl']
		);
	}

	return $revisions_data;
}
add_filter( 'wp_prepare_revision_for_js', __NAMESPACE__ . '\adstxt_revisions_restore' );

/**
 * Hide the revisions title with CSS, since WordPress always shows the title
 * field even if unchanged, and the title is not relevant for ads.txt.
 */
function admin_header_revisions_styles() {
	$current_screen = get_current_screen();

	if ( ! $current_screen || 'revision' !== $current_screen->id ) {
		return;
	}

	if ( ! isset( $_REQUEST['adstxt'] ) ) {
		return;
	}

	?>
	<style>
		.revisions-diff .diff h3 {
			display: none;
		}
		.revisions-diff .diff table.diff:first-of-type {
			display: none;
		}
	</style>
	<?php

}
add_action( 'admin_head', __NAMESPACE__ . '\admin_header_revisions_styles' );

/**
 * Add admin menu page.
 *
 * @return void
 */
function admin_menu() {
	add_options_page( esc_html__( 'Ads.txt', 'ads-txt' ), esc_html__( 'Ads.txt', 'ads-txt' ), 'manage_options', 'adstxt-settings', __NAMESPACE__ . '\settings_screen' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\admin_menu' );

/**
 * Output the settings screen.
 *
 * @return void
 */
function settings_screen() {
	$post_id = get_option( 'adstxt_post' );
	$post    = false;
	$content = false;
	$errors  = [];
	$revision_count   = 0;
	$last_revision_id = false;

	if ( $post_id ) {
		$post = get_post( $post_id );
	}

	if ( is_a( $post, 'WP_Post' ) ) {
		$content = $post->post_content;
		$revisions = wp_get_post_revisions( $post->ID );
		$revision_count = count( $revisions );
		$last_revision = array_shift( $revisions );
		$last_revision_id = $last_revision ? $last_revision->ID : false;
		$errors  = get_post_meta( $post->ID, 'adstxt_errors', true );
		$revisions_link = $last_revision_id ? admin_url( 'revision.php?adstxt=1&revision=' . $last_revision_id ) : false;
	}
?>
<div class="wrap">
<?php if ( ! empty( $errors ) ) : ?>
	<div class="notice notice-error adstxt-notice">
		<p><strong><?php echo esc_html__( 'Your Ads.txt contains the following issues:', 'ads-txt' ); ?></strong></p>
		<ul>
			<?php
			foreach ( $errors as $error ) {
				echo '<li>';

				// Errors were originally stored as an array.
				// This old style only needs to be accounted for here at runtime display.
				if ( isset( $error['message'] ) ) {
					$message = sprintf(
						/* translators: Error message output. 1: Line number, 2: Error message */
						__( 'Line %1$s: %2$s', 'ads-txt' ),
						$error['line'],
						$error['message']
					);

					echo esc_html( $message );
				} else {
					display_formatted_error( $error ); // WPCS: XSS ok.
				}

				echo '</li>';
			}
			?>
		</ul>
	</div>
<?php endif; ?>

	<h2><?php echo esc_html__( 'Manage Ads.txt', 'ads-txt' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adstxt-settings-form">
		<input type="hidden" name="post_id" value="<?php echo ( is_a( $post, 'WP_Post' ) ? esc_attr( $post->ID ) : '' ); ?>" />
		<input type="hidden" name="action" value="adstxt-save" />
		<?php wp_nonce_field( 'adstxt_save' ); ?>

		<label class="screen-reader-text" for="adstxt_content"><?php echo esc_html__( 'Ads.txt content', 'ads-txt' ); ?></label>
		<textarea class="widefat code" rows="25" name="adstxt" id="adstxt_content"><?php echo esc_textarea( $content ); ?></textarea>
		<?php
			if ( $revision_count > 1 ) {
		?>
			<div class="misc-pub-section misc-pub-revisions">
			<?php
				/* translators: Post revisions heading. 1: The number of available revisions */
				echo wp_kses_post(
					__( sprintf(
						'Revisions: <span class="adstxt-revision-count">%s</span>',
						number_format_i18n( $revision_count )
					), 'ads-txt' ) );
			?>
				<a class="hide-if-no-js" href="<?php echo esc_url( $revisions_link ); ?>">
					<span aria-hidden="true">
						<?php echo esc_html( __( 'Browse', 'ads-txt' ) ); ?>
					</span> <span class="screen-reader-text">
						<?php echo esc_html( __( 'Browse revisions', 'ads-txt' ) ); ?>
					</span>
				</a>
		</div>
		<?php
			}
		?>
		<div id="adstxt-notification-area"></div>

		<p class="submit">
			<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr( 'Save Changes' ); ?>">
			<span class="spinner" style="float:none;vertical-align:top"></span>
		</p>

	</form>

	<script type="text/template" id="tmpl-adstext-notice">
		<# if ( ! _.isUndefined( data.saved ) ) { #>
		<div class="notice notice-success adstxt-notice adstxt-saved">
			<p>{{ data.saved.saved_message }}</p>
		</div>
		<# } #>

		<# if ( ! _.isUndefined( data.errors ) ) { #>
		<div class="notice notice-error adstxt-notice adstxt-errors">
			<p><strong>{{ data.errors.error_message }}</strong></p>
			<# if ( ! _.isUndefined( data.errors.errors ) ) { #>
			<ul class="adstxt-errors-items">
			<# _.each( data.errors.errors, function( error ) { #>
				<?php foreach ( array_keys( get_error_messages() ) as $error_type ) : ?>
				<# if ( "<?php echo esc_html( $error_type ); ?>" === error.type ) { #>
					<li>
						<?php
						display_formatted_error(
							array(
							'line'  => '{{error.line}}',
							'type'  => $error_type,
							'value' => '{{error.value}}',
							)
						);
						?>
					</li>
				<# } #>
				<?php endforeach; ?>
			<# } ); #>
			</ul>
			<# } #>
		</div>

		<# if ( _.isUndefined( data.saved ) && ! _.isUndefined( data.errors.errors ) ) { #>
		<p class="adstxt-ays">
			<input id="adstxt-ays-checkbox" name="adstxt_ays" type="checkbox" value="y" />
			<label for="adstxt-ays-checkbox">
				<?php esc_html_e( 'Update anyway, even though it may adversely affect your ads?', 'ads-txt' ); ?>
			</label>
		</p>
		<# } #>

		<# } #>
	</script>
</div>

<?php
}

/**
 * Take an error array and output it as a message.
 *
 * @param  array $error {
 *     Array of error message components.
 *
 *     @type int    $line    Line number of the error.
 *     @type string $type    Type of error.
 *     @type string $value   Optional. Value in question.
 * }
 *
 * @return string|void
 */
function display_formatted_error( $error ) {
	$messages = get_error_messages();

	if ( ! isset( $messages[ $error['type'] ] ) ) {
		return __( 'Unknown error', 'adstxt' );
	}

	if ( ! isset( $error['value'] ) ) {
		$error['value'] = '';
	}

	$message = sprintf( esc_html( $messages[ $error['type'] ] ), '<code>' . esc_html( $error['value'] ) . '</code>' );

	printf(
	/* translators: Error message output. 1: Line number, 2: Error message */
		esc_html__( 'Line %1$s: %2$s', 'ads-txt' ),
		esc_html( $error['line'] ),
		wp_kses_post( $message )
	);
}

/**
 * Get all non-generic error messages, translated and with placeholders intact.
 *
 * @return array Associative array of error messages.
 */
function get_error_messages() {
	$messages = array(
		'invalid_variable'     => __( 'Unrecognized variable' ),
		'invalid_record'       => __( 'Invalid record' ),
		'invalid_account_type' => __( 'Third field should be RESELLER or DIRECT' ),
		/* translators: %s: Subdomain */
		'invalid_subdomain'    => __( '%s does not appear to be a valid subdomain' ),
		/* translators: %s: Exchange domain */
		'invalid_exchange'     => __( '%s does not appear to be a valid exchange domain' ),
		/* translators: %s: Alphanumeric TAG-ID */
		'invalid_tagid'        => __( '%s does not appear to be a valid TAG-ID' ),
	);

	return $messages;
}
