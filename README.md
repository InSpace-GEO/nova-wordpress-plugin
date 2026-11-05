# NOVA WordPress Plugin

Map NOVA content to WordPress layouts and receive publishing deliveries. Requires WordPress 6.9 or newer and PHP 8.0 or newer.

## Install

Download the installable plugin ZIP from this repository's Releases and upload it under **Plugins → Add New → Upload Plugin**. Use the release asset, which includes the WordPress plugin directory.

On a site using NOVA Bridge Suite, let in-flight deliveries finish, then deactivate the Suite before activating this plugin. A delivery already planned against the old writer can pause for reconciliation when its plugin version changes. Existing saved layouts, posting credentials and delivery receipts keep their storage keys. The optional-module settings use their own option and initially adopt the site's Blog, Service and WooCommerce choices. Use the original Suite on sites that still use legacy bridge endpoints or live-source cloning.

If you installed 1.0.0, upload the latest release ZIP once and choose **Replace current with uploaded**. From 1.0.1 onward, the active plugin checks the public GitHub release during WordPress's normal update check. Install available updates under **Dashboard → Updates** or enable auto-updates under **Plugins**. No GitHub account or token is needed on the site.

In **Settings → NOVA Settings**, enable the optional modules the site uses and save the connection credentials and posting user. Compatible delivery starts automatically once those settings are complete. Manage pauses in the NOVA app. If installations share a NOVA site ID, expand **Multi-site WordPress** to configure URL routing.

If you paused delivery in WordPress on an earlier version, set the pause in NOVA before upgrading to 1.0.2 if you want it to remain in effect.

WordPress suggests the eligible site member with the most published posts, pages and editorial custom posts. Choose an account that can publish the types you map. New posts use that account as their author. Updates preserve the existing byline. Product category deliveries require an administrator with category permissions. An upgrade preserves the existing posting account until you explicitly save a selection for new work.

The picker finds native publishing capabilities assigned through a role or directly to a site member. It also checks network super admins who belong to the site. If another plugin grants publishing permission only through a filter, an existing selection remains usable while that permission is effective. To make a new account discoverable, give it the corresponding WordPress role or direct capability.

Under **Layouts**, choose an example, map its fields and save directly from **Content rules** in step 3. Check the sync result on that screen. The posting service selects a template before it adapts the article.

Two WordPress destinations can share a NOVA page type when their permalinks distinguish them. For example, assign **Service** to both **Pages** and a **Masterclass** post type registered at `/masterclass/`. A Service item at `/masterclass/budgeting/` uses the Masterclass layout. A Service item at `/advice/` uses the Pages layout. An explicit template assignment for an individual NOVA URL takes priority. If both destinations publish directly at the root, WordPress reports the conflict and keeps the confirmed routing in place.

In **2. Choose a design**, open **Reference item** to browse or type to filter the list. Use **Use an item ID** for an example outside the list. Selecting the same example for the same WordPress destination resumes the mapping. To rebuild it from a changed example, choose **Capture a fresh layout** in step 2. In Elementor, **Design settings copied from your example** starts with recognized design settings selected for a new layout. **Select all** and **Deselect all** change them together. Saved layouts retain their previous choices. Unrelated registered metadata is available separately and is opt-in.

### Shared Elementor components

In **Content rules**, **Shared Elementor components** identifies each **Global widget** and **Saved template**. **Keep shared** preserves the native reference and protects its fields from mapping. Keep the library component available: edits to that shared component also appear on pages that use it.

To give NOVA different content for each page, choose **Create independent copy for mapping**, then select the copied fields and save. This stores the component's native elements and styling in the layout. Each delivered page receives its own copy; NOVA never changes the shared original or its other instances. Nested supported components are captured with it, so the copy no longer needs their library records. Other occurrences stay shared unless selected separately.

This supports Elementor Global widgets, Template widgets and a Shortcode widget containing a single `[elementor-template id="..."]` shortcode. Arbitrary executable shortcodes cannot be converted into native Elementor layouts. Missing, unsupported or circular library references need attention before saving. Existing saved mappings keep their fields and revisions; reopen and sync a revision previously blocked only by a supported shared component, or capture it again to choose independent copies.

