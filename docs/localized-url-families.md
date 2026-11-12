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
