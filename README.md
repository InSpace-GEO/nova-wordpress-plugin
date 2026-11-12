# NOVA WordPress Plugin

Map NOVA content to WordPress layouts and receive publishing deliveries. Requires WordPress 6.9 or newer and PHP 8.0 or newer.

## Install

Download the installable plugin ZIP from this repository's Releases and upload it under **Plugins → Add New → Upload Plugin**. Use the release asset, which includes the WordPress plugin directory.

On a site using NOVA Bridge Suite, let in-flight deliveries finish, then deactivate the Suite before activating this plugin. A delivery already planned against the old writer can pause for reconciliation when its plugin version changes. Existing saved layouts, posting credentials and delivery receipts keep their storage keys. The optional-module settings use their own option and initially adopt the site's Blog, Service and WooCommerce choices. Use the original Suite on sites that still use legacy bridge endpoints or live-source cloning.

If you installed 1.0.0, upload the latest release ZIP once and choose **Replace current with uploaded**. From 1.0.1 onward, the active plugin checks the public GitHub release during WordPress's normal update check. Install available updates under **Dashboard → Updates** or enable auto-updates under **Plugins**. No GitHub account or token is needed on the site.

In **Settings → NOVA Settings**, enable the optional modules the site uses and save the connection credentials and posting user. Compatible delivery starts automatically once those settings are complete. Manage pauses in the NOVA app. If installations share a NOVA site ID, expand **Multi-site WordPress** to configure URL routing.

To switch from a test service to production, enter the new service URL, website identifier and credentials and click **Save NOVA connection**. A dialog asks whether to **Copy and synchronize** the current layouts and mappings or **Switch without copying**. Pending deliveries from the previous connection are cancelled locally and its delivery history is kept in a separate private archive; a notice after saving says how many deliveries were cancelled. Existing WordPress content and installation URL-routing choices are kept. This does not delete published pages or cancel records on the old remote service. If a delivery or layout operation is running, wait for it to finish and save again. Credential updates within the same service and website keep layouts and queued work and show no dialog.

Each service URL and website identifier has its own layouts, mappings, revisions and publishing approvals. Returning to a previous connection restores its configuration, but does not replay archived deliveries. **Copy and synchronize** copies the local layouts, their revisions and defaults, endpoint defaults, rendering rules and strategy, then synchronizes each default that was synchronized on the previous connection, creating new templates on the new service. Remote templates, publishing approvals, synchronization state and delivery history are never copied. If the new service already has a different default for a content type, that default is left unchanged and the layout is reported for review; replace it from the layout editor if intended. Never-synchronized defaults and WooCommerce category layouts are copied for review only. Copying is refused, without changing anything, when the new connection already has its own layouts on this site; choose **Switch without copying** to use them. A failed synchronization does not undo the switch; use **Sync saved revision** in Layouts.

Upgrading preserves the current connection's layouts and approvals. If an earlier 1.1.8 build already switched connections without recording which environment owned those layouts, the old data is retained separately and is not automatically approved for the current connection. Configure that connection's layouts before publishing. Installations that already installed the withdrawn 1.1.8 build must upload the replacement ZIP once; its unchanged version does not trigger a normal WordPress update.

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

