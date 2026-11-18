# Templates of ExportFamilyBook

The text of the family book (LaTeX) and the text in the boxes of the family tree chart (GraphML) are made
from **templates**: text fields in the export form that contain the LaTeX or HTML you want, with
**placeholders** like `@SurName@` that the export replaces with the data of each individual or family.

This page describes the template language completely: first a small example, then the fields of the form,
the basic rules, all placeholders and loops, recipes for frequent wishes and the common errors.
The example settings of the module (`export_latex_settings.json`, `export_graphml_settings.json`, loaded when
a tree has no settings of its own) contain complete templates you can start from.

All examples on this page were exported from a small test tree with invented individuals; the results are
shown as the export writes them (with the user language English, which matters for dates and labels).

Contents

- [A first example](#a-first-example)
- [The fields of the form](#the-fields-of-the-form)
- [Basic rules](#basic-rules)
- [Placeholders](#placeholders)
- [Formats of names, dates, places and facts](#formats-of-names-dates-places-and-facts)
- [Loops](#loops)
- [Conditions](#conditions)
- [Labels and links in the book (LaTeX)](#labels-and-links-in-the-book-latex)
- [Portraits](#portraits)
- [Special characters](#special-characters)
- [Recipes](#recipes)
- [Common errors](#common-errors)
- [Index of all placeholders](#index-of-all-placeholders)

## A first example

The test tree used on this page:

| Individual | Data |
|---|---|
| Anna Maria Luise Muster (I1), nickname "Anni" | born 3 March 1850 in "Musterstadt, Kreis Beispiel, Deutschland", died 20 November 1920 in "Neustadt, Deutschland"; occupations Seamstress (1870) and Teacher (1880, note "Village school"); residence 1890 in Neustadt, address "Lindenweg 5"; a photo and a letter (PDF); a source "Parish register Musterstadt" |
| Johann Beispiel (I2) | her husband, born 1845 in "Altdorf, Deutschland", blacksmith; married 12 May 1872 in Musterstadt |
| Karl (I3), Emma (I4), Paul (I8) Beispiel | their children, born 1873, 1875 (died 1876) and 1880; Paul is adopted by both |
| Friedrich Wilhelm Muster (I5), Maria Klein (I6) | Anna's parents; Maria also has the married name Muster |
| Hans Muster (I7) | Anna's brother, born about 1852 |
| Georg Muster (I9) | Anna's grandfather, born 1790 |

Anna is the **reference individual** of the export.

### Family book (LaTeX)

Template (field *Entry of an individual* of the book):

```
<>@&
\textbf{@SurName@, @GivenName@}
< *@BirthDate@< in @BirthPlace@>>
< \dejavu{†}@DeathDate@>\\
```

Result in the `.tex` file (one line per individual; the date and place formats are the defaults of the
example settings, `%j.%n.%Y` and `1,-1/Deutschland`):

```
 \textbf{Muster, Anna Maria Luise}  *3.3.1850 in Musterstadt  \dejavu{†}20.11.1920\\
 \textbf{Beispiel, Johann}  *1845 in Altdorf \\
 \textbf{Klein, Maria}  \\
```

- The first line `<>@&` is not part of the text: it says that `<` and `>` enclose a **block**, `@` marks a
  placeholder and `&` separates the formats of a placeholder (see [Basic rules](#basic-rules)).
- `< *@BirthDate@< in @BirthPlace@>>` is a block with a block inside: Maria Klein has no birth date, so the
  whole block is left out; Johann has no place in the death block, Anna has everything.
- The line breaks of the template become spaces, so each entry is one line.

### Family tree chart (GraphML)

Templates (fields *Node label* of the individuals and of the families):

```
{}@&<html><b>{@GivenName&1@ }@SurName@</b>
{<br>*@BirthDate&%Y@{ @BirthPlace&1@}}
{<br>†@DeathDate&%Y@}</html>
```

```
{}@&<html>{⚭@Marriage@}{ @MarriageDate&%Y@}</html>
```

Labels of the boxes as yEd reads them:

```
<html><b>Anna Muster</b> <br>*1850 Musterstadt <br>†1920</html>
<html><b>Johann Beispiel</b> <br>*1845 Altdorf </html>
<html><b>Maria Klein</b>  </html>
<html>⚭ 1872</html>
```

Here the blocks use `{` and `}`, because `<` and `>` are needed for the HTML of the label.
The last line is the box of the marriage of Anna and Johann. (With the default *Sizing* "estimated by the
export, text centered" of the family box, the export puts the content of the family label into a centred table,
see the README, [Family nodes](../README.md#family-nodes-in-the-family-tree-chart). The example shows the
label with the size "fitted in yEd".)

## The fields of the form

### Book (LaTeX)

The `.tex` file is put together in this order:

| Part of the file | Field of the form (setting) | Template? |
|---|---|---|
| 1 | **LaTeX preamble** (`preamble`) | no: LaTeX, copied unchanged |
| 2 | `\newcommand{\GedcomLegende}{...}`: the legend, made from **List of symbols** (`symbols`, see below); only if a row of the symbols has a third column | made by the export |
| 3 | `\usepackage{filecontents}` and the list of sources as `\jobname.bib` (title and author of each source the user may see) | made by the export |
| 4 | **Title** (`title`): `\begin{document}`, title page, table of contents, `\GedcomLegende` ... | no: LaTeX, copied unchanged |
| 5 | per branch and sub branch: the heading of the branch, then the summary, then per generation the heading and the entries | see below |
| 6 | **End of the document** (`epilog`): bibliography, index, `\end{document}` | no: LaTeX, copied unchanged |

Only two fields are templates, both for individuals:

- **Summary at the beginning of a branch** (`individuals_summary_label_template`): applied to every individual of a sub branch
  at the beginning of the sub branch, before the first generation. Only entries that are not empty are written.
  The example uses it to list the individuals that connect the branch with another one: the whole text is
  inside blocks that only `@Link@` makes visible (see [Recipes](#a-list-of-the-individuals-that-connect-two-branches)).
  Leave the field empty if you do not want a summary.
- **Entry of an individual** (`individuals_label_template`): the entry of an individual in the book. It is written once per
  individual and line (an individual that connects two branches has an entry in both, see
  [Labels and links](#labels-and-links-in-the-book-latex)).

There is no template for families in the book: the families are written inside the entry of each spouse with the
loop `@ForeachFAMS@`, the parents with `@ForeachFAMC@`.

The other fields of the book that the templates use:

| Field | Used by | Content |
|---|---|---|
| **Reference symbols** (`ref_father`, `ref_mother`, `ref_wife`, `ref_husband`, `ref_son`, `ref_daughter`) | `@Reference@` | the symbol of each step of a relationship, e.g. `F`, `M`, `W`, `H`, `S`, `D` |
| **List of symbols** (`symbols`) | `@FeFactType@`, `@FeAttributeType@`, legend | first character: the column separator; then one row per GEDCOM tag: `TAG;symbol`, a third column (any text, e.g. `L`) puts the row into the legend `\GedcomLegende`, a fourth column is the text of the legend (default: the name of the tag in webtrees). Rows that are no tag (e.g. `Heirat`) only appear in the legend. Example: `;` then `OCCU;\dejavu{⚒};L` |
| **Default date format** (`default_date_format`) | all dates without format | e.g. `%j.%n.%Y` |
| **Default place format** (`default_place_format`) | all places without format | e.g. `1,-1/Deutschland` |
| **Always include / Leave out** (`include_media`, `exclude_media`) | `@ForeachMedia@` | media IDs, see the README, [Image selection](../README.md#image-selection) |
| **List of branches** (`branch_seeds`) | branches, `@Link@`, `@Id@` | see the README, [Branches](../README.md#branches) |
| **Headings**: *Heading per group of connected individuals*, *Heading of the branch*, *Heading per generation* (`hierarchy_branch`, `hierarchy_sub_branch`, `hierarchy_generation`) | headings | see below |

The **headings** are LaTeX commands that the export completes:

- `hierarchy_branch` (e.g. `\part{`): a heading per *branch*, i.e. per group of individuals that are not
  connected with each other at all. It is only written if it starts with `\`; the export adds ` <number>}`, or
  only `}` if there is just one such group.
- `hierarchy_sub_branch` (e.g. `\chapter{`): a heading per branch of the list *List of branches*; the export adds
  ` <name of the branch>}`, or only `}` if only one branch is exported. A branch can have its own value
  (column 5 of the list).
- `hierarchy_generation` (e.g. `\subsubsection{Generation`): a heading per generation; the export adds
  ` <number of the generation>}`, or only `}` if the branch has only one generation. A branch can have its own
  value (column 6 of the list).

These headings are written even if the field is empty (then only `}` remains), so keep a value in
`hierarchy_sub_branch` and `hierarchy_generation`.

### Chart (GraphML)

| Field | Content |
|---|---|
| Template for individuals, **Node label** (`individuals_label_template`) | the text in the box of an individual; HTML if it starts with `<html>`, else plain text |
| Template for individuals, **Node description** (`individuals_description_template`) | the attribute *description* of the node in the `.graphml` file; may stay empty |
| Template for families, **Node label** (`families_label_template`) | the text of the family node between the parents and the children; if it gives no text, the family is a small symbol (see the README, [Family nodes](../README.md#family-nodes-in-the-family-tree-chart)) |
| Template for families, **Node description** (`families_description_template`) | the attribute *description* of the family node; may stay empty |

Each exported branch has its own nodes; an individual that connects two branches has a node in each of them
(e.g. `@Link@` names the other branch). Colours, shapes, sizes and the
font are set in the table *Box style* of the form, not in the templates. The chart has no default formats for
dates and places, no symbols and no headings.

## Basic rules

### The first four characters

The first four characters of every template define its special characters:

| Position | Meaning | `<>@&` (book) | `{}@&` (chart) |
|---|---|---|---|
| 1 | opening bracket of a block | `<` | `{` |
| 2 | closing bracket of a block | `>` | `}` |
| 3 | start and end of a placeholder | `@` | `@` |
| 4 | separator of the formats of a placeholder | `&` | `&` |

The text of the template starts after them; a line break after them becomes a space like any other line break.
Choose characters that you do not need in the text itself: there is no way to write them as normal text
(no escape character). That is why the book uses `<>` (LaTeX needs `{}`) and the chart uses `{}` (HTML needs
`<>`). A template with four characters or fewer gives no text.

### Placeholders and formats

A placeholder is a name between two `@`: `@SurName@`. Formats follow the name, each after a `&`:
`@BirthDate&%Y@`, `@BirthPlace&1,-1/Deutschland@`, `@PortraitIcon&30&left&jpg&8@`. Which formats a placeholder
has is listed in the [tables below](#placeholders). Names are case sensitive (`@Birthdate@` is an error).

### Line breaks and spaces

All line breaks of a template, together with the spaces around them, become **one space**. So you can spread a
template over many lines; the result of an individual is one line. To get a line break in the result, write it
in the language of the output: `\\` or `\par` in LaTeX, `<br>` in an HTML label. A plain text label of the
chart (without `<html>`) cannot get a line break from the template.

Spaces between two brackets of the same kind are removed: `< <` becomes `<<` and `> >` becomes `>>`.

### Blocks: text that is left out without data

Text between the brackets (`<...>` in the book, `{...}` in the chart) is a **block**. A block is written only
if at least one placeholder in it has data; otherwise the whole block is left out, with its text.

```
1<x @NickName@ y>2<<x @NickName@>>3<z <<@NickName@>>>4<z <@NickName@>>5
```

| Individual | Result |
|---|---|
| Anna (nickname "Anni") | `1x Anni y2x Anni34z Anni5` |
| Johann (no nickname) | `12345` |

Rules:

- Blocks can be nested to any depth. An inner block without data is left out; the outer block is still written
  if another placeholder in it has data.
- A block counts as data for the block around it if it is written. **Exception:** a block that consists of
  nothing but another block, i.e. **double brackets** `<<...>>`, is written if it has data, but does not count as
  data for the block around it. In the example, `<z <<@NickName@>>>` is left out for Anna, because the only data
  in it is inside double brackets. Use double brackets for text that should only appear together with other data
  (see [Recipes](#a-list-of-the-individuals-that-connect-two-branches)).
- Some placeholders never count as data: `@FeFactType@`, `@FeAttributeType@` (labels of facts), `@Remove@` and
  `@Replace@` (they change text), `@PortraitIcon@` (returns no text) and every placeholder that returns nothing.
  `@Marriage@` counts as data if the family has a marriage, although it returns no text.
- The body of a [loop](#loops) behaves like a block: a pass of the loop without data is left out.

### What the export cleans up

- Book: a `\\` directly after `\end{...}` and directly before `\begin{...}` is removed (only spaces between them),
  because LaTeX does not allow a line break there. A text after a block that ends with `\\` can therefore end
  with `\end{...}` safely.
- Chart: a label that is only `<html>` and `</html>` (with spaces) becomes empty. In the label, `<` and `>` of the
  template are written as `&lt;` and `&gt;`, as GraphML requires; write normal HTML in the template.

## Placeholders

The tables list every placeholder. *Record* says for which record the placeholder makes sense: *individual*
(the entry of an individual, or a child in `@ForeachChildren@`), *family* (a family node of the chart, or the
family in `@ForeachFAMS@`/`@ForeachFAMC@`) or the loop it belongs to. *Output* says whether it works in the
book (LaTeX), in the chart (GraphML) or both. The results are those of Anna Muster (I1) unless noted.

### Names

| Placeholder | Meaning | Formats | Example → result | Record | Output |
|---|---|---|---|---|---|
| `@GivenName@` | given names of the primary name; `@P.N.` (unknown) becomes `…` | list of positions, `.` = abbreviate the others (see [Given names](#given-names)) | `@GivenName&1,.@` → `Anna M. L.` | individual | both |
| `@SurName@` | surname of the primary name; `@N.N.` becomes `…`. Inside `@ForeachNAME@` the surname of that name | – | `@SurName@` → `Muster` | individual | both |
| `@NickName@` | nickname (`NICK` of the first name) | – | `@NickName@` → `Anni` | individual | both |
| `@FatherGivenName@`, `@MotherGivenName@` | given names of the father resp. mother (first family as a child) | as `@GivenName@` | `@FatherGivenName&1@` → `Friedrich` | individual, family in `@ForeachFAMC@` | both |
| `@FatherSurName@`, `@MotherSurName@` | surname of the father resp. mother | – | `@MotherSurName@` → `Klein` | individual, family in `@ForeachFAMC@` | both |
| `@SpouseGivenName@`, `@SpouseSurName@` | given names resp. surname of the spouse | as `@GivenName@` resp. – | `@SpouseSurName@` → `Beispiel` | family in `@ForeachFAMS@` | both |

### Dates and places

| Placeholder | Meaning | Formats | Example → result | Record | Output |
|---|---|---|---|---|---|
| `@BirthDate@`, `@DeathDate@` | date of birth resp. death | date format (see [Dates](#dates)), default: *Default date format* | `@BirthDate&%Y@` → `1850` | individual | both |
| `@BirthPlace@`, `@DeathPlace@` | place of birth resp. death | place format (see [Places](#places)), default: *Default place format* | `@DeathPlace&1@` → `Neustadt` | individual | both |
| `@MarriageDate@` | date of the marriage | date format | `@MarriageDate@` → `12.5.1872` | family | both |
| `@MarriagePlace@` | place of the marriage | place format | `@MarriagePlace&1@` → `Musterstadt` | family | both |
| `@Marriage@` | returns no text, but counts as data if the family has a marriage (`MARR`); for a symbol that appears only for married couples | – | `<⚭@Marriage@>` → `⚭` | family | both |

### Facts

| Placeholder | Meaning | Formats | Example → result | Record | Output |
|---|---|---|---|---|---|
| `@FactXXXX@` | the value of the facts with the GEDCOM tag `XXXX`, e.g. `@FactOCCU@` (occupation), `@FactTITL@` (title), `@FactRELI@` (religion), `@FactNCHI@` (number of children) | positions in the list of these facts, sorted by date (see [Facts](#facts-1)) | `@FactOCCU&-1@` → `Teacher` | individual, family | both |

`@FactXXXX@` returns only the *value* of a fact (the text after the tag). Facts without value, like a birth or
a residence with date and place only, give nothing: use the [fact loops](#fact-loops) for them.

### Numbers, relationships and links

| Placeholder | Meaning | Formats | Example → result | Record | Output |
|---|---|---|---|---|---|
| `@Id@` | the number of the individual in the export | `gen_no` (default), `no` or `xref`, see [Numbers](#numbers-of-the-individuals) | `@Id&xref@` → `I1` | individual | both |
| `@FatherId@`, `@MotherId@` | the number of the father resp. mother | as `@Id@` | `@FatherId&xref@` → `I5` | individual, family in `@ForeachFAMC@` | both |
| `@SpouseId@` | the number of the spouse | as `@Id@` | `@SpouseId&xref@` → `I2` | family in `@ForeachFAMS@` | both |
| `@Reference@` | the relationship to the reference individual, written with the *Reference symbols* | – | Hans: `F-S`, Georg: `2F` (see below) | individual | both |
| `@Ancestor@` | a symbol for direct ancestors and descendants and another one for other blood relatives | `symbol 1&symbol 2` | `@Ancestor&A&B@` → Anna, Georg, Karl: `A`; Hans: `B`; Johann: nothing | individual | both |
| `@Link@` | for an individual that connects two branches: the name of the other branch; else nothing | – | `@Link@` → `Beispiel` | individual | both |
| `@LinkId@` | the label of the entry of the individual in the other branch | as `@Id@` | `\pageref{@LinkId&xref@}` → `\pageref{I1}` | individual | book |

`@Reference@`: each step from the reference individual is one symbol of the form, joined with `-`:
father `F`, mother `M`, husband `H`, wife `W`, son `S`, daughter `D` (with these *Reference symbols*), an adopted
step with `a` in front (Paul, adopted son: `aS`). The same symbol several times in a row is counted: the
grandfather (father of the father) is `2F`. The reference individual itself and individuals that are not related
to it get nothing. The examples use the symbols `F`, `M`, `W`, `H`, `S`, `D`.

`@Ancestor@`: *symbol 1* for the reference individual, its direct ancestors and its direct descendants (Anna,
her parents, her grandfather, her children), *symbol 2* for the other blood relatives (descendants of an
ancestor, e.g. her brother Hans), nothing for spouses and for individuals related by marriage only. Without a
reference individual nothing is returned.

### Portraits and pictures

| Placeholder | Meaning | Formats | Example → result | Record | Output |
|---|---|---|---|---|---|
| `@Portrait@` | the file name of the portrait (path inside the media folder of webtrees) | – (portrait only), `fallback` (portrait, else the default portrait), `silhouette` (always the default portrait) | `@Portrait@` → `docs-test/anna.jpg` | individual | both |
| `@PortraitSize@` | the other side of the portrait in pixels for a given width or height (keeps the aspect ratio) | `w<width>` or `h<height>`, then the value without portrait | `@PortraitSize&w30&20@` → `40` (Anna: 300 × 400 pixels), Johann: `20` | individual | both |
| `@PortraitIcon@` | puts the portrait into the `.graphml` file and shows it as the picture of the box; returns no text | `width&position&type&sharpness`, see the README, [Portraits in the family tree chart](../README.md#portraits-in-the-family-tree-chart) | `@PortraitIcon&30&left@` | individual | chart (nothing in the book) |

See [Portraits](#portraits) for which picture is the portrait. The *default portrait* is a setting of the chart
(table *Box style*: *Default portrait* per sex); the book has no such setting, so `fallback` and `silhouette`
give no default portrait there.

### Other placeholders

| Placeholder | Meaning | Formats | Example → result | Record | Output |
|---|---|---|---|---|---|
| `@Gedcom@` | the GEDCOM of the record (as far as the user may see it), lines separated by `<br>` | – | family of Georg: `0 @F3@ FAM<br>1 HUSB @I9@<br>1 CHIL @I5@` | individual, family | both (meant for the chart) |
| `@Counter@` | the number of the current pass of the loop (1, 2, ...) | – | `@Counter@. ` → `1. `, `2. ` | inside a loop | both |
| `@Remove@` | removes the given texts at the end of the text written so far | the texts, each after `&` | `@Remove&, @` | anywhere | both |
| `@Replace@` | replaces a text at the end of the text written so far | `text&replacement` | `@Replace&:&.@` | anywhere | both |

`@Remove@` and `@Replace@` work on the text of their own level: the text written before them in the same
block, loop body or template (including the results of loops and blocks before them). They act only at the end
of this text (spaces, line breaks and brackets after it do not matter) and repeat until the text no longer ends
with it:

| Template | Anna | Johann |
|---|---|---|
| `@ForeachFactOuter&OCCU&all@@ForeachFactInner@@FeFactValue@, @EndForeach@@EndForeach@@Remove&, @` | `Seamstress, Teacher` | `Blacksmith` |
| `<a: @GivenName&1@:>@Replace&:&.@` | `a: Anna.` | `a: Johann.` |

### Placeholders inside loops

These placeholders only have a value inside the loop they belong to; they are described with the
[loops](#loops):

| Placeholder | Loop |
|---|---|
| `@FeFactType@`, `@FeFactValue@`, `@FeFactDate@`, `@FeFactPlace@`, `@FeFactNote@` | `@ForeachFactOuter@` / `@ForeachFactInner@` |
| `@FeAttributeType@`, `@FeFactAttribute@` | `@ForeachAttributeOuter@` |
| `@FeStepparents@` | `@ForeachFAMC@` |
| `@FeStepchild@` | `@ForeachChildren@` |
| `@FeMediaFile@`, `@FeMediaId@`, `@FeMediaCaption@` | `@ForeachMedia@` |
| `@FeReferenceName@` | `@ForeachReference@` |
| `@Counter@` | every loop |

## Formats of names, dates, places and facts

### Given names

The format is a comma separated list of positions of the given names that are written in full. A `.` in the list
writes all other given names abbreviated to their first letter.

| Format | Anna Maria Luise | Friedrich Wilhelm |
|---|---|---|
| (none) | `Anna Maria Luise` | `Friedrich Wilhelm` |
| `1` | `Anna` | `Friedrich` |
| `2` | `Maria` | `Wilhelm` |
| `1,3` | `Anna Luise` | `Friedrich` |
| `1,.` | `Anna M. L.` | `Friedrich W.` |
| `.` | `A. M. L.` | `F. W.` |

The help of earlier versions showed formats like `1,2,3.`. Do not use a position followed by a dot: `1.` today
writes the first given name in full and abbreviates the *second* one (Anna: `Anna M.`), which is rarely what you
want. Use the list of positions and `.` instead.

### Dates

The format is a date format of webtrees, with `%` before each letter. Frequent letters:

| Letter | Meaning | Example (3 March 1850) |
|---|---|---|
| `%j` | day without leading zero | `3` |
| `%d` | day with two digits | `03` |
| `%n` | month as number without leading zero | `3` |
| `%m` | month as number with two digits | `03` |
| `%F` | name of the month | `March` |
| `%M` | short name of the month | `Mar` |
| `%Y` | year | `1850` |

| Format | Anna (3 MAR 1850) | Johann (1845) | Hans (ABT 1852) |
|---|---|---|---|
| `%j.%n.%Y` | `3.3.1850` | `1845` | `about 1852` |
| `%Y` | `1850` | `1845` | `about 1852` |
| `%d.%m.%Y` | `03.03.1850` | `1845` | `about 1852` |
| `%j %F %Y` | `3 March 1850` | `1845` | `about 1852` |
| `%j %M %Y` | `3 Mar 1850` | `1845` | `about 1852` |

- webtrees writes only the parts that are known: a year alone stays a year, whatever the format.
- Qualifiers (`about`, `before`, ...) and the names of months are written in the language of the user who
  exports (the examples were exported in English).
- **A date without format uses the *Default date format*. If there is none, the date is empty.** The chart has no
  default date format, so always give a format there (e.g. `@BirthDate&%Y@`).

### Places

A place in webtrees is a list of names separated by commas, from the smallest to the largest:
`Musterstadt, Kreis Beispiel, Deutschland`. The format is a comma separated list of positions: `1` is the first
name, `2` the second, `-1` the last, `-2` the one before the last. After a position, `/name` leaves the name out
if it is this one (several: `/name1/name2`). Positions that do not exist are skipped; the names are joined with
`, `.

| Format | Musterstadt, Kreis Beispiel, Deutschland | Altdorf, Deutschland |
|---|---|---|
| (none, no default) | `Musterstadt, Kreis Beispiel, Deutschland` | `Altdorf, Deutschland` |
| `1` | `Musterstadt` | `Altdorf` |
| `2` | `Kreis Beispiel` | `Deutschland` |
| `-1` | `Deutschland` | `Deutschland` |
| `1,-1` | `Musterstadt, Deutschland` | `Altdorf, Deutschland` |
| `1,-1/Deutschland` | `Musterstadt` | `Altdorf` |
| `1/Musterstadt,-1` | `Deutschland` | `Altdorf, Deutschland` |

A place without format uses the *Default place format* (book only); without it, the full place is written.
`1,-1/Deutschland` writes the town and, for places outside Germany, the country.

### Facts

The format of `@FactXXXX@` is a comma separated list of positions in the list of the facts with this tag, sorted
by date: `1` is the first, `-1` the last. Each value is written once, the values are joined with `, `.

| Format | Anna (Seamstress 1870, Teacher 1880) | Johann (Blacksmith) |
|---|---|---|
| `1` | `Seamstress` | `Blacksmith` |
| `-1` | `Teacher` | `Blacksmith` |
| `1,2` | `Seamstress, Teacher` | `Blacksmith` |
| `2,1` | `Teacher, Seamstress` | `Blacksmith` |
| (none) | `SeamstressTeacher` | `Blacksmith` |

Without format all values are written **without separator**; give a list of positions (e.g. `1,2,3`) or use a
[fact loop](#fact-loops) if an individual can have several facts of the tag.

### Numbers of the individuals

The format of `@Id@`, `@FatherId@`, `@MotherId@`, `@SpouseId@` and `@LinkId@`:

| Format | Meaning | Anna |
|---|---|---|
| `gen_no` (default) | number in the book: `<branch>-<generation>.<position in the generation>`; with more than one group of unconnected individuals in the tree the number of the group comes first (`1-1-3.2`) | `1-3.2` |
| `no` | running number of the individual in the export (1, 2, 3, ...) | `5` |
| `xref` | the ID of the individual in webtrees | `I1` |

Use `xref` for labels and references in LaTeX (`\label{@Id&xref@}`, `\pageref{@Id&xref@}`): it does not change
when individuals are added. The *generation* counts from the oldest generation of the branch (Georg: 1, Anna: 3).

## Loops

A loop repeats the text between `@Foreach...@` and `@EndForeach@` for each element, e.g. each family or each
fact:

```
@ForeachFAMS@ ...text with placeholders... @EndForeach@
```

- Formats of the loop follow its name: `@ForeachMedia&photo&jpg,png@`.
- Loops can be nested; every `@Foreach...@` needs its own `@EndForeach@`.
- Inside the loop the placeholders refer to the element of the pass (the family, the child, the fact, ...), see the
  column *Record*. `@Counter@` is the number of the pass.
- **A pass without data is left out**, like a block. `@FeFactType@` alone is no data: a fact loop needs a value,
  a date, a place, ... in it.
- A loop is no block: text around the loop is written even if the loop gives nothing. Put the loop into a block
  (`<... @ForeachFAMS@...@EndForeach@ ...>`) to leave out the text around it, too.
- **Do not make the body of a loop one single block**: `@ForeachFAMS@<@SpouseSurName@>@EndForeach@` gives
  nothing, because the body is itself a block and a block that consists of nothing but another block does not
  count as data (see [Blocks](#blocks-text-that-is-left-out-without-data)). Any text outside the inner block
  helps, e.g. a space or a line break: `@ForeachFAMS@ <@SpouseSurName@>@EndForeach@`.

| Loop | Repeats for | Record inside | Formats |
|---|---|---|---|
| `@ForeachFAMC@` | each family in which the individual is a child | the family | – |
| `@ForeachFAMS@` | each family in which the individual is a spouse | the family | – |
| `@ForeachChildren@` | each child of the family (in the order of the family) | the child (an individual) | – |
| `@ForeachNAME@` | each name of the individual of the given type | the individual; `@SurName@` is the surname of this name | the type of the name, e.g. `_MARNM` |
| `@ForeachFactOuter@` | each fact type (tag) of the record | the record | `order&excluded` |
| `@ForeachFactInner@` | each fact of the current type, sorted by date | the fact | – |
| `@ForeachAttributeOuter@` | each of the given attributes of the current fact | the fact | `TAG1,TAG2,...` |
| `@ForeachMedia@` | each media file of the individual (with its facts and its families) | the media file | `types&extensions&unique` |
| `@ForeachReference@` | each source of the individual (with its facts and families) | the source | – |

### Families: `@ForeachFAMC@`, `@ForeachFAMS@`, `@ForeachChildren@`

Parents (first line of the entry of Anna):

```
@ForeachFAMC@<child of <@FatherGivenName&1@ >@FatherSurName@>< and <@MotherGivenName@ >@MotherSurName@><, @FatherId&xref@/@MotherId&xref@>@EndForeach@
```

→ Anna: `child of Friedrich Muster and Maria Klein, I5/I6`; Friedrich Wilhelm (only a father known):
`child of Georg Muster, I9/`

Spouses and children:

```
@ForeachFAMS@<<@Marriage@m. @MarriageDate@< in @MarriagePlace&1@>>> <@SpouseGivenName@ @SpouseSurName@ (@SpouseId&xref@)>: <@ForeachChildren@@Counter@. @GivenName@ <*@BirthDate&%Y@>< (@FeStepchild@)>; @EndForeach@>@Remove&; @@EndForeach@
```

→ Anna: `m. 12.5.1872 in Musterstadt Johann Beispiel (I2): 1. Karl *1873; 2. Emma *1875; 3. Paul *1880 (stepparents)`;
Friedrich Wilhelm: `Maria Klein (I6): 1. Anna Maria Luise *1850; 2. Hans *about 1852`

- In `@ForeachFAMS@` the record is the family: `@MarriageDate@`, `@MarriagePlace@`, `@Marriage@`, `@FactXXXX@` of
  the family and `@SpouseGivenName@`, `@SpouseSurName@`, `@SpouseId@` (the other spouse).
- In `@ForeachFAMC@` the record is the family of the parents: `@FatherGivenName@`, `@FatherSurName@`, `@FatherId@`,
  the same for `Mother`, and `@FeStepparents@`. Outside of `@ForeachFAMC@` these placeholders refer to the first
  family in which the individual is a child.
- In `@ForeachChildren@` (inside `@ForeachFAMS@`) the record is the child: all placeholders of individuals, and
  `@FeStepchild@`.
- `@FeStepparents@` (in `@ForeachFAMC@`) and `@FeStepchild@` (in `@ForeachChildren@`) say whether the child is
  adopted in this family (GEDCOM `ADOP` with `FAMC` of the family): `stepparents` (adopted by both), `stepfather`,
  `stepmother` (passed through the translation of webtrees); nothing if not adopted.
  Paul in `@ForeachFAMC@[@FeStepparents@]@EndForeach@` → `[stepparents]`.

### Names: `@ForeachNAME@`

`@ForeachNAME&_MARNM@` repeats for each married name. webtrees gives a name the type `_MARNM` if it is a name
with `2 TYPE married` or a `2 _MARNM` below the name; other names have the type `NAME`. The surname of the name
is `@SurName@` inside the loop.

```
@SurName@< (@ForeachNAME&_MARNM@@SurName@, @EndForeach@@Remove&, @)>
```

→ Maria Klein (second name `Maria /Muster/` with `2 TYPE married`): `Klein (Muster)`; Anna: `Muster`

### Fact loops

The facts of a record are written with two nested loops: the outer loop over the fact *types* (tags), the inner
loop over the facts of each type:

```
@ForeachFactOuter&BIRT,OCCU,RESI&all@@FeFactType@: @ForeachFactInner@@Counter@ <@FeFactValue@ ><@FeFactDate@ ><in @FeFactPlace@ ><@ForeachAttributeOuter&ADDR,NOTE@@FeAttributeType&&(&)@ @FeFactAttribute@ @EndForeach@>; @EndForeach@| @EndForeach@
```

→ Anna:

```
Birth: 1 3.3.1850 in Musterstadt ; | Occupation: 1 Seamstress 1870 in Musterstadt ; 2 Teacher 1880 in Neustadt (Note) Village school ; | Residence: 1 1890 in Neustadt (Address) Lindenweg 5 ; |
```

`@ForeachFactOuter&order&excluded@`:

- *order*: comma separated tags written first, in this order (`BIRT,OCCU,RESI`).
- *excluded*: comma separated tags that are not written; `all` leaves out all tags that are not in *order*.
  Without `all`, all other tags of the record follow after the ordered ones, in the order of webtrees.
  Exclude the tags you never want, e.g. `NAME,SEX,FAMS,FAMC,OBJE,CHAN,SOUR,NOTE,_TODO`.

Inside the outer loop:

| Placeholder | Meaning | Formats |
|---|---|---|
| `@FeFactType@` | the label of the fact type: the symbol of the field *List of symbols* if the tag has one, else the name of the tag in webtrees (`Occupation`) | `IfExists&prefix&suffix`: prefix and suffix are only added to the name, not to a symbol |

Inside `@ForeachFactInner@` (the record is the fact):

| Placeholder | Meaning | Formats | Example (Anna, second occupation) |
|---|---|---|---|
| `@FeFactType@` | as above | as above | `Occupation` |
| `@FeFactValue@` | the value of the fact; for an adoption (`ADOP`) by whom (`stepparents`, ...) | – | `Teacher` |
| `@FeFactDate@` | the date of the fact | date format | `1880` |
| `@FeFactPlace@` | the place of the fact | place format | `Neustadt` |
| `@FeFactNote@` | the note of the fact (`2 NOTE`), followed by `\ ` (a LaTeX space) | – | `Village school\ ` |
| `@ForeachAttributeOuter@` | loop over the given attributes of the fact | `TAG1,TAG2,...`, e.g. `AGNC,ADDR,PHON,CAUS,TYPE,AGE` | |

Inside `@ForeachAttributeOuter@`:

| Placeholder | Meaning | Formats | Example |
|---|---|---|---|
| `@FeAttributeType@` | the label of the attribute (symbol or name, as `@FeFactType@`) | `IfExists&prefix&suffix` | `@FeAttributeType&&(&)@` → `(Address)` |
| `@FeFactAttribute@` | the value of the attribute; for `DATE` and `PLAC` the date resp. place of the fact | date resp. place format | `Lindenweg 5` |

`IfExists` (also `IfExist`, the spelling of older templates) writes the label only if the record has a fact of
this type. The loops only visit fact types that exist, so in the usual templates it changes nothing; it is kept
for older templates.

With the symbols `BIRT;*` and no symbol for `OCCU`:

```
@ForeachFactOuter&BIRT,OCCU&all@@FeFactType&&[&]@ @ForeachFactInner@@FeFactValue@@FeFactDate@ @EndForeach@@EndForeach@
```

→ Anna: `* 3.3.1850 [Occupation] Seamstress1870 Teacher1880 ` (prefix `[` and suffix `]` only around the name)

The facts of a family are written in the same way inside `@ForeachFAMS@`.

### Media: `@ForeachMedia@`

`@ForeachMedia&types&extensions&unique@` repeats for each media file of the individual: the media objects of
the individual and of its facts, then those of its families and their facts.

- *types*: comma separated media types (`photo`, `document`, `certificate`, ...; upper or lower case); empty =
  all types.
- *extensions*: comma separated file extensions (`jpg,png,pdf`); empty = all.
- `unique`: each media object only once in the whole book (at the first individual that has it).
- Files without extension, links to media objects that do not exist and media the user may not see are skipped;
  the lists *Include* and *Exclude* and the page *Image selection* decide which media objects are used.

Inside the loop (the record is the media file):

| Placeholder | Meaning | Formats | Example (Anna's photo) |
|---|---|---|---|
| `@FeMediaFile@` | the file name with its path inside the media folder of webtrees | `NoExtension`: without the extension | `docs-test/anna.jpg`, `@FeMediaFile&NoExtension@` → `docs-test/anna` |
| `@FeMediaId@` | a unique ID of the media file (for `\label` and `\ref`) | – | `b68df9de78a3beaf4a32a9726f30e73d` |
| `@FeMediaCaption@` | the title of the media file | – | `Anna in 1880` |

```
@ForeachMedia&photo&jpg,png@@FeMediaFile@ @EndForeach@
```

→ Anna: `docs-test/anna.jpg ` (the letter is a PDF of type *document*)

See [Pictures of an individual](#pictures-of-an-individual) for a complete figure.

### Sources: `@ForeachReference@`

`@ForeachReference@` repeats for each source cited by the individual, by one of its facts or by a fact of one of
its families (each source once). `@FeReferenceName@` is the number of the source in the list of sources that the
export writes into the `.tex` file (`\jobname.bib`), for `\cite`:

```
<\cite{@ForeachReference@@FeReferenceName@,@EndForeach@@Remove&,@}>
```

→ Anna: `\cite{1}`; Johann (no source): nothing

## Conditions

There is no `if`; conditions are made with blocks:

| Wish | How |
|---|---|
| text only if a value exists | put text and placeholder into a block: `< in @BirthPlace@>` |
| text only if a value exists, without counting as data for the outer block | double brackets: `<<...>>` |
| a symbol only for married couples | `<⚭@Marriage@>` (family) |
| a symbol for ancestors | `@Ancestor&symbol 1&symbol 2@` |
| text only for individuals that connect two branches | a block with `@Link@` |
| a loop only if it has passes | put the loop and its text into a block |
| the label of a fact type only if the record has such facts | `@FeFactType&IfExists@` (see [Fact loops](#fact-loops)) |

## Labels and links in the book (LaTeX)

An individual where one branch ends and another begins (start or stop individual of a branch, see the README,
[Branches](../README.md#branches)) has a full entry in both branches, with the name of the other branch for
`@Link@`. LaTeX allows each label only once, so only one of the two entries defines the label of the individual
(the main label); the other entry gets a label of its own:

- The main label stays with the entry in the individual's own branch (the later branch of the list). If this
  branch is switched off, the entry in the other branch defines it.
- `@Id&...@` directly inside `\label{...}` returns in the second entry the main label followed by `/` and the
  number of the branch of this entry (counted from 0 in the list *List of branches*), e.g. `\label{I1/0}`. A `/` never
  occurs in an XREF, so this label is never the label of another individual. If the second entry has
  `\label{@Id&...@}` without `<...>` around it, the export removes an empty `\label{}`.
- `\pageref{@Id&xref@}` and all other references to the individual (`@FatherId@`, `@MotherId@`, `@SpouseId@`,
  children, summary) point to the main label. Everything else of the second entry stays as it is (number,
  index entry, sources, pictures).
- `@LinkId@` returns the label of the entry of the individual in the other branch: in the entry with the main
  label the label of the second entry, in the second entry the main label. Use it for a page number next to
  `@Link@`, so the link works in both directions. The format is the same as for `@Id@` (`no`, `gen_no` or
  `xref`; use the same as in `\label{@Id&...@}`). `@LinkId@` is empty if `@Link@` is empty or the other entry
  is not exported, so the `<...>` block is left out. It never points to a label that is not in the book (as long
  as the template defines `\label{@Id&...@}`). In the chart it returns nothing.
- Limit: an individual whose copies are in several other branches links only to one of them (the last one
  found); `@Link@` and `@LinkId@` of the entry in the own branch show this branch.
- The labels of pictures (`\label{fig:@FeMediaId@}`) are not affected: with `@ForeachMedia&...&...&unique@` each
  picture is placed only once anyway.

Example with the branches `I5;Muster;both;I1` and `I1;Beispiel;descendants` (Anna is the stop individual of
"Muster" and the start individual of "Beispiel"):

```
@SurName@<\label{@Id&xref@}>< see @Link@< p.\pageref{@LinkId&xref@}>>
```

→ Anna in "Muster": `Muster\label{I1/0} see Beispiel p.\pageref{I1}`; Anna in "Beispiel":
`Muster\label{I1} see Muster p.\pageref{I1/0}`; Johann: `Beispiel\label{I2}`

The name of the branch is written as it is in the list *List of branches* (it may contain LaTeX); in the chart it is masked
like data.

## Portraits

All portrait placeholders (`@Portrait@`, `@PortraitSize@`, `@PortraitIcon@`, in LaTeX and GraphML) use the same
picture: the first picture of the individual (order of the media objects of the individual, then of the
files of a media object) that

- is a picture file (JPEG, PNG, GIF, ...) and no external file (web address), and
- has the media type *Photo* or no media type (GEDCOM: `TYPE` below `FORM`, upper or lower case).

Pictures of other types (certificate, document, ...) are skipped, so a certificate is never the portrait
of an individual without photo. Without such a picture the individual has no portrait (as without any
picture; `@Portrait&fallback@` gives the default portrait). This does not change the pictures of the book
(`@ForeachMedia@`) nor the list of excluded pictures of the image selection.

`@PortraitSize@` reads the picture from the media folder below the folder of webtrees on the server
(`<webtrees>/data/media/`); if the picture is missing there, the export stops with an error (see
[Common errors](#common-errors)). For the chart, `@PortraitIcon@` is the better choice: it puts the picture into
the file (see the README, [Portraits in the family tree chart](../README.md#portraits-in-the-family-tree-chart)).

## Special characters

### Book (LaTeX)

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
`\includegraphics` as in the example template. `@FeMediaFile@` and `@FeMediaId@` are not masked at all.

### Chart (GraphML)

In data, `&`, `"`, `'`, `<` and `>` are written as `&amp;`, `&quot;`, `&apos;`, `&lt;`, `&gt;`; the text of the
templates is written as it is. A `<br>` in the data (`@Gedcom@` writes its lines with `<br>`) stays a line break.

## Recipes

### Only the year

```
<*@BirthDate&%Y@>< †@DeathDate&%Y@>
```

→ Anna: `*1850 †1920`; Hans: `*about 1852`

### A shorter place

`@BirthPlace&1@` writes only the town (`Musterstadt`); `@BirthPlace&1,-1/Deutschland@` the town and the country
unless it is Germany. Set the format you use most as *Default place format* (book) and write `@BirthPlace@`.

### The occupation

All occupations: `<@FactOCCU&1,2,3@>` → Anna: `Seamstress, Teacher`. The last one only (e.g. for the chart):
`@FactOCCU&-1@` → `Teacher`. With date and place, use the fact loops:

```
@ForeachFactOuter&OCCU&all@@ForeachFactInner@@FeFactValue@< (@FeFactDate&%Y@)>, @EndForeach@@EndForeach@@Remove&, @
```

→ Anna: `Seamstress (1870), Teacher (1880)`

### Leave out text if a value is missing

Put the text and the placeholder into one block: `< in @BirthPlace@>` writes ` in Musterstadt` or nothing.
A label in front of a list of values: `<Children: @ForeachFAMS@ ... @EndForeach@>`.

### A list of the children

```
<\begin{compactenum} @ForeachFAMS@@ForeachChildren@ \item @GivenName@< *@BirthDate&%Y@> @EndForeach@@EndForeach@ \end{compactenum}>
```

→ Anna: `\begin{compactenum}  \item Karl *1873  \item Emma *1875  \item Paul *1880  \end{compactenum}`;
Hans (no children): nothing. (`compactenum` is a list of the LaTeX package `paralist`.)

### Pictures of an individual

```
@ForeachMedia&photo&jpg,png&unique@ <\begin{figure}[!htbp]\centering \includegraphics[width=\linewidth]{\detokenize{/path/to/media/@FeMediaFile@}} <\caption{@FeMediaCaption@}> \label{fig:@FeMediaId@} \end{figure}> @EndForeach@
```

→ Anna (one line in the file):

```
 \begin{figure}[!htbp]\centering \includegraphics[width=\linewidth]{\detokenize{/path/to/media/docs-test/anna.jpg}} \caption{Anna in 1880} \label{fig:b68df9de78a3beaf4a32a9726f30e73d} \end{figure}
```

Replace `/path/to/media/` by the folder with a copy of the webtrees media folder on the computer that makes the
PDF (see the README, [From the exported file to a PDF](../README.md#from-the-exported-file-to-a-pdf)). A
reference to the pictures in the text: `<see @ForeachMedia&photo&jpg,png@\ref{fig:@FeMediaId@} @EndForeach@>`.

### A link to the other branch

```
<\label{@Id&xref@}>
<\segoe{🔗} @Link@< p.\pageref{@LinkId&xref@}>\\ >
```

See [Labels and links](#labels-and-links-in-the-book-latex).

### A list of the individuals that connect two branches

In the field *Summary at the beginning of a branch*:

```
<>@&<<@SurName@, @GivenName@>>< see @Link@>
```

→ at the beginning of the branch "Muster": `Muster, Anna Maria Luise see Beispiel`, at the beginning of the
branch "Beispiel": `Muster, Anna Maria Luise see Muster`; nothing for the other individuals. The name is in
double brackets, so only `@Link@` decides whether the line is written.

### A picture in the box of the chart

Write `@PortraitIcon&30&left@` anywhere in the label template of the individuals (e.g. directly after `{}@&`),
see the README, [Portraits in the family tree chart](../README.md#portraits-in-the-family-tree-chart).

## Common errors

A typing error in a template (a bracket that is not closed, a loop without `@EndForeach@`, a placeholder that
does not exist) or a wrong value in the list of branches does not create a file. The page shows the form again
with the values entered and a message that names the error:

| Template | Message |
|---|---|
| `@Birthdate@` | Error in the template: the placeholder “@Birthdate@” does not exist. |
| `< *@BirthDate@` | Error in the template: the bracket “<” is not closed: < *@BirthDate@ |
| `@ForeachFAMS@@SpouseSurName@` | Error in the template: the loop “ForeachFAMS” has no matching @EndForeach@: @ForeachFAMS@@SpouseSurName@ |

Other mistakes give no message, but a wrong result:

| Symptom | Cause |
|---|---|
| A block is cut off, a `>` or `}` appears in the output | the closing bracket is also used in the text, e.g. `< -> @Link@>` in the book: the `>` of `->` ends the block. Choose other brackets in the first four characters or avoid the character. |
| An `@` in the text breaks the template | `@` starts a placeholder; there is no way to write it as text. Choose another placeholder character in the first four characters. |
| Dates are empty in the chart | a date without format and no *Default date format* (the chart has none): write `@BirthDate&%Y@`. |
| A loop writes nothing | the body of the loop is one single block, e.g. `@ForeachFAMS@<...>@EndForeach@`: add a space before the block. Or the pass has no data: `@FeFactType@` alone is no data. |
| A text appears although the value is missing | the text is not in the same block as the placeholder. |
| Several values are glued together (`SeamstressTeacher`) | `@FactXXXX@` without format: give positions, e.g. `@FactOCCU&1,2@`. |
| The export stops with a PHP error about `getimagesize` | `@PortraitSize@` with a portrait that is not in the media folder of webtrees on the server. |
| A line break of the template is missing | line breaks become spaces: write `\\` (LaTeX) or `<br>` (HTML). |
| A picture is missing in the book | see the README, [Pictures that are skipped](../README.md#pictures-that-are-skipped). |

## Index of all placeholders

| Placeholder | Section |
|---|---|
| `@Ancestor@` | [Numbers, relationships and links](#numbers-relationships-and-links) |
| `@BirthDate@`, `@BirthPlace@` | [Dates and places](#dates-and-places) |
| `@Counter@` | [Other placeholders](#other-placeholders) |
| `@DeathDate@`, `@DeathPlace@` | [Dates and places](#dates-and-places) |
| `@EndForeach@` | [Loops](#loops) |
| `@FactXXXX@` | [Facts](#facts) |
| `@FatherGivenName@`, `@FatherSurName@`, `@FatherId@` | [Names](#names), [Numbers](#numbers-relationships-and-links) |
| `@FeAttributeType@`, `@FeFactAttribute@` | [Fact loops](#fact-loops) |
| `@FeFactDate@`, `@FeFactNote@`, `@FeFactPlace@`, `@FeFactType@`, `@FeFactValue@` | [Fact loops](#fact-loops) |
| `@FeMediaCaption@`, `@FeMediaFile@`, `@FeMediaId@` | [Media](#media-foreachmedia) |
| `@FeReferenceName@` | [Sources](#sources-foreachreference) |
| `@FeStepchild@`, `@FeStepparents@` | [Families](#families-foreachfamc-foreachfams-foreachchildren) |
| `@ForeachAttributeOuter@`, `@ForeachFactInner@`, `@ForeachFactOuter@` | [Fact loops](#fact-loops) |
| `@ForeachChildren@`, `@ForeachFAMC@`, `@ForeachFAMS@` | [Families](#families-foreachfamc-foreachfams-foreachchildren) |
| `@ForeachMedia@` | [Media](#media-foreachmedia) |
| `@ForeachNAME@` | [Names](#names-foreachname) |
| `@ForeachReference@` | [Sources](#sources-foreachreference) |
| `@Gedcom@` | [Other placeholders](#other-placeholders) |
| `@GivenName@`, `@NickName@`, `@SurName@` | [Names](#names) |
| `@Id@`, `@LinkId@`, `@Link@`, `@Reference@` | [Numbers, relationships and links](#numbers-relationships-and-links) |
| `@Marriage@`, `@MarriageDate@`, `@MarriagePlace@` | [Dates and places](#dates-and-places) |
| `@MotherGivenName@`, `@MotherSurName@`, `@MotherId@` | [Names](#names), [Numbers](#numbers-relationships-and-links) |
| `@Portrait@`, `@PortraitIcon@`, `@PortraitSize@` | [Portraits and pictures](#portraits-and-pictures) |
| `@Remove@`, `@Replace@` | [Other placeholders](#other-placeholders) |
| `@SpouseGivenName@`, `@SpouseSurName@`, `@SpouseId@` | [Names](#names), [Numbers](#numbers-relationships-and-links) |
