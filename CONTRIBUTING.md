# Contributing

Thank you for looking. This plugin is small and opinionated, so a few words about how it is built
will save you time.

## Where things go

The plugin is laid out in one direction: `includes/admin/` may call `includes/core/`, never the
reverse. Two rules hold the rest together, and a pull request that breaks either of them will be
asked to change:

- **`includes/core/` never prints anything.** No `echo`, no markup, no redirects. It returns data or
  a `WP_Error`, and the screens in `includes/admin/views/` do the printing and the escaping.
- **Two chokepoints.** Only `Dicex_Connect_Api_Client` calls `wp_remote_*`, and only
  `Dicex_Connect_Sender` builds a send. Everything else goes through them, which is why adding a
  channel touches one file instead of twenty.

Every AJAX handler starts with `$this->guard()` — a nonce and `manage_options`, in that order,
before anything is read from the request. There is no public (`nopriv`) handler, and adding one
needs a reason and its own capability model.

## Code style

WordPress Coding Standards. In practice:

- `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top of every PHP file.
- Sanitize on the way in, escape at the moment of output, in the view.
- Every user-facing string wrapped with the text domain `dicex-connect` as a literal, and a
  `/* translators: */` comment above anything with a placeholder.
- Source strings are English. Persian is a translation, not a second source.

## Testing a change

There is no build step: the plugin runs from source. To try a change, install
[WordPress Playground](https://developer.wordpress.org/playground/) and mount the folder, or copy it
into `wp-content/plugins/` on a test site. WooCommerce, WPForms, Contact Form 7 and Gravity Forms
each light up their own card, so a change to one of them is best tried with that plugin installed.

Before you open a pull request, please check that:

- the plugin activates with no notice on a site with `WP_DEBUG` on,
- the screens you touched still work with the language set to Persian, which is right to left,
- nothing new calls the gateway outside `Dicex_Connect_Api_Client`.

## Translations

Please do not send a pull request for a `.po` file. Translations are contributed at
[translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/dicex-connect/), where
they reach every site automatically. The Persian catalogue in `translations/` is what gets imported
there; `languages/` ships only the `.pot`.

## Releases

Releases are published to WordPress.org and tagged here. The version in the plugin header, the
`DICEX_CONNECT_VERSION` constant and `Stable tag:` in `readme.txt` are always the same number, and
every release adds an entry to `changelog.txt`.

## Reporting a bug

Open an issue with the plugin, WordPress and PHP versions, what you expected and what happened. If
you paste a line from the plugin's log, remove anything that identifies a real person. For anything
that looks like a security problem, read [SECURITY.md](SECURITY.md) instead.
