# Backgrounds of the printed laptop-loan documents

One directory per paper model, **named after the version of that model**. A new version of a model
invalidates both the images here and every millimetre coordinate in the matching Twig template, so
it gets its own directory rather than overwriting this one.

| Directory | Model | Source |
|---|---|---|
| `ufa-v3-2025-08-01/` | CFA Aspect Aquitaine, *Convention de prêt de matériel informatique* V3 du 01/08/2025 | `design/sources/prets/V3-Convention-pret-materiel-informatique-01-08-2025.pdf` (gitignored) |

Pages 1 to 3 are the convention, page 4 is the restitution form.

These are read from disk by `App\Service\LaptopLoanDocumentExporter` and inlined into the HTML as
base64 data URIs, because `App\Service\GotenbergClient::convertHtmlToPdf()` posts a single
`index.html` and no companion asset. They are deliberately **not** under `assets/`: AssetMapper
content-hashes filenames, and nothing here is ever served to a browser.

Regenerating them from a new source PDF:

```console
pdftoppm -r 300 -png modele.pdf page
```

Do not route these through git-LFS. `.gitattributes` only covers `public/downloads/*.apk` and
`*.ipa`, so the default behaviour is already the right one — production once served a 133-byte LFS
pointer in place of a real file.

The UFA model is third-party branded material (Aspect Aquitaine). It is reproduced as-is for the
purpose it is meant for; see `NOTICE` for how marks are handled.
