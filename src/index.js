/**
 * Contextual Link Weaver — Gutenberg Editor Integration
 *
 * This file registers two Gutenberg extensions:
 *
 * 1. FORMAT TYPE (toolbar button)
 *    - Registered via registerFormatType as 'contextual-link-weaver/suggest'
 *    - Adds a "superhero" icon to the block toolbar when editing rich text
 *    - On click: reads the selected text, fires TWO parallel API calls:
 *        a) /link-for-text → LLM ranks internal posts by relevance
 *        b) /link-from-rag → Chatbot API returns knowledge base sources
 *    - Results appear in a Popover with two sections, each with "Insert Link"
 *    - Insert applies core/link format to the selection via applyFormat()
 *
 * 2. PLUGIN SIDEBAR
 *    - Registered via registerPlugin as 'link-weaver-plugin'
 *    - Full post scan: sends entire post content to /suggestions endpoint
 *    - LLM identifies verbatim phrases + suggests internal posts for each
 *    - One-click insertion replaces the phrase in-place with an <a> tag,
 *      scrolls to the link, and highlights it briefly
 *
 * All API calls go through WordPress apiFetch (handles nonce auth automatically).
 *
 * @package ContextualLinkWeaver
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { PanelBody, Button, Spinner, Popover } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useRef, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { registerFormatType, applyFormat } from '@wordpress/rich-text';
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';

// ─────────────────────────────────────────────────────────────────────────────
// FORMAT TYPE — Toolbar button with parallel LLM + RAG queries
// ─────────────────────────────────────────────────────────────────────────────
//
// Registered as a format type so it integrates with the Gutenberg rich-text
// system. We use tagName: 'span' with a custom className to avoid conflicts
// with core/link which owns the <a> tag. The edit component receives the
// current rich-text `value` (with selection offsets) and an `onChange` callback.

const FORMAT_TYPE = 'contextual-link-weaver/suggest';

/**
 * The edit component for the format type. Renders:
 *   - A ToolbarButton in the block toolbar (via BlockControls)
 *   - A Popover (when open) showing RAG + LLM results
 *
 * @param {Object}   props          Props from registerFormatType
 * @param {Object}   props.value    Rich-text value with .text, .start, .end
 * @param {Function} props.onChange Callback to update the rich-text value
 * @param {boolean}  props.isActive Whether this format is currently active
 */