- Salient / WPBakery fixed layouts preserve the example's native page and header settings. Existing header titles, subtitles and background images can be mapped alongside theme-defined body elements, image controls and legacy toggle/tab content. Deliveries regenerate native WPBakery styles. The preview highlights header fields, reveals entrance-animated content and shows a static first slide for Nectar Slider. Capture a fresh layout to add Salient settings that an older saved layout did not retain.
- Salient global sections and Nectar Slider slides remain shared references. NOVA checks their availability and leaves their content unchanged; edit them in Salient, or use page-local elements for content that should differ per delivery. Shared sections must be published, slider locations must contain published slides, and the necessary Salient plugins must remain active. The preview does not play slider animations or video. Cross-installation layout transfer is not included for WPBakery/Salient.
- BeTheme / BeBuilder fixed layouts support native text, rich text, headings, links, images, section/wrap backgrounds and existing accordion/tab rows. The installed theme's field definitions determine which controls can be mapped. Native sections, nested wraps, responsive settings and page presentation stay editable in BeBuilder; deliveries regenerate BeTheme's local styles and preview data. Both array and legacy encoded storage are supported. Select a BeBuilder example in Layouts; previews identify its native modules, including when the theme also renders shared header/footer templates. Plain WordPress posts on a BeTheme site continue to use WordPress fields.
- Shared BeBuilder sections and global wraps stay linked and protected; make them local in BeBuilder before mapping their content. Dynamic-data expressions stay linked to their native source. Referenced shared components and the BeTheme installation must remain available. Fixed layouts map existing rows; arbitrary repeated-region generation, editing shared theme templates, and cross-installation layout transfer are not included for this provider.
- Fixed-layout delivery for WordPress content, Gutenberg, Elementor, Flatsome, Enfold, WPBakery, Avada and Beaver Builder. The corresponding builder/theme must be available on the site.
- Fixed ACF layouts expose content inside groups, repeaters and Flexible Content rows, including native image fields. NOVA stores the example's component order and unselected values, then writes through the installed ACF field definitions. The theme and matching field groups must remain installed; the example can be deleted after capture. Component layouts are detected automatically when the native WordPress body is empty. Other examples keep their existing builder detection; choose ACF under advanced builder detection when appropriate. Existing mappings are not converted automatically: choose **Capture a fresh layout** to use the nested fields, map them and save. Shared-layout export is not yet available for this provider.
- A group, repeater or Flexible Content field is exposed even when a sub-field is a page link or a type NOVA has no dedicated handling for. Page links and those other sub-fields (clones, users, icon pickers, add-on fields) keep the example's stored value and are never filled from content; password sub-fields still keep their container out. Sub-fields that share a name in one row are one stored value, and a required sub-field hidden by its conditional logic does not need a value. Layouts saved before 1.2.3 keep the fields they were saved with; capture a fresh layout to include fields that used to be hidden.
- In the layout preview, ACF fields are found on the example page by their value: the one element in the main content showing exactly that text, or the one image of that media file in any generated size. Text repeated on the page, very short values, links and page links stay unhighlighted and remain selectable in the field list.
- For Cornerstone, select a page or post built in the content builder and map its fields under **Content rules**. NOVA saves the element tree and the page’s layout settings with the mapping. Deliveries create or update the native Cornerstone document; the example can be deleted after capture. Site-wide library components remain linked. Use the field list for controls without a unique visible element. When mapping blog posts, enable **Posts** in Cornerstone’s permissions for the NOVA publishing user. For an example captured as plain WordPress in an older version, choose **Capture a fresh layout** in step 2 and save the new mapping.
- Native publishing to NOVA Blog and Service post types, and WooCommerce category content with separate top and bottom fields.
- WPBakery fields come from the element definitions WPBakery itself registers, so elements added by themes and add-ons expose their text, rich content, links and images the same way as WPBakery's own elements. Images stored as media-library attachments can be mapped; deliveries import the supplied image and store its new attachment. Design, spacing, animation and identity settings stay protected. When a definition is unavailable, NOVA falls back to known text, link and image parameters.
- Avada images are found from their stored image URL and media-library attachment, including Image Frames, section and column backgrounds and content boxes; deliveries import the supplied image and keep the example's image size. Text inside Avada elements can be mapped.
- Beaver Builder supports layout capture and fixed-layout delivery, with native layout verification and recovery on the same destination page. Breakdance, Divi 4 and Divi 5 support layout capture. In **Layouts**, select an existing Divi 5 page to capture a fixed layout with its native adapter. The generic Gutenberg and plain WordPress writers leave Divi 5 documents protected.
- The existing NOVA posting contract and ready webhook URL remain compatible. Older explicit live-source clone jobs require the original Suite.

## Delivery history

The delivery log shows **Received** as the first time this installation recorded the delivery, with the site's timezone, and **Intended URL** as the address supplied by NOVA. Retries keep the original receipt time. The intended address can differ from the final permalink WordPress assigns. Missing historical values display a dash. **Last issue** includes the underlying WordPress message when a native save or recovery check fails; a reconciliation state still requires a verified native outcome before it can complete.

## Publishing in multiple languages

Configure the languages and their URLs in one multilingual plugin. Each delivery needs the article language and the corresponding public URL. If the URL belongs to a different language, WordPress refuses the write.

