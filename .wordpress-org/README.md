# WordPress.org plugin directory artwork

Files in this directory ship to the WP.org plugin directory's **SVN `/assets/`** path — NOT inside the plugin zip. They live in `https://plugins.svn.wordpress.org/x402-pay/assets/` and are referenced by the public listing page at `https://wordpress.org/plugins/x402-pay/`.

The directory in the plugin zip itself is `assets/` (built JS/CSS). Keep these two separate.

## Required files

| File | Size | Notes |
| --- | --- | --- |
| `banner-1544x500.png` | 1544×500 | High-DPI banner. Displayed at the top of the listing page. PNG or JPG. |
| `banner-772x250.png` | 772×250 | Low-DPI fallback. Same composition as the high-DPI banner. |
| `icon-256x256.png` | 256×256 | Square icon shown on the listing page and on WP-admin → Plugins. |
| `icon-128x128.png` | 128×128 | Smaller icon, same composition. |
| `screenshot-1.png` | any | Matches `== Screenshots ==` slot 1 in `readme.txt`. PNG or JPG. |
| `screenshot-2.png` | any | Slot 2. |
| `screenshot-3.png` | any | Slot 3. |

`icon.svg` is also accepted in place of the PNG icons and is preferred when the artwork is vector.

## Screenshot order

`readme.txt`'s `== Screenshots ==` section is the source of truth for captions and order. The N-th line maps to `screenshot-N.png` in this directory. Keep the two in sync.

## Submitting

The SVN layout is:

```
trunk/                 # plugin code (what npm run package builds)
tags/0.1.0/            # tagged releases
assets/                # everything in this directory
```

When publishing, run something like:

```
svn cp .wordpress-org/* /path/to/svn-checkout/assets/
cd /path/to/svn-checkout
svn ci -m "Update listing artwork"
```
