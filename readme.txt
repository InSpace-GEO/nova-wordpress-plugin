=== NOVA WordPress Plugin ===
Stable tag: 1.5.1
Requires at least: 6.9
Requires PHP: 8.0
License: Proprietary

Map existing layouts and publish NOVA deliveries to WordPress. Includes optional NOVA Blog, NOVA Service Pages and WooCommerce category rich text.

== Installation ==
Upload the installable ZIP from the release in WordPress. Deactivate NOVA Bridge Suite before activating the new plugin. Open Settings > NOVA Settings to configure layouts, the connection and optional modules.

== Changelog ==

= 1.5.1 =
* Map Elementor nested-accordion questions and answers, including optional FAQ rows and repeated panels.
* Keep questions paired with their answers when empty optional rows are removed.
* Reveal animated content in Elementor Canvas previews and make collapsed FAQ answers selectable.
* Map individual Elementor icon-list text and links, and clarify background-image and Media Library alternative-text controls.

= 1.5.0 =
* Map SiteJob/HALO article and service fields using their registered REST names and schemas.
* Preview and publish typed FAQs, buttons, references, supplementary content, form visibility and secondary images.
* Verify field permissions, schema changes and native saved values while preserving omitted fields.

= 1.4.15 =
* Keep shared Beaver Builder components linked to the matching Polylang or WPML language template when publishing localized pages.
* Show linked language templates and missing translations in the layout mapper, with provider-managed translations for TranslatePress and Weglot.
* Validate shared template availability before publishing and protect shared controls from per-page content bindings.

= 1.4.14 =
* Preserve existing localized category and product-tag descriptions when the field is left unmapped.
* Clarify destination-content preservation and hide internal metadata from the mapping editor.

= 1.4.13 =
* Protect localized category and product-tag fields inside Elementor product archive templates from secondary translation.
* Preserve translation of product cards, navigation and other page regions.

= 1.4.12 =
* Publish mapped Elementor images and featured images in Weglot's per-language views.
* Retain localized image imports for retry recovery and verify their saved attachment IDs and URLs.

= 1.4.11 =
* Map WooCommerce product tags to the Category content type, including native text, scalar custom fields, SEO fields and translated content.
* Publish mapped WooCommerce category and product-tag translations through Weglot and TranslatePress.
* Preserve native WPML taxonomy identities and language context during category/tag updates and readback.
* Resolve earlier published originals from the exact primary URL in an authenticated NOVA URL group, with native identity and ownership checks.
* Return the provider-generated category URL in publication receipts and retain localized field values during retry recovery.

= 1.4.10 =
* Publish Weglot and TranslatePress content at the verified original's provider-generated localized URL when its slug differs from NOVA's intended URL.
* Retain the resolved URL throughout native writing and recovery, enforce the installation's publishing scope, and return the actual URL through the existing publication report.

= 1.4.9 =
* Publish native WooCommerce category deliveries at application demo URLs, including category updates and retry recovery.
* Keep application demo posts and pages in draft and retain the existing handling for shared translated views.

= 1.4.8 =
* Synchronize fixed WooCommerce category templates with selected native content, declared SEO metadata and scalar ACF/SCF companion fields.
* Publish those category mappings through the shared destination-fitting contract, with verified creation, existing-category updates and retry recovery.
* Preserve unselected metadata and custom fields, and report the saved category revision's actual publishing support and synchronization status.
* Use native category rich-text sanitization and complete ACF runtime definitions during validation and readback.

= 1.4.7 =
* Fix false concurrent-edit warnings when saving layouts, mapping drafts, endpoint mappings or content rules on sites with legacy database encoding or different PHP serialization settings.

= 1.4.6 =
* Map individual headings and descriptions inside PowerPack Info List modules for Beaver Builder.
* Select the exact list-item field in the mapping preview while preserving icons, typography and other item settings.