const LinkWeaverInlineButton = ( { value, onChange, isActive } ) => {
    const [ isOpen,       setIsOpen       ] = useState( false );
    const [ anchorText,   setAnchorText   ] = useState( '' );

    const [ llmLoading,   setLlmLoading   ] = useState( false );
    const [ llmResults,   setLlmResults   ] = useState( [] );
    const [ llmError,     setLlmError     ] = useState( '' );

    const [ ragLoading,   setRagLoading   ] = useState( false );
    const [ ragResults,   setRagResults   ] = useState( [] );
    const [ ragError,     setRagError     ] = useState( '' );

    // Ref for the toolbar button — used as the Popover anchor.
    const buttonRef = useRef();

    const currentPostId = useSelect(
        ( select ) => select( 'core/editor' ).getCurrentPostId(),
        []
    );

    // Check if user has an active text selection (start !== end).
    const hasSelection = value.start !== undefined &&
                         value.end   !== undefined &&
                         value.start !== value.end;

    /**
     * Extracts the selected text and fires both API calls in parallel.
     * Results stream in independently — RAG typically responds faster (~12s)
     * while LLM may take 5-15s depending on the number of posts.
     */
    const handleClick = useCallback( () => {
        if ( ! hasSelection ) return;

        const selected = value.text.slice( value.start, value.end ).trim();
        if ( ! selected ) return;

        setAnchorText( selected );
        setIsOpen( true );
        setLlmLoading( true );
        setRagLoading( true );
        setLlmResults( [] );
        setRagResults( [] );
        setLlmError( '' );
        setRagError( '' );

        apiFetch( {
            path: '/contextual-link-weaver/v1/link-for-text',
            method: 'POST',
            data: { anchor_text: selected, post_id: currentPostId },
        } )
            .then( ( r ) => { setLlmResults( Array.isArray( r ) ? r : [] ); } )
            .catch( ( e ) => { setLlmError( e.message || 'LLM error' ); } )
            .finally( () => { setLlmLoading( false ); } );

        apiFetch( {
            path: '/contextual-link-weaver/v1/link-from-rag',
            method: 'POST',
            data: { query: selected },
        } )
            .then( ( r ) => { setRagResults( Array.isArray( r ) ? r : [] ); } )
            .catch( ( e ) => { setRagError( e.message || 'RAG error' ); } )
            .finally( () => { setRagLoading( false ); } );

    }, [ hasSelection, value, currentPostId ] );

    /**
     * Wraps the current selection in a core/link format with the given URL.
     * This is the standard WordPress way to insert links in rich text —
     * equivalent to clicking the built-in link button and typing a URL.
     */
    const handleInsert = useCallback( ( url ) => {
        onChange(
            applyFormat( value, {
                type: 'core/link',
                attributes: { href: url, url },
            } )
        );
        setIsOpen( false );
    }, [ value, onChange ] );

    const handleClose = useCallback( () => {
        setIsOpen( false );
        setLlmResults( [] );
        setRagResults( [] );
        setLlmError( '' );
        setRagError( '' );
    }, [] );

    return (
        <>
            { /* Toolbar button — placed in the "other" group (after alignment etc.) */ }
            <BlockControls group="other">
                <ToolbarGroup>
                    <span ref={ buttonRef }>
                        <ToolbarButton
                            icon="superhero"
                            label={ __( 'Link Weaver — find link', 'contextual-link-weaver' ) }
                            onClick={ handleClick }
                            isActive={ isOpen }
                        />
                    </span>
                </ToolbarGroup>
            </BlockControls>

            { isOpen && (
                <Popover
                    anchor={ buttonRef.current }
                    placement="bottom-start"
                    onClose={ handleClose }
                    focusOnMount={ false }
                >
                    <div style={ styles.popover }>
                        <div style={ styles.popoverHeader }>
                            <strong style={ styles.popoverTitle }>
                                { __( 'Links for:', 'contextual-link-weaver' ) }
                            </strong>
                            <em style={ styles.popoverAnchor }>&bdquo;{ anchorText }&ldquo;</em>
                            <button onClick={ handleClose } style={ styles.closeBtn } aria-label="Close">✕</button>
                        </div>

                        { /* RAG section — knowledge base sources from chatbot API */ }
                        <div style={ styles.section }>
                            <div style={ styles.sectionHeader }>
                                <span style={ styles.sectionLabel }>
                                    { __( 'Knowledge Base Sources', 'contextual-link-weaver' ) }
                                </span>
                                { ragLoading && <Spinner style={ { margin: 0 } } /> }
                            </div>

                            { ragError && <p style={ styles.errorText }>{ ragError }</p> }

                            { ! ragLoading && ! ragError && ragResults.length === 0 && (
                                <p style={ styles.emptyText }>{ __( 'No sources found.', 'contextual-link-weaver' ) }</p>
                            ) }

                            { ragResults.map( ( s, i ) => (
                                <div key={ 'rag-' + i } style={ {
                                    ...styles.suggestionItem,
                                    borderTop: i > 0 ? '1px solid #f0f0f0' : 'none',
                                    paddingTop: i > 0 ? '10px' : '0',
                                    marginTop:  i > 0 ? '10px' : '0',
                                } }>
                                    <p style={ styles.suggestionTitle }>{ s.title }</p>
                                    { s.text && <p style={ styles.suggestionReason }>{ s.text }</p> }
                                    <Button variant="primary" isSmall onClick={ () => handleInsert( s.url ) }>
                                        { __( 'Insert Link', 'contextual-link-weaver' ) }
                                    </Button>
                                </div>
                            ) ) }
                        </div>

                        { /* LLM section — internal posts ranked by the LLM */ }
                        <div style={ { ...styles.section, marginTop: '16px' } }>
                            <div style={ styles.sectionHeader }>
                                <span style={ styles.sectionLabel }>
                                    { __( 'Internal Posts (LLM)', 'contextual-link-weaver' ) }
                                </span>
                                { llmLoading && <Spinner style={ { margin: 0 } } /> }
                            </div>

                            { llmError && <p style={ styles.errorText }>{ llmError }</p> }

                            { ! llmLoading && ! llmError && llmResults.length === 0 && (
                                <p style={ styles.emptyText }>{ __( 'No matching posts found.', 'contextual-link-weaver' ) }</p>
                            ) }

                            { llmResults.map( ( s, i ) => (
                                <div key={ 'llm-' + i } style={ {
                                    ...styles.suggestionItem,
                                    borderTop: i > 0 ? '1px solid #f0f0f0' : 'none',
                                    paddingTop: i > 0 ? '10px' : '0',
                                    marginTop:  i > 0 ? '10px' : '0',
                                } }>
                                    <p style={ styles.suggestionTitle }>{ s.title }</p>
                                    { s.reasoning && <p style={ styles.suggestionReason }>{ s.reasoning }</p> }
                                    <Button variant="secondary" isSmall onClick={ () => handleInsert( s.url ) }>
                                        { __( 'Insert Link', 'contextual-link-weaver' ) }
                                    </Button>
                                </div>
                            ) ) }
                        </div>
                    </div>
                </Popover>
            ) }
        </>
    );
};

