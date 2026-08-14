# Images of the printed laptop-loan documents

One directory per paper model, **named after the version of that model**. A new version of a model
invalidates the images here and, for a scanned model, every millimetre coordinate in the matching
Twig template — so it gets its own directory rather than overwriting an existing one.

| Directory | Model | Technique | Source |
|---|---|---|---|
| `ufa-v3-2025-08-01/` | CFA Aspect Aquitaine, *Convention de prêt de matériel informatique* V3 du 01/08/2025 | scanned pages + overprint | `design/sources/prets/V3-…-01-08-2025.pdf` (gitignored) |
| `cfc-2026-08/` | CFC Beaupeyrat, *Convention de prêt de matériel* | composed HTML, these are just its two logos | `design/sources/prets/CFC/convention_pret_kit.zip` (gitignored) |

In both cases the convention is the first three pages and the restitution form the last one.

**The two models are built differently on purpose.** The UFA model only exists as a PDF typeset in
commercial fonts that cannot be embedded, so its pages are rasterised at 300 dpi and only the loan's
values are overprinted — `page-1.png` … `page-4.png`. The CFC model was supplied as real HTML, so it
is composed for real and needs no background at all; the two files here are the logo and the
Qualiopi block it embeds.

These are read from disk by `App\Service\LaptopLoanDocumentExporter` and inlined into the HTML as
base64 data URIs, because `App\Service\GotenbergClient::convertHtmlToPdf()` posts a single
`index.html` and no companion asset. They are deliberately **not** under `assets/`: AssetMapper
content-hashes filenames, and nothing here is ever served to a browser.

Rasterising a scanned model from a new source PDF:

```console
pdftoppm -r 300 -png modele.pdf page
```

Do not route these through git-LFS. `.gitattributes` only covers `public/downloads/*.apk` and
`*.ipa`, so the default behaviour is already the right one — production once served a 133-byte LFS
pointer in place of a real file.

The UFA model is third-party branded material (Aspect Aquitaine). It is reproduced as-is for the
purpose it is meant for; see `NOTICE` for how marks are handled. The Beaupeyrat logo and the
Qualiopi block are covered by the same `NOTICE` exclusion as the institution's other marks.
