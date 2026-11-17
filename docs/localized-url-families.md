# Localized URL families

The delivery contract can include an optional `url_family` object alongside its
existing content and identity:

```json
{
  "url_group_id": "50",
  "primary_url_id": "100",
  "primary_url": "https://example.test/original/",
  "primary_language": "en-US"
}
```

This describes related, independently authored localized items. Each item keeps
its own URL ID, language, content version, destination and native ownership. The
metadata is frozen in the delivery and retained in its local native-write plan.
It does not change the source-content hash or the exact receipt bytes.

WPML and Polylang use the shared site and URL-group identity to link native posts.
The plugin records committed members locally and holds a group lock in addition
to the existing per-URL and native-target locks. Delivery order can differ from
language order. A known primary published before group metadata was introduced
can be adopted through its existing NOVA association after its language and
native URL are checked. The plugin never searches arbitrary posts by slug to
infer that relationship.

The group must use compatible native post types and distinct configured language
slots. Existing, unrelated translation groups and occupied language slots are
preserved. A regional language such as `nl-BE` needs its own configured native
language slot when `nl-NL` is another member.

Deliveries without `url_family` retain the existing `site_id + url_id` family
behavior. Native ownership and version checks remain scoped to each URL and
language in both flows.

## TranslatePress and Weglot

These providers retain one native original and render its localized views. The
plugin resolves the explicit primary URL ID and verifies its committed owner,
published status, language, native type and permalink. The primary language must
match the provider's configured source language. The localized item keeps its
own authored content and version; applying its fields does not overwrite the
original's native content.

The provider-generated translated permalink is retained as the native destination,
including when its slug differs from the delivery's intended URL. Both URLs must
belong to the configured language, and the resolved destination must satisfy this
installation's publishing scope. Apply, recovery and verification require the
provider to keep resolving the original to that retained destination.

After the localized content is saved and verified, the existing `publication`
object reports the provider URL and preserves the delivered `intended_url`.
The frozen delivery bytes, source hash and ETag retain their original values.
TranslatePress and Weglot manage translated slugs through their own settings.

A grouped delivery arriving before its original is parked with
`nova_translation_source_pending`, without consuming the publication retry limit.
When a delivery verifies the original as publicly published, only its matching local
dependencies become ready. This also supports a frozen original delivery from
before URL-family metadata was introduced. Existing deliveries without family
metadata keep their existing retry behavior.
Draft or scheduled originals published later outside delivery processing may need
an explicit catch-up. This change adds no general WordPress publication hook.

When source-ready dependencies exceed one batch, they are ordinary ready work
for the existing continuation and WP-Cron backstop. The same connection, pause
and batch limits apply.

## Rollout and verification

Upgrade all WordPress installations receiving deliveries for the same NOVA site
before deploying the backend producer with automatic URL-family emission.
Older strict delivery schemas reject this field. There is no per-domain toggle
in the automatic producer.
No existing delivery is rewritten during upgrade.

Local tests use the production family/linking implementation with in-memory
WordPress, WPML and Polylang fixtures. They cover both arrival orders, reciprocal
links, replay, conflicting language slots and primary identities, pre-upgrade
primary lookup, wrong native URLs, site isolation, and the existing multilingual
flow. They do not constitute live staging or end-to-end publishing verification.
Dictionary-provider tests additionally exercise the actual grouped source lookup,
write/replay and localized custom-field renderers with in-memory provider storage.
They cover differing intended and resolved slugs, subdirectory and language-domain
routes, URL drift after planning, excluded destinations, and success-report replay.
