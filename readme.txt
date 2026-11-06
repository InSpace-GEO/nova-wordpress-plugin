=== NOVA WordPress Plugin ===
Stable tag: 1.1.8
Requires at least: 6.9
Requires PHP: 8.0
License: Proprietary

Map existing layouts and publish NOVA deliveries to WordPress. Includes optional NOVA Blog, NOVA Service Pages and WooCommerce category rich text.

== Installation ==
Upload the installable ZIP from the release in WordPress. Deactivate NOVA Bridge Suite before activating the new plugin. Open Settings > NOVA Settings to configure layouts, the connection and optional modules.

== Changelog ==

= 1.1.8 =
Switch posting environments without finishing old test deliveries. Confirm the switch to cancel pending work and retain the previous delivery history in a separate private archive. The active delivery list and discovery state start fresh. Existing WordPress content and saved layouts are kept. Credential updates within the same connection retain queued work. A running delivery must finish its current batch before the switch can proceed.

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
