# Zib Meta Image Upload Design

## Goal
Automatically upload `zib_other_data` fields `cover_image` and `thumbnail_url` to LskyPro on post save when remote image processing is enabled, then replace the stored URLs with the uploaded Lsky URLs.

## Scope
- Only runs when the existing option `process_remote_images` is enabled.
- Only touches `zib_other_data` and only the two keys above.
- Uses existing upload flows and mapping caches to avoid duplicate uploads.

## Architecture
- Keep `Remote::process_post_images()` focused on `post_content`.
- Add a dedicated method in `Remote` for processing `zib_other_data` values.
- Call this method from `PostHandler::handle_post_save()` after `process_post_images()` completes.
- Reuse `_lsky_pro_processed_urls` and `_lsky_pro_processed_photo_ids` for caching.

## Data Flow
1. `handle_post_save()` runs when saving a post and `process_remote_images` is enabled.
2. After content processing, load `zib_other_data` via `get_post_meta` and `maybe_unserialize`.
3. If data is not an array, or fields are empty/not strings, skip.
4. Normalize URLs by removing query/fragment for mapping keys.
5. If URL exists in `_lsky_pro_processed_urls`, replace directly.
6. Else, determine source type:
   - Local media URL (site baseurl): use `processLocalMediaImage()`.
   - Remote URL: use `processRemoteImage()`.
7. On success, update mapping caches and replace the field value.
8. If at least one field changed, `update_post_meta()` with the updated array.

## Error Handling
- If upload fails, keep original value and log error.
- Do not clear or overwrite unrelated keys in `zib_other_data`.
- Do not throw exceptions that block post save flow.

## Logging
Add concise logs for:
- Field name being processed.
- Original URL and replacement URL (when changed).
- Source type (local/remote) and whether mapping cache was used.

## Testing
- When `zib_other_data` is empty or non-array: skip and do not upload.
- When field URL is remote and not cached: upload and replace.
- When field URL is local media: upload and replace.
- When URL is cached: replace without uploading.
- When URL is already Lsky: skip upload and leave as-is (or cache mapping).
- When upload fails: keep original value, continue processing the other field.
