=== NOVA WordPress Plugin ===
Stable tag: 1.2.11
Requires at least: 6.9
Requires PHP: 8.0
License: Proprietary

Map existing layouts and publish NOVA deliveries to WordPress. Includes optional NOVA Blog, NOVA Service Pages and WooCommerce category rich text.

== Installation ==
Upload the installable ZIP from the release in WordPress. Deactivate NOVA Bridge Suite before activating the new plugin. Open Settings > NOVA Settings to configure layouts, the connection and optional modules.

== Changelog ==

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
The layout editor no longer offers a draft made from sample text. New editorial mappings omit the WordPress Footnotes block’s storage field.

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
