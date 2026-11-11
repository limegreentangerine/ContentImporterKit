# Content Importer Kit

A Concrete CMS package that imports articles as pages. Articles come from a
website that publishes a sitemap (the scraper), or from an uploaded JSON file.
Both go through the same checks and create pages the same way.

The package handle is `content_importer_kit` and the namespace is
`Concrete\Package\ContentImporterKit`. It depends on the
`limegreentangerine/class_kit` package (`class_kit`).

## Importing from a website

Open **Dashboard > Content Importer > Sources** and add a source:

- **News URL**: articles must be under this address.
- **Sitemap URL**: a sitemap on the same site that lists them. A sitemap index
  is not supported; use the address of one of the sitemaps it lists.
- **Selectors**: CSS selectors for the title and the article content, and
  optionally the banner image. Supported: tag names, `.class`, `#id`,
  `[attribute]`, `[attribute=value]`, descendant (space) and child (`>`)
  combinators, and comma-separated lists. Anything else is refused.
- **Ignore these pages** (optional): sitemaps often list pages that are not
  articles, such as a news index or a category. Give the last part of their
  address (the slug), one per line. `*` matches any run of letters, numbers and
  hyphens, so `category-*` ignores `category-news` and `category-events`. Ignored
  pages are never fetched.
- **Where the pages go**: parent page, page type, page template, the area the
  content goes into, and optionally the page attribute that receives the banner.
- **Limits** (optional): only articles changed in the last N days, and the most
  articles to import per run. The newest come first, so a large archive is
  imported over several runs.

Before a source runs, click **Preview** on its row to see what a run would do.
Each of the newest 20 articles is listed as one that would be created, updated,
left alone or would fail (with the reason), together with how many are already
up to date, are not articles, or are held back by the per-run limit. A preview
fetches the sitemap and the article pages, but creates no pages or images and
records nothing in the Activity page. It cannot see that a page was deleted in
Concrete, or that an unrelated page already sits at an article's address, so a
real run can still differ in those two cases.

Sources are imported by the **Import News Sources** task (Dashboard > System &
Settings > Automation, or `concrete task:import-news-sources` on the command
line). It can be scheduled. One queue message is created per article, so one bad
article never stops the others.

How a run behaves:

- Articles already imported from the version the sitemap lists now are not
  fetched at all. Others are fetched with `If-None-Match` and
  `If-Modified-Since`, and a page whose cleaned content is identical to the last
  import is not touched.
- A changed article gets a **new approved version of the same page**. The
  address, page ID and history stay. The content blocks in the target area are
  replaced; other blocks added there by editors are left alone.
- A page deleted in Concrete since the last import is created again. A page
  that was not created by the importer and already sits at an article's address
  is never overwritten: that article is reported as failed.
- Every article is recorded in **Dashboard > Content Importer > Activity**, with
  the reason when it failed. Everything is logged to the `content_importer`
  channel in Dashboard > Reports > Logs.

Safety: every request (including each redirect) is checked so that only public
http(s) addresses on ports 80 and 443 are fetched, and the connection is pinned
to the address that was checked. Imported HTML is reduced to an allowlist of
tags (see `SanitiserPolicy`) before it is stored.

Image performance: Concrete creates the thumbnails when an image is imported,
and that is the slowest part of a run. These site settings speed it up:
`concrete.misc.basic_thumbnailer_generation_strategy` (`async`),
`concrete.misc.default_png_image_compression` (lower than 9) and
`concrete.file_manager.images.create_high_dpi_thumbnails` (`false`).

## Importing from a JSON file

1. Upload a `.json` file to the File Manager.
2. Open **Dashboard > Content Importer > Import From File**.
3. Choose the file, the parent page, the page type and the page template.
4. Name the area to import blocks into and map the file's `attributes` keys to
   page attributes.

The file is a JSON array of articles:

```json
[
	{
		"urlSlug": "my-article",
		"title": "My Article",
		"description": "Short text",
		"published_date": "2024-05-01 10:00:00",
		"attributes": { "banner": "https://example.com/pic.jpg" },
		"body_html": [{ "block_type": "content", "data": "<p>HTML here</p>" }]
	}
]
```

Only `content` blocks are imported. Mapped attribute values are handled by the
type of the attribute they are mapped to:

- A jpg or png URL mapped to an image attribute is downloaded into the File
  Manager (only public http(s) addresses are fetched).
- Any other http(s) URL mapped to a text, textarea or URL attribute is stored
  as text.
- Other URLs (`javascript:`, `data:`, `ftp:` and so on) and URLs mapped to other
  attribute types are skipped.
- Values that are not URLs are stored as they are.

## Requirements

- PHP 8.4 or later.
- Concrete CMS 9.5 or later.
- Composer.
- Node.js and npm, used by the Composer post-install script and JavaScript
  formatting commands.
- Access to the private Composer registry at
  `https://packages.limegreentangerine.net` to install private dependencies.

## Install

```bash
composer install
```

Composer runs `npm install` after installation. If you need to authenticate to
the private registry, configure Composer to send the Cloudflare Access service
token headers for `packages.limegreentangerine.net`:

- `CF-ACCESS-CLIENT-ID` -> Cloudflare Access service-token client ID.
- `CF-ACCESS-CLIENT-SECRET` -> matching client secret.

Keep these credentials private; do not commit them to the repository.

## Development commands

Run these commands from the repository root:

| Command                                             | Purpose                                                                                                                        |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `composer test`                                     | Run PHPUnit tests.                                                                                                             |
| `composer test-coverage`                            | Run PHPUnit and print a coverage report.                                                                                       |
| `composer typecheck`                                | Run PHPStan using `phpstan.neon`. Findings are displayed, but the command exits successfully even when PHPStan reports errors. |
| `composer format:check`                             | Check PHP and JavaScript formatting.                                                                                           |
| `composer format`                                   | Format PHP and JavaScript files.                                                                                               |
| `composer format:php:check` / `composer format:php` | Check or format PHP files with PHP-CS-Fixer.                                                                                   |
| `composer format:js:check` / `composer format:js`   | Check or format files with Prettier.                                                                                           |

PHPStan analyzes `controller.php`, `controllers`, `src` and `tests` at level 4,
with bootstrap configuration from `phpstan-bootstrap.php`. The view templates
in `elements` and `single_pages` are skipped because Concrete injects their
variables. If you add package directories, update the `paths` in
`phpstan.neon` and the directories in `.php-cs-fixer.dist.php` so they are
included in analysis and formatting.

## GitHub Actions

- **CodeStandards** runs formatting checks and PHPStan on pushes and pull
  requests.
- **SystemTests** runs PHPUnit on pull requests.

Both workflows install dependencies from the private Composer registry. Add
these **repository secrets** under **Settings > Secrets and variables >
Actions** so the workflows can authenticate:

- `CF_ACCESS_CLIENT_ID` -> Cloudflare Access service-token client ID.
- `CF_ACCESS_CLIENT_SECRET` -> matching client secret.
