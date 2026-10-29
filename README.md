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

Default settings when the page is opened (`<type>` is `latex` or `graphml`); the module uses
the first of these files in the module folder:

1. `export_<type>_settings.local.json`: your own settings (save them with "Download Settings"
   and rename the file). This file is not part of the repository and survives updates.
2. `export_<type>_settings.txt`: old format, your own settings from an earlier version.
3. `export_<type>_settings.json`: general example of the repository.
