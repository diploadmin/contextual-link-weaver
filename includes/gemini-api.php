<?php
/**
 * LLM API abstraction layer.
 *
 * This file provides a unified interface for calling different LLM providers.
 * The active provider is determined by the `clw_llm_provider` WordPress option:
 *   - 'gemini' → Google Gemini generateContent API (gemini-2.5-flash)
 *   - 'local'  → Any OpenAI-compatible /v1/chat/completions endpoint
 *
 * Both providers receive a text prompt and are expected to return valid JSON.
 * The JSON is double-decoded: first the HTTP response body, then the LLM's
 * generated text (which is itself a JSON string).
 *
 * @package ContextualLinkWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes a prompt to the active LLM provider and returns parsed JSON.
 *
 * This is the single entry point used by all REST handlers. It reads the
 * `clw_llm_provider` option and delegates to the appropriate backend.
 *
 * @param  string          $prompt_text  The full prompt including instructions and data.
 * @return array|WP_Error  Decoded JSON array on success, WP_Error on failure.
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
 *
 * Uses the v1beta endpoint with `responseMimeType: application/json` in
 * generationConfig to force Gemini to return structured JSON without
 * Markdown code fences. The API key is passed as a URL query parameter.
 *
 * Response path: candidates[0].content.parts[0].text → JSON string → decoded.
 *
 * @param  string          $prompt_text  The prompt to send.
 * @return array|WP_Error  Decoded JSON array, or WP_Error on failure.
 */
function clw_call_gemini( $prompt_text ) {
	$api_key = get_option( 'clw_gemini_api_key', '' );

	if ( empty( $api_key ) ) {
		return new WP_Error( 'config_missing', 'Gemini API key is not configured in Link Weaver settings.' );
	}

	$model   = 'gemini-2.5-flash';
	$api_url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

	$request_body = [
		'contents'         => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt_text ] ] ] ],
		'generationConfig' => [ 'responseMimeType' => 'application/json' ],
	];

	$response = wp_remote_post( $api_url, [
		'method'  => 'POST',
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => json_encode( $request_body ),
		'timeout' => 60,
	] );

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
 *
 * Sends a single user message with `response_format: { type: "json_object" }`
 * to force structured JSON output. Auth is via Bearer token in the
 * Authorization header (optional — omitted if key is empty).
 *
 * Response path: choices[0].message.content → JSON string → decoded.
 *
 * Note: json_object mode requires the model to return a JSON *object* (not array),
 * which is why all prompts request `{"suggestions": [...]}` format.
 *
 * @param  string          $prompt_text  The prompt to send.
 * @return array|WP_Error  Decoded JSON array, or WP_Error on failure.
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

	$response = wp_remote_post( $base_url . '/chat/completions', [
		'method'  => 'POST',
		'headers' => $headers,
		'body'    => json_encode( $request_body ),
		'timeout' => 60,
	] );

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
