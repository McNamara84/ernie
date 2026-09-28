# Find page previews

These images are served locally by the public `/find` page. All four were
obtained on 2026-09-28. Existing copyright and image credits remain linked
from the public footer; this migration does not assign a new license.

| File | Source | Dimensions |
| --- | --- | --- |
| `data-portal.png` | Screenshot of <https://dataservices.gfz.de/doi-search> | 1440 × 900 |
| `data-centres.png` | [Original TYPO3 image](https://dataservices.gfz-potsdam.de/web/fileadmin/_processed_/3/6/csm_data_centres_d1e3f1fa2f.png) | 620 × 321 |
| `research-infrastructures.png` | [Original TYPO3 image](https://dataservices.gfz-potsdam.de/web/fileadmin/_processed_/1/d/csm_Kachel-FInd-Website-RI_476a130b61.png) | 620 × 318 |
| `igsn-portal.png` | Screenshot of <https://dataservices.gfz.de/igsn-search> | 1440 × 900 |

The two TYPO3 images were downloaded unchanged from the existing
[Find page](https://dataservices.gfz-potsdam.de/web/find). The portal screenshots
were captured with a temporary Pest browser test using the repository's
`npm run test:php -- tests/pest/Browser/TemporaryFindPortalScreenshotsTest.php`
wrapper and Chromium in the development Docker container (PHP memory limit 2 GB).
Both capture cases passed. The temporary test was removed afterward so routine
tests do not depend on the production portals.

To refresh the portal images, use a temporary Pest browser test with
`visit('https://dataservices.gfz.de/'.$path, ['viewport' => ['width' => 1440,
'height' => 900], 'colorScheme' => 'light', 'locale' => 'en-GB',
'reducedMotion' => 'reduce'])` for `doi-search` and `igsn-search`, a 60-second
browser timeout, and `waitForText('GFZ Data Services')`. Wait for
`document.fonts.ready` and allow the map to finish loading before taking
`screenshot(false, $filename)`. The original capture allowed another 12 seconds
for the map. Inspect both images for loaded results and map backgrounds before
copying them from `tests/Browser/Screenshots/` into this directory.
Pest clears that screenshot directory at startup, so preserve any existing
baselines before capture and restore them afterward.

The screenshots retain the map attribution and are not cropped or retouched.
They show the portals at capture time; result counts will change independently
of these static previews.