= 1.4.5 =
* Save localized values for preserved fields from linked Polylang and WPML reference pages and select them for the delivery locale.
* Show saved locale availability and reference fallbacks in the mapping editor. TranslatePress and Weglot retain their provider-managed translations.
* Allow a manual locale value for a preserved field when no linked localized reference supplies one.
* Offer to synchronize pending local mappings after saving the NOVA connection, with independent connection and mapping results.
* Keep application demo URL deliveries in draft, including matching parent paths. Hold test destinations that have no independent draft state.
* Refresh the updated Beaver page's generated CSS and JavaScript, including draft and preview assets.

= 1.4.4 =
* Allow local delivery journals up to 4 MiB so large existing-page layout updates retain their complete recovery state.

= 1.4.3 =
* Map individual text, link and image fields inside Beaver Builder HTML modules while preserving their surrounding markup.
* Highlight those fields in the mapping preview.
* Support complete Beaver creation mappings up to 1 MiB and update plans up to 1.5 MiB within the existing journal storage limit.
* Preserve native typography arrays when saving Beaver layouts.

= 1.4.2 =
* Avoid duplicating Beaver Builder node settings in captured layouts.
* Compact older saved Beaver layouts when synchronizing, preserving their design and existing saved revisions.
* Avoid repeating full Beaver layouts in update recovery records.

= 1.4.1 =
* Enable publishing and synchronizing captured Beaver Builder layouts as creation defaults.
* Save and verify native Beaver layouts on the retained destination page, with recovery that avoids duplicate pages.

= 1.4.0 =
* Link related localized pages across different NOVA URL IDs through WPML and Polylang, including translations on each URL.
* Resolve the shared original for localized TranslatePress and Weglot content while retaining each delivery's authored content.
* Resume waiting localized deliveries when their original has been published.

= 1.3.4 =
* Creation mappings can keep shared Elementor components (global widgets, saved templates, template shortcodes) linked instead of requiring an independent copy. New pages show the current library version.
* Ordinary Shortcode widgets in a creation layout are kept as fixed content.
* A shared component deleted after the mapping was saved is left out of new pages instead of failing the delivery.

= 1.3.3 =
* After a wake, keep processing deliveries in batches of three until none are due, instead of stopping after the first batch.
* Run retries at their due time, with one scheduled follow-up as a safety net when a batch cannot start the next one.
* Record a wake that arrives while a batch is running instead of refusing it; wakes during a connection switch are still refused.
* Show the next scheduled batch in the delivery status.

= 1.3.2 =
* Fix Beaver Builder detection when WordPress retains a block-based editor copy.
* Allow mapping headings, text and links with empty Beaver Themer connections.
* Clarify why fields with active connections remain protected.

= 1.3.1 =
* Add BeTheme / BeBuilder layout mapping, native writing and preview support.
* Improve Salient / WPBakery headers, images, styles and shared-content checks.
* Fix excerpt and featured-image previews and improve mixed-builder detection.
* Show delivery timestamps, intended URLs and clearer WordPress error details.

= 1.3.0 =
* Publishing status: when a delivery is published, NOVA now receives the URL WordPress actually gave the page or post, read from WordPress itself, with its status (final once published, provisional for drafts, pending and scheduled posts). NOVA can then show and track the real address, for example a dated post link (/2026/11/11/my-post/) instead of the planned /blog/my-post/, and localized WPML, Polylang, TranslatePress and Weglot URLs. The report is optional: if WordPress cannot give a usable absolute URL, or only a query such as ?p=123 identifies the page, it is left out and success is reported as before. Requires the matching posting-service release, which is deployed.
* Content-rule (Elementor) posts now follow the site's own post permalinks, including date and category structures: the slug comes from the delivery URL's last segment and WordPress decides the final link, which is reported back. Plain ?p= permalinks are still refused, and a path owned by an unrelated page still stops the delivery.
* Layout deliveries now overwrite the page at their exact URL, whatever is on it: a page another page builder renders (Elementor, Divi, WPBakery, Avada, Beaver Builder, Breakdance, Cornerstone, Enfold), a block body that differs from the captured one, or a page another NOVA delivery, mapping or content item wrote earlier. The other builder's settings are moved aside (kept in the page's _nova_replaced_builder_meta field) so the new layout is what renders. Still required: the exact URL, the mapped post type, a draft or published page, and a publishing user who can edit it. An unfinished delivery to the same URL, or an older version of the same content item, is still refused.
* A page NOVA wrote now also accepts a later delivery at the link WordPress gave it (for example the dated link reported back above), instead of failing with "A retained page cannot be moved to a different delivery URL".

