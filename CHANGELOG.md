# Changelog

Notable changes to this project are documented here.

## Unreleased

### Added

- `ImporterLogger`, the package's logger (extends ClassKit's `Logger`, channel
  `content_importer`). Everything the package logs goes through it, and a test
  fails if code logs any other way.
- `UrlGuard`: SSRF protection for server-side fetches (public http(s) hosts
  only, resolved address pinned).
- `ArticleRecord` and `ArticleFileParser`: strict validation of import records,
  with per-record errors instead of aborting the file.
- `Sanitiser` and `SanitiserPolicy`: HTMLPurifier allowlist for imported HTML.
- The sitemap scraper from adferiad-news-scraper, built in: sources are set up
  in Dashboard > Content Importer > Sources, imported by the "Import News
  Sources" task (one queue message per article), and every article is recorded
  in the Activity page. New tables `ciSources` and `ciItems`; version 0.1.0.
- An "Ignore these pages" list per source (slugs, `*` as a wildcard), so index
  and category pages that a sitemap lists are not imported as articles. Needs
  the 0.2.0 upgrade (a new `excludedSlugs` column); clear the cache afterwards.
- Changed articles are published as a new approved version of the same page,
  not as a duplicate. Unchanged articles are skipped without a request, or with
  a conditional request that is answered 304.
- `ArticlePlanner`, `ArticleImporter`, `ConcretePagePublisher` and
  `ImageImporter`; the JSON upload now uses the same publisher and image
  importer as the scraper.
- Images are checked by content (real JPEG or PNG only), saved with the right
  extension, and downloaded once per run.
- PHPUnit tests for the above, including an XSS payload corpus.
- Dependency: `ezyang/htmlpurifier`.

### Changed

- Package handle is now `content_importer_kit`, matching the
  `Concrete\Package\ContentImporterKit` namespace and the other Kit packages.
- Every remote request (including each redirect) is checked by `UrlGuard`,
  pinned to the address that was checked, limited in size while it downloads,
  and uses timeouts. Requests are never made with Guzzle's `stream` option,
  which would bypass the pin.
- The "Run Import" dashboard page is now "Import From File", and the Content
  Importer menu opens on Sources.
- The import action validates a CSRF token.

### Fixed

- Mapped attribute values are handled by the attribute's type: image URLs are
  downloaded into image attributes, other http(s) URLs are stored as text in
  text, textarea and URL attributes, and `javascript:`, `data:` and other
  non-http(s) URLs are skipped. Previously non-image URLs were dropped silently.
- Imported pages use the page template chosen on the first screen, not the page
  type's default.
- Import runs no longer abort on the first bad record or failed article, and
  the duplicate-page check now works.
- Remote image lookups no longer take about a minute per image under the web
  server (DNS now resolves through the system resolver).
- HTMLPurifier is loaded when the package is installed by symlink or path,
  where the site does not autoload the package's own vendor folder.
- Attribute names from the import file are escaped in the mapping screen.
- Removed leftover composer template names and placeholder URLs.

## 0.0.0

### Added

- Separate GitHub Actions workflows for code standards and system tests.

### Changed

- Code standards checks run on pushes and pull requests; system tests run on
  pull requests.
- Updated Composer dependencies and development tooling for the package
  template.

## 2026-09-25

### Added

- PHPStan static analysis configuration and a Composer command for type checks.
- Cloudflare Access authentication for installing dependencies from the private
  Composer registry.

### Changed

- Updated the template to use the published `limegreentangerine/class_kit`
  Composer package.
- Refined the GitHub Actions workflows and development dependencies.

## 2026-09-15

### Added

- Initial Concrete CMS Composer package template, including Composer
  autoloading, PHPUnit test setup, and a GitHub Actions test workflow.
