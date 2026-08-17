<?php
/**
 * Plugin Name:       Runtime Content Pack
 * Description:       Lets non-technical staff edit a single-page app's copy, labels, consent text and brand colours from WordPress. Publishes a JSON content pack the app reads at runtime, so wording changes need no code deploy.
 * Version:           1.0.0
 * Author:            Mark Pease
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Why this exists
 * ---------------
 * A single-page application's copy normally lives in the bundle, so changing a
 * label means a build and a deploy. That is fine until the people who own the
 * wording are not the people who can deploy, at which point every typo becomes
 * a ticket.
 *
 * This stores the whole content pack in one wp_option and serves it from a
 * public REST endpoint. The app fetches it at runtime and deep-merges it over
 * its own compiled-in defaults, which matters more than it sounds: if this
 * endpoint is unreachable the app still renders its built-in copy, so a
 * WordPress outage cannot take the form down.
 *
 * Deliberately self-contained. No ACF, no page builder, no paid dependency.
 *
 * @package wp-runtime-content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPRC_OPTION', 'wprc_content_pack' );
define( 'WPRC_VERSION', '1.0.0' );
define( 'WPRC_REST_NAMESPACE', 'content/v1' );

/**
 * The shipped default content pack.
 *
 * This should mirror whatever the application compiles in as its own fallback.
 * Anything an editor changes is stored as an override and merged over this, so
 * a partial or stale stored value is always safe.
 *
 * @return array
 */
function wprc_default_content() {
	return array(
		'version' => 'wp-1',
		'theme'   => array(
			'primaryColor' => '#1f3a5f',
			'accentColor'  => '#e8a33d',
		),
		'common'  => array(
			'continue' => 'Continue',
			'back'     => 'Back',
			'stepper'  => array(
				'eligibility' => 'Eligibility',
				'applicant'   => 'About you',
				'details'     => 'Details',
				'documents'   => 'Documents',
				'result'      => 'Result',
			),
		),
		'welcome' => array(
			'heading'      => 'Welcome',
			'intro'        => 'This short application takes about ten minutes. You can save your progress and come back to it at any point.',
			'orgName'      => 'Example Organisation',
			'startButton'  => 'Start a new application',
			'resumeButton' => 'Resume an application',
		),
		'eligibility' => array(
			'heading'       => 'Before you start, please confirm the following',
			'requirements'  => array(
				'age'       => 'You are 18 or older.',
				'residency' => 'You live in one of the areas we serve.',
				'authority' => 'You are authorised to apply on behalf of the organisation named below.',
			),
			'certifyLabel'  => 'I confirm the information above is correct as far as I know.',
			'failHeading'   => 'We are not able to continue with this application',
			'failBody'      => 'Based on your answers we cannot proceed at this time. This is not a decision about you personally, and you are welcome to apply again if your circumstances change.',
		),
		'applicant' => array(
			'heading'     => 'About you',
			'nameLabel'   => 'Full name',
			'emailLabel'  => 'Email address',
			'emailHelp'   => 'We will use this to send you a link back to your application.',
			'phoneLabel'  => 'Phone number',
		),
		'details' => array(
			'heading'      => 'A few more details',
			'amountLabel'  => 'Amount requested',
			'amountHelp'   => 'A rough figure is fine at this stage.',
			'purposeLabel' => 'What the funds are for',
		),
		'documents' => array(
			'heading' => 'Supporting documents',
			'intro'   => 'Upload anything you already have. You can add the rest later without starting over.',
			'help'    => 'PDF, JPG or PNG, up to 10 MB each.',
		),
		'result' => array(
			'headingSubmitted' => 'Application received',
			'bodySubmitted'    => 'Thank you. We have your application and will be in touch within five working days.',
			'referenceLabel'   => 'Your reference number',
		),
		'consents' => array(
			'dataUse'      => 'I agree that the information I provide may be used to assess this application and may be shared with organisations involved in that assessment.',
			'contactInfo'  => 'I agree to be contacted about this application by email or telephone.',
			'creditCheck'  => 'I understand that a check may be carried out and that this may be recorded.',
		),
		'footer' => array(
			'helpText'     => 'Need help? Contact us and we will get back to you.',
			'privacyLabel' => 'Privacy notice',
		),
	);
}

/**
 * Content paths that carry legal or consent wording.
 *
 * These get flagged in the editor and a note asking for review before
 * publishing. Somebody changing a button label and somebody changing a consent
 * statement are doing very different things, and the interface should say so.
 *
 * @return string[]
 */
function wprc_compliance_paths() {
	return array(
		'consents.dataUse',
		'consents.contactInfo',
		'consents.creditCheck',
		'eligibility.certifyLabel',
	);
}

/**
 * Seed the option on activation so the endpoint is valid immediately.
 */
function wprc_activate() {
	if ( false === get_option( WPRC_OPTION, false ) ) {
		add_option( WPRC_OPTION, wprc_default_content() );
	}
}
register_activation_hook( __FILE__, 'wprc_activate' );

