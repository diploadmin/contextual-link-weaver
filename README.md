# Contextual Link Weaver for WordPress

**Contextual Link Weaver** is an AI-powered internal linking assistant for the WordPress Gutenberg editor. It combines LLM intelligence with RAG-based knowledge base search to suggest relevant links as you write — both from your own site's posts and from external knowledge sources.

## Features

### Toolbar Button — Selection-Based Linking
Select any phrase in the editor, click the **superhero** icon in the block toolbar, and get link suggestions from two sources in parallel:

- **Knowledge Base Sources (RAG)** — queries an external chatbot API to find the most relevant pages from your knowledge base. Returns up to 5 results with deep-link URLs that highlight the matching passage on the target page.
- **Internal Posts (LLM)** — sends the selected phrase along with all published posts to your configured LLM, which ranks the top 5 most contextually relevant posts to link to.

Results appear in a popover with **Insert Link** buttons. Clicking one wraps the selected text in a standard `<a>` tag.

### Sidebar — Full Post Scan
Open the **Link Weaver** sidebar panel and click **Scan Post & Generate** to analyze the entire post content. The LLM identifies up to 5 verbatim phrases in your text that would benefit from internal links and suggests the best target post for each. One-click insertion places the link and scrolls to it in the editor.

### Multi-Provider LLM Support
Choose between two LLM backends in **Settings > Link Weaver**:

| Provider | Model | Auth |
|---|---|---|
| **Google Gemini** | `gemini-2.5-flash` | API key (URL param) |
| **Local / Custom LLM** | Any OpenAI-compatible endpoint | Bearer token |

The provider can be switched at any time without losing the other provider's credentials.

## Installation

1. Download or clone this repository.
2. Upload the `contextual-link-weaver` folder to `wp-content/plugins/`.
3. Activate the plugin in **Plugins**.
4. Go to **Settings > Link Weaver** and configure your LLM provider and (optionally) the RAG Chatbot API URL.

## Settings

Navigate to **Settings > Link Weaver** in the WordPress admin.

### LLM Provider

Select **Google Gemini** or **Local / Custom LLM**. The settings section for the selected provider is shown; the other is hidden.

**Google Gemini:**
| Field | Description |
|---|---|
| Gemini API Key | Your Google AI Studio API key. Get one free at [aistudio.google.com](https://aistudio.google.com/app/apikey). |

**Local / Custom LLM (OpenAI-compatible):**
| Field | Example |
|---|---|
| Base URL | `https://chatgpt-oss.mydepartment.ai/v1` |
| Model Name | `openai/gpt-oss-20b` |
| API Key | Bearer token (leave empty if not required) |

The Base URL should include `/v1`. The plugin appends `/chat/completions` automatically.

### RAG / Chatbot API (Source Discovery)

| Field | Example |
|---|---|
| Chatbot API URL | `https://chat-api.humainism.ai` |

When set, the toolbar button will also query this API for knowledge base sources. The API must implement:

- `POST /api/conversation/get_id` — returns `{ "conversationId": "..." }`
- `POST /api/chat/{conversation_id}` — accepts `{ "user_ip", "message", "user_type" }`, returns `{ "sources": [{ "title", "url", "deep_link_url", "text" }] }`

Leave empty to disable RAG source discovery (LLM-only mode).

## REST API Endpoints

All endpoints require the `edit_posts` capability (authenticated WordPress editors/admins).

### `POST /wp-json/contextual-link-weaver/v1/suggestions`

Full post scan. Sends the entire post content + list of all published posts to the LLM.

**Request:**
```json
{ "content": "<post HTML content>", "post_id": 123 }
```

**Response:** Array of suggestions, each with `anchor_text`, `title`, `url`, `reasoning`.

### `POST /wp-json/contextual-link-weaver/v1/link-for-text`

Selection-based LLM query. Sends a highlighted phrase + all published posts to the LLM.

**Request:**
```json
{ "anchor_text": "digital governance framework", "post_id": 123 }
```

**Response:** Array of up to 5 matches, each with `post_id`, `title`, `url`, `reasoning`.

### `POST /wp-json/contextual-link-weaver/v1/link-from-rag`

RAG-based source discovery. Queries the external chatbot API.

**Request:**
```json
{ "query": "digital governance framework" }
```

**Response:** Array of up to 5 sources, each with `title`, `url`, `text` (snippet).

## File Structure

```
contextual-link-weaver/
├── contextual-link-weaver.php     # Main plugin file: settings, REST routes, handlers
├── includes/
│   └── gemini-api.php             # LLM API abstraction (Gemini + OpenAI-compatible)
├── src/
│   └── index.js                   # Gutenberg React source (toolbar + sidebar)
├── build/
│   ├── index.js                   # Compiled JS (served to editor)
│   └── index.asset.php            # Auto-generated dependency manifest
├── package.json                   # @wordpress/scripts build tooling
└── README.md
```

## Development

```bash
git clone git@github.com:diploadmin/contextual-link-weaver.git
cd contextual-link-weaver
npm install
npm start        # Watch mode (auto-rebuild on change)
npm run build    # Production build
```

The plugin uses `@wordpress/scripts` for building. Source JS is in `src/index.js`, compiled output goes to `build/`.

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                  Gutenberg Editor                    │
│                                                     │
│  ┌──────────────┐    ┌────────────────────────────┐ │
│  │   Sidebar    │    │   Toolbar Button (select)  │ │
│  │  "Scan Post" │    │   ┌─────────────────────┐  │ │
│  └──────┬───────┘    │   │      Popover        │  │ │
│         │            │   │  ┌───────────────┐  │  │ │
│         │            │   │  │ RAG Sources   │  │  │ │
│         │            │   │  │ LLM Posts     │  │  │ │
│         │            │   │  └───────────────┘  │  │ │
│         │            │   └─────────────────────┘  │ │
│         │            └─────────┬──────────┬───────┘ │
└─────────┼──────────────────────┼──────────┼─────────┘
          │                      │          │
          ▼                      ▼          ▼
   /v1/suggestions      /v1/link-for-text  /v1/link-from-rag
          │                      │          │
          ▼                      ▼          ▼
   ┌─────────────┐      ┌──────────┐  ┌──────────────┐
   │  LLM API    │      │ LLM API  │  │ Chatbot API  │
   │ (Gemini or  │      │          │  │  (RAG/search) │
   │  local)     │      │          │  │              │
   └─────────────┘      └──────────┘  └──────────────┘
```

## Changelog

### 1.3.0
- Added RAG-based source discovery via external chatbot API
- Toolbar popover now fires LLM + RAG queries in parallel
- New settings field for Chatbot API URL
- Results displayed in two sections: Knowledge Base Sources + Internal Posts

### 1.2.0
- Added multi-provider LLM support (Google Gemini + OpenAI-compatible)
- Settings page with provider selector and conditional field display
- Settings stored separately per provider (switching doesn't erase credentials)

### 1.1.0
- Switched from Gemini to OpenAI-compatible API format
- Three configurable settings fields (URL, model, API key)

### 1.0.0
- Initial release with Gemini-powered sidebar suggestions
- One-click link insertion with scroll-to-link

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