= 1.2.14 =
* Fixed ACF/SCF layouts: an optional select, radio or button-group field that a delivery leaves empty (for example a heading-level setting the content does not fill) stopped the page with "... contains an unknown choice". It is now saved empty, as ACF itself allows for a field that is not required. A required field still needs one of its choices, and an unknown choice is still refused; that message now names the value and the allowed choices.

= 1.2.13 =
* Fixed posts on sites whose post permalinks contain the date (for example /%year%/%monthnum%/%day%/%postname%/): a delivery mapped to WordPress posts stopped before writing with "This native permalink structure needs an explicit certified creation adapter". The post is now created with the delivery's slug, WordPress gives it its dated link, and that link is accepted as the delivery URL and reported back. A post that already uses the slug stops the delivery instead of being duplicated. Pages, custom post types, categories and sites without dated post links are unchanged.
* Publishing status: a delivery that failed in WordPress now shows WordPress's own message after the code (for example "nova_layout_writer_native: ..."), so the failing check is visible without server access.

= 1.2.12 =
* Fixed ACF/SCF layouts: a repeater or flexible-content row whose mapped optional fields a delivery leaves empty is now left out of the new page instead of being published empty, as builder elements already were. A row left showing nothing once its own rows are gone is left out too; style settings (choices, toggles, colours) on their own do not keep a row. ACF's row minimums are kept: when a field's minimum, a required field or a layout's minimum needs the row, it is published with its empty values. Mapped images in later rows stay with their own row; in builder layouts, images after a removed element now also keep their element.

= 1.2.11 =
* Custom fields: ACF/SCF field groups shown on pages with the "Default template" now also apply to new pages. ACF reads the template of a not-yet-created item as missing rather than empty, so its default-template rule did not match, and capturing a page as an example stopped with "The example and new-item ACF schemas differ". A new item is now matched with the default template WordPress gives it; named templates are unchanged.

= 1.2.10 =
* Custom fields: works with ACF 6.8.11 and later. That release refuses WordPress's custom-field permission on every key that stores an ACF field, for every user, so WordPress's own Custom Fields box cannot bypass ACF. NOVA asked exactly that permission before reading or writing ACF fields, so capturing an ACF example failed with "WordPress refuses the field ..." and ACF values in deliveries were refused. NOVA now checks the same permission with only that ACF guard set aside, as ACF does for its own saves. Field permission filters, protected keys and other plugins' capability rules still apply.

= 1.2.9 =
* Custom fields: when WordPress refuses an ACF/SCF field while a post or category is captured as an example, or while saved fields are verified, the message now names the field and why: a permission filter or registered permission callback for that field, the field being marked as protected, or a capability filter on the site. Where WordPress can tell, it also names the plugin or theme that added the rule. Permissions themselves are unchanged.

= 1.2.8 =
* Custom fields: an administrator can again use an existing post or category as the example for an ACF/SCF layout, and deliveries to those layouts are verified. ACF stores a hidden reference key (such as `_content`) next to each field, and WordPress refuses those protected keys to every role, so the setup stopped with "You cannot inspect the complete metadata for this custom-field example." Permission is now checked on the field itself, as it already was for fixed component layouts and ordinary field writes.

= 1.2.7 =
* Layout preview: posts shown through a Beaver Themer singular layout now outline their title and content. The content is found in Themer's Post Content module, and a title printed by the theme or a heading module matches the stored title even when WordPress curls its quotes and dashes or the title contains HTML entities.

= 1.2.6 =
* Deliveries: WordPress installations that share one MySQL server (separate databases, common on shared hosting) no longer block each other. MySQL named locks are shared by the whole server, and every installation computed the same lock names, so when one notification reached several installations at once only one accepted it and the others answered 409 nova_posting_connection_busy. Lock names now include the installation's database name. Requests to the same installation are still serialized as before.

