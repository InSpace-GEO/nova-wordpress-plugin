# Release verification

## 1.2.2 candidate (local and staging)

All local suites passed (the WordPress-install-only locale proof was skipped as before). New checks cover unfilled optional fields in fixed layouts: an unfilled WPBakery FAQ section is removed with its heading, separator and toggles; an emptied column stays beside a filled one; only unfilled questions are removed when others are filled; an element with any filled field stays; required fields never remove their element; whitespace-only and `&nbsp;` values count as unfilled; explicit removals and unfilled fields combine; Gutenberg groups, spacers and columns and Elementor sections, columns and dividers follow the same rule; and the saved-layout delivery path removes an unfilled optional element while existing layouts publish unchanged.

Staging (WordPress with WPBakery 8.7.2 activated for the check) ran the candidate ZIP. Two example pages, one built with WPBakery's own heading, separator, accordion and two-column row and one with Gutenberg heading, group, separator, details and columns blocks, were captured, and each was mapped with a required intro, a required right column and optional FAQ heading, questions, answers and left column. Emulated deliveries (no posting service) ran through the stored-layout writer's plan, apply and finish as drafts:

- With the FAQ fields and the left column unfilled, both pages were created without the FAQ section (heading, separator, accordion or details, and their example questions), and the empty left column stayed in its row beside the filled right column.
- With the FAQ heading, the first question and answer and the left column filled, the heading, separator and first question were written and only the unfilled second question was left out.

Not covered: delivery through the remote posting service, and updating an existing published page (the drafts used on staging cannot be updated in place by design).

## 1.2.1 candidate (local and staging lab)

All 123 local suites passed, including new checks for Avada images, content and known-ID publishing, theme image picker types, URL text fields, attribute text content, transparent animation wrappers, visibility-hidden animations, body-level "slider" classes and empty image layers.

A throwaway WordPress in its own directory and table prefix on staging ran Salient 18.4.4 with its bundled WPBakery 9.0.1 and Salient Core 3.1.11, Avada 7.15.6 with Fusion Builder 3.15.6, and Divi 4.27.9, one theme at a time.

- Salient: a page with an animated heading, row background, Image With Animation, two Fancy Box styles, buttons, highlighted text and toggles exposed 23 fields, including four media-library images. In 1.2.0 the Salient images were not offered because Salient uses its own `fws_image` parameter type. In the canvas, the animated heading, the lazy image, both Fancy Boxes and the row background were visible and selectable; in 1.2.0 the image and the parallax Fancy Box stayed blank.
- Emulated delivery (no posting service): the stored-layout writer's plan, apply and finish ran with an in-memory delivery, then again with a second version for the same URL. The page was created and then updated in place; four images were imported each time; the mapped heading, rich text, link and native title were applied; two removed elements were absent; the public page rendered without unrendered shortcodes.
- Avada and Divi 4: content hidden by entrance animations (visibility in Avada, opacity in Divi) was visible after preparation, and selecting a field outlined its element. In 1.2.0 Avada page content stayed hidden because a body class containing "slider" excluded all content.

