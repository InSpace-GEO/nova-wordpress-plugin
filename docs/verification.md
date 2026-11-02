# 1.0.0 verification

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

The release ZIP contains 136 runtime files. Its SHA-256 is `6b67749d3342fbbafdd671c237b7f89243bf98ecb12477108f75fa8458a6ad90`. All 91 local suites and 17 staging test groups passed. The staging scope included refusing Divi 5 markup through automatic and manually chosen fallback writers. Divi 5 authoring is deferred until it can be tested with the actual runtime.
