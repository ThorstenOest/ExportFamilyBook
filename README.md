# ExportFamilyBook
A Module for Webtrees 2.0 to export a tree as a family book or graphml file

## Usage

The module adds the menu **Family book** ("Familienbuch" in German) to the main navigation of a tree.
It has one entry per page:

| Menu entry | Page | Address |
|---|---|---|
| Book (LaTeX) | export of the family book as a `.tex` file | `index.php?route=/module/_ExportFamilyBook_/Latex/<tree>` |
| Image selection | choose the images of the family book (see below) | `index.php?route=/module/_ExportFamilyBook_/Media/<tree>` |
| Family tree chart (GraphML) | export of the chart as a `.graphml` file | `index.php?route=/module/_ExportFamilyBook_/Graphml/<tree>` |

- The two export pages have their own form with "Export Family Tree", "Download Settings", "Upload Settings"
  and (for managers) "Save as default for this tree", and a link to the other export page.
- **Reference individual**: chosen in the form with the individual search field. It is preselected with
  the individual of the page you came from (`xref` in the address), else the individual you used last in
  the form (saved per user and tree), else your default individual of the tree resp. the default individual
  of the tree. Settings files never set the reference individual.
- **Access level**: Control panel → Modules → **Menus** → Family book. Without an entry for a tree only
  managers can see the menu and use the pages. All actions (page, export, download, upload, save) check it.
  Only managers of the tree can save (default settings, image selection).
- Earlier versions listed the module under "Charts". Their address (`.../Chart/<tree>?xref=...`)
  redirects to the new pages; `export_book=0` leads to the GraphML page.

## Image selection

The page **Image selection** shows the images that the family book would contain with the default
settings of the tree (see below), in a grid with preview images, title, file name and individual:

- The images are found exactly as by the LaTeX export: the individuals of the branches, the media of the
  individual, of the families and of the facts, the media loops of the templates (`@ForeachMedia@` with its
  type and file format filters) and the privacy rules of webtrees. Only the media loops of the templates are
  processed, so the page is much faster than an export (several seconds for about 1000 images).
  The reference individual is chosen as on the LaTeX page (`xref` in the address, else the one used last).
- The images are grouped by individual, in the order of the book or alphabetically. An image linked to
  several individuals is shown with each of them; the tick applies to the image.
- Remove the tick to leave an image out of the book; such images are greyed out. Filters: all / included /
  excluded images, search for a name, title or file, images changed since a date (date of the last change
  of the media object; unknown for media without a change date), images new since the last review.
- **Save selection** (managers only) updates `exclude_media.txt` in the folder of the tree. Only the IDs of
  the images on the page are added or removed: comments, empty lines and IDs of other media stay, the other
  files of the folder are not touched. If the folder or the file does not exist yet, it is created, starting
  from the current list (e.g. from `export_latex_settings.local.json`).
- Images in the list `include_media` are always in the book (it wins over `exclude_media`); the page shows
  them with a locked tick.
- **Mark as reviewed** (and saving) remembers the time per user and tree. "New since the last review" shows
  the images whose media object was changed after that time. An older media object that was only linked to
  an individual afterwards does not count as new. There is no filter "created since": webtrees keeps no
  creation date of a media object (imported data have no change history).

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

Managers of a tree can save the current form with "Save as default for this tree". The module then
writes one folder per tree and export type into the webtrees data folder, for example:

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

1. The folder of the tree in the data folder (see above), field by field.
2. `export_<type>_settings.local.json` in the module folder: your own settings (save them with
   "Download Settings" and rename the file). This file is not part of the repository and survives updates.
3. `export_<type>_settings.txt` in the module folder: old format, your own settings from an earlier version.
4. `export_<type>_settings.json` in the module folder: general example of the repository.

Sources 2 to 4 are alternatives: only the first existing file of them is used.

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

- The message "Please (re)run BibTeX" of biblatex may remain after the last run when `citecounter`
  is used. The list of sources is complete nevertheless.
- A large tree with many pictures needs several minutes per run.

### Family tree chart: `.graphml` to PDF with yEd

The thumbnails in the node labels are read from the path given in the label template
(`file:/path/to/webtrees/data/media/thumbs/` in the example). Adapt it before the export.

1. Open the exported `.graphml` file in yEd. All nodes are placed on top of each other at first.
2. Adapt the height of the nodes to their content, so that the pictures fit into the boxes:
   **Tools → Fit Node to Label** with
   - *Act on Selection Only*: off (all nodes)
   - *Adapt to Maximum Node*: off
   - *Ignore Width*: on (the width given by the export is kept)
   - *Ignore Height*: off
3. Arrange the chart: **Layout → Family Tree**, tab *Layout*, *Orientation*: **Left To Right**.
4. Save it as PDF: **File → Export**, file type PDF.

If you run the layout first and adapt the node height afterwards, run the layout a second time,
because it uses the node sizes it finds.
