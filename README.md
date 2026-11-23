# Media Proxy for Craft CMS

Work on a local clone of a Craft 4 or 5 site without downloading the assets.
Assets whose file is missing locally are loaded from the remote site (e.g. production).
Nothing is stored or generated locally.

## Setup

Install as a dev dependency:

```bash
composer require --dev teachmeter/craft-mediaproxy
```

That's it: no `craft plugin/install`. The package is a Yii extension that Craft loads
automatically, so it never appears in project config, and production (`composer install
--no-dev`) does not have it at all. It requires `yiisoft/yii2-composer` to be allowed in
`config.allow-plugins` of your `composer.json`, which the Craft starter project does by default.

Enable it and set the remote site URL in `.env` (or e.g. `web_environment` in `.ddev/config.yaml`):

```dotenv
MEDIA_PROXY_ENABLED=true
MEDIA_PROXY_REMOTE_URL=https://www.example.com
```

The proxy is off unless `MEDIA_PROXY_ENABLED` is `true`, `1`, `on` or `yes`.
It also does nothing when the URL is not set or points to the current host.

If the remote site is protected by HTTP basic auth, add the credentials:

```dotenv
MEDIA_PROXY_BASIC_AUTH=user:password
```

## How it works

For every asset whose file does not exist in its local volume, `asset.url` and
`asset.getUrl(transform)` return a URL on the remote site:

- without transform: the original file, at the same path on the remote.
- with transform: the transform file on the remote if production already generated it
  (according to the transform index in the cloned database), otherwise the original file,
  scaled by the browser.

Craft never starts generating a transform for these assets, so no failing queue jobs, no
missing-file errors, no broken thumbnails. This covers templates and the control panel
(asset index thumbnails, previews, relation fields), as they all go through `Asset::getUrl()`.
Assets uploaded locally behave as usual.

With `MEDIA_PROXY_BASIC_AUTH`, URLs point to `/media-proxy/<path>` on the local site instead,
and PHP streams the file from the remote with the credentials (browsers cannot send them for
another site). Only paths below an asset volume or transform folder are proxied. Range
requests are forwarded, so video seeking works. Slower, as every file goes through PHP.

## Blocked locally

- **Image editor** for remote assets: it needs the local file. Replacing the file stays
  allowed and turns the asset into a local one.
- **Asset indexing** (utility and `craft index-assets/*`): it would treat every remote file
  as missing and offer to delete its asset.

## Limits

- URLs hard-coded into content (instead of asset references like `{asset:12:url}`) are not
  rewritten.
- With basic auth, Craft has to run at the web root (`/media-proxy/...` is root-relative).
