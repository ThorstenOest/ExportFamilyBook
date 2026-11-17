# ExportFamilyBook
A Module for Webtrees 2.0 to export a tree as a family book or graphml file

## Usage

The module adds the menu **Family book** ("Familienbuch" in German) to the main navigation of a tree.
It has one entry per page, in this order (a line separates the two export pages from the two selection pages):

| Menu entry | Page | Address |
|---|---|---|
| Book (LaTeX) | export of the family book as a `.tex` file | `index.php?route=/module/_ExportFamilyBook_/Latex/<tree>` |
| Family tree chart (GraphML) | export of the chart as a `.graphml` file | `index.php?route=/module/_ExportFamilyBook_/Graphml/<tree>` |
| Branch selection ("Zweige") | edit the list of branches as a table, switch branches on and off (see below) | `index.php?route=/module/_ExportFamilyBook_/Branches/<tree>` (`&list=graphml` for the chart) |
| Image selection | choose the images of the family book (see below) | `index.php?route=/module/_ExportFamilyBook_/Media/<tree>` |

- All four pages show the **book profile** bar at the top (see below): the active profile, a list to switch
  profiles and (for managers) "Manage profiles".
- The two export pages have their own form with "Export Family Tree", "Download Settings", "Upload Settings"
  and (for managers) "Save profile “…”" and "Save as new profile", and a link to the other export page.
  On the export pages, managers find "Save profile “…”" also in the profile bar; it saves the export form
  below, like the button at the end of the form.
- After "Export Family Tree" the page shows "Export running …" with a spinner next to the button until the
  download starts (an export can take some minutes); the button cannot be pressed again meanwhile.
- **Reference individual**: chosen in the form with the individual search field. A profile other than
  Standard has its own reference individual. Otherwise it is preselected with the individual of the page you
  came from (`xref` in the address), else the individual you used last in the form (saved per user and tree),
  else your default individual of the tree resp. the default individual of the tree. Settings files never set
  the reference individual.
- **Access level**: Control panel → Modules → **Menus** → Family book. Without an entry for a tree only
  managers can see the menu and use the pages. All actions (page, export, download, upload, save) check it.
  Only managers of the tree can save (profiles, image selection, branches); every user of the module can
  switch the profile for themselves.
- Earlier versions listed the module under "Charts". Their address (`.../Chart/<tree>?xref=...`)
  redirects to the new pages; `export_book=0` leads to the GraphML page.

## Book profiles

A profile is an individual book, for example "Book for Anna". You switch between profiles at the top of each
page of the module; the export pages, the image selection and the page "Branches" show and change the state
of the active profile. The name of the active profile is always shown in the profile bar.

What belongs to a profile, and what is common:

- The profile **Standard** holds the **common settings** of all books: preamble, templates, symbols, date and
  place formats, the list of branches, the chart settings, ... It is what the module had before profiles.
- Every other profile has **its own** reference individual, title, image selection (`include_media`,
  `exclude_media`) and selection of exported branches (book and chart). All other settings come from Standard.
  So a correction of a template is made once and applies to all books.
- For a long field of the export form (preamble, title, templates, epilog, symbols, media lists), the tick
  **"only this profile"** makes the field a value of the profile of its own; remove the tick to use the common
  value again (the value of the profile is then discarded, the common value is not changed).
- Saving the export form in a profile writes the ticked fields, the reference individual and the selection of
  branches into the profile, and all other fields into the common settings. The profile bar lists the own
  settings of the active profile.
- The **branches** themselves (start and stop individuals, names, directions, order, headings) are common.
  A profile only stores which branches are exported. A branch added later appears in all profiles with the
  state of Standard and is marked "not in the profile" until the profile is saved.

Functions:

- **Switch**: choose a profile in the list. The choice is saved per user and tree (for visitors in the
  session), so several users do not disturb each other and switching writes nothing into the data folder.
- **Save profile “…”** (export form, and in the profile bar of the export pages): save the form in the
  active profile (see above).
- **Save as new profile** (export form): create a profile from the form, including unsaved changes. A new
  profile made from Standard gets its own title, image selection, branch selection and reference individual.