When exporting a layout that keeps shared components, the receiving installation needs matching published Elementor library content. Import checks its contents and media before resolving local IDs; matching numbers alone are insufficient. Independent copies carry their captured elements, while their local media dependencies follow the normal import checks.

## Share a layout across WordPress installations

On the installation where you manage the layout, save the mapping and confirm the posting-service default for its NOVA page type. In **Layouts → Share layouts between installations**, select the revisions and click **Export selected layouts**. Include older revisions if deliveries were already prepared against them.

On each receiving installation, enter the same NOVA site ID, posting-service address and access token. Choose its local publishing user. Before the first connection save, select **Multi-site WordPress → Only receive shared layouts on this installation** to prevent automatic template uploads during setup. Then open **Layouts → Share layouts between installations**, select the file and click **Import layouts**.

A successful import makes this installation receive-only for that NOVA connection, even if the box was left unchecked. Edit shared mappings on the installation that exports them. Receiving installations can publish deliveries, return receipts and import future revisions. The file takes precedence locally for its template IDs and revision numbers. The destination cards show the imported revisions available to the writer. You can import a newer revision while queued work still targets an older one.

The importer checks site-specific references. For a supported image control, it uses the source file’s contents and media type to verify the local attachment. If the correct file is missing or ambiguous, the prompt identifies it and accepts the ID of an equivalent local attachment. Upload the original file to the receiving Media Library if necessary, then retry the same file. Linked objects that the importer cannot verify are identified before any revision in that file is enabled.

Sharing supports fixed layouts for WordPress post types built with the WordPress editor, Gutenberg, Elementor, Flatsome or Enfold. The export picker identifies builders that can publish locally but cannot yet share layouts, including Cornerstone. Each delivery must include its selected template in its publishing configuration.

## Supported paths

- Fixed-layout delivery for WordPress content, Gutenberg, Elementor, Flatsome and Enfold. The corresponding builder/theme must be available on the site.
- Fixed ACF layouts expose content inside groups, repeaters and Flexible Content rows, including native image fields. NOVA stores the example's component order and unselected values, then writes through the installed ACF field definitions. The theme and matching field groups must remain installed; the example can be deleted after capture. Component layouts are detected automatically when the native WordPress body is empty. Other examples keep their existing builder detection; choose ACF under advanced builder detection when appropriate. Existing mappings are not converted automatically: choose **Capture a fresh layout** to use the nested fields, map them and save. Shared-layout export is not yet available for this provider.
- For Cornerstone, select a page or post built in the content builder and map its fields under **Content rules**. NOVA saves the element tree and the page’s layout settings with the mapping. Deliveries create or update the native Cornerstone document; the example can be deleted after capture. Site-wide library components remain linked. Use the field list for controls without a unique visible element. When mapping blog posts, enable **Posts** in Cornerstone’s permissions for the NOVA publishing user. For an example captured as plain WordPress in an older version, choose **Capture a fresh layout** in step 2 and save the new mapping.
- Native publishing to NOVA Blog and Service post types, and WooCommerce category content with separate top and bottom fields.
- WPBakery, Avada, Beaver Builder, Breakdance, Divi 4 and Divi 5 support layout capture. In **Layouts**, select an existing Divi 5 page to capture a fixed layout with its native adapter. The generic Gutenberg and plain WordPress writers leave Divi 5 documents protected.
- The existing NOVA posting contract and ready webhook URL remain compatible. Older explicit live-source clone jobs require the original Suite.

## Publishing in multiple languages

Configure the languages and their URLs in one multilingual plugin. Each delivery needs the article language and the corresponding public URL. If the URL belongs to a different language, WordPress refuses the write.

- **WPML and Polylang:** Each translation is a native WordPress post. Either language may arrive first. When NOVA sends siblings with the same URL ID, WordPress links them for the language switcher. A later update changes only the post for that language.
- **TranslatePress and Weglot:** Each translation is a localized view of a single published original. Publish or update the original through NOVA for the same URL ID first. If the translation arrives first, the worker waits and retries for a limited time. NOVA does not guess which manually created post to translate. TranslatePress also requires the posting user to have its translator capability.

