# NOVA WordPress Plugin

Map NOVA content to WordPress layouts and receive publishing deliveries. Requires WordPress 6.9 or newer and PHP 8.0 or newer.

## Install

Download the installable plugin ZIP from this repository's Releases and upload it under **Plugins → Add New → Upload Plugin**. Use the release asset, which includes the WordPress plugin directory.

On a site using NOVA Bridge Suite, let in-flight deliveries finish, then deactivate the Suite before activating this plugin. A delivery already planned against the old writer can pause for reconciliation when its plugin version changes. Existing saved layouts, posting credentials and delivery receipts keep their storage keys. The optional-module settings use their own option and initially adopt the site's Blog, Service and WooCommerce choices. Use the original Suite on sites that still use legacy bridge endpoints or live-source cloning.

If you installed 1.0.0, upload the 1.0.1 ZIP once and choose **Replace current with uploaded**. From 1.0.1 onward, the active plugin checks the public GitHub release during WordPress's normal update check. Install available updates under **Dashboard → Updates** or enable auto-updates under **Plugins**. No GitHub account or token is needed on the site.

In **Settings → NOVA Settings**, enable the optional modules the site uses, connect the posting service, then choose an example and save its mapping under **Layouts**. A fixed layout keeps its native structure and presentation in WordPress. NOVA fills the selected fields and WordPress saves the result on the intended target. Review the test draft and the confirmed NOVA page-type default before enabling publication.

## Supported paths

- Fixed-layout delivery for WordPress content, Gutenberg, Elementor, Flatsome and Enfold. The corresponding builder/theme must be available on the site.
- Native publishing to NOVA Blog and Service post types, and WooCommerce category content with separate top and bottom fields.
- WPBakery, Avada, Beaver Builder, Breakdance, Divi 4 and Divi 5 support capture, preview and native test drafts. In **Layouts**, select an existing Divi 5 page, save a fixed layout, then create a test draft. Divi uses its own adapter. The generic Gutenberg and plain WordPress writers leave Divi 5 documents protected.
- The existing NOVA posting contract and ready webhook URL remain compatible. Older explicit live-source clone jobs require the original Suite.

## Development

The plugin derives from NOVA Bridge Suite commit 7e124d48d914b805a31bee340eb6f7fe338d61cc. Retained wire names and option keys preserve saved mappings. Set `NOVA_WP_CORE_FIXTURE` to a local WordPress `wp-includes` directory and run `python tools/test.py` with PHP and Node on your path. Build the runtime ZIP with `python tools/package.py OUTPUT_DIRECTORY`.

For a stable release, publish the generated ZIP and `wordpress-update.json` together on the same GitHub release. Use the version number as the release title (e.g. `1.0.1`), prefix the tag with `v` (e.g. `v1.0.1`), and mark the stable release as latest. WordPress reads the manifest from the latest release and downloads the ZIP pinned to that version.
