# OpenZFS Summit theme

A WordPress block theme for the OpenZFS User & Developer Summit site.

- **Light and dark mode.** The site follows the visitor's system setting. A
  sun/moon toggle in the header overrides it, and the choice is remembered in
  that browser. The stored choice is applied before first paint, so there is
  no flash of the wrong mode.
- **Legible in both modes.** Every text/background pairing in the palette
  meets WCAG AA. Sponsor logos sit on light tiles so dark artwork stays
  readable on the dark background.
- **Summit layout.** A hero with the dates and Register / Schedule actions,
  About with the shape of the week, the four summit days as cards, and
  sponsor tiers with logo sizes scaled by tier.
- **WooCommerce and My Calendar** pages are styled with the same tokens.
  The two ticket products on Registration render as side-by-side ticket
  cards.
- **Editable in the Site Editor.** Header, footer, templates and patterns
  are plain blocks. Nothing requires a build step.

Requires WordPress 6.6+ (tested on 7.1.2) and PHP 8.1+.

## Install

Download a release zip (or `git archive`) and upload it under
*Appearance → Themes → Add New → Upload*, or clone into
`wp-content/themes/openzfs-summit`.

```sh
git archive --format=zip --prefix=openzfs-summit/ -o openzfs-summit.zip HEAD
```

`tools/` and `source-snapshot/` are excluded from the archive.

## Moving from "The Conference"

The previous theme's *Additional CSS* is stored per theme, so it does not
follow a theme switch. The rules that changed shop behaviour are carried
into `style.css` under *Site-owner customizations*:

- hide the add-to-cart buttons on product lists
- hide related products and product meta
- hide cart line prices
- hide the product-bundle list

The purely cosmetic rules (teal buttons, hidden titles) are replaced by the
theme's design. Two old rules were missing their leading dot and never
applied (`add-to-cart-button`, `wc-block-checkout__guest-checkout-notice`);
they are not carried over, so checkout behaves as it did before. The original is kept in
[`source-snapshot/wp-custom-css.css`](source-snapshot/wp-custom-css.css).

The default page template has no title block, matching the old site, where
every page carries its own heading in its content. Two alternative templates
are available: **Page with title** and **Page (wide, no title)**.

## Patterns

Found under *OpenZFS Summit* in the block inserter.

| pattern | use |
|---|---|
| Summit hero | dates, title, Register and Schedule buttons |
| About the Summit | description with three week-shape facts |
| Summit days | four day cards, with the Hackathon highlighted |
| Sponsors introduction | how to sponsor |
| Sponsor tier | a tier label plus logo tiles; add the class `ozs-tier-diamond`, `-gold`, `-silver` or `-bronze` |

`tools/compose-pages.php` rebuilds the Home and Sponsors pages from these
patterns and the sponsor logos in the media library, keeping the site's
text verbatim:

```sh
wp eval-file tools/compose-pages.php
```

## Test site content

`tools/fetch-source.sh` downloads the public content of
summit.openzfs.org into `source-snapshot/export/`, which git ignores.
`tools/import-content.sh` then installs the same plugin versions as the
source site and imports the pages, media, products, variations, menus and
the My Calendar event into a WordPress site in a Bastille jail. The
importer matches existing items, so it is safe to re-run.

```sh
HOST=<jail host> WPUSER=<wp admin> tools/import-content.sh
wp eval-file tools/compose-pages.php
```

The shop archive leaves out the `registration` and `complimentary` product
categories, because tickets are sold from the Registration page. Change
this with the `ozs_shop_excluded_categories` filter.

## License

GPL-2.0-or-later. Inter is bundled under the SIL Open Font License
(`assets/fonts/OFL.txt`).
