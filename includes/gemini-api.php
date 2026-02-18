<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a prompt to the configured LLM provider and returns the parsed JSON response.
 * Supports 'gemini' (Google Gemini API) and 'local' (OpenAI-compatible endpoint).
 */
function clw_get_linking_suggestions( $prompt_text ) {
	$provider = get_option( 'clw_llm_provider', 'gemini' );

	if ( $provider === 'gemini' ) {
		return clw_call_gemini( $prompt_text );
	}

	return clw_call_openai_compatible( $prompt_text );
}

/**
 * Calls the Google Gemini generateContent API.
 */
function clw_call_gemini( $prompt_text ) {
	$api_key = get_option( 'clw_gemini_api_key', '' );

	if ( empty( $api_key ) ) {
		return new WP_Error( 'config_missing', 'Gemini API key is not configured in Link Weaver settings.' );
	}

	$model   = 'gemini-2.5-flash';
	$api_url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

	$request_body = [
		'contents'        => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt_text ] ] ] ],
		'generationConfig' => [ 'responseMimeType' => 'application/json' ],
	];

	$args = [
		'method'  => 'POST',
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => json_encode( $request_body ),
		'timeout' => 60,
	];

	$response = wp_remote_post( $api_url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		$error_body = wp_remote_retrieve_body( $response );
		return new WP_Error( 'api_error', "Gemini API returned status {$response_code}.", $error_body );
	}

	$decoded_body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$generated_text = $decoded_body['candidates'][0]['content']['parts'][0]['text'] ?? null;

	if ( ! $generated_text ) {
		return new WP_Error( 'invalid_response', 'Could not parse Gemini API response.' );
	}

	return json_decode( $generated_text, true );
}

/**
 * Calls an OpenAI-compatible /chat/completions endpoint.
 */
function clw_call_openai_compatible( $prompt_text ) {
	$base_url = rtrim( get_option( 'clw_llm_url', '' ), '/' );
	$model    = get_option( 'clw_llm_model', '' );
	$api_key  = get_option( 'clw_llm_key', '' );

	if ( empty( $base_url ) || empty( $model ) ) {
		return new WP_Error( 'config_missing', 'Local LLM URL or model name is not configured in Link Weaver settings.' );
	}

	$request_body = [
		'model'           => $model,
		'messages'        => [ [ 'role' => 'user', 'content' => $prompt_text ] ],
		'response_format' => [ 'type' => 'json_object' ],
	];

	$headers = [ 'Content-Type' => 'application/json' ];
	if ( ! empty( $api_key ) ) {
		$headers['Authorization'] = 'Bearer ' . $api_key;
	}

	$args = [
		'method'  => 'POST',
		'headers' => $headers,
		'body'    => json_encode( $request_body ),
		'timeout' => 60,
	];

	$response = wp_remote_post( $base_url . '/chat/completions', $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		$error_body = wp_remote_retrieve_body( $response );
		return new WP_Error( 'api_error', "Local LLM API returned status {$response_code}.", $error_body );
	}

	$decoded_body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$generated_text = $decoded_body['choices'][0]['message']['content'] ?? null;

	if ( ! $generated_text ) {
		return new WP_Error( 'invalid_response', 'Could not parse local LLM API response.' );
	}

	return json_decode( $generated_text, true );
}
