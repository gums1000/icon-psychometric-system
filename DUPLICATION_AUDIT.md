# Duplicate Scan Report

## Identical duplicate files

1. **`includes/auth/portal-redirects.php`** and **`includes/auth/icon-psy-auth-helpers.php`** are near-identical helper copies.
   - Both define the same URL helper functions: `icon_psy_portal_url`, `icon_psy_login_url`, `icon_psy_register_url`, `icon_psy_lost_password_url`, `icon_psy_management_portal_url`.
   - Only a docblock wording difference is visible.
   - **Used status:** `portal-redirects.php` is loaded from plugin bootstrap; `icon-psy-auth-helpers.php` under `includes/auth/` appears not loaded.

2. **`includes/shortcodes/team-survey.php`** and **`includes/shortcodes/team-survey2.php`** are functionally duplicate copies.
   - Both define `icon_psy_team_survey2` and register `[icon_psy_team_survey2]`.
   - Diff shows one substantive change block (extra image URLs in `team-survey2.php`), but otherwise same structure and JS/CSS/form flow.
   - **Used status:** `team-survey2.php` is loaded from plugin bootstrap; `team-survey.php` is not referenced by includes.

## Near-duplicate files

1. **`includes/shortcodes/rater-survey-traits.php`** and **`includes/shortcodes/icon-psy-rater-survey-traits.php`**
   - Both define the same shortcode function name (`icon_psy_rater_survey_traits`) and register the same shortcode tag.
   - Both implement the same fallback branding resolver function (`icon_psy_safe_get_branding_context`) and similar helper scaffolding.
   - CSS/JS survey UI blocks are highly similar by token overlap.
   - **Used status:** bootstrap loads `rater-survey-traits.php`; the `icon-psy-` variant is not directly loaded but may be loaded by wildcard shortcode loader paths in some deployments.

2. **`includes/shortcodes/icon-profiler-report.php`**
   - Contains duplicate shortcode registration block and duplicate inline script block inside the same file.
   - Indicates a merged legacy copy section remained in-place.

3. **`includes/shortcodes/traits-report.php`**
   - Contains duplicate shortcode registration and duplicate JS blocks in two distant sections of the same file.
   - Indicates in-file duplicated legacy segment.


4. **Narrative-related duplication check**
   - There is **one narrative template file** in the repository: `includes/narratives/lens-narratives.php`.
   - Additional narrative duplication exists as repeated method blocks inside `includes/class-icon-psy-ai.php` (`get_cached_insights`, `cache_insights`, `generate_competencies` each appear twice), which looks like a merged legacy section.

## Duplicate logic blocks

### Repeated helper blocks across multiple files

1. **`icon_psy_table_exists`** is redefined in multiple places:
   - `includes/helpers/db.php`
   - `includes/shortcodes/client-portal.php`
   - `includes/shortcodes/team-report.php`
   - `includes/shortcodes/feedback-report.php`
   - `includes/shortcodes/rater-survey-traits.php`
   - plus deprecated copies.

2. **Auth URL helper set** appears in:
   - `icon-psychometric-system.php`
   - `includes/auth/portal-redirects.php`
   - `includes/auth/icon-psy-auth-helpers.php`
   - `includes/auth/tml-portal.php`

3. **Branding helper loader/fallback pattern** appears in:
   - `includes/shortcodes/client-portal.php`
   - `includes/shortcodes/team-report.php`
   - `includes/shortcodes/management-portal.php`
   - `includes/shortcodes/traits-report.php`


4. **Narrative helper/method duplication** appears in:
   - `includes/class-icon-psy-ai.php` (duplicate method definitions for `get_cached_insights`, `cache_insights`, `generate_competencies`)
   - `includes/shortcodes/feedback-report.php` + `includes/shortcodes/team-report.php` (parallel narrative fallback helper patterns)

### Duplicated CSS blocks embedded in PHP

1. Exact duplicate inline `<style>` block:
   - `includes/shortcodes/team-survey.php` (style block #1)
   - `includes/shortcodes/team-survey2.php` (style block #1)

2. Near-duplicate survey style blocks:
   - `team-survey*.php`, `rater-survey.php`, `rater-survey-traits.php`, `icon-psy-rater-survey-traits.php`

### Duplicated JavaScript blocks

1. Exact duplicate inline `<script>` block:
   - `includes/shortcodes/team-survey.php` (script block #1)
   - `includes/shortcodes/team-survey2.php` (script block #1)

2. Exact duplicated script blocks in same file:
   - `includes/shortcodes/icon-profiler-report.php` (same accordion script repeated twice)
   - `includes/shortcodes/traits-report.php` (same chart helper script repeated twice)

3. Near-duplicate survey JS blocks:
   - `team-survey*.php`, `rater-survey.php`, `rater-survey-traits.php`, `self-assessment.php`, `icon-psy-rater-survey-traits.php`
   - Same function names recur (`setAutosaveStatus`, `updateProgress`, `showCard`, `serializeForm`, `restoreForm`, `saveNow`).

## Safe to remove

1. **Likely safe** (not referenced by bootstrap includes; legacy naming/placement):
   - `includes/shortcodes/team-survey.php` (superseded by `team-survey2.php`)
   - `includes/auth/icon-psy-auth-helpers.php` in `includes/auth/` (duplicate of `portal-redirects.php`; note bootstrap currently points to a different non-existent helpers path under `includes/helpers/`)
   - Entire `includes/_deprecated/` folder appears unreferenced by current bootstrap loading.

## Needs manual check

1. `includes/shortcodes/rater-survey-traits.php` vs `includes/shortcodes/icon-psy-rater-survey-traits.php`
   - Same shortcode tag/function can conflict if both loaded by environment-specific include strategy.

2. Wildcard/conditional shortcode loading in bootstrap
   - Verify whether host-specific plugin builds ever include extra shortcode files beyond explicit requires.

3. Duplicate blocks within large report files (`traits-report.php`, `icon-profiler-report.php`)
   - Remove with caution because those files are actively used and may have diverged in subtle ways around duplicated sections.