- **Manage profiles** (managers): copy the active profile under a new name (the saved state), rename it,
  delete it (with a question). Standard cannot be renamed or deleted.
- "Download Settings" and "Upload Settings" work as before: they save resp. fill the form, whatever profile
  is active.

Files (see also below): each profile is a folder in the data folder of the tree.

```
data/ExportFamilyBook/<tree>/latex/, graphml/     profile Standard = common settings (as before)
data/ExportFamilyBook/<tree>/profiles/
    README.txt
    migrated_branch_sets.txt                       old sets converted to profiles (see below)
    Book_for_Anna/
        profile.json                               name and reference individual
        branches.json                              which branches are exported (book and chart)
        latex/title.tex                            own values: same file names as in latex/ and graphml/
        latex/include_media.txt
        latex/exclude_media.txt
        graphml/...                                (only if the profile has own chart settings)
```

```json
{
    "format": "ExportFamilyBook-profile",
    "version": 1,
    "name": "Book for Anna",
    "refid": "I1",
    "saved": "2026-11-13 12:00:00"
}
```

```json
{
    "format": "ExportFamilyBook-branch-set",
    "version": 1,
    "name": "Book for Anna",
    "saved": "2026-11-13 12:00:00",
    "latex": [
        { "start": "I1", "branch": "Family Miller", "export": true },
        { "start": "I3", "branch": "Ancestors of Anna", "export": false }
    ],
    "graphml": [
        { "start": "I1", "branch": "Family Miller", "export": true }
    ]
}
```

- A file of the profile folder wins over the common file of the same name; a missing file means the common
  value. You can make any field a value of a profile by copying its file into the profile folder (short
  options: a `settings.json` with only these options).
- The folder name is made from the name of the profile: umlauts become `ae`, `oe`, `ue`, `ss`, accents are
  removed, all other characters become `_` (e.g. "Buch für Änne" becomes `Buch_fuer_Aenne`); an existing name
  gets `-2`, `-3`. The name shown is the one in `profile.json`. `branch` in `branches.json` is only for reading;
  a branch is identified by its start individual.

### Update from a version with sets of branches

Nothing has to be done:

- The folders `latex/` and `graphml/` stay as they are and are the profile Standard. Standard is active after
  the update, so the form and the export are the same as before.
- Each set of the old page "Branches and sets" (`branch_sets/*.json`) is converted once into a profile of the
  same name, the first time a page of the module is opened: the profile exports the branches of the set and gets
  a copy of the current title and image selection. Its reference individual is empty (the form preselects as
  for Standard) until the profile is saved. The files in `branch_sets/` and `branch_set_active.txt` are not
  changed or deleted; `profiles/migrated_branch_sets.txt` lists the converted sets. A set that cannot be read is
  skipped.

## Image selection

The page **Image selection** shows the images that the family book of the active profile would contain,
in a grid with preview images, title, file name and individual:

- The images are found exactly as by the LaTeX export: the individuals of the branches, the media of the
  individual, of the families and of the facts, the media loops of the templates (`@ForeachMedia@` with its
  type and file format filters) and the privacy rules of webtrees. Only the media loops of the templates are
  processed, so the page is much faster than an export (several seconds for about 1000 images).
  The reference individual is chosen as on the LaTeX page (the one of the profile, else `xref` in the
  address, else the one used last).
- The images are grouped by individual, in the order of the book or alphabetically. An image linked to
  several individuals is shown with each of them; the tick applies to the image.
- Remove the tick to leave an image out of the book; such images are greyed out. Filters: all / included /
  excluded images, search for a name, title or file, images changed since a date (date of the last change
  of the media object; unknown for media without a change date), images new since the last review.
- **Save selection** (managers only) updates `exclude_media.txt` of the active profile (Standard: in the
  folder of the tree). Only the IDs of the images on the page are added or removed: comments, empty lines and
  IDs of other media stay, the other files of the folder are not touched. If the folder or the file does not
  exist yet, it is created, starting from the current list (e.g. from `export_latex_settings.local.json`).
- Images in the list `include_media` are always in the book (it wins over `exclude_media`); the page shows
  them with a locked tick.
