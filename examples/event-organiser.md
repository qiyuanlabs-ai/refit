# PHP 8.4 upgrade report: Event Organiser

WordPress plugin · 50 PHP files · checked 2026-10-30

**Probably runs on PHP 8.4, but 17 spots can fail with unexpected input and should be checked. 6 more problems already exist on PHP 7.2.**

| | Your code | Bundled libraries |
|---|---:|---:|
| Breaks | 0 | 0 |
| Check | 17 | 0 |
| Deprecated | 5 | 0 |
| Fixed by `refit fix` | 4 | not changed |

- **Breaks**: fails on PHP 8.4. Visitors usually see a "critical error" page.
- **Check**: can fail depending on input; needs a person to look at it.
- **Deprecated**: still works, but logs notices and will break in a later PHP version.

## Issues in your code

### Check · DateTime::modify() without error handling · 17 found

Since PHP 8.3, modify() throws DateMalformedStringException on a date string it cannot parse, where it used to return false. Bad input (often from a URL or a saved setting) now crashes the page.

Fix: Wrap the call in try { ... } catch (\Exception $e) { ... } and handle the failure there.

- `includes/class-eo-ical-parser.php:400` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-ajax.php:650` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-archives.php:183` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-install.php:350` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-register.php:510` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-utility-functions.php:424` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-utility-functions.php:439` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-utility-functions.php:444` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-utility-functions.php:446` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event-organiser-utility-functions.php:735` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event.php:360` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event.php:386` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event.php:412` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event.php:689` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- `includes/event.php:800` DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.
- …and 2 more (see the JSON report for all of them)

### Deprecated · Properties created without being declared ("dynamic properties") · 4 found, 4 auto-fixable

Deprecated in PHP 8.2. Every such assignment logs a deprecation notice, which floods debug logs; PHP 9 is expected to make it an error.

Fix: Declare the properties in the class. Refit adds them automatically as plain public properties, or adds #[\AllowDynamicProperties] when the parent class lives outside the scanned code.

- `classes/class-eo-venue-list-table.php:14` (auto) EO_Venue_List_Table creates properties $_column_headers, $items without declaring it first. Its parent class is outside the scan, so #[\AllowDynamicProperties] will be added.
- `event-organiser-debug.php:9` (auto) EventOrganiser_Debug_Page creates property $debugger without declaring it first.
- `event-organiser-settings.php:9` (auto) EventOrganiser_Settings_Page creates property $tabs without declaring it first.
- `includes/class-eo-theme-compatability.php:24` (auto) EO_Theme_Compatabilty creates properties $wp_filter, $merged_filters without declaring it first.

### Deprecated · Function strptime() is deprecated since PHP 8.1; Use date_parse_from_format() or IntlDateFormatter::parse() instead · 1 found

Still works, but logs a deprecation notice each time it runs and is expected to break in a later PHP version.

- `includes/event-organiser-utility-functions.php:859` Function strptime() is deprecated since PHP 8.1; Use date_parse_from_format() or IntlDateFormatter::parse() instead

## Already a problem on PHP 7.2

These already fail or log notices on PHP 7.2, the oldest version the project supports, so the upgrade does not change them. They are often in code that never runs, but some are real bugs.

### Breaks · Extension 'mysql_' is deprecated since PHP 5.5 and removed since PHP 7.0; Use mysqli instead · 2 found

Fails on PHP 8.4: a fatal error or a feature that no longer exists. Visitors usually see a "critical error" page where this code runs.

- `event-organiser-debug.php:156` Extension 'mysql_' is deprecated since PHP 5.5 and removed since PHP 7.0; Use mysqli instead
- `event-organiser-debug.php:541` Extension 'mysql_' is deprecated since PHP 5.5 and removed since PHP 7.0; Use mysqli instead

### Breaks · Function mysql_get_server_info() is deprecated since PHP 5.5 and removed since PHP 7.0 · 2 found

Fails on PHP 8.4: a fatal error or a feature that no longer exists. Visitors usually see a "critical error" page where this code runs.

- `event-organiser-debug.php:156` Function mysql_get_server_info() is deprecated since PHP 5.5 and removed since PHP 7.0
- `event-organiser-debug.php:541` Function mysql_get_server_info() is deprecated since PHP 5.5 and removed since PHP 7.0

### Breaks · "$this" can no longer be used in a plain function or method since PHP 7.1 · 2 found

Fails on PHP 8.4: a fatal error or a feature that no longer exists. Visitors usually see a "critical error" page where this code runs.

- `includes/class-eo-extension.php:299` "$this" can no longer be used in a plain function or method since PHP 7.1.
- `includes/class-eo-extension.php:302` "$this" can no longer be used in a plain function or method since PHP 7.1.

## How this was checked

- Target: PHP 8.4. Oldest PHP the code must keep supporting: 7.2 (default, the project does not declare one); automatic fixes never use newer syntax than that.
- Every file was compiled with PHP 8.3.6 (`php -l`). No project code was executed.
- Tools: Refit 0.1.0, Rector 2.8.3, PHP_CodeSniffer 4.0.4, PHPCompatibility 10.0.0-alpha2.
- Static checks cannot see problems that depend on runtime data, such as a function receiving null or a string where it now requires an array. Test the upgraded code on a staging site with debug logging on.

---
Generated by [Refit](https://github.com/qiyuanlabs-ai/refit), free and open source. Need the upgrade done for you? [QiYuan Labs](https://php.qiyuanlabs.ai) does fixed-price PHP upgrades.