registerFormatType( FORMAT_TYPE, {
    title:     __( 'Link Weaver Suggestion', 'contextual-link-weaver' ),
    tagName:   'span',
    className: 'clw-suggestion',
    edit:      LinkWeaverInlineButton,
} );

// ─────────────────────────────────────────────────────────────────────────────
// SIDEBAR — Full post scan via LLM
// ─────────────────────────────────────────────────────────────────────────────
//
// Registered as a Gutenberg plugin sidebar. When the user clicks "Scan Post &
// Generate", it sends the entire post HTML content to /suggestions. The LLM
// finds verbatim phrases and suggests which internal post each should link to.
//
// handleInsertLink() does in-place replacement: it walks all blocks, finds the
// one containing the anchor text, wraps it in an <a> tag, then uses
// replaceBlocks() to update the editor state. A MutationObserver watches for
// the DOM update and scrolls/highlights the inserted link.

const LinkWeaverIcon = () => <span className="dashicons dashicons-admin-links"></span>;

const LinkWeaverSidebar = () => {
    const [ isLoading,   setIsLoading   ] = useState( false );
    const [ suggestions, setSuggestions ] = useState( [] );
    const [ error,       setError       ] = useState( '' );

    const { postContent, currentPostId, blocks } = useSelect( ( select ) => ( {
        postContent:   select( 'core/editor' ).getEditedPostContent(),
        currentPostId: select( 'core/editor' ).getCurrentPostId(),
        blocks:        select( 'core/block-editor' ).getBlocks(),
    } ), [] );

    const { replaceBlocks } = useDispatch( 'core/block-editor' );

    const handleGenerateClick = () => {
        if ( ! postContent || postContent.trim().length === 0 ) {
            setError( __( 'Cannot generate suggestions for an empty post.', 'contextual-link-weaver' ) );
            return;
        }
        setIsLoading( true );
        setError( '' );
        setSuggestions( [] );

        apiFetch( {
            path: '/contextual-link-weaver/v1/suggestions',
            method: 'POST',
            data: { content: postContent, post_id: currentPostId },
        } )
            .then( ( response ) => {
                setSuggestions( Array.isArray( response ) ? response : [] );
                setIsLoading( false );
            } )
            .catch( ( err ) => {
                setError( err.message || __( 'An unknown error occurred.', 'contextual-link-weaver' ) );
                setIsLoading( false );
            } );
    };

    /**
     * Inserts a link by directly manipulating block content HTML.
     *
     * Strategy:
     *   1. Iterate all blocks, find the first whose content contains anchorText
     *   2. Replace the plain text with an <a> tag (class="clw-inserted-link")
     *   3. Use replaceBlocks() to commit the change to the editor store
     *   4. Set up a MutationObserver to detect when the DOM updates, then
     *      scroll to the block and briefly highlight the new link
     *   5. Remove the accepted suggestion from the sidebar list
     *
     * The temporary CSS class "clw-inserted-link" is used to locate the link
     * in the DOM after render, then swapped to "clw-highlight-link" for styling.
     */
    const handleInsertLink = ( anchorText, url ) => {
        let linkInserted = false;
        let targetBlockClientId = null;

        const newBlocks = blocks.map( ( block ) => {
            if ( linkInserted || ! block.attributes.content ) return block;

            let originalContent = '';
            if ( typeof block.attributes.content === 'string' ) {
                originalContent = block.attributes.content;
            } else if ( typeof block.attributes.content === 'object' && block.attributes.content.originalHTML ) {
                originalContent = block.attributes.content.originalHTML;
            } else {
                return block;
            }

            const linkedText = `<a href="${ url }" class="clw-inserted-link">${ anchorText }</a>`;

            if ( originalContent.includes( anchorText ) && ! originalContent.includes( linkedText ) ) {
                const newContent    = originalContent.replace( anchorText, linkedText );
                const newAttributes = { ...block.attributes, content: newContent };
                targetBlockClientId = block.clientId;
                linkInserted        = true;
                return { ...block, attributes: newAttributes };
            }

            return block;
        } );

        if ( linkInserted && targetBlockClientId ) {
            const editorWrapper = document.querySelector( '.editor-styles-wrapper' );

            if ( editorWrapper ) {
                const observer = new MutationObserver( ( _, obs ) => {
                    const targetBlock = document.querySelector( `[data-block="${ targetBlockClientId }"]` );
                    if ( targetBlock ) {
                        targetBlock.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                        const link = targetBlock.querySelector( 'a.clw-inserted-link' );
                        if ( link ) {
                            link.classList.add( 'clw-highlight-link' );
                            link.classList.remove( 'clw-inserted-link' );
                        }
                        obs.disconnect();
                    }
                } );
                observer.observe( editorWrapper, { childList: true, subtree: true } );
            }

            replaceBlocks( blocks.map( ( b ) => b.clientId ), newBlocks );
            setSuggestions( suggestions.filter( ( s ) => s.anchor_text !== anchorText ) );
        } else {
            alert( `Could not find the exact phrase "${ anchorText }" in your content.` );
        }
    };

    return (
        <>
            <PluginSidebarMoreMenuItem target="link-weaver-sidebar" icon={ <LinkWeaverIcon /> }>
                { __( 'Link Weaver', 'contextual-link-weaver' ) }
            </PluginSidebarMoreMenuItem>

            <PluginSidebar name="link-weaver-sidebar" title={ __( 'Link Weaver', 'contextual-link-weaver' ) }>
                <PanelBody title={ __( 'Link Suggestions', 'contextual-link-weaver' ) }>
                    <p style={ { fontSize: '12px', color: '#666', marginBottom: '12px' } }>
                        { __( 'Scan the entire post for up to 5 internal link suggestions, or select text and use the toolbar button for targeted search.', 'contextual-link-weaver' ) }
                    </p>
                    <Button
                        variant="primary"
                        __next40pxDefaultSize
                        onClick={ handleGenerateClick }
                        isBusy={ isLoading }
                    >
                        { isLoading
                            ? __( 'Generating…', 'contextual-link-weaver' )
                            : __( 'Scan Post & Generate', 'contextual-link-weaver' )
                        }
                    </Button>

                    { isLoading && <Spinner style={ { marginTop: '10px' } } /> }
                    { error && <p style={ { color: 'red', marginTop: '10px', fontSize: '12px' } }>{ error }</p> }

                    { suggestions && suggestions.length > 0 && (
                        <div style={ { marginTop: '20px' } }>
                            <h4 style={ { marginBottom: '10px', fontSize: '13px' } }>
                                { __( 'Suggestions:', 'contextual-link-weaver' ) }
                            </h4>
                            <ul style={ { listStyle: 'none', margin: 0, padding: 0 } }>
                                { suggestions.map( ( item, index ) => (
                                    <li key={ index } style={ styles.sidebarItem }>
                                        <p style={ { margin: '0 0 6px 0', fontSize: '13px' } }>
                                            <strong>{ __( 'Phrase:', 'contextual-link-weaver' ) }</strong><br />
                                            <em>&bdquo;{ item.anchor_text }&ldquo;</em>
                                        </p>
                                        <p style={ { margin: '0 0 10px 0', fontSize: '12px', color: '#555' } }>
                                            <strong>{ __( 'Link to:', 'contextual-link-weaver' ) }</strong><br />
                                            { item.title }
                                        </p>
                                        <Button
                                            variant="secondary"
                                            __next40pxDefaultSize
                                            onClick={ () => handleInsertLink( item.anchor_text, item.url ) }
                                        >
                                            { __( 'Insert Link', 'contextual-link-weaver' ) }
                                        </Button>
                                    </li>
                                ) ) }
                            </ul>
                        </div>
                    ) }
                </PanelBody>
            </PluginSidebar>
        </>
    );
};

