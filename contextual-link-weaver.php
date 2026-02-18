<?php
/**
 * Plugin Name:       Contextual Link Weaver
 * Plugin URI:        https://github.com/geosem42/contextual-link-weaver
 * Description:       Uses Google Gemini or a local OpenAI-compatible LLM to provide intelligent internal linking suggestions.
 * Version:           1.2.0
 * Author:            George Semaan
 * Author URI:        https://logicvoid.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contextual-link-weaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include the file that handles LLM API communication.
require_once plugin_dir_path( __FILE__ ) . 'includes/gemini-api.php';

/*
||--------------------------------------------------------------------------
|| Admin Settings Page
||--------------------------------------------------------------------------
*/

function clw_add_admin_menu() {
	add_options_page(
		'Contextual Link Weaver Settings',
		'Link Weaver',
		'manage_options',
		'contextual-link-weaver',
		'clw_settings_page_html'
	);
}
add_action( 'admin_menu', 'clw_add_admin_menu' );

function clw_settings_init() {
	// Provider selector
	register_setting( 'clw_settings_group', 'clw_llm_provider', [
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
		'default'           => 'gemini',
	] );

	// Gemini settings
	register_setting( 'clw_settings_group', 'clw_gemini_api_key', [
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
	] );

	// Local LLM settings
	register_setting( 'clw_settings_group', 'clw_llm_url', [
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
	] );
	register_setting( 'clw_settings_group', 'clw_llm_model', [
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
	] );
	register_setting( 'clw_settings_group', 'clw_llm_key', [
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
	] );

	add_settings_section( 'clw_provider_section', 'LLM Provider',                          '__return_false', 'contextual-link-weaver' );
	add_settings_section( 'clw_gemini_section',   'Google Gemini',                          '__return_false', 'contextual-link-weaver' );
	add_settings_section( 'clw_local_section',    'Local / Custom LLM (OpenAI-compatible)', '__return_false', 'contextual-link-weaver' );

	add_settings_field( 'clw_llm_provider_field',   'Active Provider', 'clw_provider_field_callback',    'contextual-link-weaver', 'clw_provider_section' );
	add_settings_field( 'clw_gemini_api_key_field',  'Gemini API Key',  'clw_gemini_key_field_callback',  'contextual-link-weaver', 'clw_gemini_section' );
	add_settings_field( 'clw_llm_url_field',         'Base URL',        'clw_llm_url_field_callback',     'contextual-link-weaver', 'clw_local_section' );
	add_settings_field( 'clw_llm_model_field',       'Model Name',      'clw_llm_model_field_callback',   'contextual-link-weaver', 'clw_local_section' );
	add_settings_field( 'clw_llm_key_field',         'API Key',         'clw_llm_key_field_callback',     'contextual-link-weaver', 'clw_local_section' );
}
add_action( 'admin_init', 'clw_settings_init' );

function clw_provider_field_callback() {
	$value = get_option( 'clw_llm_provider', 'gemini' );
	?>
	<select name="clw_llm_provider" id="clw_llm_provider" onchange="clwToggleSections(this.value)" style="min-width:220px">
		<option value="gemini" <?php selected( $value, 'gemini' ); ?>>Google Gemini</option>
		<option value="local"  <?php selected( $value, 'local' ); ?>>Local / Custom LLM</option>
	</select>
	<script>
	function clwToggleSections(provider) {
		var gemini = document.getElementById('clw-gemini-section');
		var local  = document.getElementById('clw-local-section');
		if (gemini) gemini.style.display = provider === 'gemini' ? '' : 'none';
		if (local)  local.style.display  = provider === 'local'  ? '' : 'none';
	}
	document.addEventListener('DOMContentLoaded', function() {
		clwToggleSections(document.getElementById('clw_llm_provider').value);
	});
	</script>
	<?php
}

function clw_gemini_key_field_callback() {
	$value = get_option( 'clw_gemini_api_key' );
	printf(
		'<input type="password" name="clw_gemini_api_key" value="%s" size="60" /><p class="description">Get your free key at <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>. Uses model <code>gemini-2.5-flash</code>.</p>',
		esc_attr( $value )
	);
}

function clw_llm_url_field_callback() {
	$value = get_option( 'clw_llm_url' );
	printf(
		'<input type="text" name="clw_llm_url" value="%s" size="60" placeholder="https://chatgpt-oss.mydepartment.ai/v1" /><p class="description">Base URL including <code>/v1</code>. The plugin appends <code>/chat/completions</code>.</p>',
		esc_attr( $value )
	);
}

function clw_llm_model_field_callback() {
	$value = get_option( 'clw_llm_model' );
	printf(
		'<input type="text" name="clw_llm_model" value="%s" size="40" placeholder="openai/gpt-oss-20b" />',
		esc_attr( $value )
	);
}

function clw_llm_key_field_callback() {
	$value = get_option( 'clw_llm_key' );
	printf(
		'<input type="password" name="clw_llm_key" value="%s" size="50" /><p class="description">Leave empty if your endpoint does not require authentication.</p>',
		esc_attr( $value )
	);
}

