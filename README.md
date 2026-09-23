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
| Sponsor tier | a tier label plus logo tiles; add ONE of the classes `ozs-tier-diamond`, `ozs-tier-gold`, `ozs-tier-silver` or `ozs-tier-bronze` to size the logos (mid size without one) |

`tools/compose-pages.php` rebuilds the Home and Sponsors pages from these
patterns and the sponsor logos in the media library, using the site's own
text, and installs the site icon. See step 4 below.

## Build a complete test site

These steps build a copy of summit.openzfs.org running this theme in a
FreeBSD Bastille jail. Run them from a checkout of this repository on a
machine that can `ssh` to the jail's host and use `doas` there.

**1. Create a jail** with network access. VNET is recommended, e.g.:

```sh
doas bastille create -V <jail> 15.1-RELEASE <ipv4>/<prefix> <host-interface>
```

**2. Provision WordPress and install the theme.** This installs the
packages from `tools/provision/packages.txt` and sets up MariaDB, PHP 8.4,
nginx and WordPress 7.1.2. It then clones this theme from GitHub into the
site and activates it.

```sh
HOST=<jail host> JAIL=<jail> FQDN=<site name> ADMIN_EMAIL=<you@example.org>     tools/provision-jail.sh
```

The WordPress admin user is `admin` (change it with `ADMIN_USER`). Its
generated password is in `/root/.wpadmin` inside the jail.

**3. Import the content** of summit.openzfs.org: pages, media, products and
variations, menus, and the My Calendar event. It installs the same plugin
versions as the source site and downloads the content into
`source-snapshot/export/` first if that folder is missing. Re-running is
safe: pages edited since the last import are kept unless
`ZFS_IMPORT_FORCE=1`.

```sh
HOST=<jail host> JAIL=<jail> WPUSER=admin tools/import-content.sh
```

**4. Compose the Home and Sponsors pages** and set the site icon:

```sh
WPPATH=/usr/local/www/wordpress   # the same WPPATH as step 2
ssh <jail host> "doas jexec <jail> su -m www -c 'cd $WPPATH && \
    env HOME=/tmp wp eval-file wp-content/themes/openzfs-summit/tools/compose-pages.php'"
```

**5. HTTPS (optional).** Point DNS at the jail. Inside the jail, run
`certbot --nginx -d <site name>`. The nginx config already serves
`/.well-known/acme-challenge/` over plain HTTP, including behind a
Cloudflare proxy, and works with any Cloudflare SSL mode.

If you set a different `WPPATH` in step 2, use the same `WPPATH` in steps
3 and 4.

The shop archive leaves out the `registration` and `complimentary` product
categories, because tickets are sold from the Registration page. Change
this with the `ozs_shop_excluded_categories` filter.

## License

GPL-2.0-or-later. Inter is bundled under the SIL Open Font License
(`assets/fonts/OFL.txt`).
