# AMS AI Bridge — v0.1.1 (Wren development project)

**Status:** Dashboard screenshot confirms v0.1.0 is active on AMS; v0.1.1 adds an admin-only Create Working Draft button. **Neither version has passed an end-to-end Elementor draft/edit/publish test.**

**Project owner:** Wren — Website & Automation Director.  
**Authorized collaborator:** Quill — SEO Director (SEO research, copy preparation, approved widget draft edits).  
**Human review/approval:** Jimy; Jessie can review/edit Elementor drafts under existing WordPress permissions.  
**App integration:** Wren's developing app consumes the versioned REST contract; no dependency on its unpublished internal code.

## Mission

Create, edit, preview and approve Elementor-compatible pages through AI-assisted workflows while preserving manual Elementor editing. Start with the existing roofing contractor marketing page and preserve the production URL. Do not automatically publish AI-generated content.

## MVP capabilities

- Authenticated status and Elementor source-page read-only summary.
- A **single hardcoded source allowlist**: the published page whose slug is `roofing-contractor-marketing`.
- Create a separate WordPress **draft** containing the source page's Elementor data, page template, and page design settings, either through authenticated REST or through **Tools → AMS AI Bridge → Create working draft** (admin only).
- Enumerate editable text widgets and change exactly one widget field on a draft, with optimistic concurrency via SHA-256 hash.
- Accept only `heading.title`, `text-editor.editor`, `button.text`, `icon-box.title_text`, `icon-box.description_text`, `image-box.title_text`, and `image-box.description_text`.
- Sanitize all text; formatted text is filtered by `wp_kses_post`.
- **No AI publishing endpoint.** A WordPress administrator reviews the draft, then chooses to apply it from **Tools → AMS AI Bridge**, requiring a browser nonce, user permissions, and confirmation.
- A separate private snapshot is saved before each production apply. Administrators can restore snapshots within the same Tools screen.
- Refuse to overwrite the live page if its Elementor data changed since draft creation.

## Current limitations and design decisions

1. Version 0.1 edits basic text widgets only; it **does not support structural moves, image changes, global templates, popups, or complete page creation from scratch**.
2. An Elementor editor still owns layout and styling. This plugin manipulates stored Elementor JSON and asks Elementor to regenerate its CSS cache; future Elementor versions may require compatibility updates.
3. The draft is a **standalone WordPress draft page**, not a WordPress revision of the original. The live URL is untouched until an administrator applies changes. The draft doesn't copy SEO plugin metadata, featured images, header/footer templates, or external custom fields. Existing live SEO metadata remains untouched when applying.
4. Applying transfers **only `_elementor_data`** from draft to source. It doesn't copy draft title, slug, WP body content or Elementor page settings. This limitation protects the existing live page in the pilot.
5. The live source should already be a native Elementor page. Do **not** apply to pages built by other editors.
6. WordPress data/DB backups and staging remain mandatory. Plugin snapshots cover only Elementor layout JSON, **not full-site backups**.
7. Performance/compatibility with the actual theme, Elementor version, Elementor Pro and other plugins is **unverified** until local staging QA.
8. No direct authentication into Wren's unfinished app is included. Instead use a versioned, stable JSON REST API. Wren can implement its own app-side adapter.

## Installation (staging first)

1. Take a **full WordPress database + files backup** and confirm restoration procedures before installation. Use a staging site, not production, for first tests.
2. In WordPress Admin: Plugins → Add New → Upload Plugin → choose the `ams-ai-bridge-v0.1.0.zip` file → Install → Activate.
3. Confirm Elementor is active and `roofing-contractor-marketing` resolves to the correct published page (observed ID **11625** in AMS screenshot).
4. After activation, WordPress administrators receive three custom permissions. The plugin also creates an **AMS AI Bridge Agent** role with only `read`, `ams_ai_bridge_read`, and `ams_ai_bridge_draft`. A dedicated WP user with this role can make authenticated REST calls without standard WordPress page-editing access. **Do not share admin credentials.**
5. For REST access, create a WordPress **Application Password** for the dedicated agent account (WordPress User Profile → Application Passwords, if enabled); keep credentials in Wren's secret manager. HTTPS required. Do not paste credentials into ChatGPT or commit them to code.
6. In staging, click **Create working draft (no live changes)** in Tools → AMS AI Bridge. Then verify `GET /wp-json/ams-ai-bridge/v1/status` and patch a basic heading through the authenticated API. Review in Elementor, then use Tools → AMS AI Bridge for a human-controlled apply. Confirm rollback before repeating in production.