- **WPML and Polylang:** Each translation is a native WordPress post. Either language may arrive first. When NOVA sends siblings with the same URL ID, WordPress links them for the language switcher. A later update changes only the post for that language.
- **TranslatePress and Weglot** serve localized views of a published original. NOVA resolves its retained original publication or the exact primary URL supplied by the authenticated URL group. Existing originals must be public, use the mapped post type and have a matching canonical URL. Conflicting ownership and unfinished native publications require recovery. If the original is absent, the translation waits for it. TranslatePress also requires the posting user to have its translator capability.

The single-original providers support mapped WordPress and Gutenberg content and the supported text fields on NOVA Blog and Service posts. Weglot also supports saved Elementor documents, their mapped native image controls and featured images on WordPress, Gutenberg and Elementor layouts. Localized images use retained media-library imports and per-language metadata. Use the normal WordPress delivery request for Weglot: its SDK does not resolve language URLs under WP-CLI. Separate translated drafts and other stored builders are not supported by these providers. TranslatePress media fields require separate support. Check the target language URL after publishing; the original should retain its own content.

TranslatePress stores identical source strings in a shared dictionary. NOVA leaves conflicting manual translations and entries known to be used by another post untouched. TranslatePress may not have recorded a page it has never rendered; use a distinct source string when the same text needs different translations on different pages.

### Mapping custom fields

In step 1, select every **NOVA content type** that should use the layout. Service and Category can share one template that creates normal WordPress pages; Category does not require WooCommerce. Distinct destination URL routes still apply per content type. Removing a type detaches this template's routing for future deliveries; retained revisions continue to handle their already queued deliveries. Install the same plugin version on installations that share exported layouts with multiple content types.

In step 3 of a fixed post layout, **Design settings copied from your example** keeps captured appearance settings such as width, background and sidebar. These do not need AI instructions. Open **Additional content fields (optional)** only to select ACF or registered WordPress fields NOVA should fill with new article content. Each selected field can have optional instructions. Closing either section keeps the selections and does not disable mappings. Existing saved editorial selections are retained; a recognized design setting already selected as content offers an explicit action to copy its example setting instead.

Save the layout on the same screen and check the sync result. For a PHP page template that renders its content from ACF, use a WordPress layout with that page template and select its custom fields. The WordPress body can be empty.

The picker supports top-level ACF text, textarea, WYSIWYG, URL and email fields, plus public WordPress fields registered as single REST strings. A delivery that omits an optional field preserves its current value. Repeater rows, flexible layouts, media and relationship fields are outside this picker.

WPML and Polylang store the mapped values on the native post for each language. Configure translated fields to allow independent values. TranslatePress and Weglot keep localized field values on the shared source post and serve them on that post’s public page through normal WordPress metadata and ACF reads. A static builder layout can use these localized fields even when the provider cannot translate the builder’s own widgets. If a theme rewrites a field’s text or prints an identical phrase in multiple places, the provider may translate that output again.

### Title, excerpt and removed elements

In a fixed post layout, **Title** and **Excerpt** (when the post type supports excerpts) appear in the field list next to **Featured image**. Map **Title** to describe the page title, which themes usually show as the H1, even when the theme or a builder template prints it outside the captured content. Without a mapping, the title is still written from the supplied heading, as before. **Excerpt** is written only when mapped.

The preview also finds an excerpt or featured image rendered by a theme or Beaver Themer in a generic text or image module. It matches the example's stored excerpt and attachment in the main content, excluding related-post cards. If the value is missing or appears more than once there, select the field from the list. Reopen a saved WordPress layout to map its newly available **Excerpt**, then save and sync the revision.

Automatic builder detection follows the example's active Elementor or Beaver Builder mode, ignoring disabled editor data retained on that item. If both are active, the wizard names the candidates and opens **Advanced: builder detection**. Select the builder that renders that example and retry **Use this existing page**.

When Beaver Builder replaces the page body, block comments in its saved WordPress editor copy do not require a second builder choice. Detection keeps the distinction when Beaver is disabled, unavailable or configured not to replace the body.

Empty Beaver Themer connection entries do not prevent mapping headings, text, buttons or links. Fields with active connections remain protected and identify that connection as the reason. Reopen the example to refresh its available fields.

Elementor widgets that show the page title, excerpt or featured image through a dynamic tag stay unchanged and name the field to map instead. Other dynamic tags remain protected.

To leave an example element out of every generated page, select one of its fields and choose **Remove this element from new pages**. The whole element is removed, including its other fields; the example page itself is unchanged. Choosing a purpose for one of its fields keeps the element again. Removal is available for fixed builder layouts.

