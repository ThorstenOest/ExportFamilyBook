<?php

/**
 * ExportFamilyBook module for Webtrees
 *
 * This file is included in the module.php file
 */

namespace haduloha\ExportFamilyBook;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\MediaFile;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\DB;
use Illuminate\Database\Query\Expression;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Elements\UnknownElement;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Error in a template or in the branch list that the user can correct in the form
 *
 * The message is translated and not escaped; the module shows it as a message on the
 * page of the form instead of the export (see ExportFamilyBook::handleForm).
 */
class ExportFamilyBookTemplateException extends RuntimeException
{
}

/**
 * The export of a tree as family book (LaTeX) or chart (GraphML)
 *
 * Gets its settings from the module (see ExportFamilyBook::exportData), reads no request data itself.
 */
class ExportFamilyBookData
{

    // variables used in different functions 
    private $generationInd  = array();
    private $include_exclude_media = array();
    private $media_included = array();
    private $source_list = array();
    private $tree = null;

    // caches for data which does not change during an export
    // (records are kept by the webtrees registry, so the same object is returned for the same xref)
    private ?WeakMap $record_cache = null;
    private $tag_label_cache = array();

    // Collection of the media of the LaTeX export (page "Image selection", see collectLatexMedia).
    // null during a normal export.
    private ?array $media_collector = null;
    // the individual whose template is processed (used by the collection)
    private string $media_person = '';

    // LaTeX: an individual connecting two branches has an entry in both branches (the "copy" in the
    // old branch, see getRecords). Only one entry may define the label of the individual
    // (\label{@Id&xref@}), otherwise LaTeX reports "multiply defined labels" and \pageref may point
    // to the copy. $entry_xref is the individual of the entry processed, $entry_has_label is false
    // for an entry that must not define the label (see labelEntries). Such an entry defines a label of
    // its own instead ("<label of the individual>/<number of its branch>", see entryLabel), the target
    // of @LinkId@ in the other entry. $entry_id is the key of the entry processed.
    private string $entry_xref = '';
    private string $entry_id = '';
    private bool $entry_has_label = true;
    // xref => key of the entry with the label of the individual; keys of the exported entries => true
    private array $label_entries = array();
    private array $exported_entries = array();

    // GraphML: portraits embedded as label icons (@PortraitIcon@, see portraitIcon)
    // pictures of the file: [base64 data, pixel width, pixel height, shown width, shown height, scale],
    // numbered from 1 (resource ids see graphmlFooter);
    // cache: key of the picture => [id of the icon resource, shown width, shown height]
    private array $icon_resources = array();
    private array $icon_cache = array();
    // icon of the node processed: [id, width, height, position] or null
    private ?array $node_icon = null;

    // Settings of the export: the setting fields of the form of the export type, completed with
    // default values (see ExportFamilyBook::exportSettings). The export reads no request data itself.
    private array $settings;
    // xref of the address of the page: the individual of the default branch if the settings have no
    // reference individual (see getSeeds)
    private string $url_xref;
    // server parameters of the request (ServerRequestInterface::getServerParams), used for the
    // path of the portrait files (see getPortrait)
    private array $server_params;

    /**
     * @param Tree $tree
     * @param array<string,string> $settings settings of the export (see ExportFamilyBook::exportSettings)
     * @param string $url_xref xref of the address of the page ('' if none)
     * @param array<string,mixed> $server_params server parameters of the request
     */
    public function __construct(Tree $tree, array $settings, string $url_xref = '', array $server_params = array())
    {
        $this->tree = $tree;
        $this->settings = $settings;
        $this->url_xref = $url_xref;
        $this->server_params = $server_params;
    }

    /**
     * Collect the media that the LaTeX export would include, without creating the book
     *
     * Runs exportLatex() with the same selection of individuals and the same media loops
     * (@ForeachMedia with its type and file format filters), but only the parts of the
     * templates that lead to a media loop are processed and nothing is written.
     * The lists include_media and exclude_media are ignored, and media marked as "unique"
     * are collected for each individual, so excluded media and all occurrences are listed.
     * Media the current user may not see are skipped, as in the export.
     *
     * @return array{persons: array<string,array<string,true>>, media: array<string,array{media: Media, files: array<string,\Fisharebest\Webtrees\MediaFile>}>}
     *         persons: individual xref => media xrefs, both in the order of the book;
     *         media: media xref => media object and the files used (file name => file)
     */
    public function collectLatexMedia(): array
    {
        $settings = $this->settings;
        $this->settings['include_media'] = '';
        $this->settings['exclude_media'] = '';
        $this->media_collector = array('persons' => array(), 'media' => array());
        $stream = fopen('php://memory', 'wb+');
        try {
            $this->exportLatex($stream);

            return $this->media_collector;
        } finally {
            fclose($stream);
            $this->media_collector = null;
            $this->settings = $settings;
        }
    }

    /**
     * Reduce a split template to the parts needed to collect the media (see collectLatexMedia)
     *
     * Keeps the media loops (without their content) and the loops and terms containing them.
     *
     * @param array $comp component of a split template
     * @return array|null null if the component contains no media loop
     */
    private function mediaOnlyTemplate(array $comp): ?array
    {
        if ($comp['type'] === 'foreach' && $comp['component'] === 'ForeachMedia') {
            $comp['subtemplate'] = array();
            return $comp;
        }
        if (($comp['type'] !== 'term' && $comp['type'] !== 'foreach') || !is_array($comp['subtemplate'])) {
            return null;
        }
        $children = array();
        foreach ($comp['subtemplate'] as $child) {
            $child = $this->mediaOnlyTemplate($child);
            if ($child !== null) {
                $children[] = $child;
            }
        }
        if ($children === array()) {
            return null;
        }
        // the components of a term are numbered from 1 (see splitTemplate), the loop content from 0
        $comp['subtemplate'] = ($comp['type'] === 'term') ? array_combine(range(1, count($children)), $children) : $children;

        return $comp;
    }

    /**
     * An empty template (used by the media collection if a template contains no media loop)
     *
     * @return array
     */
    private function emptyTemplate(): array
    {
        return array('component' => '', 'type' => 'term', 'format' => '', 'fact' => '', 'subtemplate' => array());
    }

    /**
     * Write the export to a stream (called by ExportFamilyBook::handleForm)
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param resource $stream writable stream; it is rewound after the export
     * @return resource the stream
     */
    public function export(string $export_type, $stream)
    {
        fwrite($stream, pack("CCC", 0xef, 0xbb, 0xbf));

        if ($export_type === "graphml") {
            $this->exportGraphml($stream);
        } else {
            $this->exportLatex($stream);
        }

        if (rewind($stream) === false) {
            throw new RuntimeException('Cannot rewind temporary stream');
        }

        return $stream;
    }

    /**
     * Get the given name in a predefined format
     *
     * This module returns the given name in a format defined by $format.
     * Suppose the name is "Paul Micheal Patrick" then the full name is returned
     * if no format is defined.
     * If $format="1" then "Paul" is returned.
     * If $format="1,3" then "Paul Patrick" is returned.
     * If $format="1,." then "Paul M. P." is returned.
     * If $format="." then "P. M. P." is returned.
     * If $format="1,2." then "Paul M." is returned.
     *
     * @param Individual $record
     *        	record for an individual
     * @param string $format
     *        	The format of the given name. It is a comma separated
     *        	list of numbers where each number stands for one of the given names. If a dot "."
     *        	is given then all following given names are abbreviated.
     * @return string The given name
     */
    public function getGivenName($record, $format = "")
    {
        // first get the given name

        $tmp = $record->getAllNames();
        $pname = $record->getPrimaryName();
        $givn = $tmp[$record->getPrimaryName()]['givn'];

        // if $format is given then apply the format
        if ($givn and $format) {
            $exp_givn = explode(' ', $givn);
            $count_givn = count($exp_givn);

            $exp_format = explode(",", $format);
            $givn = "";

            // loop over all parts of the given name and check if it is
            // specified in the format
            for ($i = 0; $i < $count_givn; $i++) {
                $s = (string) $i + 1;
                if (in_array($s, $exp_format)) {
                    // given name to be included
                    $givn .= " " . $exp_givn[$i];
                } elseif (
                    in_array(".", $exp_format, true) or
                    in_array(($i . '.'), $exp_format, true)
                ) {
                    // - if "." is included in the format list then all parts of the name
                    // are included abbreviated
                    // - a given name is also included abbreviated if the positions is
                    // included in $format followed by "."
                    $givn .= " " . $exp_givn[$i][0] . ".";
                }
            }
        }

        // now replace unknown names with three dots
        $givn = str_replace(
            array(
                '@P.N.', '@N.N.'
            ),
            array(
                I18N::translateContext('Unknown given name', '…'),
                I18N::translateContext('Unknown surname', '…')
            ),
            trim($givn)
        );

        return $givn;
    }

    /**
     * Format a place
     *
     * Creates a string with a place where the format is defined by $format.
     * Suppose the place is "street, town, county, country".
     * if $format is not given the "street, town, county, country" is returned.
     * if $format="1" then the "street" is returned.
     * if $format="-1" then the "country" is returned.
     * if $format="2,-1" then the "town, country" is returned.
     * if $format="2,3,-1" then the "town, county, country" is returned.
     * if $format="2/town,3,-1/another_country/third_country" then the "county, country" is returned.
     * "/" is a separator which defines a list of names which are omitted.
     *
     * @param object $place
     *        	A place object
     * @param string $format
     *        	The hierarchy levels to be returned.
     * @return string
     */
    private function formatPlace($place, $format)
    {
        $place_ret = "";
        // get the name of the place object
        if (
            is_object($place) and
            get_class($place) == "Fisharebest\Webtrees\Place"
        ) {
            $place = $place->gedcomName();
        }
        if ($place) {
            // check if default format to be used
            if (!$format and array_key_exists("default_place_format", $this->settings)) {
                $format = $this->settings["default_place_format"];
            }

            if (!$format) {
                // use full place name if $format is not given
                $place_ret .= $place;
            } else {
                $format_place_level = explode(",", $format);
                $exp_place = explode(',', $place);
                $count_place = count($exp_place);

                // loop over format components
                foreach ($format_place_level as $s) {
                    // check if there are names to be omitted seperated by "/"
                    $sarray = explode("/", $s);
                    $i = (int) $sarray[0];
                    if (abs($i) <= $count_place && $i != 0) {
                        // the required hierarch level must exists
                        if ($i > 0) {
                            // hierarchy level counted from left
                            $sp = trim($exp_place[$i - 1]);
                        } else {
                            // hierarchy level counted from right
                            $sp = trim($exp_place[$count_place + $i]);
                        }
                        // check if name should be omitted
                        if (in_array($sp, $sarray))
                            $sp = "";

                        // add comma separator
                        if ($place_ret != "" & $sp != "")
                            $place_ret .= ", ";
                        $place_ret .= $sp;
                    }
                }
            }
        }
        return $place_ret;
    }

    /**
     * Format a date
     *
     * This module takes a date object and returns the date formatted as
     * defined by $format.
     *
     * @param Date $date        	
     * @param string $format
     *        	A standard PHP date format. Without format the default date format is used;
     *        	without default date format the date format of webtrees for the language of the user.
     * @return string
     */
    private function formatDate($date, $format)
    {
        if ($date instanceof Date) {
            // check if default format to be used
            if (!$format and array_key_exists("default_date_format", $this->settings)) {
                $format = $this->settings["default_date_format"];
            }

            $date_label = $this->cached(
                $date,
                'display|' . $format,
                // no format: null lets webtrees use its date format (I18N::dateFormat())
                fn () => strip_tags($date->display($this->tree, $format ? $format : null, false))
            );
        } else {
            $date_label = "";
        }
        return $date_label;
    }

    /**
     * Write date to output file
     *
     * This module write data to the outfile and takes care
     * of all required transformations.
     *
     * @param resource $gedout
     *        	Handle to a writable stream
     * @param string $buffer
     *        	The string to be written in the file
     */
    private function graphml_fwrite($gedout, $buffer)
    {
        $buffer_encoded = mb_convert_encoding($buffer, 'UTF-8');
        $bytes_written = fwrite($gedout, $buffer_encoded);

        if ($bytes_written !== strlen($buffer_encoded)) {
            throw new RuntimeException('Unable to write to stream.  Perhaps the disk is full?');
        }
    }

    /**
     * The portrait of an individual (all portrait placeholders, LaTeX and GraphML)
     *
     * The first picture file (order of the OBJE facts, then of the files of the media object) that is no
     * external file, is a picture (MediaFile::isImage) and has the media type "photo" or none (TYPE below
     * FORM, in GEDCOM 7 MEDI; upper or lower case). Other types (certificate, document, ...) are skipped,
     * so a certificate is never the portrait. Unlike Individual::findHighlightedMediaFile, which takes the
     * first picture of any type.
     *
     * @param Individual $record
     * @return \Fisharebest\Webtrees\MediaFile|null null if the individual has no such picture
     */
    private function portraitFile(Individual $record): ?\Fisharebest\Webtrees\MediaFile
    {
        foreach ($record->facts(['OBJE']) as $fact) {
            $media = $fact->target();
            if (!$media instanceof Media) {
                continue;
            }
            foreach ($media->mediaFiles() as $media_file) {
                if (
                    $media_file->isImage() && !$media_file->isExternal() &&
                    in_array(strtolower(trim($media_file->type())), array('photo', ''), true)
                ) {
                    return $media_file;
                }
            }
        }

        return null;
    }

    /**
     * Returns the portrait file name
     *
     * This module returns the file name of the portrait of an individual.
     *
     * @param Individual $record
     *        	The record of an idividual
     * @param string $format
     *        	If $format = "silhouette" then allways the fallback
     *        	picture is used. If $format = "fallback" then the portrait is used and only
     *        	if this is not defined the fallback picture is used.
     * @param bool $servername
     *        	If $servername = true then the server file name
     *        	including the path is returned.
     * @return string The file name of the portrait
     */
    private function getPortrait($record, $format, $servername = false)
    {
        $portrait_file = "";
        $portrait_fallback = "";

        // get the fallback picture
        // the name is defined in the report form
        if ($format == "silhouette" || $format == "fallback") {
            $sex = $record->sex();
            if ($sex == "F") {
                $s = 'female';
            } elseif ($sex == "M") {
                $s = 'male';
            } else {
                $s = 'unknown';
            }
            if (array_key_exists('default_portrait_' . $s, $this->settings)) {
                $portrait_fallback = $this->settings['default_portrait_' . $s];
            } else {
                $portrait_fallback = "";
            }
        }

        if ($format == "silhouette") {
            // return the fallback figure if $format == "silhouette"
            $portrait_file = $portrait_fallback;
        } else {
            $portrait = $this->portraitFile($record);
            if ($portrait) {
                $portrait_file = $portrait->filename();
                if ($servername) {
                    // get the full server name including path
                    $document_root = $this->server_params['DOCUMENT_ROOT'];
                    $script_name = $this->server_params['SCRIPT_NAME'];
                    $web_page = substr($script_name, 0, strrpos($script_name, '/'));
                    $portrait_file = $document_root . $web_page .
                        "/data/media/" . $portrait_file;
                } else {
                    // get the file name without full server path
                    $portrait_file = $portrait->filename();
                }
            }
            if ($format == "fallback" && $portrait_file == "")
                $portrait_file = $portrait_fallback;
        }

        return $portrait_file;
    }

    /**
     * Embed the portrait of an individual as icon of the node label (GraphML, @PortraitIcon@)
     *
     * yEd shows no "data:" pictures in HTML labels and keeps no paths of pictures; it stores pictures
     * as resources of the file and refers to them with iconData="..." in the node label (structure as
     * written by yEd 3.25.1, see graphmlFooter).
     *
     * The picture is made from the original file (EXIF orientation applied) and encoded once (JPEG
     * quality 92 or lossless PNG); the result is kept in the file cache of webtrees (data/cache/).
     * If webtrees adds a watermark for the current user, the picture is made from the watermarked
     * thumbnail of webtrees instead (see watermarkedPortrait).
     *
     * Format: @PortraitIcon&width&position&type&sharpness@
     *   width      width in pixels (default 100); yEd shows the icon in this size
     *   position   "top" (default: picture above the text) or "left" (picture left of the text)
     *   type       "jpg" (default, quality 92) or "png"
     *   sharpness  1 to 20 (default 8; other values: default): the picture is stored with sharpness times
     *              the pixels and shown in the same size (yed:ScaledIcon with the scale 1/sharpness), so it
     *              stays sharp when zooming or printing. It is never enlarged beyond the original: a smaller
     *              original is stored in its own size and scaled to the shown size.
     *
     * Nothing is embedded if the individual has no highlighted image, if the current user may not see
     * the media object, or if the file is missing, cannot be read or has an unsupported format.
     *
     * @param Individual $record
     * @param array $format
     * @return array{0: string, 1: int, 2: int, 3: string}|null resource id, shown width, shown height, position
     */
    private function portraitIcon(Individual $record, array $format): ?array
    {
        $width = (int) $this->get_format($format, 0);
        if ($width < 1 || $width > 2000) {
            $width = 100;
        }
        $position = $this->get_format($format, 1) === "left" ? "left" : "top";
        $png = strtolower($this->get_format($format, 2)) === "png";
        $sharpness = trim($this->get_format($format, 3));
        $sharpness = preg_match('/^([1-9]|1[0-9]|20)$/', $sharpness) === 1 ? (int) $sharpness : 8;

        $media_file = $this->portraitFile($record);
        if ($media_file === null || $media_file->isExternal() || !$media_file->media()->canShow()) {
            return null;
        }

        $key = $media_file->media()->xref() . '|' . $media_file->filename() . '|' . $width . '|' . (int) $png . '|' . $sharpness;
        if (!array_key_exists($key, $this->icon_cache)) {
            $this->icon_cache[$key] = null;
            try {
                // shown size: a portrait higher than 1.5 times its width is reduced to this height
                $height = (int) round($width * 1.5);
                $picture = Registry::imageFactory()->thumbnailNeedsWatermark($media_file, Auth::user())
                    ? $this->watermarkedPortrait($media_file, $width, $height, $sharpness, $png)
                    : $this->originalPortrait($media_file, $width, $height, $sharpness, $png);
                if (is_array($picture)) {
                    list($encoded, $pixel_width, $pixel_height, $w, $h, $scale) = $picture;
                    $number = count($this->icon_resources) + 1;
                    $this->icon_resources[$number] = array(base64_encode($encoded), $pixel_width, $pixel_height, $w, $h, $scale);
                    $this->icon_cache[$key] = array($this->iconResourceId($number), $w, $h);
                }
            } catch (Throwable $ex) {
                // missing or unreadable file: no icon
            }
        }
        if ($this->icon_cache[$key] === null) {
            return null;
        }

        return array($this->icon_cache[$key][0], $this->icon_cache[$key][1], $this->icon_cache[$key][2], $position);
    }

