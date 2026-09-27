# FullCalendar 6.1.21, vendored

The raw ESM files of `@fullcalendar/{core,interaction,daygrid,timegrid}` 6.1.21 (MIT, see
`LICENSE.md`), each minified on its own with esbuild **without bundling**, so every module stays
the one the package ships. `importmap.php` maps the package names onto these files with `path`
entries.

**One edit after minifying:** the package's relative imports (`./internal-common.js`,
`./internal.js`) are rewritten as bare specifiers (`@fullcalendar/core/internal-common.js`,
`@fullcalendar/timegrid/internal.js`…), each with its own importmap entry. AssetMapper only
rewrites a relative import its regex recognises, and it does not recognise these: its import
clause pattern allows neither `import{` without a space nor a `$` among the names, and the
package's own `core/index.js` imports `$ as listenBySelector`. Left relative, they are requested
unversioned and answer 404. A bare specifier is resolved by the browser through the import map,
with no rewriting at all.

## Why not `importmap:require`

jsDelivr's `+esm` build inlines `internal-common.js` into `core/index.js` **and** into
`core/internal.js`. The plugins import the second, the application imports the first: two copies
of every internal class, and the calendar dies on its first render with
`Class constructor … cannot be invoked without 'new'`. (FullCalendar 5 escaped this through its
separate `@fullcalendar/common` package.) Here `internal-common.js` exists once, and both
`index.js` and `internal.js` import it relatively.

`preact` itself still comes from the importmap (`10.12.1`, the version `@fullcalendar/core` pins
with `~10.12.1`). FullCalendar 6 injects its own CSS at runtime: there is no stylesheet to load.

## Upgrading

In a scratch directory, never at the repository root:

```console
for p in core interaction daygrid timegrid; do npm pack @fullcalendar/$p@<version>; done
# extract each tarball, then for every file listed below:
npx esbuild <package>/<file> --minify --format=esm --legal-comments=none --outfile=assets/fullcalendar/<package>/<file>
# then turn every "./x.js" import into "@fullcalendar/<package>/x.js"
```

Files: `core/{index,internal,internal-common,preact}.js`, `core/locales/fr.js`,
`interaction/index.js`, `daygrid/{index,internal}.js`, `timegrid/{index,internal}.js`. Check the
`preact` version `@fullcalendar/core` asks for, then open an emploi du temps and the « semaine
type » screen and watch the console.
