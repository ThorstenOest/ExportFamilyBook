<?php

/**
 * ExportFamilyBook module for Webtrees
 *
 * This file is included in the module.php file
 */

//namespace haduloha\ExportFamilyBook\ExportFamilyBookData;

//use Aura\Router\RouterContainer;
//use Exception;
//use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Module\AbstractModule;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Tree;
//use Fisharebest\Webtrees\Webtrees;
use Fisharebest\Webtrees\Elements\UnknownElement;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Illuminate\Support\Collection;

use Fisharebest\Webtrees\Functions\FunctionsCharts;
use Fisharebest\Webtrees\Functions\FunctionsEdit;
use Fisharebest\Webtrees\Functions\FunctionsPrint;
use Fisharebest\Webtrees\Functions\FunctionsPrintLists;
//use Psr\Http\Message\ResponseFactoryInterface;
//use Psr\Http\Message\StreamFactoryInterface;



/**
 * Main class for FamilyBookExport module
 */
class ExportFamilyBookData extends AbstractModule
{


    // variables used in different functions 
    private $generationInd  = array();
    private $include_exclude_media = array();
    private $media_included = array();
    private $source_list = array();
    private $tree = null;

    /**
     * This function is called from ExportFamilyBook::postChartAction if mod_action is "export_latex" or "export_graphml".
     */
    public function export(ServerRequestInterface $request,  $stream)
    {

        #global $WT_TREE;

        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);

        $parsedBodys = $request->getParsedBody();

        // mod_action value is set by the definition of the submit button
        if (array_key_exists('mod_action', $parsedBodys)) {
            $mod_action = $parsedBodys['mod_action'];
        } else {
            $mod_action = "";
        };

        #$this->tree = $WT_TREE;
        $this->tree = $tree;
        /*
        // file name is set to the tree name
        $download_filename = $this->tree->name();
        $extension = ($mod_action == 'export_latex') ? ".tex" : ".graphml";
        if (
            strtolower(substr($download_filename, -8, 8)) !=
            $extension
        ) {
            $download_filename .= $extension;
        }
*/
        /*
        // Stream the file straight to the browser.
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $download_filename . '"');

        // Create a buffer to store the file content
        ob_start();

        // Write Byte Order Mark
        echo pack("CCC", 0xef, 0xbb, 0xbf);
        
        // $stream = fopen('php://output', 'w');
        // $stream = "";
        // Set Byte Order Mark
        #fwrite($stream, pack("CCC", 0xef, 0xbb, 0xbf));
 
        */
        fwrite($stream, pack("CCC", 0xef, 0xbb, 0xbf));