/**
 * Stored overrides merged over the shipped defaults.
 *
 * Merging this way means adding a new field in code makes it appear in the
 * editor automatically, with no migration and no risk of a stored pack from an
 * older version blanking it out.
 *
 * @return array
 */
function wprc_get_content() {
	$stored = get_option( WPRC_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return wprc_deep_merge( wprc_default_content(), $stored );
}

/**
 * Recursively merge $override onto $base.
 *
 * @param array $base     Defaults.
 * @param array $override Stored values.
 * @return array
 */
function wprc_deep_merge( $base, $override ) {
	foreach ( $override as $key => $value ) {
		if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
			$base[ $key ] = wprc_deep_merge( $base[ $key ], $value );
		} else {
			$base[ $key ] = $value;
		}
	}
	return $base;
}

/**
 * Flatten a nested array to [ 'dot.path' => value ].
 *
 * @param array  $arr    Nested array.
 * @param string $prefix Path prefix, used by the recursion.
 * @return array
 */
function wprc_flatten( $arr, $prefix = '' ) {
	$out = array();
	foreach ( $arr as $key => $value ) {
		$path = '' === $prefix ? $key : "$prefix.$key";
		if ( is_array( $value ) ) {
			$out += wprc_flatten( $value, $path );
		} else {
			$out[ $path ] = $value;
		}
	}
	return $out;
}

/**
 * Write a value at a dot path, building intermediate arrays as needed.
 *
 * @param array  $arr   Target array, by reference.
 * @param string $path  Dot path.
 * @param mixed  $value Value to set.
 */
function wprc_set_path( &$arr, $path, $value ) {
	$keys = explode( '.', $path );
	$ref  = &$arr;
	foreach ( $keys as $index => $key ) {
		if ( $index === count( $keys ) - 1 ) {
			$ref[ $key ] = $value;
		} else {
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				$ref[ $key ] = array();
			}
			$ref = &$ref[ $key ];
		}
	}
}

/**
 * Turn a camelCase or snake_case path segment into a readable label.
 *
 * @param string $segment Path segment.
 * @return string
 */
function wprc_humanize( $segment ) {
	$spaced = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $segment );
	return ucwords( str_replace( array( '_', '.' ), ' ', $spaced ) );
}

/**
 * Whether a stored value is a colour we are willing to render inline.
 *
 * The editor shows a swatch by putting the value into a style attribute, and
 * esc_attr stops the attribute being escaped without stopping CSS being
 * injected into it. Restricting this to a literal hex colour removes the
 * question entirely.
 *
 * @param string $value Candidate colour.
 * @return bool
 */
function wprc_is_hex_color( $value ) {
	return (bool) preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $value );
}

/**
 * Build a content pack from submitted form data.
 *
 * Separate from the page render so it can be tested against hostile input
 * without standing up WordPress, which is the only way to be sure about any of
 * the guarantees below.
 *
 * The loop walks the *known* content paths and pulls each value out of the
 * submission, rather than walking the submission. That inversion is what stops
 * an unexpected field introducing a key, and makes a missing field fall back to
 * its default instead of blanking.
 *
 * @param mixed $submitted Raw, unslashed $_POST['wprc']. Any shape at all.
 * @return array Complete content pack, safe to store.
 */
function wprc_build_content_from_input( $submitted ) {
	// A request need not resemble the form that generated it. This can arrive
	// as a string, or absent, or as something stranger.
	$posted = is_array( $submitted ) ? $submitted : array();
	$new    = array();

	foreach ( wprc_flatten( wprc_default_content() ) as $path => $default ) {
		$field = str_replace( '.', '__', $path );
		$value = isset( $posted[ $field ] ) ? $posted[ $field ] : null;

		// Only a scalar is something a person typed into this form. Sending
		// wprc[welcome__heading][]=x makes this an array, and handing that to
		// wp_kses_post is a TypeError on PHP 8 and the literal string "Array"
		// on PHP 7. One takes the page down, the other quietly corrupts the
		// content pack, and neither is acceptable.
		$value = is_scalar( $value ) ? (string) $value : $default;

		if ( false !== strpos( $path, 'Color' ) ) {
			$value = wprc_is_hex_color( $value ) ? $value : $default;
		} else {
			// Inline formatting is genuinely useful in body copy, so allow the
			// post-safe subset rather than stripping all markup.
			$value = wp_kses_post( $value );
		}

		wprc_set_path( $new, $path, $value );
	}

	// Stamp every publish. When somebody asks which wording was live on a given
	// day, this is the answer.
	$new['version'] = gmdate( 'Y-m-d\TH:i:s\Z' );
	return $new;
}

/* ---------------------------------------------------------------------------
 * Admin editor
 * ------------------------------------------------------------------------- */

function wprc_admin_menu() {
	add_menu_page(
		__( 'Application content', 'wp-runtime-content' ),
		__( 'Application content', 'wp-runtime-content' ),
		'edit_pages',
		'wp-runtime-content',
		'wprc_render_admin_page',
		'dashicons-edit-page',
		30
	);
}
add_action( 'admin_menu', 'wprc_admin_menu' );

