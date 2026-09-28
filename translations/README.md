# Persian translation — kept out of the plugin on purpose

`dicex-connect-fa_IR.po` and its compiled `.mo` used to ship inside
`dicex-connect/languages/`. The WordPress.org review asked for them to be
removed, and it is right: a plugin hosted in the directory receives its
translations from translate.wordpress.org, which builds a language pack per
locale and WordPress downloads it on its own. Since WordPress 4.7 that pack also
**takes precedence** over anything a plugin distributes itself, so bundling one
would not even win.

Source: <https://make.wordpress.org/polyglots/handbook/plugin-theme-authors-guide/>

## State

Rebuilt on 2026-09-19 against `dicex-connect/languages/dicex-connect.pot` at
version 1.8.2. **584 of 584 strings translated**, verified by reading the `.mo`
back the way WordPress reads it. Nothing is outstanding. "World Wide", the
region, stays in English on purpose: the product page keeps those two words in
every language. Since 2026-09-19 the catalogue also carries the plugin header —
name, description, author and their links — the way `wp i18n make-pot` does, so
the Plugins screen reads in Persian too.

`po-build.mjs` writes wherever its third argument points. Point it at
`translations`, never at `dicex-connect/languages` — only the `.pot` belongs in
the plugin folder, for the reason at the top of this file.

Do not hand-edit either file. The dictionary is
`.claude/skills/wp-i18n-persian-rtl/scripts/fa.json`; change a translation there
and rebuild:

```bash
node .claude/skills/wp-i18n-persian-rtl/scripts/po-build.mjs dicex-connect/languages/dicex-connect.pot .claude/skills/wp-i18n-persian-rtl/scripts/fa.json translations
```

Regenerate the `.pot` first whenever a source string changed, or the rebuild will
carry the old wording.

## What to do with them now the plugin is approved

1. A few days after the first SVN commit the plugin gets a translation project at
   `https://translate.wordpress.org/projects/wp-plugins/dicex-connect/`.
2. Ask the Polyglots team for Project Translation Editor rights on `fa_IR` for
   this project — a post on <https://make.wordpress.org/polyglots/> tagged
   `#fa_IR` with the project URL.
3. Import `dicex-connect-fa_IR.po` into the locale's **Stable (latest release)**
   sub-project and approve the strings.
4. A language pack is built once **90%** of that sub-project is translated and
   approved, then delivered to every site running the plugin — including sites
   that installed it before the translation existed. Generation runs about
   thirty minutes after an approval.
5. The **Stable Readme** sub-project is separate and has **no threshold**: each
   approved string ships straight away, which is how the listing page itself
   becomes Persian.

Arabic and German follow the same route and only need translators. A paid
translator can be given the Cross-Locale PTE role, which allows importing and
approving across several locales at once.