        if ($mod_action == "export_graphml") {
            $this->exportGraphml($stream);
        } else {
            $this->exportLatex($stream);
        }
        #fclose($stream);
        // Close output buffer
        //ob_end_flush();
        // exit;

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
                    //$givn .= " " . $exp_givn [$i] {0} . ".";
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
            if (!$format and array_key_exists("default_place_format", $_POST)) {
                $format = $_POST["default_place_format"];
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
     *        	A standard PHP date format.
     * @return string
     */
    private function formatDate($date, $format)
    {
        if ($date instanceof Date) {
            // check if default format to be used
            if (!$format and array_key_exists("default_date_format", $_POST)) {
                $format = $_POST["default_date_format"];
            }

            //            $date_label = strip_tags($date->display(false, $format));
            $date_label = strip_tags($date->display($this->tree, $format, false));
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
        #fwrite($gedout, mb_convert_encoding($buffer, 'UTF-8'));
        $buffer_encoded = mb_convert_encoding($buffer, 'UTF-8');
        $bytes_written = fwrite($gedout, $buffer_encoded);

        if ($bytes_written !== strlen($buffer_encoded)) {
            throw new RuntimeException('Unable to write to stream.  Perhaps the disk is full?');
        }
        // write output to buffer
        //echo mb_convert_encoding($buffer, 'UTF-8');
        // Flush the output buffer to send data to the client immediately
        //ob_flush();
        //flush();
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
     * @param string $servername
     *        	If $servername = true then the server file name
     *        	including the path is returned.
     * @return string The file name of the portrait
     */
    private function getPortrait($record, $format, $servername = false)
    {
        $portrait_file = "";

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
            if (array_key_exists('default_portrait_' . $s, $_POST)) {
                $portrait_fallback = $_POST['default_portrait_' . $s];
            } else {
                $portrait_fallback = "";
            }
        }

        if ($format == "silhouette") {
            // return the fallback figure if $format == "silhouette"
            $portrait_file = $portrait_fallback;
        } else {
            //$portrait = $record->findHighlightedMedia();
            $portrait = $record->findHighlightedMediaFile();
            if ($portrait) {
                $portrait_file = $portrait->filename();
                if ($servername) {
                    // get the full server name including path
                    $document_root = $_SERVER['DOCUMENT_ROOT'];
                    $script_name = $_SERVER['SCRIPT_NAME'];
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

        if ($portrait_file != "" && strlen($format_Size) > 1) {
            $constraint = $format_Size[0];
            $size_constraint = (float) substr($format_Size, 1);
            $image_size = getimagesize($portrait_file);
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
     *        	A list of positions in the ordered fact list which are returned
     * @return string A comma separted list of facts
     */
    private function getFact($record, $fact, $format = null)
    {
        // get all facts with identifier $fact as ordered array
        $fact_string = "";
        $Facts = $record->facts((array) $fact, true);
        if ($Facts) {
            if (empty($format)) {
                // if $format is not given return all items
                foreach ($Facts as $Fact) {
                    $fact_string .= $Fact->Value();
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
    private function graphmlHeader()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n" .
            '<graphml xmlns="http://graphml.graphdrawing.org/xmlns" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:y="http://www.yworks.com/xml/graphml" xsi:schemaLocation="http://graphml.graphdrawing.org/xmlns http://www.yworks.com/xml/schema/graphml/1.1/ygraphml.xsd">' .
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
    private function getGEDCOMTagLabel(string $tag, string $record_type = null)
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
            //$label = I18N::translate($element->label());
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
    //static $time_counter = 0;
    //static $time_start = microtime(true);

    private function increaseTimeLimit()
    {
        //global  $time_counter, $time_start;
        static $time_counter = 0;
        static $time_start;

        //if (empty($counter)) $counter = 0;
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
        return '<data key="d0"> <y:Resources/> </data>' . "\n" .
            '</graph> </graphml>';
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
            //$template = trim ( preg_replace ( '/\s+/', ' ', $template ) );
            $template = preg_replace("/([^\s])\s*[\n\r]\s*([^\s])/", "$1 $2", $template);
            // remove space between double brackets
            foreach ($bracket_array as $bra) {
                $template = preg_replace(
                    "/\Q" . $bra . "\E +\Q" . $bra . "\E/",
                    $bra . $bra,
                    $template
                );
            }

            // start with an "{" to remove everything if no data are found
            /*
			$template_array = array (
					array ("component" => $bracket_array [0],"type" => 'string',
							"format" => "","fact" => "", "subtemplate" => "" 
					) 
			);*/

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
                        /*
                                        $template_array [$i] = array (
                                                        "component" => "",
                                                        "type" => "term", 
                                                        "format" => "",
                                                        "fact" => "",
                                                        "subtemplate" => $this->splitTemplate ($subTemplate )[0]
                                        );*/
                        // set position end to the end of bracket term
                        $pos_end = $pos_bracket_end;
                        $i++;
                    } else {
                        exit("No right bracket found for left bracket at position " . $pos_bracket .
                            " for template: " . $template);
                        $pos_end = strlen($template);
                    }
                } elseif ($pos !== false) {
                    // there is a remaining tag symbol
                    /*
					if ($pos > $pos_end + 1) {
						// there is a string preceeding the tag symbol
						// add the string to the return array
						// substring {...} are removed
						$template_array [$i] = array (
								"component" => $this->removeBrackets ( 
										substr ( $template, $pos_end + 1, 
												$pos - $pos_end - 1 ), 
										$bracket_array ),"type" => "string",
								"format" => "","fact" => "", "subtemplate" => ""
						);
						$i ++;
					}
					*/
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
                                // Foreach end found
                                $pos_end = strlen($template);
                                exit("No EndForeach tag found in tempalte for " . $component);
                            }
                        } else {
                            // this is a single tag
                            // get the format definition
                            //$pos_format = strpos ( $template, $format, $pos );
                            if (substr($component, 0, 4) !== "Fact") {
                                $template_array[$i] = array(
                                    "component" => $component,
                                    "type" => "tag",
                                    "format" => $format_array,
                                    "fact" => "",
                                    "subtemplate" => ""
                                );
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

            // end with an "}" matching the "{" at the beginning
            /*$template_array [$i] = array ("component" => $bracket_array [1],
					"type" => 'string',"format" => '',"fact" => '', "subtemplate" => "" 
			);
                           */


            // now search for tags defining facts and filling the
            // "fact" array element
            /*
			for($j = 0; $j < $i; $j ++) {
				if ($template_array [$j] ["type"] == "tag" and substr ( $template_array [$j] ["component"], 0, 4 ) == "Fact") {
					$template_array [$j] ["fact"] = substr ( 
							$template_array [$j] ["component"], 4 );
					$template_array [$j] ["component"] = "Fact";
				}
			}*/
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
     * @param fact $fact
     * @param string $tag
     * @param number $level
     *
     * @return string|null
     */
    private function getAllAttributes($fact, $tag, $level = 2)
    {
        $gedcom = $fact->gedcom();
        //$gedcom = $fact->getGedcom();
        if (preg_match_all('/\n' . $level . ' (?:' . $tag . ') ?(.*(?:(?:\n3 CONT ?.*)*)*)/', $gedcom, $match)) {
            return preg_replace("/\n' . ($level +1 ) . ' CONT ?/", "\n", $match[1]);
        } else {
            return null;
        }
    }

    /**
     * Get information about adoption fact and a given family
     *
     * @param Fact $child
     * @param Family $FAMC
     *
     * @return string|null
     */
    private function getADOP($fact, $FAMC = null)
    {
        $res = null;

        $adopt_FAMC = trim($fact->attribute("FAMC"), "@");
        //$adopt_FAMC = trim($fact->getAttribute("FAMC"), "@");
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
     * set the information in the generationInd array
     *
     * @param Individual $child child record
     * @param Family $FAMC family the child belongs to
     * @param Individual $parent parent of the child
     * @param boolean $related_by_blood determines which individuals are set
     * @return string Information about the adoption
     */
    private function checkADOP($child, $FAMC, $parent = null)
    {
        //$a = self::getADOP($child, $FAMC);

        if (is_null($child)) return ("");

        $is_adopted = "";
        $ADOPs = $child->facts(['ADOP'], true);
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
     * @param array $format Format array
     * @param integer $i Array element
     * @return array element or empty string
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
     * Substitute place holder in template for individuals
     *
     * This module substitutes place holders in templates for individuals.
     *
     * @param GedcomRecord $record
     *        	The record for which the template should be filled (Individual, Family,..)
     * @param GedcomRecord $record_context
     *        	The context record, e.g. the individual for which the FAMS should be taken.
     * @param string $template
     *        	The template with place holders to be replaced
     * @param array $brackets
     *        	Left and right brackets to define tag block
     * @param string $fact_symbols
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
        //global $this->generationInd;
        //global $include_exclude_media;
        //global $this->media_included;
        if (!isset($this->media_included)) $this->media_included = array();

        if ($record instanceof Fisharebest\Webtrees\Individual) {
            $FAMC = $record->childFamilies();
        } else if ($record instanceof Fisharebest\Webtrees\Family) {
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
                //$new_string = '';
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
                    //$nodetext .= $this->substituteSpecialCharacters (
                    //                      $comp ["component"], $doctype);
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
                                        $rec = $FAMC[0]->husband();
                                    }
                                    break;
                                case "MotherGivenName":
                                case "MotherSurName":
                                    if (count($FAMC) > 0) {
                                        $rec = $FAMC[0]->wife();
                                    }
                                    break;
                                case "SpouseGivenName":
                                case "SpouseSurName":
                                    $rec = $record->husband();
                                    if (is_null($rec) or $record_context->xref() == $rec->xref()) {
                                        $rec = $record->wife();
                                    }
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
                            $rec_name = $record->facts(["NAME"], true)[0];
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
                                    $rec = $record->husband();
                                    if (is_null($rec) or $record_context->xref() == $rec->xref()) {
                                        $rec = $record->wife();
                                    }
                                    break;
                                default:
                                    if (count($FAMC) > 0) {
                                        if ($comp["component"] == "FatherId") {
                                            $rec = $FAMC[0]->husband();
                                        } elseif ($comp["component"] == "MotherId") {
                                            $rec = $FAMC[0]->wife();
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
                            }
                            break;
                        case "BirthDate":
                            $tag_replacement .= $this->formatDate(
                                $record->getBirthDate(),
                                //$record->getEstimatedBirthDate(),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "BirthPlace":
                            $tag_replacement .= $this->formatPlace(
                                $record->getBirthPlace(),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "DeathDate":
                            $tag_replacement .= $this->formatDate(
                                $record->getDeathDate(),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "DeathPlace":
                            $tag_replacement .= $this->formatPlace(
                                $record->getDeathPlace(),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "MarriageDate":
                            $tag_replacement .= $this->formatDate(
                                $record->getMarriageDate(),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "MarriagePlace":
                            $tag_replacement .= $this->formatPlace(
                                $record->getMarriagePlace(),
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
                                //$tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype );
                            }
                            break;
                        case "Link":
                            $xref = $record->xref();
                            if (
                                array_key_exists($ind_id, $this->generationInd) and
                                array_key_exists("sub_branch_link_id", $this->generationInd[$ind_id])
                            ) {
                                $tag_replacement .= $this->generationInd[$ind_id]["sub_branch_link_name"];
                                $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
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
                            if ($this->get_format($format, 0) == "IfExist" and  get_class($record) != "Fisharebest\Webtrees\Fact") {
                                $fact_records = $record->facts((array) $fact_type, true);
                                if (empty($fact_records)) {
                                    $return_value = FALSE;
                                }
                            }
                            if ($return_value) {
                                if (!empty($fact_symbols) and array_key_exists($fact_type, $fact_symbols)) {
                                    $tag_replacement .= $fact_symbols[$fact_type];
                                } else {
                                    $tag_replacement .= $this->substituteSpecialCharacters(
                                        //GedcomTag::getLabel($fact_type, $record_context),
                                        $this->getGEDCOMTagLabel($fact_type, $record_context->tag()),
                                        $doctype
                                    );
                                    $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);


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

                            //	$tag_replacement .= $record->Value();
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeFactDate":
                            $tag_replacement .= $this->formatDate($record->date(), $this->get_format($format, 0));
                            //$tag_replacement .= $this->formatDate($record->getDate(), $this->get_format($format, 0));
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeFactPlace":
                            $tag_replacement .= $this->formatPlace($record->place(), $this->get_format($format, 0));
                            //$tag_replacement .= $this->formatPlace($record->getPlace(), $this->get_format($format, 0));
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeFactAttribute":
                            if ($fact_type == 'DATE') {
                                $tag_replacement .= $this->formatDate($record->date(), $this->get_format($format, 0));
                                //$tag_replacement .= $this->formatDate($record->getDate(), $this->get_format($format, 0));
                            } else if ($fact_type == 'PLAC') {
                                $tag_replacement .= $this->formatPlace($record->place(), $this->get_format($format, 0));
                                //$tag_replacement .= $this->formatPlace($record->getPlace(), $this->get_format($format, 0));
                            } else {
                                $tag_replacement .= $record->attribute($fact_type);
                            }
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeFactNote":
                            //$notes = $record->getNotes();
                            // does not work
                            //$notes = $record->notes();
                            //$notes = $record->getNote();
                            // get Note fact
                            $n = $record->attribute('NOTE');
                            /*
                            $note_facts = $record->facts(['NOTE'], false); // unsorted
                            foreach ($note_facts as $note_fact) {
                                $note = $note_fact->target();
                                if ($note instanceof Note) {
                                    $n = $note->getNote();
                                } else {
                                    // Inline note
                                    $n = $fact->value();
                                }
                    */
                            //foreach ($notes as $n) {
                            if ($n !== "") {
                                $tag_replacement .= $this->substituteSpecialCharacters($n, $doctype) . '\\ ';
                            }
                            break;
                        case "FeMediaFile":
                            // record is of type media not of type file
                            // there could be several files per media
                            // the code has to be adapted
                            $filename = $record->filename();
                            $filename_array = explode(".", $filename);
                            $n = count($filename_array);
                            if ($n == 1) {
                                // file name has no ending stop code
                                exit('Image name \'' . $filename . "\' does not have an ending");
                            } else if ($this->get_format($format, 0) == "NoExtension") {
                                // return file name without ending	 
                                $tag_replacement .= implode(".", array_slice($filename_array, 0, $n - 1));
                            } else {
                                // return full file name
                                $tag_replacement .= $filename;
                            }
                            //$tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype );
                            break;
                        case "FeMediaId":
                            $tag_replacement .= $record->factId();
                            //$tag_replacement .= $record->xref();
                            break;
                        case "FeMediaCaption":
                            $tag_replacement .= $record->title();
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "FeReferenceName":
                            $tag_replacement .= $this->source_list[str_replace('@', '', $record)];
                            //$tag_replacement .= str_replace('@', '', str_replace('S', '', $record));
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
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
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
                                $record->getGedcom()
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "Marriage":
                            $marriage = $record->getMarriage();
                            if ($marriage) {
                                $empty_tag_found = TRUE;
                            }
                            break;
                        case "MarriageDate":
                            $tag_replacement .= $this->formatDate(
                                $record->getMarriageDate(),
                                $this->get_format($format, 0)
                            );
                            $tag_replacement = $this->substituteSpecialCharacters($tag_replacement, $doctype);
                            break;
                        case "MarriagePlace":
                            // $record->getMarriagePlace() does not work because
                            // there is no exception handling in the function
                            $marriage = $record->getMarriage();
                            if ($marriage) {
                                $tag_replacement .= $this->formatPlace(
                                    $marriage->place(),
                                    //$marriage->getPlace(),
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
                            //if (str_replace(array("\n"," "),"",$nodetext) != '') {
                            //$nodetext = $this->removeBrackets ( 
                            //		$nodetext, $brackets );
                            do {
                                $old_length = strlen($nodetext);
                                $nodetext = preg_replace($search_array, $replace_array, $nodetext);
                                $new_length = strlen($nodetext);
                            } while ($old_length !=  $new_length);
                            /*	
							} else {
								do {
									$old_length = strlen($nodetext);
									$nodetext = preg_replace ( $search_array, $replace_array, $nodetext );
									$new_length = strlen($nodetext);
								} while($old_length !=  $new_length);
							}*/
                            break;
                        case "Replace":
                            if (count($format) > 1) {
                                $search_array = "/\Q" . $format[0] . '\E(?=[\\r\\n\\' . $brackets[0] . '\\' . $brackets[1] . '\s]*$)/';
                                $replace_array = str_replace('\\', '\\\\', $format[1]);
                                //if (str_replace(array("\n"," "),"",$nodetext) != '') {
                                //$new_string = $this->removeBrackets (
                                //		$new_string, $brackets );
                                do {
                                    $old_length = strlen($nodetext);
                                    $nodetext = preg_replace($search_array, $replace_array, $nodetext);
                                    $new_length = strlen($nodetext);
                                } while ($old_length !=  $new_length);

                                /*} else {
									do {
										$old_length = strlen($nodetext);
										$nodetext = preg_replace ( $search_array, $replace_array, $nodetext );
										$new_length = strlen($nodetext);
									} while($old_length !=  $new_length);
								}*/
                            }
                            break;
                        default:
                            exit("Tag " . $comp["component"] . " in template not defined");
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
                            $FAMC = $record->childFamilies();
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
                            $FAMS = $record->spouseFamilies();
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
                            $children = $record->children();
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
                                $fact_records = $record->facts();
                                if (!empty($fact_records)) {
                                    foreach ($fact_records as $fact_record) {
                                        $tag = $fact_record->tag();
                                        // strip the leading identifiers like INDI:, FAM: from $tag
                                        // i.e. remove everything before the last colon
                                        if (strpos($tag, ':') !== FALSE) $tag = substr($tag, strripos($tag, ':') + 1);
                                        // store the tag
                                        $fact_types[] = $tag;
                                        //$fact_types[] = $fact_record->getTag();
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
                                //foreach (explode ( ',', $comp["format"] [0]) as $fact_type ) {
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
                            $fact_records = $record->facts((array) $fact_type, true);
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
                            if ($record->xref() == "I4443") {
                                $id1 = $record->xref();
                            };
                            $media_links = [];
                            foreach ($record->facts(["OBJE"], true) as $fact) {
                                // get the media object and add it to the array
                                $media_links[] = $fact->target();
                            }
                            $n1 = count($media_links);
                            // add media from spouse family
                            if ($record instanceof Fisharebest\Webtrees\Individual) {
                                // add media from facts
                                foreach ($record->facts() as $fact) {
                                    $OBJE = trim($fact->attribute('OBJE'), "@");
                                    if ($OBJE !== "") {
                                        $media_links[] = Registry::mediaFactory()->make($OBJE, $this->tree);
                                    }
                                };
                                // add media from spouse family
                                $FAMS = $record->spouseFamilies();
                                foreach ($FAMS as $rec_fam) {
                                    // Family madia
                                    foreach ($rec_fam->facts(["OBJE"], true) as $fact) {
                                        // get the media object and add it to the array
                                        $media_links[] = $fact->target();
                                    }

                                    // family fact media
                                    foreach ($rec_fam->facts() as $fact) {
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

                            //
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
                                        if ($use && $this->get_format($format, 2) == "unique") {
                                            if (in_array($id, $this->media_included)) {
                                                $use = false;
                                            } else {
                                                $this->media_included[] = $id;
                                            }
                                        }
                                        $files = $media_record->mediaFiles();

                                        if ($use and $media_record->canShow() and count($files) > 0) {
                                            //$files = $media_record->facts(['FILE'], true);
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
                            $facts = $record->facts(['SOUR']);
                            $all_sources = array();
                            foreach ($facts as $fact) {
                                $all_sources[] = $fact->Value();
                            }

                            // get all facts
                            $facts = $record->facts();
                            foreach ($record->spouseFamilies() as $family) {
                                if ($family->canShow()) {
                                    foreach ($family->facts() as $fact) {
                                        $facts[] = $fact;
                                    }
                                }
                            }

                            // get sources from facts
                            foreach ($facts as $fact) {
                                $fact_sources = $this->getAllAttributes($fact, 'SOUR');
                                if ($fact_sources) {
                                    $all_sources = array_merge($all_sources, $fact_sources);
                                }
                            }
                            $all_sources = array_unique($all_sources);

                            foreach ($all_sources as $source) {
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
                            exit("Tag " . $comp["component"] . " in template not defined");
                    }
                }
                //
                if ($tag_replacement !== "" or $empty_tag_found !== false) {
                    // data for the tag exists
                    // check for a {...} in $new_string and remove it
                    //$new_string = $this->removeBrackets ( $new_string,
                    //		$brackets );
                    // add $new_string to $nodetext[$a]
                    //$nodetext .= $new_string . $tag_replacement;
                    $nodetext .= $tag_replacement;
                    $tag_resolved = TRUE;
                    //$new_string = '';
                }
            }

            /*
			// add remaining strings to $nodetext
			$new_string = $this->removeBrackets ( $new_string, $brackets );
			$nodetext .= $new_string;
			// remove all remaining brackets (which contain record data)
			$nodetext = preg_replace ( 
					array ("/\Q" . $brackets [0] . "\E/",
							"/\Q" . $brackets [1] . "\E/" 
					), array ("","" 
					), $nodetext );
			*/
            if ($doctype == "graphml") {
                $nodetext = preg_replace("/<html>\s*<\/html>/", "", $nodetext);
            } else if ($doctype == "latex") {
                // remove \\ in "\end{} \\"
                $nodetext = preg_replace('/(\\\\end\{[^\\\{\}]*\}\s*)\\\\\\\\/', '$1 ', $nodetext);
                // remove \\ in "\\ \begin{}"
                $nodetext = preg_replace('/\\\\\\\\(\s*\\\\begin\{[^\\\{\}]*\})/', '$1 ', $nodetext);
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
            /*
			 * // $subject = preg_replace ( '/&nbsp;/', ' ', $subject );
			 * $subject = preg_replace ( "/&/", "&amp;", $subject );
			 * // $subject = preg_replace ( "/&(?![^ ][^&]*;)/", "&amp;", $subject);
			 * $subject = preg_replace ( "/\"/", "&quot;", $subject );
			 * $subject = preg_replace ( "/\'/", "&apos;", $subject );
			 * $subject = preg_replace ( "/(?<!br)>/", "&gt;", $subject );
			 * $subject = preg_replace ( "/<(?!br>)/", "&lt;", $subject );
			 */
        }
        if ($doctype == "latex") {
            $replacements = array(
                array(
                    '/\Q\\\E/', '\textbackslash '
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
                )/*,array ("/\Qö\E/",'\"o' 
			),array ("/\Qä\E/",'\"a' 
			),array ("/\Qü\E/",'\"u' 
			),array ("/\QÖ\E/",'\"O' 
			),array ("/\QÄ\E/",'\"A' 
			),array ("/\QÜ\E/",'\"U' 
			)*/
            );
        }

        foreach ($replacements as $r) {
            $subject = preg_replace($r[0], $r[1], $subject);
        }

        return $subject;
    }
    /**
     * get Records for individuals and families
     * this is stored in the table sources
     *
     * @param Tree $tree
     *        	The family tree to be considered
     * @return string with all references
     */
    private function getReferences()
    {

        $ret_string = "\n" . '\usepackage{filecontents}
						\begin{filecontents}{\jobname.bib}';

        /* old
        $source_rows = Database::prepare(
            "SELECT s_id,s_gedcom FROM `##sources` WHERE s_file = :tree_id"
        )->execute(array(
            'tree_id' => $this->tree->getTreeId(),
        ))->fetchAll();
        */
        // fetchOne()
        //$connection = DB::getConnection();
        //$query = "SELECT s_id,s_gedcom FROM `##sources` WHERE s_file = ?";
        //$source_rows = $connection->select($query, 
        //                    [$this->tree->getTreeId()]);
        $source_rows = DB::table('sources')
            ->where('s_file', '=', $this->tree->id())
            ->select(['s_id AS xref', 's_gedcom AS gedcom', new Expression("'SOUR' AS type")])
            ->get();

        foreach ($source_rows as $rows) {
            $id = $rows->xref;
            $tree = $this->tree;
            $this->source_list[$id] = count($this->source_list) + 1;
            $ret_string .= "\n" . '@book{' .  $this->source_list[$id];
            //$ret_string .= "\n" . '@book{' . str_replace(['S','X'], ['',''], $id);
            //$record = GedcomRecord::getInstance($id, $this->tree);
            $record = Registry::gedcomRecordFactory()->make($id, $tree);
            // The object may have been deleted since we added it to the cart....
            // if ($object instanceof GedcomRecord) {

            //$facts = $record->facts(['TITL'])->filter();
            // title
            foreach ($record->facts(['TITL']) as $fact) {
                //foreach ($record->facts('TITL') as $fact) {
                $ret_string .= ',title   ="' . $fact->Value() . '"';
                //$ret_string .= ',title   ="' . $fact->Value() . '"';
            }
            // author
            //foreach ($record->facts('AUTH') as $fact) {
            foreach ($record->facts(['AUTH']) as $fact) {
                $ret_string .= ',author   ="' .
                    str_replace(array(',', '&'), array(' and ', '\&'), $fact->Value()) . '"';
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
     * @return null
     */
    private function setFAMSFAMC($inds, $branch, $related_by_blood)
    {
        // $this->generationInd must be declared as global in the function 
        //global $this->generationInd;

        do {
            // first set flags for individuals
            foreach ($inds as $key => $ind_a) {
                $generation = $ind_a["generation"];
                $ind = $ind_a["ind"];
                //error_log($ind, 0);
                //if ($ind == 'I1030@20') {
                //    error_log("here we are", 0);
                //};
                $reference = $ind_a["ref"];
                if ($ind !== null) {
                    // set branch and generation
                    //$xrefInd = $ind->xref();
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
                        //$FAMS = $record->spouseFamilies();
                        $FAMS = $record->spouseFamilies();

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
                            "sub_branch" => '',
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

                    $FAMC = $ind->childFamilies();
                    foreach ($FAMC as $record) {
                        $parents = array($record->husband(), $record->wife());
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
                    $FAMS = $ind->spouseFamilies();
                    foreach ($FAMS as $record) {

                        // get spouse
                        if ($ind->sex() == "F") {
                            $spouse = $record->husband();
                        } else {
                            $spouse = $record->wife();
                        };
                        if ($spouse !== null)    $this->generationInd[$xrefInd]["spouse"][] = $spouse->xref();
                        //foreach ($spouse as $rec ) {
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
                        //}

                        // get children
                        //$children = $record->children();
                        $children = $record->children();
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
        $all_seeds = array_key_exists('branch_seeds', $_POST) ? $_POST["branch_seeds"] : '';
        //$all_seeds = $_POST["branch_seeds"];
        if (array_key_exists('refid', $_POST)) {
            $xref = $_POST["refid"];
            if ($xref === "" && array_key_exists('xref', $_GET)) {
                $xref = $_GET["xref"];
            }
        } else if ((array_key_exists('xref', $_GET))) {
            $xref = $_GET["xref"];
        } else {
            $xref = '';
        }
        $seeds_default = array(
//            "xref" => "I1",
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
                        exit("seed has too many of too few columns" . $cols);
                    }

                    $seeds[] = $seeds_default;
                    //$key = key($seeds);
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
                                exit("wrong value in seed column 6 value = " . $cols[$i]);
                            }
                        } else {
                            $seeds[$key][$seeds_default_keys[$i]] = $cols[$i];
                        }
                    }

                    // apply some checks
                    foreach (array_keys($allowed_values) as $allowed_key) {
                        if (!in_array($seeds[$key][$allowed_key], $allowed_values[$allowed_key])) {
                            exit("wrong value in seed column " . $allowed_key);
                        }
                    }
                }
                /*
                                if (count($cols) == 3) {
                                        $seeds[] = array("xref" => $cols[0],
                                                        "name" => $cols[1], 
                                                        "direction" => $cols[2],
                                                        "stop" => array(),
                                                        "hierarchy_sub_branch" => "",
                                                        "hierarchy_generation" => "",
                                                        "include" => TRUE
                                        );
                                } else if (count($cols) == 4){
                                        $seeds[] = array("xref" => $cols[0],
                                                        "name" => $cols[1],
                                                        "direction" => $cols[2],
                                                        "stop" => explode($sep2,$cols[3]),
                                                        "hierarchy_sub_branch" => "",
                                                        "hierarchy_generation" => "",
                                                        "include" => TRUE
                                            );
                                } else if (count($cols) == 5){
                                        $seeds[] = array("xref" => $cols[0],
                                                        "name" => $cols[1],
                                                        "direction" => $cols[2],
                                                        "stop" => explode($sep2,$cols[3]),
                                                        "hierarchy_sub_branch" => $cols[4],
                                                        "hierarchy_generation" => "",
                                                        "include" => TRUE
                                            );
                                } else if (count($cols) == 6){
                                        $seeds[] = array("xref" => $cols[0],
                                                        "name" => $cols[1],
                                                        "direction" => $cols[2],
                                                        "stop" => explode($sep2,$cols[3]),
                                                        "hierarchy_sub_branch" => $cols[4],
                                                        "hierarchy_generation" => $cols[5],
                                                        "include" => $cols[6]
                                                );
                                }
                                 */
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
        /*
        $rowsInd = Database::prepare(
            "SELECT i_id AS xref" .
                " FROM `##individuals` WHERE i_file = :tree_id ORDER BY i_id"
        )->execute(
            array(
                'tree_id' => $this->tree->getTreeId()
            )
        )->fetchAll();
        */
        $rowsInd = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->orderBy('i_id')
            ->select(['i_id AS xref', 'i_gedcom AS gedcom', new Expression("'INDI' AS type")])
            ->get();


        // Get all family records
        /*
        $rowsFam = Database::prepare(
            "SELECT f_id AS xref" .
                " FROM `##families` WHERE f_file = :tree_id ORDER BY f_id"
        )->execute(
            array(
                'tree_id' => $this->tree->getTreeId()
            )
        )->fetchAll();
        */
        $rowsFam = DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->orderBy('f_id')
            ->select(['f_id AS xref', 'f_gedcom AS gedcom', new Expression("'FAM' AS type")])
            ->get();

        foreach ($rowsInd as $row) {
            $xrefInd[] = $row->xref;
        };
        foreach ($rowsFam as $row) {
            $xrefFam[] = $row->xref;
        };
        //
        //global $this->generationInd;

        //
        // 

        // loop over families to start with a new branch
        if ($sort) {
            # if reference individual are set start 
            # with this individual and return only one tree
            #
            $branch = 1;
            if (array_key_exists('refid', $_POST) and $_POST["refid"]) {
                $xref = $_POST["refid"];
                //$record = Individual::getInstance($xref, $this->tree);
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
                    //setGeneration ( $xref, $branch, 1 );
                    //$record = Individual::getInstance($xref, $this->tree);
                    $record = Registry::gedcomRecordFactory()->make($xref, $this->tree);
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
            /*
			$seeds = array();
			$all_seeds = array_key_exists('branch_seeds', $_POST) ? $_POST["branch_seeds"] : '';
			//$all_seeds = $_POST["branch_seeds"];
			if (strlen($all_seeds) > 2) {
				$sep1 = $all_seeds[0];
				$sep2 = $all_seeds[1];
				$all_seeds = substr($all_seeds,2);
				foreach( explode("\n",$all_seeds) as $row) {
					$row = trim($row);
					$cols = explode($sep1,$row);
					if (count($cols) == 3) {
						$seeds[] = array("xref" => $cols[0],
								"name" => $cols[1], 
								"direction" => $cols[2],
								"stop" => array()
						);
					} else if (count($cols) == 4){
						$seeds[] = array("xref" => $cols[0],
								"name" => $cols[1],
								"direction" => $cols[2],
								"stop" => explode($sep2,$cols[3]));
					}
				}
			}
			*/
            $number_of_branches = $branch - 1;
            $number_of_sub_branches = count($seeds);

            // Now set sub branches
            $inds_to_be_copied = array();
            foreach ($seeds as $seed_no => $seed) {
                $xref = $seed["xref"];
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
                    exit('Wrong seed type for ' .  $seed["name"]);
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
                                // test
                                //if ($this->generationInd[$ind]["sub_branch_id"] !== $xref_sub_branche_id) {
                                //    $test2 = 0;
                                //}
                                //
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
            // now add ids
            $no = 0;
            $gen_no = 0;
            $last_branch = array_values($this->generationInd)[0]['branch'] - 1;

            foreach ($this->generationInd as $key => $row) {
                $no += 1;
                $this->generationInd[$key]['no'] = $no;
                $branch = $row['branch'];
                $generation = 1 + $this->generationInd[$key]['generation'] - $min_gen_of_branch[$branch];
                $this->generationInd[$key]['generation'] = $generation;
                //
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
                //
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
                $ng = max($a_generation) - min($a_generation);
                for ($i = $ng; $i >= 1; $i--) {
                    $ref = preg_replace('/(\w)\1{' . $i . '}/', ($i + 1) . '$1', $ref);
                }
                // now replace with custom symbols
                /*
				$search = array('F', 'M', 'S', 'D', 'W', 'H', 'f', 'm', 's', 'd');
				$rep 	= array($_POST["ref_father"] . '-', $_POST["ref_mother"] . '-', 
						$_POST["ref_son"] . '-', $_POST["ref_daughter"] . '-',
						$_POST["ref_wife"] . '-', $_POST["ref_husband"] . '-',
                                                'a' . $_POST["ref_father"] . '-', 'a' . $_POST["ref_mother"] . '-', 
						'a' . $_POST["ref_son"] . '-', 'a' . $_POST["ref_daughter"] . '-'
                                    );
                                 */
                $replacement = array(
                    'F' => $_POST["ref_father"] . '-',
                    'M' => $_POST["ref_mother"] . '-',
                    'S' => $_POST["ref_son"] . '-',
                    'D' => $_POST["ref_daughter"] . '-',
                    'W' => $_POST["ref_wife"] . '-',
                    'H' => $_POST["ref_husband"] . '-',
                    'f' => 'a' . $_POST["ref_father"] . '-',
                    'm' => 'a' . $_POST["ref_mother"] . '-',
                    's' => 'a' . $_POST["ref_son"] . '-',
                    'd' => 'a' . $_POST["ref_daughter"] . '-'
                );
                $new_ref = "";
                foreach (str_split($ref) as $char) {
                    //for( $i = 0; $i <= strlen($ref); $i++ ) {
                    if (array_key_exists($char, $replacement)) {
                        $new_ref = $new_ref . $replacement[$char];
                    } else {
                        $new_ref = $new_ref . $char;
                    }

                    //                                    $new_ref = $new_ref . str_replace($search,$rep,substr($ref,$i,1));
                }
                $ref = $new_ref;
                //$ref = str_replace($search,$rep,$ref);
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
            /*
			// remove subbranches which only contains links
			foreach ( $generationInd_tree as $branch => $branch_array) {
				$remove_array = array();
				foreach ( $branch_array as $sub_branch => $sub_branch_array ) {			
					$remove = true;
					foreach ( $sub_branch_array as $generation => $generation_array ) {
						foreach ( $generation_array as $key => $value ) {
							if ($value["sub_branch_link_id"] == -1) {
								$remove = false;
								break;
							}
						}
						if (!$remove) break;
					}
					if ($remove) $remove_array[] = $sub_branch;
				}
				foreach ($remove_array as $sb_remove) {
					$generationInd_tree[$branch][$sb_remove] = array();
				}
			}
			*/
        } else {
            $generationInd_tree = array();
        }

        //

        //return array ($xrefInd, $xrefFam, $generationInd_tree);
        return $generationInd_tree;
        //return array ($xrefInd, $xrefFam, $this->generationInd, $generationFam 
        //);
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

        // get parameter entered in the web form
        $parameter = $_POST;

        $this->generationInd  = array();
        $this->include_exclude_media = array();
        $this->media_included = array();


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
            list($template_fam[$a][0], $brackets[$a]) = $this->splitTemplate(
                $parameter[$name]
            );
        }



        // get record of individuals and families
        //list ( $xrefInd, $xrefFam ) = $this->getRecords ( $tree );
        $generationInd_tree = $this->getRecords(TRUE);
        //list ( $xrefInd, $xrefFam, $this->generationInd ) = $this->getRecords (
        //		$tree, TRUE );

        // Get header.
        // Buffer the output. Lots of small fwrite() calls can be very
        // slow when writing large files (copied from one of the webtree modules).
        $buffer = $this->graphmlHeader();

        // get seeds
        $seeds = $this->getSeeds();

        /*
		 * Create nodes for individuals and families
		 */

        // loop over all individuals
        //foreach ( $xrefInd as $xref ) {
        $no_edge = 0;
        $sub_branch_no = 0;
        $no_of_branches = count($generationInd_tree);
        foreach ($generationInd_tree as $branch => $branch_array) {
            //			$no_of_sub_branches = count($branch_array);
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
                    //$FAMS = array();
                    $FAMS = new Collection();
                    $ind_xref_list = array();
                    $sub_branch_no += 1;

                    foreach ($sub_branch_array as $generation => $generation_array) {
                        // create individual nodes
                        foreach ($generation_array as $ind_id => $value) {
                            // increase time limit
                            $this->increaseTimeLimit();
                            $xref = $value['xref'];
                            $node_id = $xref . "_" . $sub_branch_no;
                            $ind_xref_list[] = $xref;
                            $has_sub_branch_link = array_key_exists("sub_branch_link_id", $value);
                            //foreach ( $this->generationInd as $key => $row ) {
                            //$FAMS = array_merge($FAMS, $value['spouse_family']);
                            $FAMS = $FAMS->merge($value['spouse_family']);

                            //$record = Individual::getInstance($xref, $this->tree);
                            $record = Registry::gedcomRecordFactory()->make($xref, $this->tree);

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

                            // create export for the node
                            $buffer .= '<node id="' . $node_id . '">' . "\n" .
                                '<data key="d1"><![CDATA[http://my.site.com/' . $node_id .
                                '.html]]></data>' . "\n" . '<data key="d2"><![CDATA[' .
                                $nodetext["description"] . ']]></data>' . "\n" .
                                '<data key="d3">' . '<y:GenericNode configuration="' .
                                $node_style . '"> <y:Geometry height="' . (12 * $label_rows) .
                                '" width="' . $box_width .
                                '" x="10" y="10"/> <y:Fill color="' . $col .
                                '" transparent="false"/> <y:BorderStyle color="' .
                                $col_border . '" type="line" width="' . $border_width .
                                '"/> <y:NodeLabel alignment="center" autoSizePolicy="content" hasBackgroundColor="false" hasLineColor="false" textColor="#000000" fontFamily="' .
                                $parameter['font'] . '" fontSize="' . $font_size .
                                '" fontStyle="plain" visible="true" modelName="internal" modelPosition="l" width="129" height="19" x="1" y="1">';

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
                    $col = $parameter['color_family'];
                    $node_style = $parameter['node_style_family'];
                    $col_border = $parameter['border_family'];
                    $box_width = $parameter['box_width_family'];
                    $border_width = $parameter['border_width_family'];
                    $font_size = $parameter['font_size_family'];

                    // loop over all families
                    $xrefFam = array();
                    foreach ($FAMS as $row) {
                        $xref = $row->xref();
                        if (!in_array($xref, $xrefFam)) $xrefFam[] = $xref;
                    }
                    foreach ($xrefFam as $xref) {
                        // increase time limit
                        $this->increaseTimeLimit();
                        //$record = Family::getInstance($xref, $this->tree);
                        $record = Registry::gedcomRecordFactory()->make($xref, $this->tree);

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
                                $brackets[$a]
                            )[0];
                        }
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

                        // count the number of rows to scale the box height accordingly
                        $label_rows = count(
                            explode("&lt;br&gt;", $nodetext["label"])
                        ) +
                            count(explode("&lt;tr&gt;", $nodetext["label"]));

                        // write export data
                        $buffer .= '<node id="' . $node_id_fam . '">' . "\n";

                        $buffer .= '<data key="d1"><![CDATA[http://my.site.com/' . $node_id_fam .
                            '.html]]></data>' . "\n";
                        $buffer .= '<data key="d2"><![CDATA[' . $nodetext["description"] .
                            ']]></data>' . "\n";

                        // if no label text then set visible flag to false
                        // otherwise a box is created
                        if ($nodetext["label"] == "") {
                            $visible = "false";
                            $border = '<y:BorderStyle hasColor="true" type="line" color="' .
                                $col_border . '" width="' . $border_width . '"/>';
                        } else {
                            $visible = "true";
                            $border = '<y:BorderStyle hasColor="false" type="line" width="' .
                                $border_width . '"/>';
                        }

                        // note fill color must be black
                        // otherwise yed does not find the family nodes
                        $buffer .= '<data key="d3"> <y:ShapeNode>' . '<y:Geometry height="' .
                            $box_width . '" width="' . $box_width . '" x="28" y="28"/>' .
                            '<y:Fill color="#000000" color2="#000000" transparent="false"/>';

                        $buffer .= $border;
                        $buffer .= '<y:NodeLabel alignment="center" autoSizePolicy="content" ' .
                            'backgroundColor="' . $col . '" hasLineColor="true" ' .
                            'lineColor="' . $col_border . '" ' .
                            'textColor="#000000" fontFamily="' . $parameter['font'] .
                            '" fontSize="' . $font_size . '" ' .
                            'fontStyle="plain" visible="' . $visible .
                            '" modelName="internal" modelPosition="c" ' . 'width="' .
                            $box_width . '" height="' . (12 * $label_rows) .
                            '" x="10" y="10">';

                        $buffer .= $nodetext["label"];

                        $buffer .= '</y:NodeLabel> <y:Shape type="' . $node_style . '"/>' .
                            '</y:ShapeNode> </data>' . "\n" . "</node>\n";

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
                        //foreach ( $FAMS as $row ) {
                        //	$xref = $row->xref();
                        $node_id_fam = $xref . "_" . $sub_branch_no;
                        //
                        //$record = Family::getInstance($xref, $this->tree);
                        $record = Registry::gedcomRecordFactory()->make($xref, $this->tree);

                        // get all parents
                        $parents = array(
                            $record->husband(), $record->wife()
                        );

                        $key6 = '<data key="d6"> <y:PolyLineEdge> <y:Path sx="0.0" sy="17.5" tx="0.0" ty="-10"/> <y:LineStyle color="#000000" type="line" width="' .
                            $parameter['edge_line_width'] .
                            '"/> <y:Arrows source="none" target="none"/> <y:BendStyle smoothed="false"/> </y:PolyLineEdge> </data>' .
                            "\n" . '</edge>' . "\n";
                        // loop over parents and add edges for parents
                        foreach ($parents as $parent) {
                            if ($parent and in_array($parent->xref(),  $ind_xref_list)) {
                                $no_edge += 1;
                                $buffer .= '<edge id="' . $no_edge . '" source="' .
                                    $parent->xref() . "_" . $sub_branch_no
                                    . '" target="' . $node_id_fam . '">' . "\n" . $key6;
                            }
                        }

                        // get all children and add edges for children
                        $children = $record->children();

                        foreach ($children as $child) {
                            if (in_array($child->xref(),  $ind_xref_list)) {
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

        // global $include_exclude_media;
        // get parameter entered in the web form
        $parameter = $_POST;

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
                    $label = $this->getGEDCOMTagLabel($key);
                }

                //$label = GedcomTag::getLabel($key);
                //if (strlen($label) > 4 and substr($label, 0, 5) == '<span') {
                if (is_null($label)) {
                    $buffer .= '\item[' . $symbol . '] ' . $key;
                } else {
                    //$buffer .= '\item[' . $symbol . '] ' . GedcomTag::getLabel($key);
                    $buffer .= '\item[' . $symbol . '] ' . $label;
                }
            }
            $buffer .= '\end{compactenum}}';
        }

        // now add bibliography
        $buffer .= $this->getReferences();

        // now add title
        $buffer .= $parameter["title"];

        // get record of individuals and families
        //list ( $xrefInd, $xrefFam, $this->generationInd ) = $this->getRecords ( 
        //		$tree, TRUE );
        $generationInd_tree = $this->getRecords(TRUE);
        //list ( $xrefInd, $xrefFam, $this->generationInd, $generationFam ) = $this->getRecords (
        //		$tree, TRUE );

        // get seeds
        $seeds = $this->getSeeds();

        /*
		 * Create nodes for individuals
		 */

        // loop over all individuals
        //$last_branch = 0;
        //$last_sub_branch = $this->generationInd[0]['sub_branch'];
        //$one_branch = end($this->generationInd)['branch'] == reset($this->generationInd)['branch'];
        //foreach ( $this->generationInd as $key => $value ) {
        $no_of_branches = count($generationInd_tree);
        foreach ($generationInd_tree as $branch => $branch_array) {
            //$no_of_sub_branches = count($branch_array);
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
                    if ($no_of_generations > 0) $buffer .= $hierarchy_sub_branch .
                        ($no_of_sub_branches == 1 ? '' : " " . $section_text) .
                        "}" . "\n";

                    // summary section
                    if ($no_of_generations > 0 && sizeof($summary_template[0]) > 0) {
                        foreach ($sub_branch_array as $generation => $generation_array) {
                            //if (count($generation_array) > 0) $buffer .= $hierarchy_generation .
                            //($no_of_generations == 1 ? '' : " " . $generation) .
                            //"}" . "\n";

                            foreach ($generation_array as $ind_id => $value) {
                                $xref = $value['xref'];
                                // increase time limit
                                $this->increaseTimeLimit();

                                $name = $value['name'];

                                //$record = Individual::getInstance($xref, $this->tree);
                                $record = Registry::gedcomRecordFactory()->make($xref, $this->tree);

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
                        if (count($generation_array) > 0) $buffer .=
                            $hierarchy_generation .
                            ($no_of_generations == 1 ? '' : " " . $generation) .
                            "}" . "\n";

                        foreach ($generation_array as $ind_id => $value) {
                            $xref = $value['xref'];
                            // increase time limit
                            $this->increaseTimeLimit();

                            //$branch = $value ['branch'];
                            //$sub_branch = $value ['sub_branch'];
                            //$generation = $value ['generation'];
                            $name = $value['name'];
                            //$givenname = $value ['givenname'];

                            /*
						if ($branch > $last_branch) {
							$buffer .= $parameter["hierarchy_branch"] .  
							($one_branch ? '' : " " . $branch) . 
							"}" . "\n";
							$first_generation = $generation;
							$last_generation = $generation - 1;
							$last_branch = $branch;
						}
						
						if ($generation > $last_generation) {
							$buffer .= $parameter["hierarchy_generation"] . " " .
									 ($generation - $first_generation + 1) . "}" . "\n";
							$last_generation = $generation;
						}*/

                            //$record = Individual::getInstance($xref, $this->tree);
                            $record = Registry::gedcomRecordFactory()->make($xref, $this->tree);

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

                            list($nodetext, $tag_resolved) = $this->substitutePlaceHolder(
                                $record,
                                $record,
                                $ind_id,
                                $template,
                                "latex",
                                $brackets,
                                $fact_symbols
                            );

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

        // add footer and write buffer
        $buffer .= $parameter["epilog"];
        $this->graphml_fwrite($gedout, $buffer);
    }
}