function clw_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$provider = get_option( 'clw_llm_provider', 'gemini' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php settings_fields( 'clw_settings_group' ); ?>

			<h2><?php esc_html_e( 'LLM Provider', 'contextual-link-weaver' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="clw_llm_provider"><?php esc_html_e( 'Active Provider', 'contextual-link-weaver' ); ?></label></th>
					<td><?php clw_provider_field_callback(); ?></td>
				</tr>
			</table>

			<div id="clw-gemini-section" style="display:<?php echo $provider === 'gemini' ? '' : 'none'; ?>">
				<h2><?php esc_html_e( 'Google Gemini', 'contextual-link-weaver' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="clw_gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'contextual-link-weaver' ); ?></label></th>
						<td><?php clw_gemini_key_field_callback(); ?></td>
					</tr>
				</table>
			</div>

			<div id="clw-local-section" style="display:<?php echo $provider === 'local' ? '' : 'none'; ?>">
				<h2><?php esc_html_e( 'Local / Custom LLM (OpenAI-compatible)', 'contextual-link-weaver' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="clw_llm_url"><?php esc_html_e( 'Base URL', 'contextual-link-weaver' ); ?></label></th>
						<td><?php clw_llm_url_field_callback(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="clw_llm_model"><?php esc_html_e( 'Model Name', 'contextual-link-weaver' ); ?></label></th>
						<td><?php clw_llm_model_field_callback(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="clw_llm_key"><?php esc_html_e( 'API Key', 'contextual-link-weaver' ); ?></label></th>
						<td><?php clw_llm_key_field_callback(); ?></td>
					</tr>
				</table>
			</div>

			<?php submit_button( 'Save Settings' ); ?>
		</form>
	</div>
	<?php
}

/*
||--------------------------------------------------------------------------
|| Gutenberg Editor Integration
||--------------------------------------------------------------------------
*/

function clw_enqueue_editor_assets() {
	$asset_file_path = plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

	if ( ! file_exists( $asset_file_path ) ) {
		return;
	}

	$asset_file = include $asset_file_path;

	wp_enqueue_script(
		'contextual-link-weaver-editor-script',
		plugin_dir_url( __FILE__ ) . 'build/index.js',
		$asset_file['dependencies'],
		$asset_file['version']
	);
}
add_action( 'enqueue_block_editor_assets', 'clw_enqueue_editor_assets' );

function clw_register_rest_route() {
	register_rest_route(
		'contextual-link-weaver/v1',
		'/suggestions',
		[
			'methods'             => 'POST',
			'callback'            => 'clw_handle_suggestions_request',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);
}
add_action( 'rest_api_init', 'clw_register_rest_route' );

/**
 * Handles the incoming request from the editor to generate link suggestions.
 */
function clw_handle_suggestions_request( WP_REST_Request $request ) {
	$post_content = $request->get_param( 'content' );
	if ( empty( $post_content ) ) {
		return new WP_REST_Response( [ 'error' => 'Content is empty.' ], 400 );
	}

	$posts = get_posts( [
		'numberposts' => -1,
		'post_status' => 'publish',
		'post_type'   => 'post',
	] );

	$post_list       = [];
	$current_post_id = $request->get_param( 'post_id' );

	foreach ( $posts as $post ) {
		if ( $post->ID == $current_post_id ) {
			continue;
		}
		$post_list[] = [
			'id'    => $post->ID,
			'title' => $post->post_title,
			'url'   => get_permalink( $post->ID ),
		];
	}

	if ( empty( $post_list ) ) {
		return new WP_REST_Response( [ 'error' => 'No other published posts available to link to.' ], 400 );
	}

	$post_list_json = wp_json_encode( $post_list );

	$prompt = "You are an expert SEO who is building an internal link graph for a blog. Your task is to analyze the draft article and suggest internal links.

    Here is a JSON list of all available articles to link to (including their 'id', 'title', and 'url'):
    {$post_list_json}

    Here is the content of the new draft article:
    ---
    {$post_content}
    ---

    Follow these rules STRICTLY:
    1.  The 'anchor_text' MUST be a phrase that exists verbatim within the draft article's content. Do NOT invent or summarize phrases.
    2.  The 'anchor_text' MUST be between 4 and 6 words long. This is a strict range.
    3.  The selected 'anchor_text' should be a self-contained, natural-sounding phrase. Avoid selecting awkward sentence fragments.
    4.  Find up to 5 of the best possible linking opportunities, then find the single most contextually relevant article from the JSON list for each one.
    5.  NEVER use the title of an article from the JSON list as the 'anchor_text' unless that exact phrase also appears in the draft article.

    Return your answer ONLY as a JSON object with a single key 'suggestions' whose value is an array of objects. Each object must have three keys: 'anchor_text', 'post_id_to_link', and 'reasoning'. If you cannot find any good matches that follow all the rules, return {\"suggestions\": []}.";

	$suggestions_from_api = clw_get_linking_suggestions( $prompt );

	if ( is_wp_error( $suggestions_from_api ) ) {
		return new WP_REST_Response( [ 'error' => $suggestions_from_api->get_error_message() ], 500 );
	}

	if ( ! is_array( $suggestions_from_api ) ) {
		return new WP_REST_Response( [ 'error' => 'API returned a non-array response.' ], 500 );
	}

	// Unwrap {"suggestions": [...]} envelope if present.
	if ( isset( $suggestions_from_api['suggestions'] ) && is_array( $suggestions_from_api['suggestions'] ) ) {
		$suggestions_from_api = $suggestions_from_api['suggestions'];
	}

	$final_suggestions = [];
	foreach ( $suggestions_from_api as $suggestion ) {
		foreach ( $post_list as $post ) {
			if ( $post['id'] == $suggestion['post_id_to_link'] ) {
				$suggestion['title'] = $post['title'];
				$suggestion['url']   = $post['url'];
				$final_suggestions[] = $suggestion;
				break;
			}
		}
	}

	return new WP_REST_Response( $final_suggestions, 200 );
}
