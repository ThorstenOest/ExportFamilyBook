# ExportFamilyBook
A Module for Webtrees 2.0 to export a tree as a family book or graphml file

## Usage

The module adds the menu **Family book** ("Familienbuch" in German) to the main navigation of a tree.
It has one entry per page:

| Menu entry | Page | Address |
|---|---|---|
| Book (LaTeX) | export of the family book as a `.tex` file | `index.php?route=/module/_ExportFamilyBook_/Latex/<tree>` |
| Image selection | choose the images of the family book (see below) | `index.php?route=/module/_ExportFamilyBook_/Media/<tree>` |
| Branch selection ("Zweige") | edit the list of branches as a table, switch branches on and off (see below) | `index.php?route=/module/_ExportFamilyBook_/Branches/<tree>` (`&list=graphml` for the chart) |
| Family tree chart (GraphML) | export of the chart as a `.graphml` file | `index.php?route=/module/_ExportFamilyBook_/Graphml/<tree>` |

- All four pages show the **book profile** bar at the top (see below): the active profile, a list to switch
  profiles and (for managers) "Manage profiles".
- The two export pages have their own form with "Export Family Tree", "Download Settings", "Upload Settings"
  and (for managers) "Save profile “…”" and "Save as new profile", and a link to the other export page.
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
- **Save profile “…”** (export form): save the form in the active profile (see above).
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