The single-original providers support mapped WordPress and Gutenberg content and the supported text fields on NOVA Blog and Service posts. Weglot also supports saved Elementor documents. Use the normal WordPress delivery request for Weglot: its SDK does not resolve language URLs under WP-CLI. Separate translated drafts, translated media fields and other stored builders are not supported by these providers. Check the target language URL after publishing; the original should retain its own content.

TranslatePress stores identical source strings in a shared dictionary. NOVA leaves conflicting manual translations and entries known to be used by another post untouched. TranslatePress may not have recorded a page it has never rendered; use a distinct source string when the same text needs different translations on different pages.

### Mapping custom fields

In step 1, select every **NOVA content type** that should use the layout. Service and Category can share one template that creates normal WordPress pages; Category does not require WooCommerce. Distinct destination URL routes still apply per content type. Removing a type detaches this template's routing for future deliveries; retained revisions continue to handle their already queued deliveries. Install the same plugin version on installations that share exported layouts with multiple content types.

In step 3 of a fixed post layout, **Design settings copied from your example** keeps captured appearance settings such as width, background and sidebar. These do not need AI instructions. Open **Additional content fields (optional)** only to select ACF or registered WordPress fields NOVA should fill with new article content. Each selected field can have optional instructions. Closing either section keeps the selections and does not disable mappings. Existing saved editorial selections are retained; a recognized design setting already selected as content offers an explicit action to copy its example setting instead.

Save the layout on the same screen and check the sync result. For a PHP page template that renders its content from ACF, use a WordPress layout with that page template and select its custom fields. The WordPress body can be empty.

The picker supports top-level ACF text, textarea, WYSIWYG, URL and email fields, plus public WordPress fields registered as single REST strings. A delivery that omits an optional field preserves its current value. Repeater rows, flexible layouts, media and relationship fields are outside this picker.

WPML and Polylang store the mapped values on the native post for each language. Configure translated fields to allow independent values. TranslatePress and Weglot keep localized field values on the shared source post and serve them on that post’s public page through normal WordPress metadata and ACF reads. A static builder layout can use these localized fields even when the provider cannot translate the builder’s own widgets. If a theme rewrites a field’s text or prints an identical phrase in multiple places, the provider may translate that output again.

## Development

Start each change from the latest `main`. Use `feature/<short-description>` for new functionality (for example, `feature/share-layouts`) and `hotfix/<short-description>` for bug fixes (for example, `hotfix/wpml-featured-image`).

Open a pull request into `main` and describe how you verified the change. To run the local suite, set `NOVA_WP_CORE_FIXTURE` to a WordPress `wp-includes` directory and run `python tools/test.py` with PHP and Node on your path. For changes to publishing, check the rendered result on staging. After the PR merges, GitHub automatically deletes its source branch in this repository.

The plugin derives from NOVA Bridge Suite commit `7e124d48d914b805a31bee340eb6f7fe338d61cc`. Retained wire names and option keys preserve saved mappings.

## Manual releases

Merging a PR puts the change on `main`. Publishing a GitHub release makes the update available to WordPress installations. Documentation-only changes need no plugin release.

1. Before release, use a PR to set the same new version in the plugin header and `NOVA_BRIDGE_SUITE_VERSION` in `nova-wordpress-plugin.php`, and in the `Stable tag` in `readme.txt`. Add the user-facing changes to the changelog in `readme.txt`. This can be part of the feature or hotfix PR.
2. After merge, check out the exact `main` commit to be released and run `python tools/package.py OUTPUT_DIRECTORY`. Test the generated installable ZIP on staging.
3. Manually create a GitHub release at that same commit. For version `X.Y.Z`, use tag `vX.Y.Z` and title `X.Y.Z`. Attach both generated files, `nova-wordpress-plugin-X.Y.Z.zip` and `wordpress-update.json`, then publish the stable release as **Latest**.
4. Verify that the public manifest reports the new version and that the versioned ZIP downloads without GitHub credentials. Sites discover the release at their next WordPress update check and can install it automatically if they have enabled auto-updates. [Public update manifest](https://github.com/InSpace-GEO/nova-wordpress-plugin/releases/latest/download/wordpress-update.json)
