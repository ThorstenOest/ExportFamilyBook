# ExportFamilyBook
A Module for Webtrees 2.0 to export a tree as a family book or graphml file

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