- **Mark as reviewed** (and saving) remembers the time per user and tree. "New since the last review" shows
  the images whose media object was changed after that time. An older media object that was only linked to
  an individual afterwards does not count as new. There is no filter "created since": webtrees keeps no
  creation date of a media object (imported data have no change history).

## Branches

The setting **Seeds** (`branch_seeds`) divides the book and the chart into branches. The page
**Branch selection** ("Zweige") shows this list as a table with one row per branch, so you do not have to
edit the text field of the export pages (it stays available there). It shows the state of the active profile:

- **Exported**: remove the tick to leave the individuals of a branch out of the book resp. the chart
  (column 7 `exclude`). **All on** / **All off** switch all branches.
- **Start individual** and **stop individuals** are shown with name and life span; choose them with the
  individual search of webtrees. **Name of the branch**, **direction** (both directions, towards the parents,
  towards spouses and children). The order of the rows is the order of the branches in the book: drag a
  row by its handle or use the arrows.
- **Headings** (book only, "Show headings"): the heading of the branch and the heading per generation of a
  row are only needed if they differ from the defaults. The defaults are set once above the table
  (settings `hierarchy_sub_branch` and `hierarchy_generation`, saved in `settings.json` of the tree).
- The book and the chart have lists of their own (tabs on the page). Typically the chart has fewer branches
  and other stop individuals than the book.
- **Save** (managers only) writes `branch_seeds.txt` in the folder of the tree. The export reads it unchanged.
  In a profile other than Standard the ticks "Exported" go to `branches.json` of the profile and the rest of the
  table (the branches themselves) to the common `branch_seeds.txt` (see "Book profiles").
  Rows that were not changed keep their line exactly (spaces, number of columns); changed rows are written
  with as few columns as needed. The separators of the list are kept. Lines that the page does not
  understand (comments starting with `#`, lines without a separator, lines with an error for the export)
  are shown greyed out and kept as they are, empty lines as well. Without a folder of the tree, the page
  starts with the current list (e.g. from `export_latex_settings.local.json`) and saving creates the folder.
- Saving checks the changed values: the start individual must exist and may start only one branch, stop
  individuals must exist, the direction must be valid, names and headings must not contain the column
  separator or a line break. If something is wrong, nothing is saved and the rows are marked. An unknown
  individual in a row that was not changed is only a warning (the export skips such a branch).

### Format of the list

The first two characters are the separators (e.g. `;,`). Then one branch per line with 3 to 7 columns:

```
;,
I1;Family Miller;both;I7,I8;\chapter{;\section{Generation;include
I2;Family Smith;descendants
I3;Ancestors of Anna;ancestors;;;;exclude
```

start individual; name; direction (`both`, `ancestors`, `descendants`); stop individuals (separated by the
second separator); heading of the branch (LaTeX command, the module adds ` <name>}`); heading per generation;
`include` or `exclude`. Missing columns get the defaults (no stop individuals, default headings, include).

How the export forms the branches (good to know when choosing stop individuals):

- The branches are formed one after the other in the order of the list. A branch starts with the start
  individual, goes one step in the chosen direction (parents; spouses and children; or all of them) and from
  there continues in all directions (parents, spouses, children) as far as the family is connected.
- A stop individual belongs to the branch, but the branch does not continue beyond it. The module adds no
  stop individuals by itself: the start individual of another branch is not a stop unless you enter it.
