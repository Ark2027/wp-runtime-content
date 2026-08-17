# Runtime Content Pack

[![lint](https://github.com/Ark2027/wp-runtime-content/actions/workflows/lint.yml/badge.svg)](https://github.com/Ark2027/wp-runtime-content/actions/workflows/lint.yml)

A WordPress plugin that lets the people who own the wording change the wording, without involving the people who own the deploy pipeline.

## The problem

A single-page app keeps its copy in the bundle. Changing a label means a build and a deploy. That's fine right up until the person who notices the typo isn't the person who can ship it, and then every comma becomes a ticket that sits for a week.

The obvious fix is a CMS, which introduces a new problem: now the form depends on the CMS being up. If the endpoint is down, does the application break?

It shouldn't, and here it doesn't.

## How it works

WordPress stores the whole content pack in a single option and serves it from a public REST endpoint:

```
GET /wp-json/content/v1/content
```

The app fetches that at runtime and **deep-merges it over its own compiled-in defaults**. That ordering is the important part:

- Endpoint unreachable? The app renders its built-in copy. A WordPress outage cannot take the form down.
- New field added in code? It appears in the editor automatically, no migration, no risk of an older stored pack blanking it.
- Partial or stale stored content? Still safe, because the defaults fill the gaps.

The editor is generated from the default content rather than hand-built, so adding a field in one place makes it editable everywhere.

## Rolling it out

Point a non-production build at the endpoint first:

```ts
// environment.uat.ts
contentUrl: 'https://your-wordpress-site/wp-json/content/v1/content',
```

Edit something, press Publish, watch it appear on UAT about a minute later. Only point production at it once you've seen that work. The compiled-in fallback means the failure mode is "stale copy", not "broken form", but there's no reason to find that out in production.

## Things worth pointing at

**Every publish is stamped.** `version` is set to a UTC timestamp on save. When someone asks which consent wording was live on a particular day, there's an answer rather than a shrug.

**Legal copy is flagged as legal copy.** Consent statements and eligibility certifications are marked in the editor with a note asking for review. Somebody changing a button label and somebody changing a consent statement are doing very different things, and the interface should say so out loud.

**Saving iterates the defaults, not the submission.** The save loop walks the known content paths and pulls each value from the POST. An unexpected field in the request can't introduce a key, and a missing one falls back to its default rather than blanking. Trusting the shape of your own form is how you end up with surprises in an option row.

**Colours are validated, not just escaped.** The editor renders a swatch by putting the stored value into a `style` attribute. `esc_attr` prevents the attribute being escaped but does nothing about CSS being injected into it, so the value has to match a literal hex colour or it reverts to the default. This is the one thing I changed from the version I originally shipped.

## Security

Standard WordPress practice, applied rather than assumed:

| | |
|---|---|
| `wp_verify_nonce` on save | CSRF |
| `current_user_can( 'edit_pages' )` | checked on both the menu and the render |
| `sanitize_text_field( wp_unslash( … ) )` | on the nonce |
| `wp_kses_post` | body copy keeps safe inline HTML, loses the rest |
| `esc_html` / `esc_attr` / `esc_textarea` / `esc_url` | on output, per context |
| hex validation | on anything that reaches a `style` attribute |

The REST endpoint is deliberately public and unauthenticated. It serves display copy that any visitor to the application already sees, and no applicant data passes through it.

## Install

**Plugins → Add New → Upload Plugin**, then activate. The option is seeded on activation so the endpoint is valid straight away. Or drop the folder in `wp-content/plugins/`.

No ACF, no page builder, no paid dependency. One file.

## Deploying it

`.github/workflows/deploy.yml` pushes to a staging site over SFTP on every push to `staging`. Host, port, user and password all come from repository secrets. The SFTP account is chrooted to the plugin's own directory, so a full sync can only ever touch this plugin and never the wider install.

## Limits

The content pack is one option row. Fine at this size; a few thousand fields would want a different storage shape.

There's no revision history in the plugin itself. The version stamp tells you *when* something changed, not *what*. WordPress revisions don't apply to options, so recovering an old pack means a database backup. If I needed that I'd write each published pack to a custom table rather than trying to bolt revisions onto an option.

Adding, removing or reordering fields is still code. This edits the wording of existing content, which is the thing that changes weekly. Structure changes are rare enough to be worth a deploy.

`Access-Control-Allow-Origin: *` is broad. It's display copy, so this is a considered choice rather than an oversight, but if you'd rather scope it to your app's origin that's a one-line change in `wprc_rest_content()`.

## License

GPL-2.0-or-later, matching WordPress.