= 1.2.5 =
* Layout preview: posts and pages shown through a Divi Theme Builder template no longer appear blank. Their template sections that wait for Divi's entrance animation are now shown, and the post content is outlined in Divi's Post Content module (and Elementor Pro's Post Content widget) when the theme has no standard content wrapper.

= 1.2.4 =
* ACF layout preview: selecting a field now highlights it on the example page, and clicking content on the page selects its field. Because the theme decides where each value is printed, a field is found by its value: the one element in the page content showing exactly that text, or the one image of that media file (in any generated size, including background images). Values shown more than once, very short values, links and page links are not guessed and stay in the field list.

= 1.2.3 =
* ACF layouts: a group, repeater or flexible content field is no longer hidden because one of its sub-fields is a page link or another type without dedicated handling. Page-built themes that put their whole page in a flexible "content builder" can now be read, mapped and published. Page links are read and written natively and keep their example target in a layout. Other sub-field types (for example clones, users or icon pickers) are carried over unchanged and are never filled from content. Password sub-fields still keep their container out.
* ACF layouts: sub-fields that share a name inside one row are treated as the single stored value they are. Sub-fields with the same name but a different type still keep their container out.
* ACF layouts: a required sub-field that the field's conditional logic hides (for example a button address when the button points at a page) no longer has to be filled.
* Layouts saved before 1.2.3 keep publishing exactly as before. Recapture a layout to include fields that were previously hidden.

= 1.2.2 =
* Fixed builder layouts: when a delivery leaves an optional field empty, its element is now left out of the new page instead of being published empty. A section or container left with nothing to show (apart from separators and spacers) is left out too, for example an FAQ section whose questions were not filled. A grid column stays in place, empty, while its row still shows other content, so the remaining columns keep their widths. Required fields, and elements that still show any filled field, are never removed. An optional image left empty keeps the example image as before.

= 1.2.1 =
* WPBakery: themes that register their own image picker, such as Salient, now expose those images for mapping and import, along with links stored as plain text fields and heading text stored as an attribute.
* Avada: map images in Image Frames, section and column backgrounds, content boxes and other elements that store a media-library image; deliveries import the supplied image. The text inside Avada elements such as content boxes can be mapped. Fixed Avada layouts can now publish deliveries.
* Layout preview: content that themes keep hidden until their scripts run now appears, including animation wrappers, elements hidden with visibility, image layers built by scripts, and pages whose body classes mention a slider. Verified with Salient, Avada and Divi 4.

= 1.2.0 =
* WPBakery: map images stored in the media library and the text, rich content and links of elements added by themes and add-ons. Fixed WPBakery layouts can now publish deliveries. Design settings stay protected.
* The layout preview outlines the selected WPBakery, Avada or Divi 4 element and shows content that the site normally reveals with scripts, including entrance animations and lazy-loaded images.
* Map the page Title and Excerpt in fixed post layouts, including titles that the theme prints outside the page content. Unmapped titles keep their previous behaviour.
* Elementor widgets that show the page title, excerpt or featured image through a dynamic tag point to the field to map. Each dynamic control is listed once.
* Choose Remove this element from new pages to leave an example element out of generated pages.

= 1.1.8 =
Switch posting environments without finishing old test deliveries. Changing the service URL or website identifier cancels pending deliveries and keeps the previous delivery history in a separate private archive; a notice reports what was cancelled. A dialog offers to copy the current layouts and mappings to the new connection and synchronize them. Each service URL and website identifier has separate layouts, mappings, revisions and publishing approvals; returning to a previous connection restores its configuration without replaying old deliveries. Existing remote defaults on the new service are never replaced automatically. Existing WordPress content is kept. Credential updates within the same connection retain layouts and queued work. Layout screens stay usable while deliveries run. Reload an open editor after changing connections.

= 1.1.7 =
* Map SEO titles and meta descriptions for detected Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework, SmartCrawl, Slim SEO and Squirrly SEO plugins. New layouts select both automatically. Open SEO metadata to deselect them or add instructions; existing saved selections stay unchanged.
* Choose NOVA's SEO title and meta description as field purposes when mapping other native content fields.
* Initialize the protected mapping preview even when a custom theme omits WordPress script hooks.
* Open the original example in a new tab using the arrow beside its title.
* Show a clear message when the preview cannot initialize.
= 1.1.6 =
Capture and publish fixed ACF component layouts, including groups, flexible content, repeated columns and image fields. Deliveries keep the captured component order and unselected design settings, and can create or update native posts without the original example. Existing mappings stay unchanged; capture a fresh layout to use the nested ACF fields.
The mapping preview can scroll through full-height theme containers without enabling site scripts. Supported scroll-reveal content remains visible while hidden menus and responsive variants stay hidden.

= 1.1.5 =
Elementor Global widgets and saved templates can remain shared in a creation layout. Their fields are protected and clearly identified. Choose Create independent copy for mapping to give each delivered page its own captured content and styling without editing the shared original. Known Elementor template shortcodes can be captured the same way.
Shared-layout imports verify reusable Elementor components by their contents before selecting local IDs. Missing or circular references report which component needs attention.

= 1.1.4 =
Use one saved layout for multiple NOVA content types, such as Service and Category creating normal WordPress pages. Existing URL routing and retained delivery revisions remain supported.
Design settings and additional content fields are grouped separately. Recognized design settings no longer appear as new editorial choices. Collapse either section without disabling mappings; optional field instructions are tucked away. Previously mapped design settings remain unchanged until you explicitly choose to copy the example setting.

= 1.1.3 =
Deliveries can use the site's hostname with or without www. WordPress publishes at its configured address. The layout preview highlights selected Custom HTML fields on optimized pages and preserves selection for previously saved block mappings.

= 1.1.2 =
Selecting a Custom HTML field highlights its text, link, or image in the mapping preview. Click the preview to select the matching field, even when the same question appears twice. Selecting an answer opens its FAQ section. The editor identifies structured data fields that have no visible element.

= 1.1.1 =
Capture a fresh Gutenberg Custom HTML layout to map individual headings, text, links, images and FAQ fields while preserving the surrounding markup and styling. Existing layout revisions keep their original bindings.
Map individual JSON-LD values using a named Custom purpose and guidance. New captures omit unmapped schema on generated pages.
Layouts can use distinct WordPress permalink paths to direct the same NOVA page type to different destinations, such as Pages and a custom post type.

= 1.1.0 =
Export approved layouts and their revisions from one WordPress installation and import them on others using the same NOVA site ID. Receiving installations can publish without changing the shared mappings.
Fix title-only drafts when WPML creates translations of a featured image during delivery.

= 1.0.7 =
Map a Cornerstone page or post from an existing example. Deliveries apply the saved layout through Cornerstone so the result remains editable in the builder.

= 1.0.6 =
Save and sync directly from Content rules. Selecting the same example again can resume its mapping. New layouts select eligible page design settings by default, with bulk controls for changing the selection.
The layout editor no longer offers a draft made from sample text. New editorial mappings omit the WordPress Footnotes blockâ€™s storage field.

= 1.0.5 =
Select editorial ACF and registered WordPress text fields when mapping a fixed layout. Each language can receive its own field values with WPML, Polylang, TranslatePress and Weglot. WPML can link a single translation delivered before its original.

= 1.0.4 =
WordPress uses the NOVA article language when publishing to WPML or Polylang. Translations that share a NOVA URL ID can create and update their own posts. On TranslatePress and Weglot, a translated delivery uses the published original for that NOVA URL.

= 1.0.3 =
Elementor Loop Carousel and Loop Grid widgets can keep their query and their published Loop Item template when syncing a saved layout. If this reference blocked an earlier sync, reopen the layout and choose Sync saved revision.

= 1.0.2 =
Choose the WordPress user who publishes deliveries. Multi-site routing settings are grouped together. Layout setup now goes directly from field mapping to Save & review.
Compatible delivery is automatic for a configured connection. If you want to keep a site paused, pause it in the NOVA app before upgrading.

= 1.0.1 =
Capture a Divi 5 layout, map its content fields, and create a native test draft from the saved layout.
Receive future updates through the WordPress dashboard while the plugin is active. Sites on 1.0.0 must upload this ZIP once.

= 1.0.0 =
First standalone API Mapping release with internal builder adapters and native WordPress publishing.
