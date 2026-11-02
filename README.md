# NOVA WordPress Plugin

Map NOVA content to WordPress layouts and receive publishing deliveries. Requires WordPress 6.9 or newer and PHP 8.0 or newer.

## Install

Download the installable plugin ZIP from this repository's Releases and upload it under **Plugins → Add New → Upload Plugin**. Use the release asset, which includes the WordPress plugin directory.

On a site using NOVA Bridge Suite, let in-flight deliveries finish, then deactivate the Suite before activating this plugin. A delivery already planned against the old writer can pause for reconciliation when its plugin version changes. Existing saved layouts, posting credentials and delivery receipts keep their storage keys. The optional-module settings use their own option and initially adopt the site's Blog, Service and WooCommerce choices. Install updates from this repository's release assets. Use the original Suite on sites that still use legacy bridge endpoints or live-source cloning.

In **Settings → NOVA Settings**, enable the optional modules the site uses, connect the posting service, then choose an example and save its mapping under **Layouts**. A fixed layout keeps its native structure and presentation in WordPress. NOVA fills the selected fields and WordPress saves the result on the intended target. Review the test draft and the confirmed NOVA page-type default before enabling publication.

## Supported paths

- Fixed-layout delivery for WordPress content, Gutenberg, Elementor, Flatsome and Enfold. The corresponding builder/theme must be available on the site.
- Native publishing to NOVA Blog and Service post types, and WooCommerce category content with separate top and bottom fields.
- WPBakery, Avada, Beaver Builder, Breakdance and Divi 4 can provide capture, preview and native test drafts. The mapping screen reports each target's capabilities. Divi 5 documents are recognized and protected from generic writes; Divi 5 authoring is deferred pending native testing.
- The existing NOVA posting contract and ready webhook URL remain compatible. Older explicit live-source clone jobs require the original Suite.

## Development

The plugin derives from NOVA Bridge Suite commit 7e124d48d914b805a31bee340eb6f7fe338d61cc. Retained wire names and option keys preserve saved mappings. Set `NOVA_WP_CORE_FIXTURE` to a local WordPress `wp-includes` directory and run `python tools/test.py` with PHP and Node on your path. Build the runtime ZIP with `python tools/package.py OUTPUT_DIRECTORY`.
