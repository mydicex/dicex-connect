# Security policy

## Supported versions

The current release on WordPress.org is the one that receives fixes. At the time of writing that is
the 1.8 series. Older versions are not patched; please update before reporting.

## Reporting a vulnerability

**Please do not open a public issue, and do not post it in the WordPress.org support forum.** A
report in the open is a working exploit for every site still running the plugin.

Write to **wp-support@dicex.me** with "security" in the subject, or use GitHub's private
vulnerability reporting on this repository (Security → Report a vulnerability).

Please include:

- the plugin version, and the WordPress and PHP versions,
- what an attacker can do, and what access they need to start (no account, a subscriber account, an
  administrator),
- the steps to reproduce it, with a request or a screenshot if that is clearer.

We will confirm we have it, keep you posted while a fix is prepared, and credit you in the changelog
when the fix ships, unless you would rather we did not.

## What to leave out of a report

The plugin's log can contain customers' mobile numbers, even though it hides part of each one. If
you attach a log, remove anything that identifies a real person first.
