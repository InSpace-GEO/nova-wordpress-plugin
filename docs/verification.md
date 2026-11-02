# Release verification

## 1.0.2

All 93 local suites passed. Coverage includes ranking eligible site members by published content, preserving a different user's existing byline, checking destination permissions before a write, verifying previously committed work after a permission change, and routing signed delivery notifications.

The final ZIP upgraded the active standalone plugin from 1.0.1 on WordPress staging. All 139 installed files matched the archive. The ready, layout design, save and sync routes were registered. The connection, layouts and modules screens rendered. The archive SHA-256 is `c07ff0cba4b2a5d1a76dcc400497db59773a721285924bcab43a90c3cb232a62`.

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