    // JPEG quality of the embedded portraits; lifetime of their cache entries (as webtrees thumbnails: 100 days)
    private const PORTRAIT_JPEG_QUALITY = 92;
    private const PORTRAIT_CACHE_TTL = 8640000;

    /**
     * Portrait made from the original file, cached in the file cache of webtrees (data/cache/)
     *
     * The key contains the file (tree, name, time of change, size), the size rules and the type, so a
     * changed file or other parameters give a new entry.
     *
     * @return array{0: string, 1: int, 2: int, 3: int, 4: int, 5: float}|null
     *         encoded picture, pixel width, pixel height, shown width, shown height, scale
     */
    private function originalPortrait(\Fisharebest\Webtrees\MediaFile $media_file, int $width, int $height, int $sharpness, bool $png): ?array
    {
        $filesystem = $media_file->media()->tree()->mediaFilesystem();
        $path = $media_file->filename();
        $key = implode('|', array(
            'ExportFamilyBook-portrait',
            $media_file->media()->tree()->name(),
            $path,
            (string) $filesystem->lastModified($path),
            (string) $filesystem->fileSize($path),
            (string) $width,
            (string) $height,
            (string) $sharpness,
            $png ? 'png' : 'jpg' . self::PORTRAIT_JPEG_QUALITY,
        ));

        $picture = Registry::cache()->file()->remember(
            $key,
            fn () => $this->encodePortrait($filesystem->read($path), $width, $height, $sharpness, $png) ?? false,
            self::PORTRAIT_CACHE_TTL
        );

        return is_array($picture) ? $picture : null;
    }

    /**
     * Reduce a picture to the size rules of @PortraitIcon@ and encode it once
     *
     * Shown size: the picture fits into width x height. Stored pixels: sharpness times the shown size,
     * but never more than the original; an original smaller than the shown size is enlarged to it (as
     * the thumbnails of webtrees). Transparent parts become white.
     *
     * @return array{0: string, 1: int, 2: int, 3: int, 4: int, 5: float}|null see originalPortrait
     */
    private function encodePortrait(string $data, int $width, int $height, int $sharpness, bool $png): ?array
    {
        $info = @getimagesizefromstring($data);
        $image = $info === false ? false : @imagecreatefromstring($data);
        if ($image === false) {
            return null;
        }
        if (($info[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
            $image = $this->orientImage($image, is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1);
        }
        unset($data);

        $original_width = imagesx($image);
        $original_height = imagesy($image);
        $fit = min($width / $original_width, $height / $original_height);
        $w = max(1, (int) round($original_width * $fit));
        $h = max(1, (int) round($original_height * $fit));
        if ($fit >= 1) {
            // original not larger than the shown size: enlarged to it
            list($pixel_width, $pixel_height, $scale) = array($w, $h, 1.0);
        } elseif ($fit * $sharpness >= 1) {
            // never enlarged: the original in its own size, scaled to the shown size
            list($pixel_width, $pixel_height, $scale) = array($original_width, $original_height, $fit);
        } else {
            $pixel_width = max(1, (int) round($original_width * $fit * $sharpness));
            $pixel_height = max(1, (int) round($original_height * $fit * $sharpness));
            $scale = 1 / $sharpness;
        }

        $canvas = imagecreatetruecolor($pixel_width, $pixel_height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $pixel_width, $pixel_height, $original_width, $original_height);
        unset($image);
        ob_start();
        $png ? imagepng($canvas, null, 9) : imagejpeg($canvas, null, self::PORTRAIT_JPEG_QUALITY);
        $encoded = (string) ob_get_clean();
        unset($canvas);

        return array($encoded, $pixel_width, $pixel_height, $w, $h, $scale);
    }

    /**
     * Turn a GD image as given by the EXIF orientation (1 to 8) of the file
     */
    private function orientImage(\GdImage $image, int $orientation): \GdImage
    {
        // rotation angle (counterclockwise, as imagerotate) and mirroring after the rotation
        $turns = array(2 => array(0, true), 3 => array(180, false), 4 => array(180, true), 5 => array(270, true),
            6 => array(270, false), 7 => array(90, true), 8 => array(90, false));
        if (!isset($turns[$orientation])) {
            return $image;
        }
        list($angle, $mirror) = $turns[$orientation];
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated === false) {
                return $image;
            }
            $image = $rotated;
        }
        if ($mirror) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /**
     * Portrait made from the watermarked thumbnails of webtrees (when webtrees adds a watermark for the
     * current user): same sizes as originalPortrait, the thumbnail of webtrees is encoded again
     *
     * @return array{0: string, 1: int, 2: int, 3: int, 4: int, 5: float}|null see originalPortrait
     */
    private function watermarkedPortrait(\Fisharebest\Webtrees\MediaFile $media_file, int $width, int $height, int $sharpness, bool $png): ?array
    {
        $shown = $this->portraitThumbnail($media_file, $width, $height);
        if ($shown === null) {
            return null;
        }
        $stored = $shown;
        $scale = 1.0;
        $original = $sharpness > 1 ? $this->originalImageSize($media_file) : null;
        // only an original larger than the shown size gives more pixels
        if ($original !== null && ($original[0] > imagesx($shown) || $original[1] > imagesy($shown))) {
            $fit = min($width / $original[0], $height / $original[1]);
            if ($fit * $sharpness >= 1) {
                $stored = $this->portraitThumbnail($media_file, $original[0], $original[1]);
                $scale = $fit;
            } else {
                $stored = $this->portraitThumbnail($media_file, $width * $sharpness, (int) round($width * $sharpness * 1.5));
                $scale = 1 / $sharpness;
            }
            if ($stored === null) {
                $stored = $shown;
                $scale = 1.0;
            }
        }
        ob_start();
        $png ? imagepng($stored, null, 9) : imagejpeg($stored, null, self::PORTRAIT_JPEG_QUALITY);
        $encoded = (string) ob_get_clean();

        return array($encoded, imagesx($stored), imagesy($stored), imagesx($shown), imagesy($shown), $scale);
    }

    /**
     * Picture of a media file reduced (or enlarged) by webtrees to fit into width x height (as for the
     * thumbnails of the web pages, incl. its cache and watermark rules); transparent parts become white
     *
     * @return \GdImage|null
     */
    private function portraitThumbnail(\Fisharebest\Webtrees\MediaFile $media_file, int $width, int $height): ?\GdImage
    {
        $factory = Registry::imageFactory();
        $data = $factory->mediaFileThumbnail(
            $media_file,
            $width,
            $height,
            'contain',
            $factory->thumbnailNeedsWatermark($media_file, Auth::user())
        );
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return null;
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);
        unset($image);

        return $canvas;
    }

