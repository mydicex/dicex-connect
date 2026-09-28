## What this changes

<!-- One or two sentences. If it fixes an issue: Closes #123 -->

## Kind of change

- [ ] Bug fix
- [ ] New behaviour
- [ ] Translation-affecting change (a new or changed user-facing string)
- [ ] Documentation only

## How it was tried

<!-- Which WordPress and PHP, and which of WooCommerce, WPForms, Contact Form 7, Gravity Forms or Digits were installed. -->

## Checklist

- [ ] `includes/core/` still prints nothing, and only `Dicex_Connect_Api_Client` calls `wp_remote_*`
- [ ] Every new AJAX handler starts with `$this->guard()`
- [ ] Input sanitized where it arrives, output escaped where it is printed
- [ ] New strings use the `dicex-connect` text domain, with a `/* translators: */` comment where there is a placeholder
- [ ] No `.po` or `.mo` file in this pull request — translations go to translate.wordpress.org
- [ ] Tried with the language set to Persian, if the change touches a screen
