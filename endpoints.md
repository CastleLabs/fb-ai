# AI Engine REST API Reference (Meow Apps)

This document consolidates all known REST API endpoints exposed by the **AI Engine** WordPress plugin (namespace: `/wp-json/mwai/v1`). It includes public-facing endpoints, admin-only configuration routes, helper utilities, and authentication methods.

---

## 1. Authentication

### Option A: Application Passwords (recommended)

* Create an Application Password for an **Administrator** user.
* Use Basic Auth: `--user 'username:app_password'`
* Requires **HTTPS**.

**Example:**

```bash
curl -i \
  --user 'adminuser:APP_PASSWORD' \
  -H 'Accept: application/json' \
  https://example.com/wp-json/mwai/v1/settings/chatbots
```

### Option B: WP Nonce (frontend only)

* For logged-in admin sessions.
* Pass `X-WP-Nonce` header with cookies.

---

## 2. Core Chat & Query Endpoints

These endpoints are designed for integration (e.g., Messenger bots).

* `POST /simpleTextQuery` – single text response.
* `POST /simpleFastTextQuery` – faster, lightweight text responses.
* `POST /simpleChatbotQuery` – chatbot conversation (supports `chatId` for memory).
* `POST /simpleJsonQuery` – enforce JSON-formatted replies.
* `POST /simpleVisionQuery` – analyze images.
* `POST /simpleImageQuery` – generate images.
* `POST /simpleImageEditQuery` – edit images.
* `POST /simpleTranscribeAudio` – transcribe audio.
* `POST /simpleFileUpload` – upload file (URL or base64).
* `POST /moderationCheck` – text moderation.

---

## 3. Chatbot & Settings Management

Endpoints to read or update chatbot instructions and plugin options.

* `GET /settings/chatbots` – list chatbot configs (includes `instructions`).
* `POST /settings/chatbots` – create/update chatbot definitions.
* `GET /settings/options` – view plugin options.
* `POST /settings/update` – bulk update options (dangerous; overwrites all).
* `POST /settings/reset` – reset settings.
* `GET|POST /settings/themes` – manage AI Engine themes.

---

## 4. Sessions & Auth Utilities

* `POST /start_session` – start chatbot session.
* `GET /simpleAuthCheck` – check auth status.

---

## 5. AI Core Endpoints

Lower-level routes for interacting directly with AI providers.

* `POST /ai/completions` – completions API.
* `POST /ai/images` – generate images.
* `POST /ai/image_edit` – edit images.
* `POST /ai/copilot` – code assistant.
* `POST /ai/magic_wand` – enhancement features.
* `POST /ai/json` – structured responses.
* `POST /ai/moderate` – moderation.
* `POST /ai/transcribe_audio` – transcribe audio.
* `POST /ai/transcribe_image` – OCR on images.
* `POST /ai/models` – list models.
* `POST /ai/test_connection` – check provider connectivity.

---

## 6. OpenAI Utility Endpoints

For managing OpenAI files and fine-tuning jobs.

* Files: `GET /openai/files/list`, `POST /openai/files/upload`, `delete`, `download`, `finetune`.
* Fine-tunes: `GET /openai/finetunes/list`, `list_deleted`; `POST /openai/finetunes/delete`, `cancel`.

---

## 7. Content Helper Endpoints

AI-powered WordPress automations.

* **Posts:**

  * `POST /helpers/update_post_title`
  * `POST /helpers/update_post_excerpt`
  * `POST /helpers/create_post`
  * `GET /helpers/post_content`

* **Images:**

  * `POST /helpers/create_image`
  * `POST /helpers/generate_image_meta`

* **Posts Info:**

  * `GET /helpers/count_posts`
  * `GET /helpers/posts_ids`
  * `GET /helpers/post_types`

* **Tasks & Cron:**

  * `GET /helpers/tasks_list`
  * `POST /helpers/task_run|pause|resume|delete`
  * `GET /helpers/task_logs`
  * `POST /helpers/task_logs_delete`
  * `POST /helpers/tasks_reset`
  * `POST /helpers/task_create_test`
  * `GET /helpers/cron_events`
  * `POST /helpers/run_cron`
  * `POST /helpers/optimize_database`

---

## 8. Logs & Templates

* Logs: `POST /system/logs/list|delete|meta|activity|activity_daily`, `GET /get_logs`, `GET /clear_logs`.
* Templates: `GET|POST /system/templates`.

---

## 9. Discussions & Forms

* Discussions: `POST /discussions/list`, `delete`.
* Forms: `GET /forms/list|get`, `POST /forms/create|update|delete`.

---

## 10. Miscellaneous

* `GET /listChatbots` – quick list of chatbot IDs.
* `GET /mcp/functions` – list MCP functions.

---

## 11. Usage Notes

* **Integration focus**: For Messenger or external bots, stick to `simpleChatbotQuery`, `simpleVisionQuery`, `simpleFileUpload`, and `moderationCheck`.
* **Admin-only**: Settings and chatbots management require **admin authentication** (App Password or nonce).
* **Best practice**: Use `/settings/chatbots` for targeted chatbot updates. Avoid `/settings/update` unless replacing the entire options object.

---

## 12. Example: Update Chatbot Instructions

```bash
curl -X POST \
  --user 'adminuser:APP_PASSWORD' \
  -H 'Content-Type: application/json' \
  -d '{
    "chatbots": {
      "supportBot": {
        "name": "Support Bot",
        "instructions": "You are a helpful support assistant. Always ask for order ID first.",
        "active": true
      }
    }
  }' \
  https://example.com/wp-json/mwai/v1/settings/chatbots
```

---

## 13. Troubleshooting

* **401 Unauthorized** → wrong username/password, or server dropping `Authorization` header.
* **403 Forbidden** → user lacks `manage_options` capability.
* **404 Not Found** → plugin inactive or endpoint disabled.
* **Cloudflare/security plugins** may block `Authorization`; whitelist `/wp-json/mwai/v1/*` if needed.