function wprc_render_admin_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}

	if ( isset( $_POST['wprc_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wprc_nonce'] ) ), 'wprc_save' ) ) {
		$submitted = isset( $_POST['wprc'] ) ? wp_unslash( $_POST['wprc'] ) : array();
		update_option( WPRC_OPTION, wprc_build_content_from_input( $submitted ) );

		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Content published. It will reach the application within a couple of minutes.', 'wp-runtime-content' )
			. '</p></div>';
	}

	$content    = wprc_get_content();
	$flat       = wprc_flatten( $content );
	$compliance = wprc_compliance_paths();
	$endpoint   = rest_url( WPRC_REST_NAMESPACE . '/content' );

	$groups = array();
	foreach ( $flat as $path => $value ) {
		$top              = explode( '.', $path )[0];
		$groups[ $top ][] = $path;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Application content', 'wp-runtime-content' ); ?></h1>
		<p><?php esc_html_e( 'Edit the words, labels and colours applicants see, then press Publish. Changes reach the application within a couple of minutes, with no developer involved.', 'wp-runtime-content' ); ?></p>
		<p style="color:#666;">
			<?php esc_html_e( 'Live content feed:', 'wp-runtime-content' ); ?>
			<code><?php echo esc_url( $endpoint ); ?></code>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'wprc_save', 'wprc_nonce' ); ?>
			<?php foreach ( $groups as $section => $paths ) : ?>
				<h2 style="margin-top:28px;border-bottom:1px solid #ddd;padding-bottom:6px;">
					<?php echo esc_html( wprc_humanize( $section ) ); ?>
				</h2>
				<table class="form-table" role="presentation"><tbody>
				<?php
				foreach ( $paths as $path ) :
					$field = str_replace( '.', '__', $path );
					// Cast on the way out too. The option is written by the save
					// path above, but it is also just a database row, and this
					// template should not fall over because something else put
					// an unexpected type in it.
					$value    = is_scalar( $flat[ $path ] ) ? (string) $flat[ $path ] : '';
					$is_legal = in_array( $path, $compliance, true );
					$is_color = ( false !== strpos( $path, 'Color' ) );
					$label    = wprc_humanize( implode( ' › ', array_slice( explode( '.', $path ), 1 ) ) );
					if ( '' === $label ) {
						$label = wprc_humanize( $path );
					}
					?>
					<tr>
						<th scope="row" style="width:240px;">
							<?php echo esc_html( $label ); ?>
							<?php if ( $is_legal ) : ?>
								<span style="display:inline-block;margin-left:6px;background:#fcf0d6;color:#7a5b00;font-size:11px;padding:1px 6px;border-radius:3px;">
									<?php esc_html_e( 'compliance copy', 'wp-runtime-content' ); ?>
								</span>
							<?php endif; ?>
						</th>
						<td>
							<?php if ( $is_color ) : ?>
								<input type="text"
									name="wprc[<?php echo esc_attr( $field ); ?>]"
									value="<?php echo esc_attr( $value ); ?>"
									pattern="#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?"
									style="width:140px;font-family:monospace;" />
								<?php if ( wprc_is_hex_color( $value ) ) : ?>
									<span style="display:inline-block;width:22px;height:22px;vertical-align:middle;border:1px solid #ccc;border-radius:4px;background:<?php echo esc_attr( $value ); ?>;"></span>
								<?php endif; ?>
							<?php else : ?>
								<textarea name="wprc[<?php echo esc_attr( $field ); ?>]"
									rows="<?php echo (int) ( strlen( $value ) > 90 ? 3 : 1 ); ?>"
									style="width:100%;max-width:760px;"><?php echo esc_textarea( $value ); ?></textarea>
								<?php if ( $is_legal ) : ?>
									<p class="description">
										<?php esc_html_e( 'Legal text. Please have it reviewed before publishing; every change is stamped.', 'wp-runtime-content' ); ?>
									</p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endforeach; ?>
			<?php submit_button( __( 'Publish', 'wp-runtime-content' ) ); ?>
		</form>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * Public REST endpoint the application reads
 * ------------------------------------------------------------------------- */

function wprc_register_routes() {
	register_rest_route(
		WPRC_REST_NAMESPACE,
		'/content',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => 'wprc_rest_content',
		)
	);
}
add_action( 'rest_api_init', 'wprc_register_routes' );

/**
 * Serve the merged content pack.
 *
 * Public and unauthenticated on purpose: this is display copy that any visitor
 * to the application already sees. No applicant data passes through here.
 *
 * The short cache is a deliberate compromise. Longer would spare the origin,
 * but an editor who publishes a fix wants to see it, and a minute is about the
 * longest wait before people start pressing the button again.
 *
 * @param WP_REST_Request $request Unused.
 * @return WP_REST_Response
 */
function wprc_rest_content( $request ) {
	$response = rest_ensure_response( wprc_get_content() );
	$response->header( 'Access-Control-Allow-Origin', '*' );
	$response->header( 'Cache-Control', 'public, max-age=60' );
	return $response;
}