- Avada images and publishing: a page with a section background, an Image Frame and two content boxes exposed all four images and the content-box text. Two emulated deliveries through the stored-layout writer imported three images as new attachments (keeping Avada's `|full` size), applied the heading, box text, link and native title, removed the second box, updated the same page in place and set Avada's builder status; the public page rendered the imported images without unrendered shortcodes.

Not covered: delivery through the remote posting service.

## 1.2.0 candidate (local and staging)

All 123 local suites passed. New suites cover WPBakery element definitions (attachment images, theme container elements, design-group and encoded content exclusions, packed links, heading-safe binding), image import targets, removed elements (source preservation, nested/duplicate/bound rejections, Gutenberg placeholders), native title and excerpt in test drafts and in saved-layout deliveries (sealed digests unchanged for layouts without the new choices), Elementor dynamic tags, preview markers, and the canvas handling of lazy images, entrance animations, shortcode markers and title text matching.

Staging (WordPress 7.1.4, PHP 8.1.34, WPBakery 8.7.2, Elementor 4.1.4 with Pro 4.1.2) ran the candidate ZIP. WPBakery was activated for the review. A test-only must-use plugin registered two elements through `vc_map` that render the way commercial themes do (an animated image hidden until scripts run with its source in `data-lazy-src`, and a box with a `data-bg` background image); it stands in for commercial theme elements, which are not installed on staging.

- WPBakery 8.7.2 element definitions were read under WP-CLI and in the layout editor. A page with WPBakery's own heading, text, Single Image, button and accordion plus the two test elements exposed 14 fields, including three media-library images; design, spacing and animation parameters stayed protected.
- In the layout editor canvas, selecting the Single Image and the animated test image outlined each image. WPBakery's entrance-animated paragraph, the animated image and the lazy background were visible. Removing an element faded and outlined it in the canvas and marked its field "Removed from new pages".
- A fixed WPBakery layout was saved locally only (no posting-service synchronization). A native test draft imported the mapped image as a new attachment, replaced the mapped heading, left out the removed elements and set WPBakery's editor state. The delivery compile and known-ID provider wrote a second item with the mapped native title and an imported image; provider verification passed and the rendered page contained no unrendered shortcodes. The local test profile was removed afterwards.
- An Elementor page whose heading, text editor and image use the post-title, post-excerpt and post-featured-image dynamic tags listed each control once, protected, pointing to Title, Excerpt and Featured image. Selecting Title outlined the dynamic heading.
- On a Gutenberg post, selecting Title outlined the theme's post title.

Not verified on staging: a delivery from the remote posting service for a WPBakery layout (the review avoided synchronizing new templates to the connected test service), Avada and Divi 4 preview markers (those builders are inactive on staging), and commercial themes that extend WPBakery.

## 1.1.8 replacement candidate (local)

All 119 local suites passed. Coverage exercises explicit switch confirmation, ordinary credential rotation, separate archived history, preservation of exact receipt bytes, rollback on archive/settings/commit failures, active-worker exclusion, old webhook rejection and suppression of retired deliveries after reconnecting. The replacement build also tests empty layout catalogs for a different service URL or site ID, independent layout saves and revisions, restoration on switching back, stale-editor rejection, new template creation, and delivery-profile approval when both services return identical template UUIDs and revision numbers. Local recipe revision 7 successfully maps to remote template revision 1. Existing delivery, mapping, layout and editor suites remain green.

The optional copy on switch is covered by the environment switch suite: an identical copy of layouts, revisions, defaults and endpoint mappings; no sync state or approvals copied; refusal when the destination already has layouts, for receive-only installations and for inconsistent history; rollback of the whole switch when copying fails; synchronization of only previously synchronized defaults at their exact revisions against the new connection; existing remote defaults reported as conflicts instead of replaced; failures that keep the committed switch; and the synchronization time budget. Six deliberate code mutations were each caught by the suite.

Staging (not production) was tested against an emulated second posting service implemented at the WordPress HTTP boundary from the pinned contract; the plugin code ran unmodified through the same save and synchronization handlers as the administration screens. Verified there: upgrade preservation; empty layouts after a URL-only or site-only switch; stale-editor rejection; new template creation even when the emulated service returned the test template UUID; local revision 3 mapped to remote revision 1; credential rotation; restoration on switching back; no replay of archived deliveries listed by the real test service; copy into a service that already had an informative default (service default synchronized, informative reported and left unchanged until explicitly replaced; existing remote template untouched); refusal to copy into a connection with its own layouts; copy without synchronization sending nothing; and copying between two production-like connections. The emulator is not a real NOVA service, and the administration screens were not exercised in a browser.

Additional staging checks:

- Upgrade from 1.1.7, in a throwaway WordPress on staging with its own table prefix (removed afterwards). 1.1.7 refused to switch while a delivery was unfinished, reproducing the original report. After upgrading, layouts, defaults, jobs and settings keys were unchanged and the archive table was created; the same switch then succeeded with copying, cancelled and archived the unfinished delivery, and switching back restored the 1.1.7-era layouts without re-admitting either delivery that the emulated service still listed.
- A full delivery cycle in a switched environment against the emulated service: discovery, snapshot fetch with attempt authority, connector events and archival on switching back. The native page write could not complete on this staging site because Polylang and TranslatePress are both active; the plugin correctly refused and reported publication_failed. This is unrelated to switching.
- Administration screens in a browser: changing the service URL and saving opens the switch dialog (Copy and synchronize, Switch without copying, Cancel) and Cancel saves nothing; an unchanged save submits directly through the real form; the Layouts screen loads, sends the environment fingerprint and tells a stale screen to reload; copied defaults display in the new environment. A switch was not submitted through the browser form because that requires entering service credentials.

These checks found and fixed two defects: the REST guard took the delivery lock for reads, so the Layouts screen failed whenever its parallel requests or a delivery batch overlapped (reads now take no lock, and layout writes use a separate authoring lock that waits up to 15 seconds); and saved connection notices were printed twice.

The replacement ZIP has not been released. The local tests execute the production layout, synchronization, connection, settings, discovery, journal and worker code against deterministic in-memory storage, CMS and HTTP fixtures. They do not establish a live database transaction or an end-to-end posting-service test.

The earlier, withdrawn build was extracted on staging and its focused PHP suites ran under PHP 8.1.34. That result does not verify the replacement's layout-isolation changes.

Archival requires InnoDB for the WordPress options and delivery tables. A failed storage check retains the current connection. An active delivery batch requires retrying the save after the batch finishes. Archived records are private retained evidence, not remotely acknowledged cancellation.

## 1.1.7 release

All 114 local suites passed, including 110 layout editor workflows. Regression coverage includes existing saved selections, new-layout SEO defaults, deselection and reopening, optional values, provider permissions, retained layout revisions, custom fields and preview authentication. Existing mappings are not opted into SEO fields automatically.

On isolated WordPress 7.1.2, each of eight SEO plugins completed six native Page/Post create or update operations: Yoast SEO 28.0, Rank Math 1.0.279, SEOPress 10.3, All in One SEO 5.0.5, The SEO Framework 5.2.0, SmartCrawl 3.17.1, Slim SEO 4.11.5 and Squirrly SEO 14.2.9. These 48 operations verified native storage and the rendered title and meta description, retained the target ID on updates, and preserved an existing description when an optional value was omitted. Four localized render-hook cases per provider also passed. Posting-service responses and localized render inputs were simulated; no live mapping model or translation service was exercised.

A custom staging theme with missing WordPress script hooks reproduced the missing preview bootstrap. The fallback initialized one protected canvas, exposed 18 fields and made lower sections visible without revealing hidden menus. Six native create/update regression cases passed across core content, Gutenberg and Elementor. Normal hook output remains unchanged, and the iframe sandbox remains restricted.

Preview compatibility is not universal: fresh embedded previews in a separate staging installation remained blank although their signed preview URLs rendered directly. That issue remains unresolved. The release includes a timeout message and a link to the original example; it does not claim to fix every custom-theme preview.

## 1.1.0 release

The release is based on the published 1.0.7 commit. All 104 local suites passed. The 156-file ZIP tested on both staging hosts has SHA-256 `4877520a076b9617665944a9856296ee5fb9abba2b72640d1ce43b90a59b0cb2`.

On two independent WordPress 7.1.4 installations, the source exported approved Page and Post layouts. The receiver rejected a different image with the same attachment ID, rendered the correct local file, and completed a queued revision 1 delivery after revision 2 had been imported. A revision 2 delivery updated the same Page. The receiver also published a Post while its mapping saves and default updates were blocked. These tests used real WordPress and Elementor with simulated posting-service traffic.

In a separate staging installation with WPML 4.9.5, the release recovered a title-only draft from the previous pilot and reused its original attachment without a second download. A fresh delivery rendered the body and set the featured image while WPML created the translated attachments.

We installed the public 1.0.7 ZIP with X 10.8.12 and Cornerstone 7.9.7, saved a mapping, published a Page and removed the example. After upgrading to this ZIP, a new delivery using the existing mapping updated the same Page. Its body and image rendered and the saved data reopened in Cornerstone. Fresh Page and Post mappings also passed creation and update checks in 1.1.0.

## 1.1.0 shared layouts staging candidate

All 102 local suites passed. We installed the same candidate ZIP on two isolated WordPress 7.1.4 installations. The authoring site ran Elementor 4.1.4 and the receiving site ran Elementor 3.35.9. The WordPress editor, import handlers, delivery queue, builder output and served images were real. A simulated posting service supplied deliveries and recorded outbound requests.

Attachment 42 on the two installations contained different images. Import identified the missing background by filename and layout control. The receiver subsequently rendered the matching file from its own attachment 84. A revision 1 delivery queued before revision 2 was imported still published using revision 1 and sent a success receipt. A revision 2 delivery updated the same native page using attachment 95. The test also created a Page and a Post from the imported layouts after the original example was deleted.

The canonical import superseded a competing local layout with the same template and revision. After import, Save and Sync on the receiver refused mapping changes. Direct attempts to create a remote template, change its revision or change its default produced zero outgoing requests. Template reads and delivery receipts still reached the simulated service. The authoring installation retained its usual ability to send mappings and defaults. Both temporary WordPress installations were removed.

Tested ZIP SHA-256 is `ca64d5293bc0cbfe24c958fb9749914a36bdcc0ba05fdf5ea05d1a1244e3ca95`.

### Featured images on a multilingual receiving site

The first two-site test used locally stored background images. A separate test exercised a delivery that supplied a native Page body and a featured image to download. On isolated WordPress 7.1.4 with WPML 4.9.5 configured to translate attachments into three languages, the original plugin reserved draft 9 and downloaded the image once. WPML created attachment 10 and translations 11 and 12. All three carried the same NOVA image marker. The writer stopped before saving the Page body or thumbnail.

With the revised writer, the same operation recovered draft 9, wrote the fitted body, set attachment 10 as its featured image and verified the result. Both translations were retained and recovery performed zero additional downloads. A fresh delivery created draft 14, rendered the intended body and selected the original featured image on its first attempt. The original image and its two translations were created from a single download.

All 102 local suites passed, including coverage for conflicting attachment groups and interrupted document saves. An unchanged reservation created by the revised writer can resume. A partial document save or an intervening user edit still requires reconciliation. The 153 runtime files installed on staging matched the revised ZIP. Its SHA-256 is `6e3bdbf86be94d9093c258b7110cd65fc282230a7f580cfbcd8483f3699e0139`.

## 1.0.7 staging candidate

The isolated `inspace-seo.com` lab ran WordPress 7.1.2, Themeco X 10.8.12 and Cornerstone 7.9.7. We saved a fixed layout from a native Page and another from a native Post through the mapping endpoints, then deleted both example items. With fitted test deliveries, NOVA created each target and updated it a second time. HTTP requests found the expected heading, formatted body, button text and destination, and newly imported image. Both updates retained the target’s original WordPress ID and URL. Cornerstone’s own document reader returned the new mapped values, and the saved presentation settings survived. We also checked that a publishing user without Cornerstone’s Posts permission could not publish that destination.

The signed preview returned HTML for each reference. In Chrome, clicking the heading, body or button in the captured staging markup selected the correct mapping field. With a simulated WPML domain-per-language configuration, authenticated preview requests for a German page and post rendered those items on the staging admin origin. A separate check used WPML’s native query parser. The test did not use a live second language domain or the client’s child theme. The WordPress writer received synthetic fitted deliveries; no external posting service or LLM was called.

All 102 local suites passed. The installable ZIP contains 152 NOVA runtime files; no Themeco files are bundled. ZIP SHA-256: `66547aab41326ea9a006248e897d658fe6fa2df0891217927537cbbd3eebe420`.

## 1.0.6 staging candidate

All 100 local suites passed, including 104 layout editor workflows. Tests cover revision conflicts, saving and retrying sync on step 3, explicit default replacement, and resuming a mapping when its example is selected again. The wizard no longer creates sample drafts. A browser with an interrupted draft request from an older build can still look up that specific draft. Field tests cover excluding core footnotes from new choices, retaining legacy bindings, and offering a separate ACF field labelled Footnotes. The theme tests covered 25 Astra 4.11.7 and 10 Kadence 1.2.16 design settings, opt-in editorial metadata, and previously saved empty or partial selections.

We installed the ZIP on inspace-seo.com and verified all 149 runtime files. Chrome loaded the site's editor assets and exercised a 38-setting example against isolated responses. It used the combined reference picker with a mouse and keyboard, checked the manual ID fallback, and confirmed that step 3 sends users back to step 2 through the top navigation. It selected and deselected settings without replacing the preview iframe, reselected the example, then saved the field binding and the 36 settings left selected. Exactly one request verified the settings on save. The saved layout offered sync without a sample draft section or draft title. Nine PHP regression suites passed for the same server code on the staging host with in-memory fixtures, including the field catalog, save and sync handlers. The browser and server tests did not change any staging mapping or remote default.

Staging ZIP SHA-256 is `88e844ca5012d105fa158690089fcf989a7b3a27fc10ff27549a604c11b68cca`.

## 1.0.5

We tested the installable ZIP on isolated WordPress 7.1.2 with ACF Pro 6.8.0.1. For each provider we saved a fixed page layout using the editor’s capture and save endpoints, selected an ACF field and a registered WordPress REST string field, then sent fitted values through the plugin’s publishing writer. The posting-service transport was simulated. The WordPress plugins, storage, and public HTTP requests were real.

| Provider | Languages | Result |
| --- | --- | --- |
| WPML 4.9.5 | German and English | German arrived first on the English-primary site. Both native pages kept their IDs, reciprocal translation links and independent field values through both updates. |
| Polylang 3.8.5 | Dutch and French | Both pages rendered their mapped fields and kept their reciprocal links. The lab initially returned 404 for the French URL after enabling language directories. Refreshing WordPress permalinks registered the French route. The same plugin ZIP then passed. |
| TranslatePress 3.3.7 with Personal | Dutch and German | Both URLs rendered the exact ACF rich text and registered metadata from one native post. Updating the original preserved the current translation and updating the translation preserved the original. |
| Weglot 6.4.3 | English and German | Both public URLs retained the exact fitted text and rich markup. Replaying each delivery reused the same native post and an unmapped field kept its original value. |

All four providers passed four creates or updates and seven anonymous, nonredirecting HTTP page checks apiece. The fixtures and saved test mappings were removed. All 100 local test suites passed. The ZIP contains 149 runtime files and its SHA-256 is `e60d2146b495071b8908e4484ffc5c3b85d047a77100c84038b143a9d97b8d41`.

## 1.0.4

All 98 local suites passed. The installable ZIP contains 146 runtime files; its SHA-256 is `9108e5edeafe3ac3020c7ee5459d5f8f9ee69011cf21e4c06786ef918fcde061`.

The local worker tests exercise a pending receipt for one language followed by an article in a second language with the same backend URL ID. Separate destination pages can finish independently; the same canonical destination still blocks a competing mutation. Replaying the first receipt does not create another page.

On an isolated WordPress 7.1.2 staging installation:

- **WPML 4.9.5:** With English primary and German configured on a different host, German arrived first. The stored-layout writer created two native posts using the same slug and NOVA URL ID, linked them for the switcher and updated each independently. The German delivery on the English URL was rejected. The synthetic German host had no public DNS; the checks used WPML's native permalink and WordPress's content renderer.
- **Polylang 3.8.5:** With Dutch primary, the French delivery created and then updated `/fr/article/`. The Dutch sibling kept its own native ID, URL and content. Both directions of the language switcher were checked. A French delivery aimed at the Dutch URL was rejected.
- **TranslatePress 3.3.7 with Personal:** Two German deliveries updated the rendered title and body at the localized HTTP URL. The published Dutch original kept its native ID and original bytes, passed its own native writer verification after both updates, and replay created no duplicate.
- **Weglot 6.4.3:** An English-source project served both the English original and its German translation from one post on a site whose WordPress locale was Dutch. In a normal WordPress request, two German deliveries and a replay succeeded. HTTP reads of both URLs confirmed the intended title and body, and the original still passed its native writer verification.

For both WPML and Polylang, an editor filled the target language slot after NOVA had planned a second post. Applying the frozen plan left the editor’s post untouched and did not create a duplicate. Both suites passed 21 staging checks.

The TranslatePress test also seeded an unrelated, manually reviewed translation for the same source string. The attempted overwrite was refused, and the existing dictionary entry was unchanged. Local tests covered an interrupted write and retries, including cases where the original or translation changed in the meantime.

These tests exercise the WordPress writers with synthetic NOVA deliveries. No client site or external posting-service publication is part of the verification.

## Locale association change included in 1.0.4

Stored-layout and owned-CPT associations now include the delivery locale. Case
and underscore/hyphen variants share one association. Two locales sharing a
backend URL ID retain separate native pages at their supplied URLs and update
independently. Each locale retains its content/version guards. A matching legacy
association is reused lazily without changing its old option; another locale
cannot adopt its native page. Malformed legacy identities require reconciliation.
This earlier change retained the existing operation journals, reservation recovery,
native language routing and target URL locks. The provider routing tests above
cover the additional changes in the final release.

Owned CPTs retain a private native owner marker when a page is created or first
adopted. Another locale cannot adopt that same native URL, including through an
already-planned operation with identical fitted values. Both writers check the
exact post type, parent and slug for owned draft reservations hidden by draft
permalinks, and repeat that check under their target URL lock before insert. The
stored-layout writer uses its existing owner and reservation markers.
Unowned existing CPTs remain adoptable, and their unrelated native marker data is
preserved. The owner marker does not change frozen plan or native snapshot bytes.

The disposable WordPress 7.1.4/MariaDB proof in
`modules/posting-service/tests/locale-associations-wordpress.php` passed 102 checks
using registered Service/Blog CPTs and the stored-layout core/Gutenberg providers
for native Pages, Posts and the Service CPT.
It published two locales with one URL ID, updated each independently, upgraded a
legacy association without duplication, and recovered a lost insert reply while
preserving the frozen operation plan and digest. Same-URL locale collisions,
competing adoption plans, and competing creation plans produced no overwrite or
second reserved draft. The script requires the dedicated
`https://bridge-locale.test` installation and `NOVA_DISPOSABLE_WORDPRESS=1`; it
retains its disposable records for inspection. Elementor locale behavior is
covered by the local writer fixtures; this proof does not install Elementor or a
multilingual plugin and does not exercise an external NOVA delivery.

## 1.0.2

All 93 local suites passed. Coverage includes ranking eligible site members by published content, preserving a different user's existing byline, checking destination permissions before a write, verifying previously committed work after a permission change, and routing signed delivery notifications.

The initial 1.0.2 ZIP upgraded the active standalone plugin from 1.0.1 on WordPress staging. All 139 installed files matched the archive. The ready, layout design, save and sync routes were registered. The connection, layouts and modules screens rendered.

The revised 1.0.2 ZIP changes one file: the connection form now puts **Save NOVA connection** before the collapsed **Multi-site WordPress** settings. That file was installed on staging, where its rendered order and all 50 connection checks passed. The other 138 files and the public update manifest are unchanged. The revised archive SHA-256 is `46374228f93f77e943b481194ffca8570e8cbe792a8757b68c420748109863a3`.

On that site a disposable Gutenberg mapping saved straight from its field rules. The real sync client uploaded V3 and confirmed the Service default against a simulated posting service. During a simulated outage, the edit stayed recorded locally; retry confirmed the same revision. The native writer created and updated a WordPress draft as a publisher without settings access, and refused to write after destination permissions were revoked. The site had one user; preserving a different user's byline was tested locally. No external posting job was submitted.

## 1.0.1

On staging with the licensed Divi 5.18.1 theme, a native example exposed its heading and text controls. The mapping was saved and the example was deleted. In a fresh WordPress request, the final ZIP created a draft from that revision. Repeating the same operation returned the same draft. Divi rendered one mapped H3 and one mapped paragraph. Its Visual Builder data loader reopened the saved section, row, column, heading and text modules with the new values. This exercises native draft authoring. Divi's posting-service publishing path is disabled.

All 92 local suites passed. WordPress 6.9's core update check also offered a simulated newer stable release and correctly reported no update for equal or older versions. The test served the generated manifest locally and used no credentials.

The final installable ZIP contains 137 runtime files. Each installed file matched the archive on staging. SHA-256 is `9487313b46ee4d57c18dd1c2c6aed49d7932b1c8ade13a9049bf76788c16f438`.

## 1.0.0

The release candidate was installed as a ZIP on a WordPress 7.1.4 staging site. Each test request loaded either the original Suite or the new plugin through WordPress. Activation conflicts were checked in both directions. Real fixture posts, templates, attachments and categories were written through WordPress. Network calls to the external posting service were simulated, including CPT template sync. An end-to-end external NOVA delivery was outside this test.

| Feature | Tested behavior |
| --- | --- |
| Elementor 4.1.4 and WordPress blocks | Capture, save, remove the example, create a draft, apply an update to the same ID and render the updated document. |
| Enfold 8.3 and Flatsome 3.20.7 | Capture native layouts and presentation, render content and media, apply updates and re-read saved native documents in a fresh request. |
| WPBakery 8.7.2, Avada Builder 3.15.6, Beaver Builder Lite 2.10.2.2, Breakdance 2.8.0 and Divi 4.27.7 | Native test-draft creation and native readback or rendered output for the installed builder. The fixed-layout delivery path has a separate capability check. |
| NOVA Blog and Service | Registered post types and field descriptions, native writer create and update, stable IDs, receipt verification and template/default sync with a simulated remote service. |
| WooCommerce 10.9.3 | A category retained HTML in the main description and in the separate field rendered below its products. |
| Switching from Bridge Suite | A saved Gutenberg mapping created by 3.0.0 produced a rendered draft under 1.0.0 after its example had been removed, using the original saved revision. |

The active plugins, theme, connection and saved mapping defaults matched the original site state after testing. The installable package excludes tests, development tools and third-party themes and plugins.

The release ZIP contains 136 runtime files. Its SHA-256 is `6b67749d3342fbbafdd671c237b7f89243bf98ecb12477108f75fa8458a6ad90`. All 91 local suites and 17 staging test groups passed. The staging scope included refusing Divi 5 markup through automatic and manually chosen fallback writers. Version 1.0.0 did not offer Divi 5 authoring.