In fixed builder layouts, an optional field that a delivery leaves empty (no text, or only whitespace) removes its element from that page instead of publishing it empty. If every field of an element is optional and empty, the element is left out; if any of its fields is filled, the element stays and only the empty parts are blank. A section, row or other container that is left with nothing to show is removed as well; separators and spacers on their own do not keep it. A grid column stays in place, empty, while its row still shows other content, so the remaining columns keep their widths. Required fields never remove their element. An optional image left empty keeps the example image, as before.

The page preview outlines WPBakery, Avada and Divi 4 elements directly. It shows content that the site normally reveals with scripts, such as entrance animations and lazy-loaded images.

### Mapping SEO metadata

New fixed post layouts automatically select **SEO title** and **Meta description** for detected Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework, SmartCrawl, Slim SEO and Squirrly SEO installations. Save and sync normally; opening **SEO metadata** in step 3 is only needed to deselect a field or add instructions. Reopening an existing mapping preserves its saved selections, including deselections.

The SEO title uses NOVA's `title` value; the meta description uses `meta_description`. These are separate from the visible page heading. The same purposes are available when mapping other native text fields. Optional instructions can refine the mapping. Omitting an optional SEO value preserves its current value; NOVA never copies the example page's metadata.

The SEO plugin must be configured to emit metadata. Its native formatting and other settings remain in effect. Canonical URLs, indexing directives and social metadata are protected by this dropdown. The SEO provider dropdown covers native post types, including Pages and Posts.

Fixed WooCommerce category and product-tag layouts support selected native fields, declared scalar taxonomy metadata and scalar ACF/SCF companions. Their SEO fields use their registered schema or the administrator's confirmed field definition. Select WooCommerce product tags as the destination and Category as the NOVA content type to publish to product-tag archives. The saved field purposes and instructions go through the shared destination-fitting contract. Delivery creates the taxonomy item at its planned URL or updates the existing item, preserving unselected fields and product assignments. Repeated regions and nested custom-field structures remain available in the local editor and require a corresponding publishing provider before synchronization.

Weglot and TranslatePress category and product-tag deliveries store translated editorial fields on the original taxonomy item and render them for the requested language. The delivery uses the URL group's explicit primary item and reports the provider's resolved localized URL. WPML and Polylang deliveries update their existing native translated taxonomy items through the same approved field mapping.

## Development

Start each change from the latest `main`. Use `feature/<short-description>` for new functionality (for example, `feature/share-layouts`) and `hotfix/<short-description>` for bug fixes (for example, `hotfix/wpml-featured-image`).

Open a pull request into `main` and describe how you verified the change. To run the local suite, set `NOVA_WP_CORE_FIXTURE` to a WordPress `wp-includes` directory and run `python tools/test.py` with PHP and Node on your path. For changes to publishing, check the rendered result on staging. After the PR merges, GitHub automatically deletes its source branch in this repository.

The plugin derives from NOVA Bridge Suite commit `7e124d48d914b805a31bee340eb6f7fe338d61cc`. Retained wire names and option keys preserve saved mappings.

## Manual releases

Keep public release notes and PR descriptions product-focused. Use synthetic examples in committed tests and exclude client identities, private notes and source exports.

Merging a PR puts the change on `main`. Publishing a GitHub release makes the update available to WordPress installations. Documentation-only changes need no plugin release.

1. Before release, use a PR to set the same new version in the plugin header and `NOVA_BRIDGE_SUITE_VERSION` in `nova-wordpress-plugin.php`, and in the `Stable tag` in `readme.txt`. Add the user-facing changes to the changelog in `readme.txt`. This can be part of the feature or hotfix PR.
2. After merge, check out the exact `main` commit to be released and run `python tools/package.py OUTPUT_DIRECTORY`. Test the generated installable ZIP on staging.
3. Manually create a GitHub release at that same commit. For version `X.Y.Z`, use tag `vX.Y.Z` and title `X.Y.Z`. Attach both generated files, `nova-wordpress-plugin-X.Y.Z.zip` and `wordpress-update.json`, then publish the stable release as **Latest**.
4. Verify that the public manifest reports the new version and that the versioned ZIP downloads without GitHub credentials. Sites discover the release at their next WordPress update check and can install it automatically if they have enabled auto-updates. [Public update manifest](https://github.com/InSpace-GEO/nova-wordpress-plugin/releases/latest/download/wordpress-update.json)