registerPlugin( 'link-weaver-plugin', { render: LinkWeaverSidebar } );

// ─────────────────────────────────────────────────────────────────────────────
// Shared inline styles
// ─────────────────────────────────────────────────────────────────────────────
// Using inline styles because the plugin doesn't ship a CSS file.
// The Popover and sidebar share some visual patterns (suggestion cards, etc.).

const styles = {
    popover: {
        padding: '16px',
        minWidth: '320px',
        maxWidth: '420px',
        maxHeight: '70vh',
        overflowY: 'auto',
        fontSize: '13px',
    },
    popoverHeader: {
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'baseline',
        gap: '6px',
        marginBottom: '14px',
        paddingBottom: '10px',
        borderBottom: '1px solid #e0e0e0',
        position: 'relative',
        paddingRight: '24px',
    },
    popoverTitle: {
        fontSize: '12px',
        color: '#666',
        fontWeight: 'normal',
    },
    popoverAnchor: {
        fontSize: '13px',
        fontWeight: '600',
        color: '#1e1e1e',
    },
    closeBtn: {
        position: 'absolute',
        top: 0,
        right: 0,
        background: 'none',
        border: 'none',
        cursor: 'pointer',
        fontSize: '14px',
        color: '#888',
        padding: '0',
        lineHeight: 1,
    },
    section: {
        padding: '12px',
        background: '#f9f9f9',
        borderRadius: '6px',
        border: '1px solid #eaeaea',
    },
    sectionHeader: {
        display: 'flex',
        alignItems: 'center',
        gap: '8px',
        marginBottom: '10px',
    },
    sectionLabel: {
        fontSize: '11px',
        fontWeight: '600',
        textTransform: 'uppercase',
        letterSpacing: '0.5px',
        color: '#555',
    },
    errorText: {
        color: '#cc1818',
        fontSize: '12px',
        margin: '4px 0',
    },
    emptyText: {
        color: '#888',
        fontSize: '12px',
        margin: '4px 0',
    },
    suggestionItem: {
        paddingBottom: '4px',
    },
    suggestionTitle: {
        margin: '0 0 3px 0',
        fontWeight: '600',
        fontSize: '13px',
        color: '#1e1e1e',
    },
    suggestionReason: {
        margin: '0 0 8px 0',
        fontSize: '11px',
        color: '#888',
        lineHeight: '1.4',
    },
    sidebarItem: {
        border: '1px solid #ddd',
        padding: '12px',
        borderRadius: '4px',
        marginBottom: '10px',
    },
};