    /**
     * Size of the original picture of a media file as webtrees shows it (EXIF orientation applied)
     *
     * @return array{0: int, 1: int}|null width, height
     */
    private function originalImageSize(\Fisharebest\Webtrees\MediaFile $media_file): ?array
    {
        try {
            $filesystem = $media_file->media()->tree()->mediaFilesystem();
            $data = $filesystem->read($media_file->filename());
        } catch (Throwable $ex) {
            return null;
        }
        $size = @getimagesizefromstring($data);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }
        list($w, $h) = $size;
        if (function_exists('exif_read_data') && ($size[2] ?? 0) === IMAGETYPE_JPEG) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
            // orientations 5 to 8: the picture is turned by 90 degrees
            if (is_array($exif) && in_array((int) ($exif['Orientation'] ?? 1), array(5, 6, 7, 8), true)) {
                list($w, $h) = array($h, $w);
            }
        }

        return array($w, $h);
    }

    /**
     * Get portrait size
     *
     * This module returns the height or width a portrait must have
     * to preserve the aspect ratio given a pre-defined width or height.
     * A width is defined when $format[0] starts with a "w" followed by the width.
     * A height is defined when $format[0] starts with a "h" followed by the height.
     *
     * If $format is of length 2 then the second array element contains a default
     * size. This is used for fallback figures.
     *
     * @param Individual $record        	
     * @param array $format        	
     * @return string
     */
    private function getPortraitSize($record, $format)
    {
        // get portrait file
        $format_Size = $format[0];
        if (count($format) > 1) {
            $format_default = $format[1];
        } else {
            $format_default = "";
        }

        $portrait_file = $this->getPortrait($record, "", true);
        $image_length = $format_default;

        // a missing file or a file that is no picture: as an individual without portrait (default value)
        $image_size = false;
        if ($portrait_file != "" && strlen($format_Size) > 1 && is_file($portrait_file)) {
            $image_size = @getimagesize($portrait_file);
        }

        if ($image_size !== false) {
            $constraint = $format_Size[0];
            $size_constraint = (float) substr($format_Size, 1);
            $width = $image_size[0];
            $height = $image_size[1];

            if ($constraint == "w") {
                // constraint is the width, get the height
                if ($width != 0)
                    $image_length = (int) ($height * $size_constraint / $width);
            } else {
                // constraint is the height, get the width
                if ($height != 0)
                    $image_length = (int) ($width * $size_constraint / $height);
            }
        }

        return $image_length;
    }

    /**
     * Get facts
     *
     * This module return a list of facts for an individual or family. All facts are
     * are of gedcom type defined by $fact. E.g. $fact = "OCCU" selects occupations.
     * The $format parameter defines which facts are returned, e.g.
     * $format=-1 means that the last fact with identifier $fact from the
     * ordered fact list will be returned. Doublets in the fact list are removed
     * automatically.
     *
     * @param Individual $record
     *        	The record for which the facts are returned
     * @param string $fact
     *        	The gedcom identifier of the fact, e.g. "OCCU"
     * @param string $format
     *        	A list of positions in the ordered fact list which are returned;
     *        	without format all facts (empty values left out)
     * @return string A comma separted list of facts
     */
    private function getFact($record, $fact, $format = null)
    {
        // get all facts with identifier $fact as ordered array
        $fact_string = "";
        $Facts = $this->recordFacts($record, (array) $fact, true);
        if ($Facts) {
            if (empty($format)) {
                // if $format is not given return all items like the list of all positions:
                // separated by ", ", a value only once, empty values left out
                $fact_list = array();
                foreach ($Facts as $Fact) {
                    $fact_value = trim($Fact->Value());
                    if ($fact_value !== "" && !in_array($fact_value, $fact_list)) {
                        if ($fact_string != "")
                            $fact_string .= ", ";
                        $fact_list[] = $fact_value;
                        $fact_string .= $fact_value;
                    }
                }
            } else {
                // selects the items from the fact array as defined
                // in the $format parameter
                $exp_format = explode(",", $format);
                $count_facts = count($Facts);
                // fact list is used to avoid having facts twice
                $fact_list = array();
                // loop over all components of $format
                foreach ($exp_format as $s) {
                    $i = (int) $s;
                    // check if item position exists
                    if (abs($i) <= $count_facts && $i != 0) {
                        if ($i > 0) {
                            $j = $i - 1;
                        } else {
                            // if position is negativ count from the end
                            $j = $count_facts + $i;
                        }
                        $fact_value = trim($Facts[$j]->Value());
                        if (!in_array($fact_value, $fact_list)) {
                            // add a separator
                            if ($fact_string != "")
                                $fact_string .= ", ";
                            $fact_list[] = $fact_value;
                            $fact_string .= $fact_value;
                        }
                    }
                }
            }
        }

        return trim($fact_string);
    }

    /**
     * Return the header for the graphml file
     *
     * This module returns the header of the graphml file
     *
     * @return String The header of the graphml file
     */
    private function graphmlHeader(bool $icons = false)
    {
        // with embedded portraits (@PortraitIcon@) all name spaces of a file written by yEd 3.25.1,
        // the icon resources use the prefix "yed"
        $namespaces = $icons ?
            'xmlns="http://graphml.graphdrawing.org/xmlns" xmlns:java="http://www.yworks.com/xml/yfiles-common/1.0/java" xmlns:sys="http://www.yworks.com/xml/yfiles-common/markup/primitives/2.0" xmlns:x="http://www.yworks.com/xml/yfiles-common/markup/2.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:y="http://www.yworks.com/xml/graphml" xmlns:yed="http://www.yworks.com/xml/yed/3"' :
            'xmlns="http://graphml.graphdrawing.org/xmlns" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:y="http://www.yworks.com/xml/graphml"';

        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n" .
            '<graphml ' . $namespaces . ' xsi:schemaLocation="http://graphml.graphdrawing.org/xmlns http://www.yworks.com/xml/schema/graphml/1.1/ygraphml.xsd">' .
            "\n" . '<!--Created by Webtrees -->' . "\n" .
            '<key for="graphml" id="d0" yfiles.type="resources"/>' . "\n" .
            '<key for="node" id="d1" attr.name="url" attr.type="string"/>' .
            "\n" .
            '<key for="node" id="d2" attr.name="description" attr.type="string"/>' .
            "\n" . '<key for="node" id="d3" yfiles.type="nodegraphics"/>' .
            "\n" .
            '<key for="edge" id="d4" attr.name="url" attr.type="string"/>' .
            "\n" .
            '<key for="edge" id="d5" attr.name="description" attr.type="string"/>' .
            "\n" . '<key for="edge" id="d6" yfiles.type="edgegraphics"/>' .
            "\n" . "\n" . '<graph edgedefault="directed" id="G">' . "\n";
    }

    /**
     * Return GEDCOM label
     *
     * Returns the label of a GEDCOM tag, husband for HSB 
     *
     * @return String
     */
    private function getGEDCOMTagLabel(string $tag, ?string $record_type = null)
    {
        $cache_key = ($record_type ?? "\0") . '|' . $tag;
        if (!array_key_exists($cache_key, $this->tag_label_cache)) {
            $this->tag_label_cache[$cache_key] = $this->findGEDCOMTagLabel($tag, $record_type);
        }

        return $this->tag_label_cache[$cache_key];
    }

    private function findGEDCOMTagLabel(string $tag, ?string $record_type)
    {
        if (!is_null($record_type)) $tag = $record_type . ':' . $tag;
        $element = Registry::elementFactory()->make($tag);
        if ($element instanceof UnknownElement) {
            $element = Registry::elementFactory()->make('INDI:' . $tag);
        };

        if ($element instanceof UnknownElement) {
            $label = null;
        } else {
            $label = $element->label();
        }

        return $label;
    }

    /**
     * Increases the time limit
     *
     * This module estimates the processing time and 
     * increases the php time limit accordingly 
     *
     * @return Boolean
     */
    private function increaseTimeLimit()
    {
        static $time_counter = 0;
        static $time_start;

        if (empty($time_start)) $time_start = microtime(true);

        $time_counter++;
        if ($time_counter == 100) {
            $time_med = (microtime(true) - $time_start) * 1000;

            set_time_limit(intval($time_med));

            $time_counter = 0;
            $time_start = microtime(true);

            return true;
        }
        return false;
    }

    /**
     * Return the footer for the graphml file
     *
     * This module returns the footer of the graphml file
     *
     * @return String The footer of the graphml file
     */
    private function graphmlFooter()
    {
        if ($this->icon_resources === array()) {
            return '<data key="d0"> <y:Resources/> </data>' . "\n" .
                '</graph> </graphml>';
        }

        // embedded portraits (@PortraitIcon@) as written by yEd 3.25.1: the resources are a child of
        // <graphml> after </graph>; per picture an icon resource (referred to by iconData of the label)
        // pointing to the picture resource (base64 in lines of 76 characters); yed:ScaledIcon has the
        // shown size and the scale, yed:ImageIcon the size of the picture in pixels
        $resources = '';
        foreach ($this->icon_resources as $number => list($data, $width, $height, $shown_width, $shown_height, $scale)) {
            // scale with a point as decimal separator (independent of the locale), e.g. "1.0", "0.5"
            $scale = rtrim(rtrim(sprintf('%.6F', $scale), '0'), '.');
            $scale .= str_contains($scale, '.') ? '' : '.0';
            $resources .= '<y:Resource id="' . $this->iconResourceId($number) . '">' . "\n" .
                '<yed:ScaledIcon height="' . $shown_height . '" width="' . $shown_width . '" xScale="' . $scale . '" yScale="' . $scale . '">' . "\n" .
                '<yed:ImageIcon height="' . $height . '" image="' . $this->imageResourceId($number) . '" width="' . $width . '"/>' . "\n" .
                '</yed:ScaledIcon>' . "\n" .
                '</y:Resource>' . "\n" .
                '<y:Resource id="' . $this->imageResourceId($number) . '" type="java.awt.image.BufferedImage" xml:space="preserve">' .
                substr(chunk_split($data, 76, "&#13;\n"), 0, -strlen("&#13;\n")) . '</y:Resource>' . "\n";
        }

        return '</graph>' . "\n" .
            '<data key="d0"> <y:Resources>' . "\n" . $resources . '</y:Resources> </data>' . "\n" .
            '</graphml>';
    }

    /**
     * Ids of the resources of the embedded portraits (see graphmlFooter): the icon resource of picture
     * n has the id 2n-1, the picture resource 2n. The file has no other resources, so the ids are unique.
     */
    private function iconResourceId(int $number): string
    {
        return (string) (2 * $number - 1);
    }

    private function imageResourceId(int $number): string
    {
        return (string) (2 * $number);
    }

    /**
     * Does a split template (see splitTemplate) contain the tag, also within terms and loops?
     */
    private function templateHasTag(array $components, string $tag): bool
    {
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            if (!isset($component['type'])) {
                // content of a loop: a list of components
                if ($this->templateHasTag($component, $tag)) {
                    return true;
                }
                continue;
            }
            if ($component['type'] === 'tag' && $component['component'] === $tag) {
                return true;
            }
            if (is_array($component['subtemplate']) && $this->templateHasTag($component['subtemplate'], $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split template into components
     *
     * This module takes a template entered in the web form and converts it
     * to a list stored in an array. The components of the list are
     * either strings or tags with formats. Tags are supposed to be replaced
     * by gedcom data during export.
     *
     * @param String $template
     *        	The template to be decomposed.
     * @return Array Each element of the array is itself an array.
     *         Each of these arrays consist of 4 elements.
     *         Element "type" defines if the template component is a string ("string")
     *         or a tag ("tag"). The "component" contains either the string or the tag
     *         name. "format" contains a format array and "fact" the gedcom fact identifier
     *         in case the tag is "Fact".
     */
    private function splitTemplate($template)
    {

        // check that the template has at least four characters
        if (strlen($template) > 4) {
            // extract the symbols identifying tags and format descriptions
            $bracket_array = array(
                $template[0], $template[1]
            );

            $tag = $template[2];
            $format = $template[3];

            // remove line breaks
            $template = preg_replace("/([^\s])\s*[\n\r]\s*([^\s])/", "$1 $2", $template);
            // remove space between double brackets
            foreach ($bracket_array as $bra) {
                $template = preg_replace(
                    "/\Q" . $bra . "\E +\Q" . $bra . "\E/",
                    $bra . $bra,
                    $template
                );
            }

            $template_array = array();
            $i = 1;
            $pos_end = 3;
            $pos = 0;

            // now split the template searching for the next tag symbol or bracket
            // $pos is the position of the next tag symbol or bracket
            // $pos_end is the position of the last tag
            while ($pos_end < strlen($template)) {
                $pos = strpos($template, $tag, $pos_end + 1);
                $pos_bracket = strpos($template, $bracket_array[0], $pos_end + 1);

                if ($pos === false && $pos_bracket === false) {
                    // no tag symbol or bracket found
                    if ($pos_end + 1 < strlen($template)) {
                        // terminating string at the end of the template
                        // add the string to the return array
                        // substring {...} are removed
                        $template_array[$i] = array(
                            "component" => $this->removeBrackets(
                                substr($template, $pos_end + 1),
                                $bracket_array
                            ), "type" => "string",
                            "format" => "", "fact" => "", "subtemplate" => ""
                        );
                        $i++;
                    }
                    $pos_end = strlen($template);
                } elseif ((($pos_bracket === false) || ($pos_bracket > $pos_end + 1)) &&
                    (($pos === false) || ($pos > $pos_end + 1))
                ) {

                    // there is a string preceeding the tag of next bracket
                    if ($pos_bracket && $pos) {
                        $pos = min($pos, $pos_bracket);
                    } elseif ($pos_bracket) {
                        $pos = $pos_bracket;
                    }

                    if ($pos > $pos_end + 1) {
                        // there is a string preceeding the tag symbol
                        // add the string to the return array
                        // substring {...} are removed
                        $template_array[$i] = array(
                            "component" => $this->removeBrackets(
                                substr(
                                    $template,
                                    $pos_end + 1,
                                    $pos - $pos_end - 1
                                ),
                                $bracket_array
                            ), "type" => "string",
                            "format" => "", "fact" => "", "subtemplate" => ""
                        );
                        $i++;
                    }
                    // last character of the string component
                    $pos_end = $pos - 1;
                } elseif (($pos_bracket !== false) && (
                    ($pos === false) || ($pos_bracket < $pos))) {
                    // bracket comes befor tag symbol
                    $pos = $pos_bracket;
                    $pos_bracket_end = $this->findRightMatch($template, $pos_bracket, $bracket_array[0], $bracket_array[1]);

                    if ($pos_bracket_end !== false) {
                        $subTemplate = substr($template, 0, 4) .
                            substr(
                                $template,
                                $pos_bracket + 1,
                                $pos_bracket_end - $pos_bracket - 1
                            );
                        $template_array[$i] = $this->splitTemplate($subTemplate)[0];
                        // set position end to the end of bracket term
                        $pos_end = $pos_bracket_end;
                        $i++;
                    } else {
                        throw new ExportFamilyBookTemplateException(I18N::translate(
                            'Error in the template: the bracket “%1$s” is not closed: %2$s',
                            $bracket_array[0],
                            $this->templateExcerpt($template, $pos_bracket)
                        ));
                    }
                } elseif ($pos !== false) {
                    // there is a remaining tag symbol
                    // now the tag is added to the return array
                    // search for the end of the tag
                    $pos_end = strpos($template, $tag, $pos + 1);

                    // check for a format string
                    $pos_format = strpos($template, $format, $pos);

                    if ($pos_format < $pos_end && $pos_format !== false) {
                        // a format definition exists, split it
                        $format_array = explode(
                            $format,
                            substr(
                                $template,
                                $pos_format + 1,
                                $pos_end - $pos_format - 1
                            )
                        );
                    } else {
                        $format_array = array("");
                        $pos_format = $pos_end;
                    }
                    $component = substr(
                        $template,
                        $pos + 1,
                        $pos_format - $pos - 1
                    );

                    if ($pos_end !== false) {
                        if (substr($template, $pos, 8) === "@Foreach") {
                            // this a a foreach loop
                            // search the end of the loop

                            $pos_EndForeach = $this->findRightMatch($template, $pos, "@Foreach", "@EndForeach@");

                            if ($pos_EndForeach !== false) {
                                $subTemplate = substr($template, 0, 4) .
                                    substr(
                                        $template,
                                        $pos_end + 1,
                                        $pos_EndForeach - $pos_end - 1
                                    );
                                // add the tag to the return array
                                $subTemplateSplit = $this->splitTemplate(
                                    $subTemplate
                                )[0];
                                $template_array[$i] = array(
                                    "component" => $component,
                                    "type" => "foreach",
                                    "format" => $format_array,
                                    "fact" => "",
                                    "subtemplate" => array($subTemplateSplit)
                                );
                                // set position end to the end of @EndForeach@
                                $pos_end = $pos_EndForeach + 11;
                                $i++;
                            } else {
                                // no matching @EndForeach@
                                throw new ExportFamilyBookTemplateException(I18N::translate(
                                    'Error in the template: the loop “%1$s” has no matching %2$s: %3$s',
                                    $component,
                                    '@EndForeach@',
                                    $this->templateExcerpt($template, $pos)
                                ));
                            }
                        } else {
                            // this is a single tag
                            // get the format definition
                            if (substr($component, 0, 4) !== "Fact") {
                                $template_array[$i] = array(
                                    "component" => $component,
                                    "type" => "tag",
                                    "format" => $format_array,
                                    "fact" => "",
                                    "subtemplate" => ""
                                );
                                // @Id@ directly in "\label{": the label of the individual (see $entry_has_label)
                                if ($component === "Id" && preg_match('/\\\\label\s*\{\s*$/', substr($template, 0, $pos))) {
                                    $template_array[$i]["in_label"] = true;
                                }
                            } else {
                                $template_array[$i] = array(
                                    "component" => "Fact",
                                    "type" => "tag",
                                    "format" => $format_array,
                                    "fact" => substr($component, 4),                                                                            "subtemplate" => ""
                                );
                            };
                            $i++;
                        }
                    }
                } else {
                    $pos_end = strlen($template);
                }
            }

        } else {
            // template is shorter than 4 letters and has no definition of special symbols
            if (strlen($template) > 1) {
                // extract the symbols identifying tags and format descriptions
                $bracket_array = array($template[0], $template[1]);
            } else {
                $bracket_array = array();
            }
            $template_array = array();
        }

        // put everything in a term object thus it is removered when there is no
        // tag resolved
        $template_array  = array(
            "component" => "",
            "type" => "term",
            "format" => "",
            "fact" => "",
            "subtemplate" => $template_array
        );

        return array(
            $template_array, $bracket_array
        );
    }

    /**
     * A short part of a template for an error message
     *
     * @param string $template
     * @param int $pos position of the error
     * @return string the template from $pos on, at most 60 characters, line breaks as spaces
     */
    private function templateExcerpt(string $template, int $pos): string
    {
        $excerpt = preg_replace('/\s+/', ' ', substr($template, $pos));
        if (mb_strlen($excerpt) > 60) {
            $excerpt = mb_substr($excerpt, 0, 60) . '…';
        }

        return $excerpt;
    }

    /**
     * Error for a placeholder or loop of a template that does not exist
     *
     * @param string $component name of the placeholder or loop
     * @return ExportFamilyBookTemplateException
     */
    private function unknownPlaceholder(string $component): ExportFamilyBookTemplateException
    {
        return new ExportFamilyBookTemplateException(I18N::translate(
            'Error in the template: the placeholder “%s” does not exist.',
            '@' . $component . '@'
        ));
    }

    /**
     * find match to a left tag
     *
     * This module searches in a string to a right tag match given
     * the left tag at position $pos.
     *
     * @param String $string
     *        	The string where the tag should be searched.
     * @param Integer $pos_left
     *        	The position of the left tag in $string.
     * @param String $left_tag
     *        	The $left tag, e.g. "<"
     * @param String $right_tag
     *        	The right $tag, e.g. ">"
     * @return Integer Position of the matching right tag.
     */
    private function findRightMatch($string, $pos_left, $left_tag, $right_tag)
    {
        $len_left_tag = strlen($left_tag);
        $len_right_tag = strlen($right_tag);

        $pos_right = false;
        if ($pos_left !== false) {
            $counter = 1;
            $pos = $pos_left + $len_left_tag;
            while ($counter !== 0) {
                $pos_left_next = strpos($string, $left_tag, $pos);
                $pos_right = strpos($string, $right_tag, $pos);
                if ($pos_left_next !== false and $pos_right !== false and $pos_left_next < $pos_right) {
                    $counter = $counter + 1;
                    $pos = $pos_left_next + $len_left_tag;
                } else if ($pos_right !== false) {
                    $counter = $counter - 1;
                    $pos = $pos_right + $len_right_tag;
                } else {
                    $counter = 0;
                    $pos_right = false;
                }
            }
        }

        return ($pos_right);
    }

    /**
     * Remove brackets
     *
     * This module removes substring {...} within a string.
     *
     * @param string $subject
     *        	the input string where brackets should be
     *        	removed.
     * @return string The input string where brackets are removed.
     */
    private function removeBrackets($subject, $brackets)
    {
        $count = 1;
        // take into account that there might be multiple brackets.
        if (!is_null($brackets) and count($brackets) == 2) {
            while ($count > 0 and strlen($subject) > 0) {
                // use regular expressions to remove brackets
                $subject = preg_replace(
                    "/\Q" . $brackets[0] . "\E[^\Q" . $brackets[0] .
                        $brackets[1] . "\E]*\Q" . $brackets[1] . "\E/",
                    "",
                    $subject,
                    -1,
                    $count
                );
            }
        }
        return $subject;
    }
    /**
     * Get the value of level 2 data in the fact
     *
     * @param Fact $fact
     * @param string $tag
     * @param int $level
     *
     * @return string|null
     */
    private function getAllAttributes($fact, $tag, $level = 2)
    {
        $gedcom = $fact->gedcom();
        if (preg_match_all('/\n' . $level . ' (?:' . $tag . ') ?(.*(?:(?:\n3 CONT ?.*)*)*)/', $gedcom, $match)) {
            return preg_replace("/\n' . ($level +1 ) . ' CONT ?/", "\n", $match[1]);
        } else {
            return null;
        }
    }

    /**
     * Get information about adoption fact and a given family
     *
     * @param Fact $fact
     * @param Family|null $FAMC
     *
     * @return string|null
     */
    private function getADOP($fact, $FAMC = null)
    {
        $res = null;

        $adopt_FAMC = trim($fact->attribute("FAMC"), "@");
        $ADOP = $this->getAllAttributes($fact, "ADOP", 3);

        if (!is_null($ADOP)) {
            if ($ADOP[0] === "BOTH") {
                $res = "stepparents";
            } elseif ($ADOP[0] === "HUSB") {
                $res = "stepfather";
            } elseif ($ADOP[0] === "WIFE") {
                $res = "stepmother";
            }

            if (!is_null($FAMC)) {
                if ($adopt_FAMC !== $FAMC->xref()) {
                    $res = null;
                }
            }
        }

        return ($res);
    }

    /**
     * Check if a child is adopted in a family
     *
     * @param Individual|null $child child record
     * @param Family $FAMC family the child belongs to
     * @param Individual|null $parent parent of the child
     * @return string Information about the adoption
     */
    private function checkADOP($child, $FAMC, $parent = null)
    {

        if (is_null($child)) return ("");

        $is_adopted = "";
        $ADOPs = $this->recordFacts($child, ['ADOP'], true);
        foreach ($ADOPs as $fact) {

            $adopt_ADOP = $this->getADOP($fact, $FAMC);
            if (!is_null($adopt_ADOP)) {
                if (is_null($parent)) {
                    if ($adopt_ADOP !== "") $is_adopted = $adopt_ADOP;
                } else {
                    if (
                        $adopt_ADOP === "stepparents" ||
                        ($parent->sex() === "F" && $adopt_ADOP === "stepmother") ||
                        ($parent->sex() === "M" && $adopt_ADOP === "stepfather")
                    ) {
                        $is_adopted = $adopt_ADOP;
                    } elseif ($adopt_ADOP !== "") {
                        $is_adopted = "by_spouse";
                    }
                }
            }
        }
        return ($is_adopted);
    }

    /**
     * Returns a format element or ""
     *
     * This module returns a format element or "".
     *
     * @param array<string> $format Format array
     * @param integer $i Array element
     * @return string element or empty string
     */
    private function get_format($format, $i)
    {
        if (count($format) > $i) {
            return $format[$i];
        } else {
            return "";
        }
    }

    /**
     * Returns a value of a record from the cache or computes it
     *
     * webtrees filters, sorts and checks the privacy of facts on every call
     * of facts(), childFamilies() etc. The templates ask for the same data
     * many times, so the results are kept for the duration of the export.
     *
     * @param GedcomRecord $record
     * @param string $key Cache key, unique per record
     * @param callable $compute Computes the value if it is not cached
     * @return mixed
     */
    private function cached($record, $key, $compute)
    {
        $this->record_cache ??= new WeakMap();
        $values = $this->record_cache[$record] ?? array();
        if (!array_key_exists($key, $values)) {
            $values[$key] = $compute();
            $this->record_cache[$record] = $values;
        }
        return $values[$key];
    }

    /**
     * Cached version of GedcomRecord::facts()
     */
    private function recordFacts($record, array $filter = [], bool $sort = false)
    {
        return $this->cached(
            $record,
            'facts|' . implode(',', $filter) . '|' . (int) $sort,
            fn () => $record->facts($filter, $sort)
        );
    }

    /**
     * Cached version of a getter without parameters, e.g. getBirthDate()
     */
    private function recordValue($record, string $method)
    {
        return $this->cached($record, $method, fn () => $record->$method());
    }

    /**
     * Cached version of Individual::childFamilies()
     */
    private function childFamilies($individual)
    {
        return $this->cached($individual, 'childFamilies', fn () => $individual->childFamilies());
    }

    /**
     * Cached version of Individual::spouseFamilies()
     */
    private function spouseFamilies($individual)
    {
        return $this->cached($individual, 'spouseFamilies', fn () => $individual->spouseFamilies());
    }

    /**
     * Can the individual be exported?
     *
     * Only individuals the current user may see in webtrees are exported, neither as
     * an entry nor as a relative of another individual. Family::husband(), wife() and
     * children() are not sufficient: they also return hidden individuals if their
     * names may be shown (SHOW_LIVING_NAMES) or if SHOW_PRIVATE_RELATIONSHIPS is set.
     * Facts are filtered by webtrees itself in GedcomRecord::facts().
     */
    private function canExport($individual): bool
    {
        return $individual instanceof Individual && $individual->canShow();
    }

    /**
     * Husband of a family, null if he may not be exported
     */
    private function husband($family)
    {
        return $this->cached($family, 'husband', function () use ($family) {
            $husband = $family->husband();
            return $this->canExport($husband) ? $husband : null;
        });
    }

    /**
     * Wife (or second partner) of a family, null if she may not be exported
     */
    private function wife($family)
    {
        return $this->cached($family, 'wife', function () use ($family) {
            $wife = $family->wife();
            return $this->canExport($wife) ? $wife : null;
        });
    }

    /**
     * Partner of $individual in $family, null if there is none or if it may not be exported
     */
    private function spouse($family, $individual)
    {
        // without privacy filter, otherwise a hidden husband would make the wife her own spouse
        $spouse = $family->husband(Auth::PRIV_HIDE);
        if (is_null($spouse) or $individual->xref() == $spouse->xref()) {
            $spouse = $family->wife(Auth::PRIV_HIDE);
        }
        return $this->canExport($spouse) ? $spouse : null;
    }

    /**
     * Children of a family which may be exported
     */
    private function children($family)
    {
        return $this->cached(
            $family,
            'children',
            fn () => $family->children()->filter(fn ($child) => $this->canExport($child))->values()
        );
    }

    /**
     * Can the source with the given xref ("@S1@") be shown? Inline sources are always shown.
     */
    private function canShowSource(string $source): bool
    {
        if (!preg_match('/^@(' . Gedcom::REGEX_XREF . ')@$/', $source, $match)) {
            return true;
        }
        $record = Registry::sourceFactory()->make($match[1], $this->tree);
        return $record === null || $record->canShow();
    }

    /**
     * Substitute place holder in template for individuals
     *
     * This module substitutes place holders in templates for individuals.
     *
     * @param GedcomRecord|Fact|MediaFile|string $record
     *        	The record for which the template should be filled (Individual, Family, a fact or
     *        	media file in a loop, ...)
     * @param GedcomRecord|Fact $record_context
     *        	The context record, e.g. the individual for which the FAMS should be taken.
     * @param string $template
     *        	The template with place holders to be replaced
     * @param array $brackets
     *        	Left and right brackets to define tag block
     * @param array<string,string> $fact_symbols
     *        	List of symbols to the used for facts
     * @param integer $counter
     *        	Counter of foreach loop
     * @param string $fact_type
     *        	Fact type within a foreach fact loop
     * @return string The template with place holders replaced
     */
    private function substitutePlaceHolder(
        $record,
        $record_context,
        $ind_id,
        $template,
        $doctype,
        $brackets = array("{", "}"),
        $fact_symbols = array(),
        $counter = 0,
        $fact_type = "",
        $name_key = ""
    ) {
        if (!isset($this->media_included)) $this->media_included = array();

        $FAMC = array();
        if ($record instanceof Individual) {
            $FAMC = $this->childFamilies($record);
        } else if ($record instanceof Family) {
            $FAMC = array($record);
        }
        /*
		 * replace tags in the template with data
		 *
		 * Algorithm:
		 * - loop over all template components
		 * - replace tags
		 */
        $nodetext = '';
        $tag_resolved = FALSE;
        if ($template) {
            // loop over all template components
            foreach ($template as $comp) {
                $tag_replacement = "";
                $empty_tag_found = false;
                $format = $comp["format"];

                if ($comp["type"] == "term") {
                    // this is a part of the template in brackets to be removed if there is no tag in it1
                    list($term_tag_replacement, $term_tag_resolved) = $this->substitutePlaceHolder(
                        $record,
                        $record_context,
                        $ind_id,
                        $comp["subtemplate"],
                        $doctype,
                        $brackets,
                        $fact_symbols,
                        $counter,
                        $fact_type,
                        $name_key
                    );
                    if ($term_tag_resolved) {
                        $nodetext .= $term_tag_replacement;
                        // set tag as resolved only in case there are no double brackets
                        // note that $comp ["subtemplate"][1] is the first element in the array 
                        if (
                            sizeof($comp["subtemplate"]) > 1 ||
                            $comp["subtemplate"][1]["type"] !== "term"
                        ) {
                            $tag_resolved = TRUE;
                        }
                    }
                } elseif ($comp["type"] == "string") {
                    // element is a string, add it to $new_string
                    $nodetext .= $comp["component"];
                } elseif ($comp["type"] == "tag") {
                    // element is a tag, get the tag data

                    switch ($comp["component"]) {
                        case "GivenName":
                        case "FatherGivenName":
                        case "MotherGivenName":
                        case "SpouseGivenName":
                        case "SurName":
                        case "FatherSurName":
                        case "MotherSurName":
                        case "SpouseSurName":
                            $rec = null;
                            switch ($comp["component"]) {
                                case "GivenName":
                                case "SurName":
                                    $rec = $record;
                                    break;
                                case "FatherGivenName":
                                case "FatherSurName":
                                    if (count($FAMC) > 0) {
                                        $rec = $this->husband($FAMC[0]);
                                    }
                                    break;
                                case "MotherGivenName":
                                case "MotherSurName":
                                    if (count($FAMC) > 0) {
                                        $rec = $this->wife($FAMC[0]);
                                    }
                                    break;
                                case "SpouseGivenName":
                                case "SpouseSurName":
                                    $rec = $this->spouse($record, $record_context);
                                    break;
                            }
                            switch ($comp["component"]) {
                                case "GivenName":
                                case "FatherGivenName":
                                case "MotherGivenName":
                                case "SpouseGivenName":
                                    if (!is_null($rec)) {
                                        $tag_replacement .= $this->getGivenName(
                                            $rec,
                                            $this->get_format($format, 0)
                                        );
                                    }
                                    break;
                                case "SurName":
                                case "FatherSurName":
                                case "MotherSurName":
                                case "SpouseSurName":
                                    if (!is_null($rec)) {
                                        if ($name_key == "") {
                                            $name_key = $rec->getPrimaryName();
                                        }
                                        $tag_replacement .= $rec->getAllNames()[$name_key]['surname'];
                                        $tag_replacement = str_replace(
                                            '@N.N.',
                                            I18N::translateContext(
                                                'Unknown surname',
                                                '…'
                                            ),
                                            $tag_replacement
                                        );
                                    }
                                    break;
                            }

                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "NickName":
                            $rec_name = $this->recordFacts($record, ["NAME"], true)[0];
                            $nickname = $rec_name->attribute("NICK");
                            if ($nickname) {
                                $tag_replacement .= $this->substituteSpecialCharacters($nickname, $doctype);
                            }
                            break;
                        case "Id":
                        case "FatherId":
                        case "MotherId":
                        case "SpouseId":
                            $rec = null;
                            switch ($comp["component"]) {
                                case "Id":
                                    $rec = $record;
                                    break;
                                case "SpouseId":
                                    $rec = $this->spouse($record, $record_context);
                                    break;
                                default:
                                    if (count($FAMC) > 0) {
                                        if ($comp["component"] == "FatherId") {
                                            $rec = $this->husband($FAMC[0]);
                                        } elseif ($comp["component"] == "MotherId") {
                                            $rec = $this->wife($FAMC[0]);
                                        }
                                    }
                                    break;
                            }
                            if (!is_null($rec)) {
                                $xref = $rec->xref();
                                if ($this->get_format($format, 0) === "") {
                                    $tag_replacement = $this->generationInd[$xref]["gen_no"];
                                } else {
                                    $tag_replacement = $this->generationInd[$xref][$this->get_format($format, 0)];
                                }
                                $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                                if (!$this->entry_has_label && !empty($comp["in_label"]) && $xref === $this->entry_xref) {
                                    // label in an entry that must not define the label of the individual (copy
                                    // of an individual connecting two branches): the label of the entry
                                    $tag_replacement = $this->entryLabel($this->entry_id, $tag_replacement);
                                }
                            }
                            break;
                        case "LinkId":
                            // LaTeX: label of the entry of the individual in the other branch (see @Link@),
                            // empty if that entry is not exported
                            if (
                                $doctype === "latex" &&
                                array_key_exists($ind_id, $this->generationInd) &&
                                array_key_exists("sub_branch_link_id", $this->generationInd[$ind_id])
                            ) {
                                $xref = $this->generationInd[$ind_id]["xref"];
                                $link_id = (string) $this->generationInd[$ind_id]["sub_branch_link_id"];
                                $target = (string) $this->generationInd[$xref]["sub_branch_id"] === $link_id ? $xref : $xref . '_' . $link_id;
                                if ($target !== (string) $ind_id && array_key_exists($target, $this->exported_entries)) {
                                    $field = $this->get_format($format, 0) === "" ? "gen_no" : $this->get_format($format, 0);
                                    $tag_replacement = $this->entryLabel(
                                        $target,
                                        $this->substituteSpecialCharacters($this->generationInd[$xref][$field], $doctype)
                                    );
                                }
                            }
                            break;
                        case "BirthDate":
                            $tag_replacement .= $this->formatDate(
                                $this->recordValue($record, "getBirthDate"),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "BirthPlace":
                            $tag_replacement .= $this->formatPlace(
                                $this->recordValue($record, "getBirthPlace"),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "DeathDate":
                            $tag_replacement .= $this->formatDate(
                                $this->recordValue($record, "getDeathDate"),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "DeathPlace":
                            $tag_replacement .= $this->formatPlace(
                                $this->recordValue($record, "getDeathPlace"),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "MarriageDate":
                            $tag_replacement .= $this->formatDate(
                                $this->recordValue($record, "getMarriageDate"),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "MarriagePlace":
                            $tag_replacement .= $this->formatPlace(
                                $this->recordValue($record, "getMarriagePlace"),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "Fact":
                            $tag_replacement .= $this->getFact(
                                $record,
                                $comp["fact"],
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "Reference":
                            $xref = $record->xref();
                            if (array_key_exists($xref, $this->generationInd)) {
                                $tag_replacement .= $this->generationInd[$xref]["reference"];
                            }
                            break;
                        case "Link":
                            $xref = $record->xref();
                            if (
                                array_key_exists($ind_id, $this->generationInd) and
                                array_key_exists("sub_branch_link_id", $this->generationInd[$ind_id])
                            ) {
                                $tag_replacement .= $this->generationInd[$ind_id]["sub_branch_link_name"];
                                // the name of a branch is no data but a setting and may contain LaTeX
                                // (it is also used unchanged as heading), so it is masked only for GraphML
                                if ($doctype !== "latex") {
                                    $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                                }
                            }
                            break;
                        case "Ancestor":
                            $xref = $record->xref();
                            if (array_key_exists($xref, $this->generationInd)) {
                                if ($this->generationInd[$xref]["ancestor"] == 1 or $this->generationInd[$xref]["ancestor"] == 2) {
                                    $tag_replacement .= $this->get_format($format, $this->generationInd[$xref]["ancestor"] - 1);
                                }
                            }
                            break;
                        case "FeAttributeType":
                        case "FeFactType":
                            $return_value = TRUE;
                            // "IfExists" is the spelling of the help, "IfExist" the one of older templates
                            if (in_array($this->get_format($format, 0), array("IfExist", "IfExists"), true) and  get_class($record) != "Fisharebest\Webtrees\Fact") {
                                $fact_records = $this->recordFacts($record, (array) $fact_type, true);
                                // a collection (webtrees 2), empty() would always be false
                                if (count($fact_records) === 0) {
                                    $return_value = FALSE;
                                }
                            }
                            if ($return_value) {
                                if (!empty($fact_symbols) and array_key_exists($fact_type, $fact_symbols)) {
                                    $tag_replacement .= $fact_symbols[$fact_type];
                                } else {
                                    // masked once (masking twice would mask the masking characters again)
                                    $tag_replacement .= $this->substituteSpecialCharacters(
                                        (string) $this->getGEDCOMTagLabel($fact_type, $record_context->tag()),
                                        $doctype
                                    );

                                    $tag_replacement = $this->get_format($format, 1) . $tag_replacement;
                                    $tag_replacement = $tag_replacement . $this->get_format($format, 2);
                                }
                                $nodetext .= $tag_replacement;
                                $tag_replacement = '';
                            }
                            break;
                        case "FeFactValue":
                            if ($record->tag() === "ADOP") {
                                // for adoption get the information by which parents
                                $ADOP = $this->getADOP($record);
                                if (!is_null($ADOP)) {
                                    $tag_replacement .= I18N::translate($ADOP);
                                }
                            } else {
                                $tag_replacement .= $record->Value();
                            }

                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeFactDate":
                            $tag_replacement .= $this->formatDate($record->date(), $this->get_format($format, 0));
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeFactPlace":
                            $tag_replacement .= $this->formatPlace($record->place(), $this->get_format($format, 0));
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeFactAttribute":
                            if ($fact_type == 'DATE') {
                                $tag_replacement .= $this->formatDate($record->date(), $this->get_format($format, 0));
                            } else if ($fact_type == 'PLAC') {
                                $tag_replacement .= $this->formatPlace($record->place(), $this->get_format($format, 0));
                            } else {
                                $tag_replacement .= $record->attribute($fact_type);
                            }
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeFactNote":
                            // get Note fact
                            $n = $record->attribute('NOTE');
                            if ($n !== "") {
                                // followed by a LaTeX space ("\ ") in LaTeX only
                                $tag_replacement .= $this->substituteSpecialCharacters($n, $doctype) . ($doctype === "latex" ? '\\ ' : '');
                            }
                            break;
                        case "FeMediaFile":
                            // record is of type media not of type file
                            // there could be several files per media
                            // the code has to be adapted
                            $filename = $record->filename();
                            $filename_array = explode(".", $filename);
                            $n = count($filename_array);
                            if ($this->fileExtension($filename) === "") {
                                // file name without extension: not reached, @ForeachMedia@ skips such files
                            } else if ($this->get_format($format, 0) == "NoExtension") {
                                // return file name without ending	 
                                $tag_replacement .= implode(".", array_slice($filename_array, 0, $n - 1));
                            } else {
                                // return full file name
                                $tag_replacement .= $filename;
                            }
                            break;
                        case "FeMediaId":
                            $tag_replacement .= $record->factId();
                            break;
                        case "FeMediaCaption":
                            $tag_replacement .= $record->title();
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeReferenceName":
                            $tag_replacement .= $this->source_list[str_replace('@', '', $record)];
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeStepparents":
                            $ADOP = $this->checkADOP($record_context, $record);
                            $tag_replacement .= I18N::translate($ADOP);
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeStepchild":
                            $ADOP = $this->checkADOP($record, $record_context); // $record = child, $record_context = FAMC 
                            if (is_null($ADOP)) {
                                $ADOP = $this->checkADOP($record, $record_context); // $record = child, $record_context = FAMC   
                            }
                            $tag_replacement .= I18N::translate($ADOP);
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "Portrait":
                            $tag_replacement .= $this->getPortrait(
                                $record,
                                $this->get_format($format, 0)
                            );
                            // a file name: in LaTeX "{", "}" and "~" stay unchanged
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype === "latex" ? "latex_filename" : $doctype);
                            break;
                        case "PortraitIcon":
                            // GraphML only: the portrait is embedded in the file and shown as icon of the
                            // node label; the placeholder itself returns no text
                            if ($doctype === "graphml" && $record instanceof Individual) {
                                $this->node_icon = $this->portraitIcon($record, $format) ?? $this->node_icon;
                            }
                            break;
                        case "PortraitSize":
                            $tag_replacement = $this->getPortraitSize(
                                $record,
                                $format
                            );
                            break;
                        case "Counter":
                            if ($counter > 0) $tag_replacement = $counter;
                            break;
                        case "Gedcom":
                            $tag_replacement = preg_replace(
                                "/\\n/",
                                "<br>",
                                $record instanceof GedcomRecord
                                    ? $record->privatizeGedcom(Auth::accessLevel($this->tree))
                                    : $record->gedcom()
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "Marriage":
                            $marriage = $this->recordValue($record, "getMarriage");
                            if ($marriage) {
                                $empty_tag_found = TRUE;
                            }
                            break;
                        case "MarriageDate":
                            $tag_replacement .= $this->formatDate(
                                $this->recordValue($record, "getMarriageDate"),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "MarriagePlace":
                            // $record->getMarriagePlace() does not work because
                            // there is no exception handling in the function
                            $marriage = $this->recordValue($record, "getMarriage");
                            if ($marriage) {
                                $tag_replacement .= $this->formatPlace(
                                    $marriage->place(),
                                    $this->get_format($format, 0)
                                );
                                $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            }
                            break;
                        case "Remove":
                            $search_array = array();
                            $replace_array = array();
                            foreach ($format as $f) {
                                $search_array[] = "/\Q" . $f . '\E(?=[\\r\\n\\' . $brackets[0] . '\\' . $brackets[1] . '\s]*$)/';
                                $replace_array[] = '';
                            };
                            do {
                                $old_length = strlen($nodetext);
                                $nodetext = preg_replace($search_array, $replace_array, $nodetext);
                                $new_length = strlen($nodetext);
                            } while ($old_length !=  $new_length);
                            break;
                        case "Replace":
                            if (count($format) > 1) {
                                $search_array = "/\Q" . $format[0] . '\E(?=[\\r\\n\\' . $brackets[0] . '\\' . $brackets[1] . '\s]*$)/';
                                $replace_array = str_replace('\\', '\\\\', $format[1]);
                                do {
                                    $old_length = strlen($nodetext);
                                    $nodetext = preg_replace($search_array, $replace_array, $nodetext);
                                    $new_length = strlen($nodetext);
                                } while ($old_length !=  $new_length);

                            }
                            break;
                        default:
                            throw $this->unknownPlaceholder($comp["component"]);
                    }
                } elseif ($comp["type"] == "foreach") {
                    // loop

                    $counter = 0;
                    switch ($comp["component"]) {
                        case "ForeachNAME":
                            // loop over all names
                            $names = $record->getAllNames();
                            foreach ($names as $name_key => $name) {
                                if ($name['type'] == $comp["format"][0]) {
                                    list($term_tag_replacement, $term_tag_resolved) = $this->substitutePlaceHolder($record, $record, $ind_id, $comp["subtemplate"], $doctype, $brackets, $fact_symbols, $counter, "", $name_key);
                                    if ($term_tag_resolved) {
                                        $tag_replacement .= $term_tag_replacement;
                                        $tag_resolved = TRUE;
                                    }
                                }
                            }
                            break;
                        case "ForeachFAMC":
                            // loop over all families where individual is a child
                            $FAMC = $this->childFamilies($record);
                            foreach ($FAMC as $family) {
                                $counter += 1;
                                list($term_tag_replacement, $term_tag_resolved) = $this->substitutePlaceHolder($family, $record, $ind_id, $comp["subtemplate"], $doctype, $brackets, $fact_symbols, $counter);
                                if ($term_tag_resolved) {
                                    $tag_replacement .= $term_tag_replacement;
                                    $tag_resolved = TRUE;
                                }
                            }
                            break;
                        case "ForeachFAMS":
                            // loop over all spouse families of an individual
                            $FAMS = $this->spouseFamilies($record);
                            foreach ($FAMS as $family) {
                                $counter += 1;
                                list($term_tag_replacement, $term_tag_resolved) = $this->substitutePlaceHolder($family, $record, $ind_id, $comp["subtemplate"], $doctype, $brackets, $fact_symbols, $counter);
                                if ($term_tag_resolved) {
                                    $tag_replacement .= $term_tag_replacement;
                                    $tag_resolved = TRUE;
                                }
                            }
                            break;
                        case "ForeachChildren":
                            // loop over all children of a family 
                            $children = $this->children($record);
                            foreach ($children as $child) {
                                $counter += 1;
                                list($term_tag_replacement, $term_tag_resolved) =  $this->substitutePlaceHolder($child, $record, $ind_id, $comp["subtemplate"], $doctype, $brackets, $fact_symbols, $counter);
                                if ($term_tag_resolved) {
                                    $tag_replacement .= $term_tag_replacement;
                                    $tag_resolved = TRUE;
                                }
                            }
                            break;
                        case "ForeachFactOuter":
                        case "ForeachAttributeOuter":
                            // create fact list facts
                            if (count($comp["format"]) > 0) {
                                $facts_ordered = explode(',', $comp["format"][0]);
                            } else {
                                $facts_ordered = array();
                            }

                            if ($comp["component"] == "ForeachAttributeOuter") {
                                $fact_types = $facts_ordered;
                            } else {
                                if (count($comp["format"]) > 1) {
                                    $facts_excluded = explode(',', $comp["format"][1]);
                                } else {
                                    $facts_excluded = array();
                                }
                                $fact_types = array();
                                $fact_records = $this->recordFacts($record);
                                if (!empty($fact_records)) {
                                    foreach ($fact_records as $fact_record) {
                                        $tag = $fact_record->tag();
                                        // strip the leading identifiers like INDI:, FAM: from $tag
                                        // i.e. remove everything before the last colon
                                        if (strpos($tag, ':') !== FALSE) $tag = substr($tag, strripos($tag, ':') + 1);
                                        // store the tag
                                        $fact_types[] = $tag;
                                    }
                                }
                                $fact_types = array_unique($fact_types);
                                $fact_types_ordered = array_intersect($facts_ordered, $fact_types);
                                $fact_types_unordered = array_diff($fact_types, $facts_ordered);

                                // exclude fact types as defined in the options
                                if (in_array('all', $facts_excluded) or in_array('ALL', $facts_excluded)) {
                                    $fact_types_unordered = array();
                                } else {
                                    $fact_types_unordered = array_diff($fact_types_unordered, $facts_excluded);
                                }

                                $fact_types = array_merge($fact_types_ordered, $fact_types_unordered);
                            }
                            // loop over all fact types
                            foreach ($fact_types as $fact_type) {
                                // loop over facts of the same type sorted
                                $counter += 1;
                                list($term_tag_replacement, $term_tag_resolved) =  $this->substitutePlaceHolder(
                                    $record,
                                    $record_context,
                                    $ind_id,
                                    $comp["subtemplate"],
                                    $doctype,
                                    $brackets,
                                    $fact_symbols,
                                    $counter,
                                    $fact_type
                                );
                                if ($term_tag_resolved) {
                                    $tag_replacement .= $term_tag_replacement;
                                    $tag_resolved = TRUE;
                                }
                            }
                            break;
                        case "ForeachFactInner":
                            // loop over facts of the same type sorted
                            $fact_records = $this->recordFacts($record, (array) $fact_type, true);
                            if (!empty($fact_records)) {
                                foreach ($fact_records as $fact_record) {
                                    $counter += 1;
                                    list($term_tag_replacement, $term_tag_resolved) = $this->substitutePlaceHolder(
                                        $fact_record,
                                        $record,
                                        $ind_id,
                                        $comp["subtemplate"],
                                        $doctype,
                                        $brackets,
                                        $fact_symbols,
                                        $counter,
                                        $fact_type
                                    );
                                    if ($term_tag_resolved) {
                                        $tag_replacement .= $term_tag_replacement;
                                        $tag_resolved = TRUE;
                                    }
                                }
                            }
                            break;
                        case "ForeachMedia":
                            // loop over media files
                            $media_links = [];
                            foreach ($this->recordFacts($record, ["OBJE"], true) as $fact) {
                                // get the media object and add it to the array
                                $media_links[] = $fact->target();
                            }
                            $n1 = count($media_links);
                            // add media from spouse family
                            if ($record instanceof Individual) {
                                // add media from facts
                                foreach ($this->recordFacts($record) as $fact) {
                                    $OBJE = trim($fact->attribute('OBJE'), "@");
                                    if ($OBJE !== "") {
                                        $media_links[] = Registry::mediaFactory()->make($OBJE, $this->tree);
                                    }
                                };
                                // add media from spouse family
                                $FAMS = $this->spouseFamilies($record);
                                foreach ($FAMS as $rec_fam) {
                                    // Family madia
                                    foreach ($this->recordFacts($rec_fam, ["OBJE"], true) as $fact) {
                                        // get the media object and add it to the array
                                        $media_links[] = $fact->target();
                                    }

                                    // family fact media
                                    foreach ($this->recordFacts($rec_fam) as $fact) {
                                        $OBJE = trim($fact->attribute('OBJE'), "@");
                                        if ($OBJE !== "") {
                                            $media_links[] = Registry::mediaFactory()->make($OBJE, $this->tree);
                                        }
                                    };
                                }
                            }
                            if ($n1 < count($media_links)) {
                                $n1 = count($media_links);
                            }

                            // links to a media object which does not exist are skipped
                            $media_links = array_filter($media_links, fn ($media) => $media instanceof Media);
                            if (!empty($media_links)) {
                                foreach ($media_links as $media_record) {
                                    $id = trim($media_record->xref(), "@");

                                    if ($media_record !== null) {

                                        // check against the inclusion and exclusion list
                                        if (in_array($id, $this->include_exclude_media["include"])) {
                                            $use = true;
                                        } else if (in_array($id, $this->include_exclude_media["exclude"])) {
                                            $use = false;
                                        } else {
                                            $use = true;
                                        }

                                        // check if media already shown
                                        // (the media collection lists the media at each individual)
                                        if ($use && $this->media_collector === null && $this->get_format($format, 2) == "unique") {
                                            if (in_array($id, $this->media_included)) {
                                                $use = false;
                                            } else {
                                                $this->media_included[] = $id;
                                            }
                                        }
                                        $files = $media_record->mediaFiles();

                                        if ($use and $media_record->canShow() and count($files) > 0) {
                                            foreach ($files as $file) {
                                                $usefile = true;
                                                // check if type is in format[0]
                                                if ($this->get_format($format, 0) != "") {
                                                    $types = array_map("strtolower",explode(',', $this->get_format($format, 0)));
                                                    $type = strtolower($file->type());
                                                    if (!in_array($type, $types)) {
                                                        $usefile = false;
                                                    }
                                                }
                                                // check if ending is in format[1]
                                                if ($this->get_format($format, 1) != "") {
                                                    $endings = array_map("strtolower", explode(',', $this->get_format($format, 1)));
                                                    $filename = $file->filename();
                                                    $filename_array = explode(".", $filename);
                                                    $n = count($filename_array);
                                                    if ($n > 1) {
                                                        $ending = strtolower($filename_array[$n - 1]);
                                                        if (!in_array($ending, $endings)) {
                                                            $usefile = false;
                                                        }
                                                    } else {
                                                        $usefile = false;
                                                    }
                                                }
                                                // files without extension are skipped (@FeMediaFile&NoExtension@
                                                // and \includegraphics need one), also on the page "Image selection"
                                                if ($usefile && $this->fileExtension($file->filename()) === "") {
                                                    $usefile = false;
                                                }

                                                // collect the media file (page "Image selection")
                                                if ($usefile && $this->media_collector !== null) {
                                                    $this->media_collector['persons'][$this->media_person][$id] = true;
                                                    $this->media_collector['media'][$id]['media'] = $media_record;
                                                    $this->media_collector['media'][$id]['files'][$file->filename()] = $file;
                                                    $tag_resolved = TRUE;
                                                    continue;
                                                }
                                                // include media file in latex file
                                                if ($usefile) {
                                                    $counter += 1;
                                                    $tag_replacement .= $this->substitutePlaceHolder(
                                                        $file,
                                                        $media_record,
                                                        $ind_id,
                                                        $comp["subtemplate"],
                                                        $doctype,
                                                        $brackets,
                                                        $fact_symbols,
                                                        $counter,
                                                        $fact_type
                                                    )[0];
                                                    $tag_resolved = TRUE;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        case "ForeachReference":
                            // get direct sources
                            $facts = $this->recordFacts($record, ['SOUR']);
                            $all_sources = array();
                            foreach ($facts as $fact) {
                                $all_sources[] = $fact->Value();
                            }

                            // get all facts
                            $facts = $this->recordFacts($record)->all();
                            foreach ($this->spouseFamilies($record) as $family) {
                                if ($family->canShow()) {
                                    foreach ($this->recordFacts($family) as $fact) {
                                        $facts[] = $fact;
                                    }
                                }
                            }

                            // get sources from facts
                            foreach ($facts as $fact) {
                                $fact_sources = $this->cached($fact, 'sources', fn () => $this->getAllAttributes($fact, 'SOUR'));
                                if ($fact_sources) {
                                    $all_sources = array_merge($all_sources, $fact_sources);
                                }
                            }
                            $all_sources = array_unique($all_sources);

                            foreach ($all_sources as $source) {
                                if (!$this->canShowSource($source)) {
                                    continue;
                                }
                                $counter += 1;
                                list($term_tag_replacement, $term_tag_resolved) = $this->substitutePlaceHolder(
                                    $source,
                                    $record,
                                    $ind_id,
                                    $comp["subtemplate"],
                                    $doctype,
                                    $brackets,
                                    $fact_symbols,
                                    $counter,
                                    $fact_type
                                );
                                if ($term_tag_resolved) {
                                    $tag_replacement .= $term_tag_replacement;
                                    $tag_resolved = TRUE;
                                }
                            }
                            break;
                        default:
                            throw $this->unknownPlaceholder($comp["component"]);
                    }
                }
                if ($tag_replacement !== "" or $empty_tag_found !== false) {
                    // data for the tag exists
                    $nodetext .= $tag_replacement;
                    $tag_resolved = TRUE;
                }
            }

            if ($doctype == "graphml") {
                if (str_contains($nodetext, '<html>')) {
                    $nodetext = preg_replace("/<html>\s*<\/html>/", "", $nodetext);
                }
            } else if ($doctype == "latex") {
                // remove \\ in "\end{} \\"
                // (the checks with str_contains only avoid running the expressions without need)
                if (str_contains($nodetext, '\\end{')) {
                    $nodetext = preg_replace('/(\\\\end\{[^\\\{\}]*\}\s*)\\\\\\\\/', '$1 ', $nodetext);
                }
                // remove \\ in "\\ \begin{}"
                if (str_contains($nodetext, '\\begin{')) {
                    $nodetext = preg_replace('/\\\\\\\\(\s*\\\\begin\{[^\\\{\}]*\})/', '$1 ', $nodetext);
                }
            }
        }

        return array($nodetext, $tag_resolved);
    }

    /**
     * Substitute characters
     *
     * This module substitutes the following special
     * html characters:
     * " -> &quot;
     * & -> &amp;
     * < -> &lt;
     * > -> &gt;
     * ' -> &apos;
     * &nbsp; -> " "
     *
     * @param string $subject
     *        	the input string where special characters should
     *        	be substituted.
     * @param string $doctype
     *        	defines if the document is graphml or latex
     * @return string The input string with substituted characters
     */
    private function substituteSpecialCharacters($subject, $doctype)
    {
        if ($doctype == "graphml") {
            $replacements = array(
                array(
                    "/&/", "&amp;"
                ), array(
                    "/\"/", "&quot;"
                ), array(
                    "/\'/", "&apos;"
                ), array(
                    "/(?<!br)>/", "&gt;"
                ), array(
                    "/<(?!br>)/", "&lt;"
                )
            );
        }
        if ($doctype == "latex" || $doctype == "latex_filename") {
            // the replacements are done one after the other: braces are masked directly after the
            // backslash, before any replacement which adds braces itself ('"{}', '\textasciitilde{}')
            // "latex_filename" (file names, e.g. of @Portrait@) keeps the replacements of older
            // versions: braces and "~" are valid in a path and must stay unchanged there
            $replacements = array(
                array(
                    '/\Q\\\E/', '\textbackslash '
                ), array(
                    '/\Q{\E/', '\{'
                ), array(
                    '/\Q}\E/', '\}'
                ), array(
                    '/\Q~\E/', '\textasciitilde{}'
                ), array(
                    '/\Q&\E/', '\& '
                ), array(
                    '/\Q$\E/', '\\\$ '
                ), array(
                    '/\Q%\E/', '\% '
                ), array(
                    '/\Q_\E/', '\_ '
                ), array(
                    '/\Q^\E/', '\^ '
                ), array(
                    '/\Q...\E/', '\ldots '
                ), array(
                    '/\Q|\E/', '\textbar '
                ), array(
                    '/\Q#\E/', '\#'
                ), array(
                    '/\Q§\E/', '\§'
                ), array(
                    '/\Q"\E/', '"{}'
                ), array(
                    '/\n/', '\\ '
                )
            );
            if ($doctype == "latex_filename") {
                // without the replacements of "{", "}" and "~"
                array_splice($replacements, 1, 3);
            }
        }

        foreach ($replacements ?? array() as $r) {
            $subject = preg_replace($r[0], $r[1], (string) $subject);
        }

        return $subject;
    }

    /**
     * Mask a value of the bibliography (title or author of a source)
     *
     * The value is written between double quotes into the .bib file. BibTeX needs balanced braces
     * and ends the value at a double quote outside braces; LaTeX needs its special characters masked.
     * All characters are replaced in one pass, so nothing is masked twice.
     *
     * @param string $value
     * @return string
     */
    private function bibtexValue(string $value): string
    {
        return strtr($value, array(
            '\\' => '\textbackslash{}',
            '{' => '\textbraceleft{}',
            '}' => '\textbraceright{}',
            '"' => '\textquotedbl{}',
            '~' => '\textasciitilde{}',
            '^' => '\textasciicircum{}',
            '&' => '\&',
            '%' => '\%',
            '$' => '\$',
            '#' => '\#',
            '_' => '\_',
            "\r" => '',
            "\n" => ' ',
        ));
    }

    /**
     * Extension of a file name (without the dot), '' if there is none
     *
     * @param string $filename file name with path (also "\" as separator)
     * @return string
     */
    private function fileExtension(string $filename): string
    {
        return pathinfo(str_replace('\\', '/', $filename), PATHINFO_EXTENSION);
    }
    /**
     * get Records for individuals and families
     * this is stored in the table sources
     *
     * @return string with all references
     */
    private function getReferences()
    {

        $ret_string = "\n" . '\usepackage{filecontents}
						\begin{filecontents}{\jobname.bib}';

        $source_rows = DB::table('sources')
            ->where('s_file', '=', $this->tree->id())
            ->select(['s_id AS xref', 's_gedcom AS gedcom', new Expression("'SOUR' AS type")])
            ->get();

        foreach ($source_rows as $rows) {
            $id = $rows->xref;
            $tree = $this->tree;
            if (!Registry::sourceFactory()->make($id, $tree, $rows->gedcom)->canShow()) {
                continue;
            }
            $this->source_list[$id] = count($this->source_list) + 1;
            $ret_string .= "\n" . '@book{' .  $this->source_list[$id];
            $record = Registry::gedcomRecordFactory()->make($id, $tree, $rows->gedcom);

            // title
            foreach ($record->facts(['TITL']) as $fact) {
                $ret_string .= ',title   ="' . $this->bibtexValue($fact->Value()) . '"';
            }
            // author
            foreach ($record->facts(['AUTH']) as $fact) {
                // several authors are separated by commas in webtrees and by "and" in BibTeX
                $ret_string .= ',author   ="' .
                    str_replace(',', ' and ', $this->bibtexValue($fact->Value())) . '"';
            }
            $ret_string .= "}";
        }

        $ret_string .= "\n" . '\end{filecontents}' . "\n";

        return $ret_string;
    }

    /**
     * set the information in the generationInd array
     *
     * @param array $inds List of individuals
     * @param integer $branch branch number
     * @param boolean $related_by_blood determines which individuals are set
     * @return void
     */
    private function setFAMSFAMC($inds, $branch, $related_by_blood)
    {

        do {
            // first set flags for individuals
            foreach ($inds as $key => $ind_a) {
                $generation = $ind_a["generation"];
                $ind = $ind_a["ind"];
                $reference = $ind_a["ref"];
                if ($ind !== null) {
                    // set branch and generation
                    $xrefInd = $ind->xref();
                    if (
                        empty($this->generationInd) or
                        !array_key_exists($xrefInd, $this->generationInd)
                    ) {
                        $record = $ind;
                        $name = $record->getAllNames()[$record->getPrimaryName()]['surname'];
                        $name = str_replace(
                            '@N.N.',
                            I18N::translateContext(
                                'Unknown surname',
                                '…'
                            ),
                            $name
                        );
                        // spouse families
                        $FAMS = $this->spouseFamilies($record);

                        $this->generationInd[$xrefInd] = array(
                            "branch" => $branch,
                            "sub_branch" => "",
                            "sub_branch_id" => -1,
                            "children" => array(),
                            "parents" => array(),
                            "spouse" => array(),
                            "spouse_family" => $FAMS,
                            "generation" => $generation,
                            "name" => $name,
                            "givenname" => $this->getGivenName(
                                $record
                            ),
                            "reference" => $reference,
                            "ancestor" => '',
                            "xref" => $xrefInd
                        );
                    } else {
                        $inds[$key]["ind"] = null;
                    }
                }
            }
            // now search for relations
            $new_inds = array();
            foreach ($inds as $ind_a) {
                $generation = $ind_a["generation"];
                $ind = $ind_a["ind"];
                $reference = $ind_a["ref"];
                if ($ind !== null) {
                    // set branch and generation
                    $xrefInd = $ind->xref();
                    $record = $ind;
                    // 1. get Father and Mother
                    // Familes where ind is a child FAMC, i.e. generation - 1
                    // If related by blood is required, only include father/mother if 
                    // relation does not include a "+" 
                    $include_ind = !$related_by_blood || strpos($reference, "+") === false;

                    $FAMC = $this->childFamilies($ind);
                    foreach ($FAMC as $record) {
                        $parents = array($this->husband($record), $this->wife($record));
                        foreach ($parents as $rec) {
                            if ($rec !== null) {
                                $this->generationInd[$xrefInd]["parents"][] = $rec->xref();
                                $is_adopted = $this->checkADOP($ind, $record, $rec);
                                if (
                                    $rec !== null && $include_ind &&
                                    !array_key_exists($rec->xref(), $this->generationInd)
                                ) {
                                    if ($is_adopted === "" || $is_adopted === "by_spouse") {
                                        $new_inds[] = array(
                                            "ind" => $rec,
                                            "ref" => $reference . '-' . $rec->sex(),
                                            "generation" => $generation - 1
                                        );
                                    } elseif ($is_adopted === "stepparents" && !$related_by_blood) {
                                        $new_inds[] = array(
                                            "ind" => $rec,
                                            "ref" => $reference . '-a' . $rec->sex(),
                                            "generation" => $generation - 1
                                        );
                                    }
                                }
                            }
                        }
                    };

                    // families of the same generation FAMS, i.e. generation +0
                    // get spouse and childreen
                    $FAMS = $this->spouseFamilies($ind);
                    foreach ($FAMS as $record) {

                        // get spouse
                        if ($ind->sex() == "F") {
                            $spouse = $this->husband($record);
                        } else {
                            $spouse = $this->wife($record);
                        };
                        if ($spouse !== null)    $this->generationInd[$xrefInd]["spouse"][] = $spouse->xref();
                        if (
                            $spouse !== null && !$related_by_blood &&
                            !array_key_exists($spouse->xref(), $this->generationInd)
                        ) {
                            $new_inds[] = array(
                                "ind" => $spouse,
                                "ref" => $reference . '0' . $spouse->sex(),
                                "generation" => $generation
                            );
                        }

                        // get children
                        $children = $this->children($record);
                        foreach ($children as $child) {
                            // check if adopted
                            $is_adopted = $this->checkADOP($child, $record, $ind);

                            $this->generationInd[$xrefInd]["children"][] = $child->xref();
                            if (!array_key_exists($child->xref(), $this->generationInd)) {
                                if ($is_adopted === "" || $is_adopted === "by_spouse") {
                                    $new_inds[] = array(
                                        "ind" => $child,
                                        "ref" => $reference . '+' . $child->sex(),
                                        "generation" => $generation + 1
                                    );
                                } elseif ($is_adopted === "stepparents" && !$related_by_blood) {
                                    // include if adopted by both parents
                                    $new_inds[] = array(
                                        "ind" => $child,
                                        "ref" => $reference . '+a' . $child->sex(),
                                        "generation" => $generation + 1
                                    );
                                }
                            }
                        }
                    };
                }
            }
            $inds = $new_inds;
        } while (count($inds) > 0);
    }

    /**
     * get Seeds
     *
     * @return list of seeds to structure the family tree
     */
    private function getSeeds()
    {

        $seeds = array();
        $all_seeds = array_key_exists('branch_seeds', $this->settings) ? $this->settings["branch_seeds"] : '';
        // the reference individual of the form, else the individual of the address of the page
        $xref = $this->settings["refid"] ?? '';
        if ($xref === '') {
            $xref = $this->url_xref;
        }
        $seeds_default = array(
            "xref" => $xref,
            "name" => "not defined",
            "direction" => "both",
            "stop" => array(),
            "hierarchy_sub_branch" => "",
            "hierarchy_generation" => "",
            "include" => TRUE
        );
        $seeds_default_keys = array_keys($seeds_default);
        $allowed_values = array("direction" => array("both", "ancestors", "descendants"));

        if (strlen($all_seeds) < 5 && $xref != '') {
            $seeds[] = $seeds_default;
        } else if (strlen($all_seeds) > 4) {
            $sep1 = $all_seeds[0];
            $sep2 = $all_seeds[1];
            $all_seeds = substr($all_seeds, 2);
            foreach (explode("\n", $all_seeds) as $row) {
                $row = trim($row);
                $cols = explode($sep1, $row);
                if (sizeof($cols) > 1) {
                    if (sizeof($cols) > sizeof($seeds_default) || sizeof($cols) < 3) {
                        throw new ExportFamilyBookTemplateException(I18N::translate(
                            'Error in the list of branches: a line has too many or too few columns: %s',
                            $row
                        ));
                    }

                    $seeds[] = $seeds_default;
                    end($seeds);
                    $key = key($seeds);

                    foreach (range(0, sizeof($cols) - 1) as $i) {
                        if ($i === 3) {
                            $seeds[$key][$seeds_default_keys[$i]] = explode($sep2, $cols[3]);
                        } elseif ($i == 6) {
                            if (strtolower($cols[$i]) === "exclude") {
                                $seeds[$key][$seeds_default_keys[$i]] = FALSE;
                            } elseif (strtolower($cols[$i]) === "include") {
                                $seeds[$key][$seeds_default_keys[$i]] = TRUE;
                            } else {
                                throw new ExportFamilyBookTemplateException(I18N::translate(
                                    'Error in the list of branches: the value “%1$s” is not allowed in the last column (allowed: %2$s): %3$s',
                                    $cols[$i],
                                    'include, exclude',
                                    $row
                                ));
                            }
                        } else {
                            $seeds[$key][$seeds_default_keys[$i]] = $cols[$i];
                        }
                    }

                    // apply some checks
                    foreach (array_keys($allowed_values) as $allowed_key) {
                        if (!in_array($seeds[$key][$allowed_key], $allowed_values[$allowed_key])) {
                            throw new ExportFamilyBookTemplateException(I18N::translate(
                                'Error in the list of branches: the direction “%1$s” is not allowed (allowed: %2$s): %3$s',
                                $seeds[$key][$allowed_key],
                                implode(', ', $allowed_values[$allowed_key]),
                                $row
                            ));
                        }
                    }
                }
            }
        }
        return $seeds;
    }
    /**
     * get Records for individuals and families
     *
     * @param Boolean $sort
     *        	determines if individuals are sorted by generation
     * @return list record list for individuals and families
     */
    private function getRecords($sort = FALSE)
    {

        // Get all individuals
        $rowsInd = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->orderBy('i_id')
            ->select(['i_id AS xref', 'i_gedcom AS gedcom', new Expression("'INDI' AS type")])
            ->get();

        $xrefInd = array();
        foreach ($rowsInd as $row) {
            // create the record from the data already read, this avoids one database query per individual
            $individual = Registry::individualFactory()->make($row->xref, $this->tree, $row->gedcom);
            // export only individuals the current user may see
            if ($this->canExport($individual)) {
                $xrefInd[] = $row->xref;
            }
        };

        // loop over families to start with a new branch
        if ($sort) {
            # if reference individual are set start 
            # with this individual and return only one tree
            #
            $branch = 1;
            if (
                array_key_exists('refid', $this->settings) and $this->settings["refid"] and
                $this->canExport(Registry::individualFactory()->make($this->settings["refid"], $this->tree))
            ) {
                $xref = $this->settings["refid"];
                $record = Registry::gedcomRecordFactory()->make($xref, $this->tree);
                // first determin relationships without spouses
                $this->setFAMSFAMC(array(array(
                    "ind" => $record,
                    "ref" => "r", "generation" => 0
                )), $branch, TRUE);
                $generationInd_wo_spouses = $this->generationInd;
                $this->generationInd = array();

                // now determine relationship with spouses

                $this->setFAMSFAMC(array(array(
                    "ind" => $record,
                    "ref" => "r", "generation" => 0
                )), $branch, FALSE);

                // now merge relationships
                foreach ($generationInd_wo_spouses as $xref => $ind) {
                    if (key_exists($xref, $this->generationInd)) {
                        $this->generationInd[$xref]["reference"] = $ind["reference"];
                    }
                }

                $branch += 1;
            }

            foreach ($xrefInd as $xref) {
                if (
                    empty($this->generationInd) or
                    !array_key_exists($xref, $this->generationInd)
                ) {
                    $record = Registry::individualFactory()->make($xref, $this->tree);
                    $this->setFAMSFAMC(array(array(
                        "ind" => $record,
                        "ref" => "n", "generation" => 0
                    )), $branch, FALSE);
                    $branch += 1;
                };
            };

            // Now set sub branches

            // First get seeds for branches
            $seeds = $this->getSeeds();
            $seeds_exported = array();
            foreach ($seeds as $key => $seed) {
                if ($seed["include"]) {
                    $seeds_exported[] = $key;
                }
            }
            $number_of_branches = $branch - 1;
            $number_of_sub_branches = count($seeds);

            // Now set sub branches
            $inds_to_be_copied = array();
            foreach ($seeds as $seed_no => $seed) {
                $xref = $seed["xref"];
                // skip seeds the current user may not see (or which do not exist)
                if (!array_key_exists($xref, $this->generationInd)) {
                    continue;
                }
                $name = $seed["name"];
                $direction = $seed["direction"];
                $stop =  $seed["stop"];
                $xref_sub_branche_id = $this->generationInd[$xref]["sub_branch_id"];
                // set branch for seed
                $inds_to_be_copied[] = array(
                    "xref" => $xref,
                    "sub_branch" => $this->generationInd[$xref]["sub_branch"],
                    "sub_branch_id" => $this->generationInd[$xref]["sub_branch_id"]
                );
                $this->generationInd[$xref]["sub_branch"] = $name;
                $this->generationInd[$xref]["sub_branch_id"] = $seed_no;
                // select only one direction
                if ($direction == 'ancestors') {
                    // select only ancestors (parents)
                    $inds = $this->generationInd[$xref]["parents"];
                } else if ($direction == 'descendants') {
                    // selects descendants (spouse and children)
                    $inds = array_merge(
                        $this->generationInd[$xref]["spouse"],
                        $this->generationInd[$xref]["children"]
                    );
                } else if ($direction == 'both') {
                    $inds = array_merge(
                        $this->generationInd[$xref]["parents"],
                        $this->generationInd[$xref]["spouse"],
                        $this->generationInd[$xref]["children"]
                    );
                } else {
                    // not reached: getSeeds() already checks the direction
                    throw new ExportFamilyBookTemplateException(I18N::translate(
                        'Error in the list of branches: the direction “%1$s” is not allowed (allowed: %2$s): %3$s',
                        (string) $direction,
                        'both, ancestors, descendants',
                        (string) $seed["name"]
                    ));
                }

                do {
                    // set sub branches in field for all members belonging to subbranch $this->generationInd[$ind]["sub_branch"]
                    $new_inds = array();
                    foreach ($inds as $ind) {

                        if ($seed_no !== $this->generationInd[$ind]["sub_branch_id"]) {
                            if (in_array($ind,  $stop)) {
                                // stop individual
                                $inds_to_be_copied[] = array(
                                    "xref" => $ind,
                                    "sub_branch" => $this->generationInd[$ind]["sub_branch"],
                                    "sub_branch_id" => $this->generationInd[$ind]["sub_branch_id"]
                                );
                            } else {
                                $new_inds = array_merge(
                                    $new_inds,
                                    $this->generationInd[$ind]["parents"],
                                    $this->generationInd[$ind]["spouse"],
                                    $this->generationInd[$ind]["children"]
                                );
                            }
                            $this->generationInd[$ind]["sub_branch"] = $name;
                            $this->generationInd[$ind]["sub_branch_id"] = $seed_no;
                        }
                    }

                    $inds = $new_inds;
                } while (count($inds) > 0);
            }

            // check if inds_to_be_copied are no connection via parents, children or spouse to the branch
            // without connection they will not be added later
            foreach ($inds_to_be_copied as $ind_key => $ind_array) {
                $xref = $ind_array["xref"];
                $branch_id = $ind_array["sub_branch_id"];
                $connection = FALSE;
                foreach (array("parents", "spouse", "children") as $rel) {
                    foreach ($this->generationInd[$xref][$rel] as $rel_ind) {
                        $rel_branch_id = $this->generationInd[$rel_ind]["sub_branch_id"];
                        if ($rel_branch_id === $branch_id) {
                            $connection = TRUE;
                        }
                    }
                }
                $inds_to_be_copied[$ind_key]["connected"] = $connection;
            }

            // now sort for branches and generations
            $a_branch = $a_sub_branch = $a_generation = $a_name = $a_givenname = array();
            foreach ($this->generationInd as $key => $row) {
                $a_branch[$key] = $row['branch'];
                $a_sub_branch[$key] = $row['sub_branch_id'];
                $a_generation[$key] = $row['generation'];
                $a_name[$key] = $row['name'];
                $a_givenname[$key] = $row['givenname'];
            }

            array_multisort(
                $a_branch,
                SORT_ASC,
                $a_sub_branch,
                SORT_ASC,
                $a_generation,
                SORT_ASC,
                $a_name,
                SORT_ASC,
                $a_givenname,
                SORT_ASC,
                SORT_STRING,
                $this->generationInd
            );

            // get smallest generation per branch
            $last_branch = array_values($this->generationInd)[0]['branch'];
            $min_gen_of_branch = array($last_branch => array_values($this->generationInd)[0]['generation']);
            foreach ($this->generationInd as $key => $row) {
                $branch = $row['branch'];
                $generation =  $row['generation'];
                if ($branch != $last_branch) {
                    $min_gen_of_branch[$branch] = $generation;
                };
                $last_branch = $branch;
                if ($generation < $min_gen_of_branch[$branch]) $min_gen_of_branch[$branch] = $generation;
            }
            // now add ids (the variables of the previous individual are set by the first one)
            $no = 0;
            $gen_no = 0;
            $sub_branch_no = 0;
            $last_sub_branch_id = null;
            $last_gen = null;
            $number_of_inds = array();
            $last_branch = array_values($this->generationInd)[0]['branch'] - 1;
            // generation range, used to count same symbols in the reference
            $ng = max($a_generation) - min($a_generation);

            foreach ($this->generationInd as $key => $row) {
                $no += 1;
                $this->generationInd[$key]['no'] = $no;
                $branch = $row['branch'];
                $generation = 1 + $this->generationInd[$key]['generation'] - $min_gen_of_branch[$branch];
                $this->generationInd[$key]['generation'] = $generation;
                $sub_branch_id = $row['sub_branch_id'];
                $sub_branch = $row['sub_branch'];
                if ($branch != $last_branch) {
                    $sub_branch_no = 1;
                    $last_gen = $generation;
                    $last_sub_branch_id = $sub_branch_id;
                    $number_of_inds[$branch] = array($sub_branch_id =>
                    array('no_of_inds' => 0, 'sub_branch' => $sub_branch));
                } else if ($sub_branch_id != $last_sub_branch_id) {
                    $sub_branch_no += 1;
                    $number_of_inds[$branch][$sub_branch_id] = array('no_of_inds' => 0, 'sub_branch' => $sub_branch);
                };
                $number_of_inds[$branch][$sub_branch_id]['no_of_inds'] += 1;
                if (
                    $branch != $last_branch or $sub_branch_id != $last_sub_branch_id or
                    $generation != $last_gen
                ) {
                    $gen_no = 0;
                }
                $last_sub_branch_id = $sub_branch_id;
                $last_branch = $branch;
                $last_gen = $generation;
                $gen_no += 1;
                $this_gen_no = $generation . "." . $gen_no;
                if ($number_of_sub_branches > 0) $this_gen_no = $sub_branch_no . "-" . $this_gen_no;
                if ($number_of_branches > 1) $this_gen_no = $branch . "-" . $this_gen_no;
                $this->generationInd[$key]['gen_no'] = $this_gen_no;

                // now consolidate "reference"
                $ref = $this->generationInd[$key]['reference'];
                $search = array('0+', '0-', '-M', '-F', '+M', '+F', '0F', '0M', '-aM', '-aF', '+aM', '+aF');
                $rep     = array('+',  '-', 'F', 'M', 'S', 'D', 'W', 'H', 'f', 'm', 's', 'd');
                $ref = str_replace($search, $rep, $ref);
                // remove starting r
                if ($ref[0] == "r") {
                    $ref = substr($ref, 1);
                    // now check if direct ancestor or direct descendant
                    if (!preg_match('/[WHSDfmsd]/', $ref) or !preg_match('/[WHFMfmsd]/', $ref)) {
                        // direct ancestor or direct descendant
                        $this->generationInd[$key]['ancestor'] = 1;
                    } else if (preg_match('/^[FM]*[SD]*$/', $ref)) {
                        // indirect 
                        $this->generationInd[$key]['ancestor'] = 2;
                    }
                } else {
                    $ref = '';
                }

                // count same symbols
                for ($i = $ng; $i >= 1; $i--) {
                    $ref = preg_replace('/(\w)\1{' . $i . '}/', ($i + 1) . '$1', $ref);
                }
                // now replace with custom symbols
                $replacement = array(
                    'F' => $this->settings["ref_father"] . '-',
                    'M' => $this->settings["ref_mother"] . '-',
                    'S' => $this->settings["ref_son"] . '-',
                    'D' => $this->settings["ref_daughter"] . '-',
                    'W' => $this->settings["ref_wife"] . '-',
                    'H' => $this->settings["ref_husband"] . '-',
                    'f' => 'a' . $this->settings["ref_father"] . '-',
                    'm' => 'a' . $this->settings["ref_mother"] . '-',
                    's' => 'a' . $this->settings["ref_son"] . '-',
                    'd' => 'a' . $this->settings["ref_daughter"] . '-'
                );
                $new_ref = "";
                foreach (str_split($ref) as $char) {
                    if (array_key_exists($char, $replacement)) {
                        $new_ref = $new_ref . $replacement[$char];
                    } else {
                        $new_ref = $new_ref . $char;
                    }

                }
                $ref = $new_ref;
                $ref = substr($ref, 0, -1);
                // set reference
                $this->generationInd[$key]['reference'] = $ref;
            }
            // add main sub branch if it does not exists
            foreach ($number_of_inds as $key => $row) {
                if (!array_key_exists(-1, $row))
                    $number_of_inds[$key][-1] = array('no_of_inds' => 0, 'sub_branch' => '');
            }

            // now copy seeds to seed branch 

            if (count($seeds) > 0) {
                foreach ($inds_to_be_copied as $row) {
                    $xref = $row["xref"];
                    $branch = $this->generationInd[$xref]["branch"];
                    $xref_sub_branch_id = $this->generationInd[$xref]["sub_branch_id"];
                    $xref_sub_branch  = $this->generationInd[$xref]["sub_branch"];
                    $original_sub_branch_id = $row["sub_branch_id"];
                    $original_sub_branch = $row["sub_branch"];
                    $connected = $row["connected"];
                    $id = $xref . '_' . $original_sub_branch_id;

                    // copy only if sub branch has members
                    // and if they are connected to the branch via parent, children or spouse
                    if (
                        array_key_exists($original_sub_branch_id, $number_of_inds[$branch]) and
                        $number_of_inds[$branch][$original_sub_branch_id]['no_of_inds'] > 0 and
                        $connected
                    ) {
                        // this is the new copy in the old subbranch
                        $this->generationInd[$id] = $this->generationInd[$xref];
                        $this->generationInd[$id]["sub_branch"] = $original_sub_branch;
                        $this->generationInd[$id]["sub_branch_id"] = $original_sub_branch_id;

                        if (in_array($xref_sub_branch_id, $seeds_exported)) {
                            $this->generationInd[$id]["sub_branch_link_id"] = $xref_sub_branch_id;
                            $this->generationInd[$id]["sub_branch_link_name"] = $xref_sub_branch;
                        }
                        // link from sub branch to  branch
                        if (in_array($original_sub_branch_id, $seeds_exported)) {
                            $this->generationInd[$xref]["sub_branch_link_id"] = $original_sub_branch_id;
                            $this->generationInd[$xref]["sub_branch_link_name"] = $original_sub_branch;
                        }
                    }
                }

                // now sort again for branches and generations					
                foreach ($this->generationInd as $key => $row) {
                    $a_branch[$key] = $row['branch'];
                    $a_sub_branch[$key] = $row['sub_branch_id'];
                    $a_generation[$key] = $row['generation'];
                    $a_name[$key] = $row['name'];
                    $a_givenname[$key] = $row['givenname'];
                }

                array_multisort(
                    $a_branch,
                    SORT_ASC,
                    $a_sub_branch,
                    SORT_ASC,
                    $a_generation,
                    SORT_ASC,
                    $a_name,
                    SORT_ASC,
                    $a_givenname,
                    SORT_ASC,
                    SORT_STRING,
                    $this->generationInd
                );
            }
            // copy everything in the final structure
            $generationInd_tree = array();
            foreach ($this->generationInd as $key => $row) {
                $branch = $row['branch'];
                $sub_branch_id = $row['sub_branch_id'];
                $generation = $row['generation'];
                if (!array_key_exists($branch, $generationInd_tree)) $generationInd_tree[$branch] = array();
                if (!array_key_exists($sub_branch_id, $generationInd_tree[$branch])) $generationInd_tree[$branch][$sub_branch_id] = array();
                if (!array_key_exists($generation, $generationInd_tree[$branch][$sub_branch_id])) $generationInd_tree[$branch][$sub_branch_id][$generation] = array();
                $generationInd_tree[$branch][$sub_branch_id][$generation][$key] = $row;
            }
        } else {
            $generationInd_tree = array();
        }

        return $generationInd_tree;
    }

    /**
     * Settings of the family nodes with marriage information (GraphML)
     *
     * Older settings (files in the data folder, profiles, downloaded files, example file) have one block
     * "Family" only. A missing field of the block "Family with marriage information" gets the value of
     * this block for the colours, the border width and the font size (as the box of the text looked
     * before); the shape is "rectangle" and the size automatic, because the shape and the size of the
     * block "Family" are made for the small symbol.
     *
     * @param array $settings settings or form data
     * @return array the settings with all fields of the family nodes
     */
    public static function familyNodeSettings(array $settings): array
    {
        $defaults = array(
            'color_family' => '#ffffff',
            'border_family' => '#ffffff',
            'border_width_family' => '1',
            'font_size_family' => '10',
            'node_style_family' => 'diamond',
            'box_width_family' => '120',
        );
        foreach ($defaults as $field => $default) {
            if (!array_key_exists($field, $settings)) {
                $settings[$field] = $default;
            }
        }
        foreach (array('color', 'border', 'border_width', 'font_size') as $field) {
            if (!array_key_exists($field . '_marriage', $settings)) {
                $settings[$field . '_marriage'] = $settings[$field . '_family'];
            }
        }
        foreach (array(
            'node_style_marriage' => 'rectangle', 'box_width_marriage' => '', 'box_height_marriage' => '', 'size_marriage' => 'export',
        ) as $field => $default) {
            if (!array_key_exists($field, $settings)) {
                $settings[$field] = $default;
            }
        }

        return $settings;
    }

    /**
     * Lines of text of the label of a family node as yEd shows them
     *
     * An HTML label ("<html>...") breaks lines at <br>, </tr>, </p>, </div>, </li> and headings, not at
     * line breaks of the template; the tags are removed. A label without HTML breaks lines at line breaks.
     * Empty lines are left out, so a label without text gives no lines.
     *
     * @param string $label the label (before < and > are replaced)
     * @return array<string>
     */
    private function familyLabelLines(string $label): array
    {
        if (preg_match('~^\s*<html>~i', $label) === 1) {
            $text = str_replace(array("\r", "\n"), ' ', $label);
            $text = (string) preg_replace('~<\s*(br|/tr|/p|/div|/li|/h[1-6]|hr)\b[^>]*>~i', "\n", $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $text = str_replace("\r", '', $label);
        }
        $lines = array();
        foreach (explode("\n", $text) as $line) {
            $line = trim((string) preg_replace('~[\s\x{00A0}]+~u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Width of characters relative to the font size (em) for the fonts of the form (GraphML)
     *
     * Classes: n narrow (space . , : ; ( ) [ ] - / i j l t f r I ! ...), d digits and other signs, l lower
     * case letters, u upper case letters, w wide letters (m w M W), s symbols (from U+2000, e.g. ⚭ †, which
     * are mostly shown in an own font). Measured with the Java of yEd 3.25.1 (Swing, as yEd draws HTML
     * labels) for font sizes 6 to 14; a font not in the list uses the widest value of each class.
     * "line" is the line height relative to the font size.
     */
    private const FAMILY_FONT_METRICS = array(
        'Times New Roman' => array('n' => 0.30, 'd' => 0.52, 'l' => 0.46, 'u' => 0.66, 'w' => 0.88, 's' => 0.85, 'line' => 1.3),
        'Dialog' => array('n' => 0.28, 'd' => 0.56, 'l' => 0.55, 'u' => 0.69, 'w' => 0.83, 's' => 0.85, 'line' => 1.3),
        'Franklin Gothic Book' => array('n' => 0.28, 'd' => 0.58, 'l' => 0.52, 'u' => 0.58, 'w' => 0.80, 's' => 0.85, 'line' => 1.3),
        'Bookman Old Style' => array('n' => 0.35, 'd' => 0.62, 'l' => 0.60, 'u' => 0.71, 'w' => 0.94, 's' => 0.85, 'line' => 1.3),
        'Lucida Handwriting' => array('n' => 0.42, 'd' => 0.64, 'l' => 0.68, 'u' => 0.75, 'w' => 0.98, 's' => 0.85, 'line' => 1.45),
    );

    /**
     * Estimated width of a line of text in a family node (GraphML)
     *
     * The sum of the widths of the characters by class (see FAMILY_FONT_METRICS) with 8 % and 1 point
     * added, so the text is rather a few points too wide than cut off: compared with the width yEd uses
     * for 412 labels in the 5 fonts and 8 font sizes, no estimate was too small, on average 13 % too
     * large.
     *
     * @param string $line text without HTML (see familyLabelLines)
     * @param array<string,float> $metrics widths of the classes (see FAMILY_FONT_METRICS)
     * @param float $font_size
     * @return float
     */
    private function familyTextWidth(string $line, array $metrics, float $font_size): float
    {
        $em = 0.0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (preg_match('~^[\s.,:;\'!|()\[\]/"`\x{00A0}ijltfrI-]$~u', $char) === 1) {
                $em += $metrics['n'];
            } elseif (preg_match('~^[mwMW]$~', $char) === 1) {
                $em += $metrics['w'];
            } elseif (preg_match('~^\p{Lu}$~u', $char) === 1) {
                $em += $metrics['u'];
            } elseif (preg_match('~^\p{Ll}$~u', $char) === 1) {
                $em += $metrics['l'];
            } elseif (mb_ord($char) >= 0x2000) {
                $em += $metrics['s'];
            } else {
                $em += $metrics['d'];
            }
        }

        return $em * $font_size * 1.08 + 1;
    }

    /**
     * The shape of a family node with marriage information (GraphML, see exportGraphml)
     *
     * The node is as large as the text: estimated from the widths of the characters of the longest line
     * in the font of the form (see familyTextWidth), the number of lines and the font size, with a margin;
     * a width or height set in the form is used instead. The label has the same size as the node
     * (autoSizePolicy "node_size") and covers it with the fill and border colour of the form, so the edges
     * end at the border of the box. The node keeps the black fill, by which the layout "Family Tree" of
     * yEd finds the family nodes. The shape of the form is the shape of the node under the label: it
     * decides where the edges end; "rectangle" ends them exactly at the border of the box.
     *
     * Centring: yEd draws an HTML label as a Swing label as large as the box less 2 points on each side,
     * with the content at the left and in the middle vertically; alignment="center" does not move it.
     * So the content of an HTML label is put into a table as wide as this area with one centred cell
     * without line breaks; this centres it horizontally, also if the estimate is a bit too large. A label
     * without HTML is centred horizontally by yEd itself (alignment="center").
     *
     * Size "fitted in yEd" (size_marriage "yed"): the content is not put into the table, because "Fit Node to
     * Label" of yEd measures the content of a label with autoSizePolicy "node_size" (a copy of the label with
     * the size of its content) and would keep the width of the table. The estimated size is the size until
     * the node is fitted in yEd; after fitting, the box is as large as the text plus the inner margin and the
     * space of the dialog, so the text is in the middle (1 point difference with a space of 1).
     *
     * @param array $parameter settings of the form (see familyNodeSettings)
     * @param array<string> $lines lines of text of the label (see familyLabelLines)
     * @param string $label the label with < and > replaced
     * @return string the <data key="d3"> element
     */
    private function familyTextNode(array $parameter, array $lines, string $label): string
    {
        $font_size = is_numeric($parameter['font_size_marriage']) && (float) $parameter['font_size_marriage'] > 0
            ? (float) $parameter['font_size_marriage'] : 10.0;
        $metrics = self::FAMILY_FONT_METRICS[$parameter['font']] ?? null;
        if ($metrics === null) {
            // font not in the form: the widest value of each class
            $metrics = array();
            foreach (self::FAMILY_FONT_METRICS as $font_metrics) {
                foreach ($font_metrics as $class => $value) {
                    $metrics[$class] = max($metrics[$class] ?? 0.0, $value);
                }
            }
        }
        $text_width = 0.0;
        foreach ($lines as $line) {
            $text_width = max($text_width, $this->familyTextWidth($line, $metrics, $font_size));
        }
        // 2 points inner margin of the label on each side and 1 point between text and border
        $width = $parameter['box_width_marriage'];
        $width = is_numeric($width) && (float) $width > 0 ? (float) $width : ceil($text_width) + 6;
        $height = $parameter['box_height_marriage'];
        $height = is_numeric($height) && (float) $height > 0 ? (float) $height
            : ceil(count($lines) * $font_size * $metrics['line']) + 6;

        // HTML label: the content in a centred table cell as wide as the label less its inner margins
        if ($parameter['size_marriage'] !== 'yed'
            && preg_match('~^(\s*&lt;html&gt;)(.*?)(&lt;/html&gt;\s*)?$~is', $label, $match) === 1) {
            $label = $match[1] . '&lt;table width="' . max(1, (int) floor($width - 4)) .
                '" cellpadding="0" cellspacing="0"&gt;&lt;tr&gt;&lt;td align="center" nowrap&gt;' . $match[2] .
                '&lt;/td&gt;&lt;/tr&gt;&lt;/table&gt;' . ($match[3] ?? '&lt;/html&gt;');
        }

        $border_width = is_numeric($parameter['border_width_marriage']) ? (float) $parameter['border_width_marriage'] : 1.0;
        $shapes = array(
            'rectangle', 'roundrectangle', 'ellipse', 'parallelogram', 'hexagon', 'triangle', 'rectangle3d',
            'octagon3d', 'diamond', 'trapezoid', 'trapezoid2',
        );
        $shape = in_array($parameter['node_style_marriage'], $shapes, true) ? $parameter['node_style_marriage'] : 'rectangle';
        $color = $parameter['color_marriage'];
        $border = $parameter['border_marriage'];
        if ($border_width > 0) {
            // the border of the node lies on the border of the label (no black edge)
            $node_border = '<y:BorderStyle color="' . $border . '" type="line" width="' . $parameter['border_width_marriage'] . '"/>';
            $label_line = 'hasLineColor="true" lineColor="' . $border . '"';
        } else {
            $node_border = '<y:BorderStyle hasColor="false" type="line" width="1.0"/>';
            $label_line = 'hasLineColor="false"';
        }

        return '<data key="d3"> <y:ShapeNode><y:Geometry height="' . $height . '" width="' . $width .
            '" x="28" y="28"/><y:Fill color="#000000" color2="#000000" transparent="false"/>' . $node_border .
            '<y:NodeLabel alignment="center" autoSizePolicy="node_size" backgroundColor="' . $color . '" ' .
            $label_line . ' textColor="#000000" fontFamily="' . $parameter['font'] . '" fontSize="' .
            $parameter['font_size_marriage'] . '" fontStyle="plain" visible="true" modelName="internal" ' .
            'modelPosition="c" width="' . $width . '" height="' . $height . '" x="0" y="0">' . $label .
            '</y:NodeLabel> <y:Shape type="' . $shape . '"/></y:ShapeNode> </data>' . "\n";
    }

    /**
     * Is a family at the end of a branch only (GraphML)?
     *
     * Where a branch ends and another one begins, the individual connecting both (red border) is exported
     * in both branches with all its families. A family gets no node in a sub branch if
     * - in this sub branch only one member of the family (husband, wife, children) is exported and this
     *   member connects the sub branch with another one, and
     * - in another exported sub branch at least two members of the family are exported, so the family is
     *   shown there.
     * Families without a connecting individual (e.g. couples without children) and families shown nowhere
     * else keep their node.
     *
     * @param Family $family
     * @param array<string,bool> $members individuals of this sub branch (xref => true)
     * @param array<string,bool> $links connecting individuals of this sub branch (xref => true)
     * @param array<int,array<string,bool>> $sub_branch_members individuals of each exported sub branch
     * @param int $index index of this sub branch in $sub_branch_members
     * @return bool
     */
    private function familyAtBranchEnd($family, array $members, array $links, array $sub_branch_members, int $index): bool
    {
        $in_family = array();
        foreach (array($this->husband($family), $this->wife($family)) as $parent) {
            if ($parent) {
                $in_family[$parent->xref()] = true;
            }
        }
        foreach ($this->children($family) as $child) {
            $in_family[$child->xref()] = true;
        }
        $here = array_intersect_key($in_family, $members);
        if (count($here) !== 1 || !isset($links[array_key_first($here)])) {
            return false;
        }
        foreach ($sub_branch_members as $i => $other) {
            if ($i !== $index && count(array_intersect_key($in_family, $other)) >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Export the data in graphml format
     *
     * This is the main module which export the familty tree in graphml
     * format.
     *
     * @param resource $gedout
     *        	Handle to a writable stream
     */
    private function exportGraphml($gedout)
    {

        // get parameter entered in the web form (older settings: see familyNodeSettings)
        $parameter = self::familyNodeSettings($this->settings);

        $this->generationInd  = array();
        $this->include_exclude_media = array();
        $this->media_included = array();
        $this->icon_resources = array();
        $this->icon_cache = array();

        foreach (array(
            "label", "description"
        ) as $a) {
            $name = "individuals_" . $a . "_template";
            // First split the html templates
            // This is done once and later used when exporting
            // data for the familty tree record.
            $template_ind[$a] = array();
            list($template_ind[$a][0], $brackets[$a]) = $this->splitTemplate(
                $parameter[$name]
            );

            $name = "families_" . $a . "_template";
            // First split the html templates
            // This is done once and later used when exporting
            // data for the familty tree record.
            $template_fam[$a] = array();
            list($template_fam[$a][0], $brackets_fam[$a]) = $this->splitTemplate(
                $parameter[$name]
            );
        }

        // get record of individuals and families
        $generationInd_tree = $this->getRecords(TRUE);

        // Get header.
        // Buffer the output. Lots of small fwrite() calls can be very
        // slow when writing large files (copied from one of the webtree modules).
        // the name spaces needed for embedded portraits only if a template asks for them, so the
        // header of other exports stays unchanged
        $buffer = $this->graphmlHeader(
            $this->templateHasTag($template_ind["label"][0], "PortraitIcon") ||
                $this->templateHasTag($template_ind["description"][0], "PortraitIcon")
        );

        // get seeds
        $seeds = $this->getSeeds();

        /*
		 * Create nodes for individuals and families
		 */

        // loop over all individuals
        // individuals of each exported sub branch, in the order of the export (see familyAtBranchEnd)
        $sub_branch_members = array();
        foreach ($generationInd_tree as $branch_array) {
            foreach ($branch_array as $sub_branch_id => $sub_branch_array) {
                if (key_exists($sub_branch_id, $seeds) && $seeds[$sub_branch_id]["include"]) {
                    $members = array();
                    foreach ($sub_branch_array as $generation_array) {
                        foreach ($generation_array as $value) {
                            $members[$value['xref']] = true;
                        }
                    }
                    $sub_branch_members[] = $members;
                }
            }
        }

        $no_edge = 0;
        $sub_branch_no = 0;
        $no_of_branches = count($generationInd_tree);
        foreach ($generationInd_tree as $branch => $branch_array) {
            $no_of_sub_branches = 0;
            foreach ($branch_array as $sub_branch_id => $sub_branch_array) {
                if (key_exists($sub_branch_id, $seeds)) {
                    if ($seeds[$sub_branch_id]["include"]) $no_of_sub_branches++;
                }
            }

            foreach ($branch_array as $sub_branch_id => $sub_branch_array) {
                if (key_exists($sub_branch_id, $seeds)) {
                    if ($seeds[$sub_branch_id]["include"]) {
                        $include = TRUE;
                    } else {
                        $include = FALSE;
                    }
                } else {
                    $include = FALSE;
                }

                if ($include) {

                    $no_of_generations = count($sub_branch_array);
                    // families of the sub branch in the order of their first occurrence
                    $xrefFam = array();
                    // individuals of the sub branch, the xref is the key
                    $ind_xref_list = array();
                    // individuals connecting the sub branch with another one (red border), the xref is the key
                    $link_xref_list = array();
                    $sub_branch_no += 1;

                    foreach ($sub_branch_array as $generation => $generation_array) {
                        // create individual nodes
                        foreach ($generation_array as $ind_id => $value) {
                            // increase time limit
                            $this->increaseTimeLimit();
                            $xref = $value['xref'];
                            $node_id = $xref . "_" . $sub_branch_no;
                            $ind_xref_list[$xref] = true;
                            $has_sub_branch_link = array_key_exists("sub_branch_link_id", $value);
                            if ($has_sub_branch_link) {
                                $link_xref_list[$xref] = true;
                            }
                            foreach ($value['spouse_family'] as $family) {
                                $xrefFam[$family->xref()] = $family->xref();
                            }

                            $record = Registry::individualFactory()->make($xref, $this->tree);

                            // get parameter for the export
                            $sex = $record->sex();
                            if ($sex == "F") {
                                $s = 'female';
                            } elseif ($sex == "M") {
                                $s = 'male';
                            } else {
                                $s = 'unknown';
                            }
                            $col = $parameter['color_' . $s];
                            $box_width = $parameter['box_width_' . $s];
                            if (!$has_sub_branch_link) {
                                $border_width = $parameter['border_width_' . $s];
                                $node_style = $parameter['node_style_' . $s];
                                $col_border = $parameter['border_' . $s];
                            } else {
                                $border_width = $parameter['border_width_link'];
                                $node_style = $parameter['node_style_link'];
                                $col_border = $parameter['border_link_color'];
                            }
                            $font_size = $parameter['font_size_' . $s];
                            // set by @PortraitIcon@
                            $this->node_icon = null;
                            // loop to create the output for the node label
                            // and the node description
                            foreach (array(
                                "label", "description"
                            ) as $a) {
                                $nodetext[$a] = $this->substitutePlaceHolder(
                                    $record,
                                    $record,
                                    $ind_id,
                                    $template_ind[$a],
                                    "graphml",
                                    $brackets[$a]
                                )[0];
                            }

                            // the replacement of < and > has to be done for "lable"
                            // for "description" no replacement must be done (not clear why)
                            $nodetext["label"] = str_replace(
                                "<",
                                "&lt;",
                                $nodetext["label"]
                            );
                            $nodetext["label"] = str_replace(
                                ">",
                                "&gt;",
                                $nodetext["label"]
                            );

                            // count the number of rows to set the box height accordingly
                            $label_rows = count(
                                explode("&lt;br&gt;", $nodetext["label"])
                            ) +
                                count(explode("&lt;tr&gt;", $nodetext["label"])) + 1;
                            $node_height = 12 * $label_rows;

                            // portrait as icon of the label (@PortraitIcon@)
                            $icon_attributes = '';
                            if ($this->node_icon !== null) {
                                list($icon_id, $icon_width, $icon_height, $icon_position) = $this->node_icon;
                                if ($icon_position === 'left') {
                                    $icon_attributes = ' iconData="' . $icon_id . '" iconTextGap="4" horizontalTextPosition="right" verticalTextPosition="center"';
                                    $node_height = max($node_height, $icon_height + 8);
                                } else {
                                    $icon_attributes = ' iconData="' . $icon_id . '" iconTextGap="4" horizontalTextPosition="center" verticalTextPosition="bottom"';
                                    $node_height += $icon_height + 4;
                                }
                            }

                            // create export for the node
                            $buffer .= '<node id="' . $node_id . '">' . "\n" .
                                '<data key="d1"><![CDATA[http://my.site.com/' . $node_id .
                                '.html]]></data>' . "\n" . '<data key="d2"><![CDATA[' .
                                $nodetext["description"] . ']]></data>' . "\n" .
                                '<data key="d3">' . '<y:GenericNode configuration="' .
                                $node_style . '"> <y:Geometry height="' . $node_height .
                                '" width="' . $box_width .
                                '" x="10" y="10"/> <y:Fill color="' . $col .
                                '" transparent="false"/> <y:BorderStyle color="' .
                                $col_border . '" type="line" width="' . $border_width .
                                '"/> <y:NodeLabel alignment="center" autoSizePolicy="content" hasBackgroundColor="false" hasLineColor="false" textColor="#000000" fontFamily="' .
                                $parameter['font'] . '" fontSize="' . $font_size .
                                '" fontStyle="plain" visible="true" modelName="internal" modelPosition="l" width="129" height="19" x="1" y="1"' .
                                $icon_attributes . '>';

                            // no line break before $nodetext allowed
                            $buffer .= $nodetext["label"] . "\n" .
                                '</y:NodeLabel> </y:GenericNode> </data>' . "\n" .
                                "</node>\n";

                            // write to file if buffer is full
                            if (strlen($buffer) > 65536) {
                                $this->graphml_fwrite($gedout, $buffer);
                                $buffer = '';
                            }
                        }
                    }
                    /*
				 * Create nodes for families
				 */
                    // get parameter for the export
                    // family without marriage information (empty label): small symbol
                    $col = $parameter['color_family'];
                    $node_style = $parameter['node_style_family'];
                    $col_border = $parameter['border_family'];
                    $box_width = $parameter['box_width_family'];
                    $border_width = $parameter['border_width_family'];
                    $font_size = $parameter['font_size_family'];

                    // loop over all families
                    foreach ($xrefFam as $xref) {
                        // increase time limit
                        $this->increaseTimeLimit();
                        $record = Registry::familyFactory()->make($xref, $this->tree);

                        // no node (and no edges) where a branch ends and the family belongs to another branch
                        if ($this->familyAtBranchEnd($record, $ind_xref_list, $link_xref_list, $sub_branch_members, $sub_branch_no - 1)) {
                            unset($xrefFam[$xref]);
                            continue;
                        }

                        $node_id_fam = $xref . "_" . $sub_branch_no;
                        // now replace the tags with record data
                        // the algorithm is the same as for individuals (see above)
                        foreach (array(
                            "label", "description"
                        ) as $a) {
                            $nodetext[$a] = $this->substitutePlaceHolder(
                                $record,
                                $record,
                                '',
                                $template_fam[$a],
                                "graphml",
                                $brackets_fam[$a]
                            )[0];
                        }
                        // text lines of the label as shown by yEd (without HTML tags)
                        $label_lines = $this->familyLabelLines($nodetext["label"]);

                        // for the "lable" < and > must be replaced
                        // for description no replacement is required (not clear why this is the case)
                        $nodetext["label"] = str_replace(
                            "<",
                            "&lt;",
                            $nodetext["label"]
                        );
                        $nodetext["label"] = str_replace(
                            ">",
                            "&gt;",
                            $nodetext["label"]
                        );

                        // write export data
                        $buffer .= '<node id="' . $node_id_fam . '">' . "\n";

                        $buffer .= '<data key="d1"><![CDATA[http://my.site.com/' . $node_id_fam .
                            '.html]]></data>' . "\n";
                        $buffer .= '<data key="d2"><![CDATA[' . $nodetext["description"] .
                            ']]></data>' . "\n";

                        if (count($label_lines) > 0) {
                            // family with marriage information: the node has the size of the text and the
                            // label covers it (autoSizePolicy node_size), so the edges end at the border of the
                            // box; the node itself stays black for the layout "Family Tree" of yEd
                            $buffer .= $this->familyTextNode($parameter, $label_lines, $nodetext["label"]);
                        } else {
                            // family without marriage information: small symbol, the label is not visible
                            // count the number of rows to scale the box height accordingly
                            $label_rows = count(
                                explode("&lt;br&gt;", $nodetext["label"])
                            ) +
                                count(explode("&lt;tr&gt;", $nodetext["label"]));

                            // note fill color must be black
                            // otherwise yed does not find the family nodes
                            $buffer .= '<data key="d3"> <y:ShapeNode>' . '<y:Geometry height="' .
                                $box_width . '" width="' . $box_width . '" x="28" y="28"/>' .
                                '<y:Fill color="#000000" color2="#000000" transparent="false"/>';

                            $buffer .= '<y:BorderStyle hasColor="true" type="line" color="' .
                                $col_border . '" width="' . $border_width . '"/>';
                            $buffer .= '<y:NodeLabel alignment="center" autoSizePolicy="content" ' .
                                'backgroundColor="' . $col . '" hasLineColor="true" ' .
                                'lineColor="' . $col_border . '" ' .
                                'textColor="#000000" fontFamily="' . $parameter['font'] .
                                '" fontSize="' . $font_size . '" ' .
                                'fontStyle="plain" visible="false" modelName="internal" modelPosition="c" ' . 'width="' .
                                $box_width . '" height="' . (12 * $label_rows) .
                                '" x="10" y="10">';

                            $buffer .= $nodetext["label"];

                            $buffer .= '</y:NodeLabel> <y:Shape type="' . $node_style . '"/>' .
                                '</y:ShapeNode> </data>' . "\n";
                        }
                        $buffer .= "</node>\n";

                        // write data if buffer is full
                        if (strlen($buffer) > 65536) {
                            $this->graphml_fwrite($gedout, $buffer);
                            $buffer = '';
                        }
                    }

                    /*
				 * Create edges from families to individuals
				 */

                    // loop over families
                    foreach ($xrefFam as $xref) {
                        $node_id_fam = $xref . "_" . $sub_branch_no;
                        $record = Registry::familyFactory()->make($xref, $this->tree);

                        // get all parents
                        $parents = array(
                            $this->husband($record), $this->wife($record)
                        );

                        $key6 = '<data key="d6"> <y:PolyLineEdge> <y:Path sx="0.0" sy="17.5" tx="0.0" ty="-10"/> <y:LineStyle color="#000000" type="line" width="' .
                            $parameter['edge_line_width'] .
                            '"/> <y:Arrows source="none" target="none"/> <y:BendStyle smoothed="false"/> </y:PolyLineEdge> </data>' .
                            "\n" . '</edge>' . "\n";
                        // loop over parents and add edges for parents
                        foreach ($parents as $parent) {
                            if ($parent and isset($ind_xref_list[$parent->xref()])) {
                                $no_edge += 1;
                                $buffer .= '<edge id="' . $no_edge . '" source="' .
                                    $parent->xref() . "_" . $sub_branch_no
                                    . '" target="' . $node_id_fam . '">' . "\n" . $key6;
                            }
                        }

                        // get all children and add edges for children
                        $children = $this->children($record);

                        foreach ($children as $child) {
                            if (isset($ind_xref_list[$child->xref()])) {
                                $no_edge += 1;
                                $buffer .= '<edge id="' . $no_edge . '" source="' . $node_id_fam .
                                    '" target="' . $child->xref() . "_" . $sub_branch_no
                                    . '">' . "\n" . $key6;
                            }
                        }

                        // write data if buffer is full
                        if (strlen($buffer) > 65536) {
                            $this->graphml_fwrite($gedout, $buffer);
                            $buffer = '';
                        }
                    }
                }
            }
        }
        // add footer and write buffer
        $buffer .= $this->graphmlFooter();
        $this->graphml_fwrite($gedout, $buffer);
    }
    /**
     * The entry of each individual which defines the label of the individual
     *
     * An individual connecting two branches has a full entry in both (getRecords): the original in its
     * own branch (key = xref) and a copy in the old branch (key = xref_<number of the old branch>).
     * The label belongs to the original. If the branch of the original is not exported, the first
     * exported copy (order of the book) defines it.
     *
     * @param array $generationInd_tree see getRecords
     * @param array $seeds see getSeeds
     * @return array<string,string> xref => key of the entry
     */
    private function labelEntries(array $generationInd_tree, array $seeds): array
    {
        $entries = array();
        $this->exported_entries = array();
        foreach ($generationInd_tree as $branch_array) {
            foreach ($branch_array as $sub_branch_id => $sub_branch_array) {
                // the same selection of branches as in exportLatex
                if (!key_exists($sub_branch_id, $seeds) || !$seeds[$sub_branch_id]["include"]) {
                    continue;
                }
                foreach ($sub_branch_array as $generation_array) {
                    foreach ($generation_array as $ind_id => $value) {
                        $xref = $value['xref'];
                        $this->exported_entries[(string) $ind_id] = true;
                        if (!array_key_exists($xref, $entries) || (string) $ind_id === $xref) {
                            $entries[$xref] = (string) $ind_id;
                        }
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * Set the entry which is processed next (see $entry_has_label)
     *
     * @param string $xref individual of the entry
     * @param string|int $ind_id key of the entry in generationInd
     * @param array<string,string> $label_entries see labelEntries
     */
    private function setLabelEntry(string $xref, $ind_id, array $label_entries): void
    {
        $this->entry_xref = $xref;
        $this->entry_id = (string) $ind_id;
        $this->label_entries = $label_entries;
        $this->entry_has_label = ($label_entries[$xref] ?? (string) $ind_id) === (string) $ind_id;
    }

    /**
     * The label of an entry (LaTeX)
     *
     * The entry with the label of the individual (see labelEntries) has this label; another entry of
     * the individual (copy in a second branch) has "<label of the individual>/<number of its branch>".
     * A "/" never occurs in an XREF, so this label cannot be the label of another individual.
     *
     * @param string $ind_id key of the entry in generationInd
     * @param string $label label of the individual (already masked)
     */
    private function entryLabel(string $ind_id, string $label): string
    {
        $xref = $this->generationInd[$ind_id]["xref"];
        if (($this->label_entries[$xref] ?? $ind_id) === $ind_id) {
            return $label;
        }

        return $label . '/' . $this->generationInd[$ind_id]["sub_branch_id"];
    }

    /**
     * Export the data in graphml format
     *
     * This is the main module which export the familty tree in graphml
     * format.
     *
     * @param resource $gedout
     *        	Handle to a writable stream
     */
    private function exportLatex($gedout)
    {

        // settings of the export
        $parameter = $this->settings;

        $this->generationInd  = array();
        $this->include_exclude_media = array();
        $this->media_included = array();

        $name = "individuals_label_template";
        // First split the html templates
        // This is done once and later used when exporting
        // data for the familty tree record.
        $template = array();
        list($template[0], $brackets) = $this->splitTemplate($parameter[$name]);

        $summary_template = array();
        list($summary_template[0], $summary_brackets) = $this->splitTemplate($parameter["individuals_summary_label_template"]);

        // media collection: only the media loops of the templates are processed
        if ($this->media_collector !== null) {
            $template[0] = $this->mediaOnlyTemplate($template[0]) ?? $this->emptyTemplate();
            $summary_template[0] = $this->mediaOnlyTemplate($summary_template[0]) ?? $this->emptyTemplate();
        }

        // get list of media to be included and excluded
        $this->include_exclude_media = array("include" => array(), "exclude" => array());
        foreach (array("include", "exclude") as $a) {
            $media_list = $parameter[$a . "_media"];
            $l = strlen($media_list);
            if (strlen($media_list) > 0) $this->include_exclude_media[$a] = array_map('strtoupper', array_map('trim', explode(',', $media_list)));
        }

        // now generate an array with fact names to be replaced by symbols
        $all_symb = $parameter["symbols"];
        $sep = $all_symb[0];
        $all_symb = substr($all_symb, 1);
        $fact_symbols = array();
        $fact_legend = array();
        $fact_label = array();
        foreach (explode("\n", $all_symb) as $row) {
            $row = trim($row);
            $cols = explode($sep, $row);
            if (count($cols) > 1) {
                $fact_symbols[$cols[0]] = $cols[1];
            }
            if (count($cols) > 2) {
                // if the entry exist it will be included in the Legend
                $fact_legend[$cols[0]] = $cols[1];
            }
            if (count($cols) > 3) {
                // if entry exists it will be used in the legend as a description
                $fact_label[$cols[0]] = $cols[3];
            }
        }

        // Get header.
        // Buffer the output. Lots of small fwrite() calls can be very
        // slow when writing large files (copied from one of the webtree modules).
        $buffer = $parameter["preamble"];

        // now add legend
        if (count($fact_legend) > 0) {
            $buffer .= '\newcommand{\GedcomLegende}{\begin{compactenum}';
            foreach ($fact_legend as $key => $symbol) {
                if (array_key_exists($key, $fact_label)) {
                    $label = $fact_label[$key];
                } else {
                    // label of webtrees (the label of the settings may contain LaTeX and is not masked)
                    $label = $this->getGEDCOMTagLabel($key);
                    if (!is_null($label)) {
                        $label = $this->substituteSpecialCharacters($label, "latex");
                    }
                }

                if (is_null($label)) {
                    $buffer .= '\item[' . $symbol . '] ' . $key;
                } else {
                    $buffer .= '\item[' . $symbol . '] ' . $label;
                }
            }
            $buffer .= '\end{compactenum}}';
        }

        // now add bibliography (not needed by the media collection)
        if ($this->media_collector === null) {
            $buffer .= $this->getReferences();
        }

        // now add title
        $buffer .= $parameter["title"];

        // get record of individuals and families
        $generationInd_tree = $this->getRecords(TRUE);

        // get seeds
        $seeds = $this->getSeeds();

        // the entry of each individual which defines its label
        $label_entries = $this->labelEntries($generationInd_tree, $seeds);

        /*
		 * Create nodes for individuals
		 */

        // loop over all individuals
        $no_of_branches = count($generationInd_tree);
        foreach ($generationInd_tree as $branch => $branch_array) {
            $no_of_sub_branches = 0;
            foreach ($branch_array as $sub_branch_id => $sub_branch_array) {
                if (key_exists($sub_branch_id, $seeds)) {
                    if ($seeds[$sub_branch_id]["include"]) $no_of_sub_branches++;
                }
            }

            if (($no_of_sub_branches > 0) && (substr($parameter["hierarchy_branch"], 0, 1) == "\\")) $buffer .= $parameter["hierarchy_branch"] .
                ($no_of_branches == 1 ? '' : " " . $branch) .
                "}" . "\n";

            foreach ($branch_array as $sub_branch_id => $sub_branch_array) {
                if (key_exists($sub_branch_id, $seeds)) {
                    if ($seeds[$sub_branch_id]["include"]) {
                        $include = TRUE;
                    } else {
                        $include = FALSE;
                    }
                } else {
                    $include = FALSE;
                }

                if ($include) {
                    $no_of_generations = count($sub_branch_array);
                    $hierarchy_sub_branch = $seeds[$sub_branch_id]["hierarchy_sub_branch"];
                    $hierarchy_generation = $seeds[$sub_branch_id]["hierarchy_generation"];
                    if ($hierarchy_sub_branch === "") $hierarchy_sub_branch = $parameter["hierarchy_sub_branch"];
                    if ($hierarchy_generation === "") $hierarchy_generation = $parameter["hierarchy_generation"];
                    $section_text = $seeds[$sub_branch_id]["name"];
                    // an empty field: no heading (instead of a single "}")
                    if ($no_of_generations > 0 && trim($hierarchy_sub_branch) !== "") $buffer .= $hierarchy_sub_branch .
                        ($no_of_sub_branches == 1 ? '' : " " . $section_text) .
                        "}" . "\n";

                    // summary section
                    if ($no_of_generations > 0 && sizeof($summary_template[0]) > 0) {
                        foreach ($sub_branch_array as $generation => $generation_array) {

                            foreach ($generation_array as $ind_id => $value) {
                                $xref = $value['xref'];
                                // increase time limit
                                $this->increaseTimeLimit();

                                $name = $value['name'];

                                $record = Registry::individualFactory()->make($xref, $this->tree);

                                // get parameter for the export
                                $sex = $record->sex();
                                if ($sex == "F") {
                                    $s = 'female';
                                } elseif ($sex == "M") {
                                    $s = 'male';
                                } else {
                                    $s = 'unknown';
                                }

                                // loop to create the output for the node label
                                // and the node description

                                $this->media_person = $xref;
                                $this->setLabelEntry($xref, $ind_id, $label_entries);
                                list($nodetext, $tag_resolved) = $this->substitutePlaceHolder(
                                    $record,
                                    $record,
                                    $ind_id,
                                    $summary_template,
                                    "latex",
                                    $summary_brackets,
                                    $fact_symbols
                                );

                                // no line break before $nodetext allowed
                                if ($nodetext !== "") {
                                    $buffer .= $nodetext  . "\n";
                                }

                                // write to file if buffer is full
                                if (strlen($buffer) > 65536) {
                                    $this->graphml_fwrite($gedout, $buffer);
                                    $buffer = '';
                                }
                            }
                        }
                    }

                    // generation sections
                    foreach ($sub_branch_array as $generation => $generation_array) {
                        if (count($generation_array) > 0 && trim($hierarchy_generation) !== "") $buffer .=
                            $hierarchy_generation .
                            ($no_of_generations == 1 ? '' : " " . $generation) .
                            "}" . "\n";

                        foreach ($generation_array as $ind_id => $value) {
                            $xref = $value['xref'];
                            // increase time limit
                            $this->increaseTimeLimit();

                            $name = $value['name'];

                            $record = Registry::individualFactory()->make($xref, $this->tree);

                            // get parameter for the export
                            $sex = $record->sex();
                            if ($sex == "F") {
                                $s = 'female';
                            } elseif ($sex == "M") {
                                $s = 'male';
                            } else {
                                $s = 'unknown';
                            }

                            // loop to create the output for the node label
                            // and the node description

                            $this->media_person = $xref;
                            $this->setLabelEntry($xref, $ind_id, $label_entries);
                            list($nodetext, $tag_resolved) = $this->substitutePlaceHolder(
                                $record,
                                $record,
                                $ind_id,
                                $template,
                                "latex",
                                $brackets,
                                $fact_symbols
                            );
                            if (!$this->entry_has_label) {
                                // "\label{@Id&xref@}" without "<...>" around it
                                $nodetext = str_replace('\label{}', '', $nodetext);
                            }

                            // no line break before $nodetext allowed
                            $buffer .= $nodetext  . "\n";

                            // write to file if buffer is full
                            if (strlen($buffer) > 65536) {
                                $this->graphml_fwrite($gedout, $buffer);
                                $buffer = '';
                            }
                        }
                    }
                }
            }
        }

        $this->setLabelEntry('', '', array());

        // add footer and write buffer
        $buffer .= $parameter["epilog"];
        $this->graphml_fwrite($gedout, $buffer);
    }
}