- If a later branch reaches individuals of an earlier branch, they move to the later branch ("the last branch
  wins"). Start and stop individuals that are connected to their earlier branch appear there a second time,
  with a reference to the other branch.
- Individuals that belong to no branch are not exported (if the list has branches).
- Switching a branch off (`exclude`) does not change how the branches are formed: the individuals of that
  branch are only left out of the output.

## Settings files

"Download Settings" saves the settings of the form as a readable JSON file
(`export_latex_settings.json` or `export_graphml_settings.json`):

```json
{
    "format": "ExportFamilyBook-settings",
    "version": 1,
    "export_type": "latex",
    "settings": { "title": "...", "...": "..." }
}
```

Only the setting fields of the form are saved, no control fields like `_csrf`.
"Upload Settings" reads JSON files and files in the old format (`*.txt`, base64 encoded).

## Default settings of a tree (files in the data folder)

Managers of a tree save the current form with "Save profile “Standard”" (earlier versions: "Save as default
for this tree"). The module then writes one folder per tree and export type into the webtrees data folder,
for example:

```
data/ExportFamilyBook/<tree>/latex/
    settings.json                            short options (format as "Download Settings")
    preamble.tex                             long fields: the text exactly as in the form,
    title.tex                                no escaping, one file per field
    individuals_summary_label_template.tex
    individuals_label_template.tex
    epilog.tex
    branch_seeds.txt                         first characters are the separators, as in the form
    symbols.txt
    include_media.txt                        media IDs, one per line; empty lines and text after # are ignored
    exclude_media.txt
    README.txt                               short description of the format
data/ExportFamilyBook/<tree>/graphml/
    settings.json
    individuals_label_template.html
    individuals_description_template.txt
    families_label_template.html
    families_description_template.txt
    branch_seeds.txt
    README.txt
data/ExportFamilyBook/<tree>/profiles/        the other book profiles (see "Book profiles")
data/ExportFamilyBook/<tree>/branch_sets/      sets of earlier versions (only read once, see "Book profiles")
```

- Edit these files with any text editor (UTF-8, LF or CRLF line endings, a byte order mark is ignored).
  One line break at the end of a text file is ignored.
- `<tree>` is the name of the tree. If it contains characters other than letters, digits, `_` and `-`,
  they are replaced by `_` and a short hash is added (e.g. `my tree` becomes `my_tree-` followed by 8 hex digits).
- If a file is missing, that field gets its value from the next source below. To clear a field, keep its
  file and leave it empty.
- Saving only rewrites files whose value has changed, so your line endings and the comments in a list file
  stay as long as you do not change that field in the form. `README.txt` is never overwritten.
- The web server needs write access to the data folder (webtrees needs it anyway).

## Where the form gets its values

When the page is opened (`<type>` is `latex` or `graphml`), the first existing source wins:

1. The folder of the active profile (only for a profile other than Standard), field by field.
2. The folder of the tree in the data folder (see above), field by field (the profile Standard).
3. `export_<type>_settings.local.json` in the module folder: your own settings (save them with
   "Download Settings" and rename the file). This file is not part of the repository and survives updates.
4. `export_<type>_settings.txt` in the module folder: old format, your own settings from an earlier version.
5. `export_<type>_settings.json` in the module folder: general example of the repository.

Sources 3 to 5 are alternatives: only the first existing file of them is used.

## Notes on the templates

### `IfExists` and `IfExist`

`@FeFactType&IfExists@` and `@FeAttributeType&IfExists@` return nothing if the individual has no fact of
this type. Both spellings work: `IfExists` (as in the help of the form) and `IfExist` (older templates).

### Which picture is the portrait

All portrait placeholders (`@Portrait@`, `@PortraitSize@`, `@PortraitIcon@`, in LaTeX and GraphML) use the same
picture: the first picture of the individual (order of the media objects of the individual, then of the
files of a media object) that

- is a picture file (JPEG, PNG, GIF, ...) and no external file (web address), and
- has the media type *Photo* or no media type (GEDCOM: `TYPE` below `FORM`, upper or lower case).

Pictures of other types (certificate, document, ...) are skipped, so a certificate is no longer the portrait
of an individual without photo. Without such a picture the individual has no portrait (as without any
picture; `@Portrait&fallback@` gives the silhouette). This does not change the pictures of the book
(`@ForeachMedia@`) nor the list of excluded pictures of the image selection.

### Special characters in the LaTeX export

Data from webtrees (names, places, dates, facts, notes, captions of pictures, titles and authors of sources)
may contain characters with a special meaning in LaTeX. The export masks them, so they appear in the book as
they are written in webtrees:

| Character in webtrees | In the `.tex` file |
|---|---|
| `\` | `\textbackslash ` |
| `{` `}` | `\{` `\}` |
| `~` | `\textasciitilde{}` (prints a tilde, e.g. in `*~` or in a web address, not a space) |
| `&` `$` `%` `_` `#` `^` `\|` `§` | `\&` `\$` `\%` `\_` `\#` `\^` `\textbar` `\§` |
| `...` | `\ldots` |
| `"` | `"{}` |
| line break in a note | `\\` |

In the bibliography (the `.bib` part of the file) the title and the author of a source are masked in the
same way, with `\textbraceleft{}`/`\textbraceright{}` for braces and `\textquotedbl{}` for `"`, because
BibTeX itself needs balanced braces and ends a value at `"`.

Nothing is masked in the text of the templates, the preamble, title and epilog, the symbols, the
relationship symbols and the names of the branches: they are LaTeX written on purpose. The name of a branch
is therefore used unchanged as heading and for `@Link@`. File names (`@FeMediaFile@`, `@Portrait@`) keep
`{`, `}` and `~`, because these are valid characters of a path; use `\detokenize{...}` around a path in
`\includegraphics` as in the example template.

### Labels of individuals that connect two branches

An individual where one branch ends and another begins (start or stop individual of a branch, see
[Branches](#branches)) has a full entry in both branches, with the name of the other branch for `@Link@`.
LaTeX allows each label only once, so only one of the two entries defines the label of the individual
(the main label); the other entry gets a label of its own:

- The main label stays with the entry in the individual's own branch (the later branch of the list). If this
  branch is switched off, the entry in the other branch defines it.
- `@Id&...@` directly inside `\label{...}` returns in the second entry the main label followed by `/` and the
  number of the branch of this entry, e.g. `\label{I12/1}`. A `/` never occurs in an XREF, so this label is
  never the label of another individual.
- `\pageref{@Id&xref@}` and all other references to the individual (`@FatherId@`, `@MotherId@`, `@SpouseId@`,
  children, summary) point to the main label. Everything else of the second entry stays as it is (number,
  index entry, sources, pictures).
- `@LinkId@` returns the label of the entry of the individual in the other branch: in the entry with the main
  label the label of the second entry, in the second entry the main label. Use it for a page number next to
  `@Link@`, so the link works in both directions:

      <\segoe{🔗} @Link@< S.\pageref{@LinkId&xref@}>\\ >

  The format is the same as for `@Id@` (`no`, `gen_no` or `xref`; use the same as in `\label{@Id&...@}`).
  `@LinkId@` is empty if `@Link@` is empty or the other entry is not exported, so the `<...>` block is left
  out. It never points to a label that is not in the book (as long as the template defines
  `\label{@Id&...@}`). In GraphML it returns nothing.
- Limit: an individual whose copies are in several other branches links only to one of them (the last one
  found); `@Link@` and `@LinkId@` of the entry in the own branch show this branch.

Existing templates need no change. The labels of pictures (`\label{fig:@FeMediaId@}`) are not affected: with
`@ForeachMedia&...&...&unique@` each picture is placed only once anyway.

### Portraits in the family tree chart

`@PortraitIcon@` in the label template of the individuals (GraphML) puts the portrait of the individual into
the `.graphml` file itself. yEd shows it as the icon of the box, above or left of the text. No folder with
pictures is needed on the computer that runs yEd. Tested with yEd 3.25.1 (JPEG and PNG).

    @PortraitIcon&width&position&type&sharpness@

| Part | Values | Default |
|---|---|---|
| width | width of the picture in pixels; yEd shows it in this size | `100` |
| position | `top` (picture above the text) or `left` (picture left of the text) | `top` |
| type | `jpg` (quality 92) or `png` (lossless, larger files) | `jpg` |
| sharpness | `1` to `20`: the picture is stored with this many times the pixels of the width (other values: the default) | `8` |

- The portrait is the first photo of the individual (see [Which picture is the portrait](#which-picture-is-the-portrait)).
  The module reduces the original file
  (turned as given by its EXIF orientation) and encodes it once, JPEG with quality 92 or lossless PNG.
  A picture higher than 1.5 times its width is reduced to this height.
- The reduced pictures are kept in the cache of webtrees (`data/cache/`, like the thumbnails of the web
  pages), so the next export is faster. A changed file (time of change or size) gives a new picture.
- If webtrees shows the pictures with a watermark for the user who exports, the module uses the
  watermarked thumbnails of webtrees instead (encoded again, so with somewhat lower quality).
- Only pictures the user may see in webtrees are embedded. Individuals without portrait, with a missing or
  unreadable file or with an external picture (a web address) get no picture; there is no silhouette.
- Sharpness: with `2` a picture shown 100 pixels wide is stored 200 pixels wide and shown in half its size,
  so it stays sharp when zooming in yEd and in a printed or PDF chart. The size in the box and the size of
  the box are the same for every sharpness. A picture is never enlarged beyond its original: an original
  narrower than width × sharpness is stored in its own size and scaled to the width, so a small original
  limits the sharpness. An original smaller than the shown size is enlarged to it, as before.
- The default sharpness `8` is made for small pictures left of the text, e.g. `@PortraitIcon&30&left@`
  (the same as `@PortraitIcon&30&left&jpg&8@`): shown 30 pixels wide, stored 240 pixels wide.
- **The size of the file depends on width × sharpness** (the stored pixels), not on the sharpness alone. With
  the default sharpness `8` and the default width `100`, up to 800 pixels are stored per picture
  (`@PortraitIcon@`: about 85 KB per picture, see below). For wide pictures choose a smaller sharpness,
  e.g. `@PortraitIcon&100&top&jpg&3@` (300 pixels, as in the example template).
- A picture used by several boxes is embedded only once.
- The placeholder returns no text, its place in the template does not matter. In the LaTeX export it returns
  nothing.
- The height of the box is increased by the height of the picture. As before, use **Tools → Fit Node to
  Label** in yEd for the individuals (see below).
- Why not `<img src="data:...">` in the HTML of the label: yEd draws HTML labels with Java, which does not
  load such pictures. yEd itself stores pictures as resources in the file and refers to them as label icons;
  `@PortraitIcon@` writes them in the same way as yEd 3.25.1: the resources follow `</graph>`, the label
  refers to an icon resource (`yed:ScaledIcon` with the shown size and the scale `1/sharpness`), which refers
  to the picture resource with the image data (`yed:ImageIcon` with the size in pixels).
  For this the export adds the name spaces of yEd (`xmlns:yed` and others) to the start of the file. This is
  done by the module, also for templates saved with an older version; the templates need no change.

Size of the file: in a test with 538 different portraits shown 100 pixels wide (JPEG, quality 92), the file
grew by

| sharpness | growth of the file | per picture |
|---|---|---|
| 1 | 4.3 MB | about 8 KB |
| 2 | 10.3 MB | about 19 KB |
| 3 | 17.0 MB | about 32 KB |
| 4 | 23.6 MB | about 44 KB |
| 8 (default) | 45.9 MB | about 85 KB |

Pictures shown 30 pixels wide left of the text (`@PortraitIcon&30&left&jpg&...@`, same test):

| sharpness | stored width | growth of the file | per picture |
|---|---|---|---|
| 4 | 120 pixels | 5.2 MB | about 10 KB |
| 8 | 240 pixels | 12.9 MB | about 24 KB |
| 10 | 300 pixels | 17.0 MB | about 32 KB |

For very large charts, `1` to `3` keep the file small. In this test the first export took 14 to 23 seconds
(the module reduces the originals), the next one 8 to 9 seconds (pictures from the cache; the same export
without pictures: about 9 seconds).

How to change an existing template that uses picture files:

1. Remove the part with the picture, for example
   `{<td width="33"><img src="file:/path/to/thumbs/@Portrait@" ... height="@PortraitSize&w30@"></td>}`.
2. Write `@PortraitIcon&100&left&jpg&3@` (picture left of the text, as in the old table) or
   `@PortraitIcon&100&top&jpg&3@` (picture above the text) anywhere in the template, e.g. directly after the
   first line `{}@&`.
3. If the box is too narrow for picture and text, increase the width of the boxes in the form.

Templates that keep `@Portrait@` with a path work as before.

### Family nodes in the family tree chart

Each family is a node between the parents and the children. The table "Box style" of the form has a column
for each kind of box (male, female, unknown sex and the two kinds of family nodes) and a row for the shape,
fill colour, border colour, size, border width, font size and default portrait. The two columns of the family
nodes are:

- **Family without marriage information**: the label template of the families gives no text (for example
  no date of marriage). The node is a small symbol in the shape and size of the block (*Symbol
  width/height*), as before.
- **Family with marriage information**: the label template gives text (the ring symbol, the date of
  marriage, ...). The node is a box as large as the text, the text lies in it with the fill and border
  colour of the block, and the edges end at the border of the box. The size is estimated from the longest
  line (without HTML tags), the number of lines and the font size, with a small margin (see below). *Box
  width* and *Box height* set it yourself; empty means the size of the text. The node itself stays black under the
  text, because the layout *Family Tree* of yEd finds the family nodes by their black fill. The *shape* is
  the shape of this node: it decides where the edges end. With `rectangle` (default) they end exactly at the
  border of the box; the box with the text is always a rectangle. A border width of `0` gives a box without
  border.

**Size and centring of the text in the box:** the width of a line is estimated from the widths of its
characters in the font of the form: narrow characters (space, `. , : ; ( ) [ ] - /`, `i j l t f r I`),
digits and other signs, lower case letters, upper case letters, wide letters (`m w M W`) and symbols such
as ⚭ or † (mostly shown in their own font, e.g. DejaVu Sans). The values per font were measured with the
Java of yEd 3.25.1 for the fonts of the form; another font uses the largest value of each class. Spaces at
the beginning and the end of a line are not counted. The estimate is rather a few points too wide than too
small: for the labels of a real family tree in all fonts of the form and font sizes 6 to 14 no text was cut
off, and the box was on average about 13 % wider than the text.

yEd places the content of an HTML label (`<html>...`) at the left of the box and in the middle vertically;
`alignment="center"` does not move it. Therefore the export puts the content of an HTML label into a table
as wide as the box (less the inner margin of 2 points on each side) with one centred cell without line
breaks, so the text is in the middle of the box, also where the estimate is a bit too wide. Nothing has to
be changed in the template. Several lines (`<br>`) are centred each. A label without HTML is centred by yEd
itself.

*Size* in the block chooses who sets the size of these boxes:

- **estimated by the export, text centred** (default): as described above; nothing to do in yEd.
- **fitted in yEd (Tools, Fit Node to Label)**: the export writes the estimated size without the centring
  table, and yEd fits each box to its text (step 3a in
  [Family tree chart](#family-tree-chart-graphml-to-pdf-with-yed)). For a label with
  `autoSizePolicy="node_size"` yEd measures the content of the label, so the box gets exactly the size of the
  text plus the inner margin of the label and the space of the dialog; the label keeps covering the whole
  node, so no black edge is left. The text is then in the middle up to 1 point (with a space of 1).

Older settings (files in the data folder, profiles, downloaded settings files, the example file) have one
block "Family" only. They need no change: this block is used for families without marriage information,
and families with marriage information take its fill colour, border colour, border width and font size,
with the shape `rectangle` and the size of the text (shape and size of the old block are made for the small
symbol).

**End of a branch:** an individual where one branch ends and another begins (red border) is in both
branches with all its families. A family gets no node (and no edges) in a branch where only this individual
of the family is exported, if the family is shown in another branch (at least two of its members are
exported there). So the box of the marriage is no longer hanging at the red individual in the old branch,
whose spouse and children are not in it; the branch where the family is keeps the node. Families without
such an individual are not changed, e.g. a couple without children keeps its node.

### Errors in a template or in the list of branches

A typing error in a template (a bracket that is not closed, a loop without `@EndForeach@`, a placeholder that
does not exist) or a wrong value in the list of branches does not create a file. The page shows the form again
with the values entered and a message that names the error.

### Pictures that are skipped

A link to a media object that does not exist and a media file without file extension (e.g. `photo` instead
of `photo.jpg`) are skipped, in the export and on the page **Image selection**.

## From the exported file to a PDF

### Family book: `.tex` to PDF with TeXworks (MiKTeX)

The example templates are written for XeLaTeX, because they use UTF-8 symbols and system fonts.

1. Before the export, adapt the media path in the templates (`/path/to/webtrees/data/media/` in the
   example) to a folder on the computer that builds the PDF. It must contain a copy of the webtrees
   media folder.
2. Install the fonts used in the preamble. The example uses Times New Roman, DejaVu Sans, Quivira and
   Segoe UI Symbol.
3. Open the exported `.tex` file in TeXworks and select the profile **XeLaTeX+MakeIndex+BibTeX**
   in the drop-down list next to the green button.
4. Press the green button. MiKTeX asks to install missing packages on the first run (for example
   `bibtex8bit`); confirm these dialogs.
5. Press the green button again until the page numbers, the index and the list of sources no longer
   change. Usually two or three runs are needed.

On the command line the same is done with:

```
xelatex book.tex
bibtex8 book
makeindex book
xelatex book.tex
xelatex book.tex
```

Notes:

- The name of the `.tex` file must not contain spaces (use `family_book.tex`, not `family book.tex`).
  The example preamble loads the list of sources with `\bibliography{\jobname}`; with a space in the
  file name BibTeX does not find this file and stops with an error. After renaming, delete the
  auxiliary files with the old name (`.aux`, `.bbl`, `.bib`, `.idx`, ...).
- The message "Please (re)run BibTeX" of biblatex may remain after the last run when `citecounter`
  is used. The list of sources is complete nevertheless.
- A large tree with many pictures needs several minutes per run.

### Family tree chart: `.graphml` to PDF with yEd

The example template embeds the portraits in the file (`@PortraitIcon@`, see
[Portraits in the family tree chart](#portraits-in-the-family-tree-chart)), so no folder with pictures is
needed. A template that refers to picture files with a path (`<img src="file:/...@Portrait@">`) needs
these files on the computer that runs yEd.

1. Open the exported `.graphml` file in yEd. All nodes are placed on top of each other at first.
2. Select the individuals only (the family nodes are black, see
   [Family nodes](#family-nodes-in-the-family-tree-chart); their size is set by the export):
   **Tools → Select Elements** with
   - tab *General*: *Select Inverse*: on
   - tab *Nodes*: *Fill Color*: on, black (`#000000`)

   This selects all nodes that are not black, i.e. the individuals. Tested with yEd 3.25.1.
3. Adapt the height of the individuals to their content, so that the pictures fit into the boxes:
   **Tools → Fit Node to Label** with
   - *Act on Selection Only*: on (only the individuals selected in step 2)
   - *Adapt to Maximum Node*: off
   - *Ignore Width*: on (the width given by the export is kept)
   - *Ignore Height*: off

   Without the selection the family nodes would be changed too, e.g. a small symbol stretched to a line.

   3a. Only if *Size* of the block *Family with marriage information* is *fitted in yEd*: fit the boxes of
   the families with marriage information to their text. First select them:
   **Tools → Select Elements** with
   - tab *General*: *Clear Selection First*: on, *Select Inverse*: off
   - tab *Nodes*: *Fill Color*: on, black (`#000000`)

   then **Tools → Select Elements** again with
   - tab *General*: *Act On Current Selection Only*: on, *Select Inverse*: off
   - tab *Nodes*: *Label*: on, *Match Type*: *Regular Expression*, text `(?s).+`

   The second selection keeps only the black nodes with a label text; the families without marriage
   information (small symbols, empty label) are left out, otherwise they would grow to the minimum size of
   the dialog (at least 5 × 5). Then **Tools → Fit Node to Label** with
   - *Act on Selection Only*: on
   - *Adapt to Maximum Node*: off
   - *Ignore Width*: off, *Ignore Height*: off
   - *Horizontal Space* and *Vertical Space*: 1 (the minimum)
4. Arrange the chart: **Layout → Family Tree**, tab *Layout*, *Orientation*: **Left To Right**.
5. Save it as PDF: **File → Export**, file type PDF.

If you run the layout first and adapt the node height afterwards, run the layout a second time,
because it uses the node sizes it finds.