### API base URL

`https://www.awakenmediasolutions.com/wp-json/ams-ai-bridge/v1` **for production after QA**. During initial testing, replace the hostname with the actual staging domain.

### Endpoints

| Method | Route | Result |
| --- | --- | --- |
| `GET` | `/status` | Plugin version, site page ID and supported features |
| `GET` | `/pages/{id}` | Summary and hash for allowlisted live source |
| `POST` | `/pages/{id}/draft` | Separate Elementor draft and draft editor URL |
| `GET` | `/drafts/{id}/widgets` | Text-editable widgets and current draft hash |
| `PATCH` | `/drafts/{id}/widgets/{widget_id}` | Update exactly one text field on a draft |

`PATCH` JSON request body:

```json
{
  "field": "title",
  "value": "Roofing Marketing Systems for Inland Empire Contractors",
  "expected_hash": "<sha256 from GET /drafts/{id}/widgets>"
}
```

Response returns `new_hash`, which the Wren app must use for subsequent changes. If another editor has changed the page, the API returns HTTP `409` and requires reloading. Never automatically retry stale hashes.

**Authentication:** WordPress Application Password over HTTPS (HTTP Basic Auth). **Authorization:** AMS custom permissions and source allowlist. Agent role cannot use the WordPress admin apply/restore forms. Keep HTTP requests and their credentials in Wren's server backend; never put secrets in browser JavaScript.

## Integration contract for Wren

Wren app should implement an adapter interface:

```ts
interface AmsBridgeAdapter {
  status(): Promise<BridgeStatus>;
  inspectPage(sourceId: number): Promise<SourceSummary>;
  createDraft(sourceId: number): Promise<{draft_id: number; edit_url: string; draft_hash: string}>;
  listWidgets(draftId: number): Promise<{hash: string; widgets: EditableWidget[]}>;
  updateWidget(draftId: number, widgetId: string, field: string, value: string, expectedHash: string): Promise<{new_hash: string}>;
}
```

The adapter should be independent from app UI internals and accept a configurable HTTPS base URL; it must never use the WordPress site's database credentials. Quill may propose content, select existing editable widget IDs, and request draft edits through Wren's mediated access. Approval/publishing belongs to humans through WordPress admin.

**Future integration points (NOT implemented):** `site_id`, `job_id`, `agent_id`, proposed change diff, structured approvals, audit logs, multi-site policy, Elementor widget-schema support, Elementor revision snapshots and UI previews. Version new endpoints rather than changing existing response shapes unexpectedly.

## Acceptance checks before production

- [ ] Dedicated agent can read source and edit bridge drafts but cannot publish pages or use the admin apply screen.
- [ ] Attempt to edit a nonallowlisted page returns forbidden.
- [ ] Source page is unchanged after a draft edit.
- [ ] Draft text remains editable inside Elementor after save/reopen.
- [ ] Existing colors, responsive styling, section order and images remain unchanged.
- [ ] Invalid/missing widget ID is rejected, and a stale hash returns 409.
- [ ] WP admin can apply an approved draft to the original URL and see updated content.
- [ ] Source changed between draft and apply triggers conflict rejection.
- [ ] Admin can restore the snapshot; full hosting backup can also be restored.
- [ ] Canonical URL, Yoast metadata, forms, links, headings, analytics and mobile layout are unaffected except for intentional changes.
- [ ] Wren can operate all REST endpoints from a backend adapter without exposing the Application Password to the browser.

## Scope notes

Never reactivate ClickRank or change the www canonical as part of this pilot. Do not fabricate testimonials, performance metrics or client results. All AI-written material must be reviewed for AMS's human-first voice and alignment with the Marketing Systems Partner positioning.

## 0.1.1 change log — November 19, 2026

- Added **Tools → AMS AI Bridge → Create working draft (no live changes)**.
- Draft creation uses the same allowlisted roofing source and guards as API creation, plus WordPress admin privilege + nonce checks.
- Copied `_wp_page_template` to new drafts for closer visual parity.
- Made no changes to REST endpoint paths or payload shapes, so Wren's app adapter contract remains `v1`.
- **Production caution:** Although the plugin is active on the live AMS site, full Elementor integration and restore testing have **not yet been completed**. Create/read a test draft only after site backups are confirmed. Never use Apply to live page during initial QA.
