<?php

/**
 * ExportFamilyBook module for Webtrees
 *
 * Ported to Webtrees by Iain MacDonald <ijmacd@gmail.com>
 */

 //namespace haduloha\ExportFamilyBook;
 include 'moduleExport.php';

//use Aura\Router\RouterContainer;
//use Exception;
//use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Webtrees;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Tree;
//use Fisharebest\Webtrees\Webtrees;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

use Fisharebest\Webtrees\Functions\FunctionsCharts;
use Fisharebest\Webtrees\Functions\FunctionsEdit;
use Fisharebest\Webtrees\Functions\FunctionsPrint;
use Fisharebest\Webtrees\Functions\FunctionsPrintLists;
    
/**
 * Main class for FamilyBookExport module
 */
class ExportFamilyBook extends AbstractModule implements ModuleCustomInterface, ModuleChartInterface {

    use ModuleCustomTrait;
    use ModuleChartTrait;

    private ResponseFactoryInterface $response_factory;
    private StreamFactoryInterface $stream_factory;

    /**
     * @param ResponseFactoryInterface $response_factory
     * @param StreamFactoryInterface   $stream_factory
     */
    public function __construct(ResponseFactoryInterface $response_factory, StreamFactoryInterface $stream_factory)
    {
        $this->response_factory = $response_factory;
        $this->stream_factory   = $stream_factory;
    }

/**
     * The URL for a page showing chart options.
     *
     * @param Individual                                $individual
     * @param array<bool|int|string|array<string>|null> $parameters
     *
     * @return string
     */
    public function chartUrl(Individual $individual, array $parameters = []): string
    {
        if (!in_array('settings', $parameters)) {
            $settings = null;
            array_push($parameters, $settings);  
        };
        return route('module', [
                'module' => $this->name(),
                'action' => 'Chart',
                'xref'   => $individual->xref(),
                'tree'    => $individual->tree()->name(),
        ] + $parameters);
    }


    public function boot(): void {
        // Register a namespace for our views.
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function resourcesFolder(): string {
        return __DIR__ . '/resources/';
    }

    public function title(): string {
        return I18N::translate('Export Family Book (Latex / GraphML)');
    }

    public function description(): string {
        return I18N::translate('This is a module to export a tree in Latex (as family book) or GraphML (as chart) format.');
    }

    public function chartMenuClass(): string {
        return 'menu-chart-familybook';
    }

    /**
     * A menu item for this chart for an individual box in a chart.
     *
     * @param Individual $individual
     *
     * @return Menu|null
     */
    public function chartBoxMenu(Individual $individual): ?Menu {
        return $this->chartMenu($individual);
    }
    
    /**
     * A function to return the current individual logged in.
     *
     * @param Individual $individual
     *
     * @return Menu|null
     */
    public function getIndividual($tree, $xref): Individual {
        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual, false, true);

        return $individual;
    }

     /**
     * This function is called when the menu chart-button is hit to open the export definition page or when the "Change export type" button on the definition page is hit calling $module->chartUrl.
     *
     * @param Individual $individual
     *
     * @return Menu|null
     */
    public function getChartAction(ServerRequestInterface $request, array $settings = NULL): ResponseInterface {

        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        $individual = $this->getIndividual($tree, $request->getQueryParams()['xref']);
        $queryParams = $request->getQueryParams();
      
        // set the export_book variable according to the get-parameter 'export_book'
        if (array_key_exists('export_book', $queryParams)) {
            $export_book = $queryParams['export_book'];
         } else {
            $export_book = true;
         };
        
        // set the page_title variable according to the get-parameter 'export_book'
        if ($export_book) {
            $page_title = 'Export Family Book';
        } else {
            $page_title = 'Export GraphML';
        };

        // open resources\views\page.phtml and pass parameter to the page
        return $this->viewResponse($this->name() . '::page', [
                    'tree' => $tree,
                    'individual' => $individual,
                    'title' => $page_title,
                    'module' => $this,
                    'export_book' => $export_book,
                    'settings' => $settings
        ]);
    }

    
     /**
     *  Defined in InteractiveTreeModule.php. It is called for form action of method post, e.g.
     * <form action='<?= $module->chartUrl($individual, ['export_book' => !$export_book]) ?>' method='post'>
     * The call is generated in the function ModuleSection::handle
     *
     * @param Individual $individual
     *
     * @return Menu|null
     */
    public function postChartAction(ServerRequestInterface $request): ResponseInterface {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        //$individual = $this->getIndividual($tree, $request->getQueryParams()['xref']);

        $parsedBodys = $request->getParsedBody();

        // set settings to null as default value 
        $settings = null;

        // mod_action value is set by the definition od the submit button
        if (array_key_exists('mod_action', $parsedBodys)) {
            $mod_action = $parsedBodys['mod_action'];
         } else {
            $mod_action = "";
         };

        switch ($mod_action) {
            case "export_graphml":
            case "export_latex":
                //copied from GedcomExportService::export
                $stream = fopen('php://memory', 'wb+');

                if ($stream === false) {
                    throw new RuntimeException('Failed to create temporary stream');
                }
        
                //stream_filter_append($stream, GedcomEncodingFilter::class, STREAM_FILTER_WRITE, ['src_encoding' => UTF8::NAME, 'dst_encoding' => $encoding]);
                // end of copy from GedcomExportService::export

                $exportData = new ExportFamilyBookData();
                $resource = $exportData->export($request, $stream);
                $stream   = $this->stream_factory->createStreamFromResource($resource);

                $this->tree = $tree;
                // file name is set to the tree name
                $download_filename = $this->tree->name();
                $extension = ($mod_action == 'export_latex') ? ".tex" : ".graphml";
                if (
                    strtolower(substr($download_filename, -8, 8)) !=
                    $extension
                ) {
                    $download_filename .= $extension;
                }
                         
                $response = $this->response_factory->createResponse()
                    ->withBody($stream)
                    ->withHeader('content-type', 'text/plain; charset=UTF-8')
                    ->withHeader('content-disposition', 'attachment; filename="' . $download_filename . '"');

                break;
            case "download_settings_graphml":
            case "download_settings_latex":
                // download the formula data into a local file
                // file name is set to the tree name
                $download_filename = ($mod_action == 'download_settings_graphml') ? "export_graphml_settings.txt" : "export_latex_settings.txt";
                
                // Write parameter (base64 encoded serialized POST data)
                $serialized_post = serialize($_POST);
                $encoded_post = base64_encode($serialized_post);

                $stream   = $this->stream_factory->createStreamFromResource($encoded_post);
                $response = $this->response_factory->createResponse()
                    ->withBody($stream)
                    ->withHeader('content-type', 'text/plain; charset=UTF-8')
                    ->withHeader('content-disposition', 'attachment; filename="' . $download_filename . '"');
/*
                header('Content-Type: text/plain; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $download_filename . '"');

                // Create a buffer to store the file content
                ob_start();

                // Write Byte Order Mark
                echo pack("CCC", 0xef, 0xbb, 0xbf);

                // Write parameter (base64 encoded serialized POST data)
                $serialized_post = serialize($_POST);
                $encoded_post = base64_encode($serialized_post);
                echo $encoded_post;

                // Get the content from the buffer and clean up
                $file_content = ob_get_clean();

                // Set the content length header
                header('Content-Length: ' . strlen($file_content));

                // Output the file content
                echo $file_content;
*/
                break;
            case "upload_settings_graphml":
            case "upload_settings_latex":
                // set the setting variable based on the uploaded file
                // upload the formula data into a local file
                // get temporary file name of the uploaded file on the server
                if ($_FILES['uploadedfile']['error'] === UPLOAD_ERR_OK) {
                    $uploaded_file = $_FILES['uploadedfile']['tmp_name'];
                    
                    // Read the content of the uploaded file
                    $file_content = file_get_contents($uploaded_file);
                    
                    // Remove the Byte Order Mark if present
                    $file_content = preg_replace('/^\xEF\xBB\xBF/', '', $file_content);
                    
                    // Decode the base64 encoded data
                    $decoded_data = base64_decode($file_content);
                    
                    // Unserialize the data
                    $unserialized_data = unserialize($decoded_data);
                    
                    // Now you can work with $unserialized_data
                    $settings = $unserialized_data;
                }                
                // exit;
                $response = $this->getChartAction($request, $settings);
                break;
            case "change_export_type":
                $response = $this->getChartAction($request, $settings);
                break;
            case "help":
                        // set the export_book variable according to the get-parameter 'export_book'
        if (array_key_exists('export_book', $queryParams)) {
            $export_book = $queryParams['export_book'];
         } else {
            $export_book = true;
         };
        
        // set the page_title variable according to the get-parameter 'export_book'
        if ($export_book) {
            $page_title = 'Export Family Book';
        } else {
            $page_title = 'Export GraphML';
        };

        // open resources\views\page.phtml and pass parameter to the page
        $response = $this->viewResponse($this->name() . '::page', [
                    'tree' => $tree,
                    'individual' => $individual,
                    'title' => $page_title,
                    'module' => $this,
                    'export_book' => $export_book,
                    'settings' => $settings
        ]);
                break;
        } 
       
        return $response;
    }
    
    
    	/**
	 * Get the help text
	 *
	 * This function returns a help text for individuals and families.
	 * Do not include here " or \" but only ' or \'
	 *
	 * @param string $s
	 * @return string
	 */
	public function getHelpText($s) {
		$help_array["individuals"] = array(
				array('GivenName',
						'',
						I18N::translate ( 'position list of given names' ) . ', \'.\' ' .
						I18N::translate ( 'for abbreviation'),
						'@GivenName&1,2,3.@'),
				array('SurName','', '-', '@SurName@'),
				array('NickName','', '-', '@NickName@'),
				array('BirthDate, DeathDate, FeFactDate', '',
						I18N::translate ( 'PHP date format specification' ),
						'@DeathDate&%j.%n.%Y@'),
				array('BirthPlace, DeathPlace, FeFactPlace', '',
						I18N::translate ('list of positions, exclusion followed after' ) . ' /',
						'@DeathPlace&2,3/USA@'),
				array('FactXXXX', '',
						I18N::translate ( 'position in the ordered fact list' ),
						'@FactOCCU&1,2,-1@'),
				array('Portrait', '',
						'fallback ' . I18N::translate ( 'or' ) .' silhouette',
						'@Portrait&fallback@'),
				array('Gedcom','', '-','@Gedcom@'),
				array('Reference', 
						I18N::translate ('Link to the reference individual' ),
						'', '@Reference@'),
				array('Link', 
						I18N::translate ('Link to second branch with individuum' ),
						'', '@Link@'),
				array('Remove', '',
						I18N::translate ('String to be removed' ),
						'@Remove&,@'),
				array('Replace', '',
						I18N::translate ('String to be replaced & replacement' ),
						'@Replace&:&\\@'),
				array('Ancestor', 
						I18N::translate ('Ancestors and descendants of ancestors' ),
						I18N::translate ('Symbol for ancestors & symbols for descendants of ancestors'),
						'@Ancestor&*&**@'),
				array('ForeachNAME', 
						I18N::translate ('Foreach loop over all names of an individual' ),
						'Type', '@ForeachNAME&_MARNM@'),
				array('ForeachFAMS', 
						I18N::translate ('Foreach loop over families where individual is a spouse' ),
						'-', '@ForeachFAMS@'),
				array('ForeachFAMC', 
						I18N::translate ('Foreach loop over families where individual is a child' ),
						'-', '@ForeachFAMC@'),
				array('ForeachFactOuter', 
						I18N::translate ('Foreach loop over fact types given as format' ),
						I18N::translate ('Comma separated list of ordered facts & comma separated list of facts to be neglected (all everything excluded except the ordered list)'), '@ForeachFactOuter&OCCU,EDUC&all@'),
				array('ForeachFactInner', 
						I18N::translate ('Foreach loop over facts within in ForeachFactOuter loop' ),
						'-', '@ForeachFactInner@'),
				array('FeFactType, FeAttributeType', 
						I18N::translate ('Returns the fact type within a ForeachFactOuter loop' ),
						'IfExists (nothing is returned if no facts exists)&prefix&postfix', 
						'@FeFactType&IfExists&\underline{&}:@'),
				array('FeFactValue', 
						I18N::translate ('Returns the fact value within a ForeachFactInner loop' ),
						'-', '@FeFactValue@'),
				array('FeStepparents', 
						I18N::translate ('Returns if a child is adopted in a ForeachFAMC loop' ),
						'-', '@FeStepparents@'),
				array('FeStepchild', 
						I18N::translate ('Returns if a child is adopted in a ForeachChildren loop' ),
						'-', '@FeStepchild@'),
				array('ForeachMedia', 
						I18N::translate ('Foreach loop over media object' ),
						'1. Comma separated list of types, 2. Comma separated list of formats, 3. unique if no duplicates', 
						'@ForeachMedia&photo&jpg,png@'),
				array('FeMediaFile', 
						I18N::translate ('Returns the media file name within a ForeachMedia loop' ),
						'NoExtension if extension should be removed', '@FeMediaFile&NoExtension@'),
				array('FeMediaCaption', 
						I18N::translate ('Returns the media title within a ForeachMedia loop' ),
						'-', '@FeMediaCaption@'),
				array('ForeachReference', 
						I18N::translate ('Foreach loop over references' ),
						'-', 
						'@ForeachReferences@'),
				array('FeReferenceName', 
						I18N::translate ('Returns the reference id within a ForeachReference loop' ),
						'-', '@FeReferenceName@'),
				array('Counter', 
						I18N::translate ('Counter in foreach loop' ),
						'-', '@Counter@')
		);
		$help_array["families"] = array(
				array('MarriageDate',
						'',
						I18N::translate ( 'PHP date format specification' ),
						'@MarriageDate&%j.%n.%Y@'),
				array('MarriagePlace',
						'',
						I18N::translate ('list of positions, exclusion followed after' ) . ' /',
						'@MarriagePlace&2,3/USA@'),
				array('Marriage',
						'',
						'-',
						'@Marriage@'),
				array('FactXXXX',
						'',
						I18N::translate ( 'position in the ordered fact list' ),
						'@FactOCCU&1,2,-1@'),
				array('Gedcom',
						'',
						'-',
						'@Gedcom@'),
				array('Remove',
						'',
						I18N::translate ('String to be removed' ),
						'@Remove&,@')
		);
		$help_array["latex"] = $help_array["individuals"];
		array_push($help_array["latex"],
				array('FatherGivenName, MotherGivenName, SpouseGivenName',
						'',
						I18N::translate ( 'position list of given names' ) . ', \'.\' ' .
						I18N::translate ( 'for abbreviation' ),
						'@GivenName&1,2,3.@'),
				array('FatherSurName, MotherSurName, SpouseSurName',
						'',
						'-',
						'@SurName@'),
				array('Id, FatherId, MotherId, SpouseId',
						'',
						'no, gen_no or xref',
						'@Id&gen_no@'),
				array('',
						'',
						I18N::translate ('' ),
						'@@')
				);
				
		$help_text = 
			'<table class=\'facts_table width50\'>' .
			'<td class=\'optionbox\' colspan=\'5\'>' . I18N::translate ( 
			'List of allowed keywords to be used in the templates.' ) . ' ' .
			I18N::translate ( 
				'The first four characters within a template define the brackets used to group tag areas and the identifier for a tag and the format part, e.g. {}@&.' ) . 
                                'Text inside brackets are only included if the text includes a resolved tag.' .
                                'Double brackets will hide if resolved tags to the outside.' .
				'<br><br><table border=\'1\'>' .
			'<tr><th>' . I18N::translate ( 'Tag' ) . '</th><th>' .
			I18N::translate ( 'Description' ) . '</th><th>' .
			I18N::translate ( 'Format' ) . '</th><th>' .
			I18N::translate ( 'Example given identifier @&' ) . '</th></tr>' ;
		
			foreach ($help_array[$s] as $this_help) {
			$help_text .= '<tr><td>' . $this_help[0] .
						'</td><td>' . $this_help[1] .
						'</td><td>' . $this_help[2] . 
						'</td><td>' . $this_help[3] . '</td></tr>';
		}
		$help_text .= '</table>';
			
		return $help_text;
	}

    
	/**
	 * Generate a form to define the graphml format
	 *
	 * This function generates a form to define the export parameter
	 * and to trigger the export by submit.
	 *
	 * @param array $settings
	 *        	The setting in the form
	 */
	public function setParameterGraphml($settings = NULL, $tree, $individual) {
		
		// fill read settings if not passed
		if (is_null ( $settings )) {
            $filename = "export_graphml_settings.txt";
			$file_path_and_name = dirname(__FILE__) . DIRECTORY_SEPARATOR . "{$filename}";
			if (file_exists ( $file_path_and_name )) {
				$myfile = fopen ( $file_path_and_name, "r" );
				$settings = fread ( $myfile, filesize ( $file_path_and_name ) );
				$settings = unserialize ( base64_decode ( $settings ) );
			} else {
				$settings = array ();
			}
		}
		;
				
		// header
        echo '<div id="reportengine-page">
            <form action=\''. $this->chartUrl($individual, ['export_book' => false]) . '\' name="setupreport" enctype="multipart/form-data" method="post">',
		    csrf_field();

		echo '<table class="facts_table width50">
		<tr><td class="topbottombar" colspan="7">', I18N::translate ( 
				'Export tree in graphml format' ), '</td></tr>';
		
		/*
		 * Reference person
		 */
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate (
				"Reference Individual" ), '</td>';
		echo '<td class="optionbox" colspan="5">';
        echo view('components/select-individual', ['name' => "refid", 'id' => 'pid', 'tree' => $tree, 'individual' => $individual]);
		echo '</td>';
		
		echo '<tr><td class="descriptionbox width30 wrap" rowspan="3">', I18N::translate (
				"Reference symbols" ), '</td>';
		foreach (array("father", "mother", "wife", "husband", "son", "daughter") as $n) {
			$name = 'ref_' . $n;
			echo '<td class="optionbox" colspan="2">',  I18N::translate('symbol for'),' ',
			I18N::translate($n), "\n";
			echo '<input type="text" size="15" value="' .
					(array_key_exists ( $name, $settings ) ? $settings [$name] : "") .
					'" name="' . $name . '"></td>';
			if ($n == "mother" or $n == "husband") echo '</tr><tr>';
		};
		echo '</tr>';
		
		/*
		 * Individual/family node text and description
		 *
		 * Reads the template from file and opens a textarea with the template
		 * used for the node text and node description  
		 *
		 */
		
		foreach ( array ("individuals","families" 
		) as $s1 ) {
			$help_text = addslashes($this->getHelpText($s1));
			echo '<tr><td class="descriptionbox width30 wrap" colspan="5">', I18N::translate ( 
					'Template for ' . $s1 );
			echo '<span class="wt-icon-help" onclick="javascript:open(\'\', \'Help window\', \'height=600,width=800,resizable=yes\').document.write(\'<html>' . $help_text . '</html>\')"></span>';				
			echo '</td></tr>';
			
			foreach ( array ("label","description" 
			) as $s2 ) {
				echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
						"Node " . $s2 ), '</td>';
				
				$name = $s1 . "_" . $s2 . "_template";
				$s = array_key_exists ( $name, $settings ) ? $settings [$name] : "";
				$nrow = substr_count ( $s, "\n" ) + 1;
				
				echo '<td class="optionbox" colspan="4">' . '<textarea rows="' .
						 $nrow . '" cols="100" name="' . $name . '">';
				echo $s;
				echo '</textarea></td></tr>';
			}
		}
		
		
		/*
		 * seeds for branches
		 */
		
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate (
				'Seeds for branches' ), '</td></tr>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate (
				"Seeds" ), '</td>';
		
		$name = "branch_seeds";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		
		$nrow = min(10,substr_count ( $s, "\n" ) + 1);
		
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
		'" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';
		
		/*
		 * Box style header
		 *
		 * This is the header line for the block which defines the box styles.
		 * Different box styles can be defines for
		 * individuals (male, femal, unknown sex) and families.
		 */
		echo '<tr><td class="descriptionbox width30 wrap" rowspan="7">', I18N::translate ( 
				'Box style' ), '</td>';
		echo '<td class="descriptionbox width30 wrap"  colspan="1">', I18N::translate ( 
				'Male' ), '</td>';
		echo '<td class="descriptionbox width30 wrap"  colspan="1">', I18N::translate ( 
				'Female' ), '</td>';
		echo '<td class="descriptionbox width30 wrap"  colspan="1">', I18N::translate ( 
				'Unknown sex' ), '</td>';
		echo '<td class="descriptionbox width30 wrap"  colspan="1">', I18N::translate ( 
				'Family' ), '</td></tr>';
		
		/*
		 * Box style - box type
		 *
		 * Here the types of the boxes are defined.
		 */
		
		$choicelist = array ("BevelNode2","Rectangle","RoundRect","BevelNode",
				"BevelNodeWithShadow","BevelNode3","ShinyPlateNode",
				"ShinyPlateNodeWithShadow","ShinyPlateNode2","ShinyPlateNode3" 
		);
		echo '<tr>';
		foreach ( array ("male","female","unknown" 
		) as $s ) {
			$name = "node_style_" . $s;
			// $selected = $settings[$name];
			$selected = array_key_exists ( $name, $settings ) ? $settings [$name] : "BevelNode";
			
			echo '<td class="optionbox"  colspan="1">' . '<select name="' . $name .
					 '">';
			foreach ( $choicelist as $o ) {
				echo '<option value="' . $o . '"';
				if ($selected == $o)
					echo 'selected';
				echo '>' . $o . '</option>';
			}
			;
			echo '</select></td>';
		}
		
		$name = "node_style_family";
		// $selected = $settings[$name];
		$selected = array_key_exists ( $name, $settings ) ? $settings [$name] : "diamond";
		
		$choicelist_family = array ("rectangle","roundrectangle","ellipse",
				"parallelogram","hexagon","triangle","rectangle3d","octagon3d",
				"diamond","trapezoid","trapezoid2" 
		);
		
		echo '<td class="optionbox"  colspan="1">' .
				 '<select name="node_style_family">';
		foreach ( $choicelist_family as $o ) {
			echo '<option value="' . $o . '"';
			if ($selected == $o)
				echo 'selected';
			echo '>' . $o . '</option>';
		}
		;
		echo '</select></td></tr>';
		
		/*
		 * Box style - Fill color
		 *
		 * Here the fill colors of the boxes are defined.
		 */
		echo '<tr><td class="optionbox" colspan="1">' .
				 I18N::translate ( 'Fill color' ) . '<input type="color" value="' .
				 (array_key_exists ( "color_male", $settings ) ? $settings ["color_male"] : "#ccccff") .
				 '" name="color_male"></td>';
		echo '<td class="optionbox" colspan="1">' .
				 I18N::translate ( 'Fill color' ) . '<input type="color" value="' .
				 (array_key_exists ( "color_female", $settings ) ? $settings ["color_female"] : "#ffcccc") .
				 '" name="color_female"></td>';
		echo '<td class="optionbox" colspan="1">' .
				 I18N::translate ( 'Fill color' ) . '<input type="color" value="' .
				 (array_key_exists ( "color_unknown", $settings ) ? $settings ["color_unknown"] : "#ffffff") .
				 '" name="color_unknown"></td>';
		echo '<td class="optionbox" colspan="1">' .
				 I18N::translate ( 'Fill color' ) . '<input type="color" value="' .
				 (array_key_exists ( "color_family", $settings ) ? $settings ["color_family"] : "#ffffff") .
				 '" name="color_family"></td></tr>';
		
		/*
		 * Box style - Border color
		 *
		 * Here the border colors of the boxes are defined.
		 */
		echo '<tr>';
		foreach ( array ("male","female","unknown" 
		) as $s ) {
			$name = "border_" . $s;
			echo '<td class="optionbox" colspan="1">' .
					 I18N::translate ( 'Border color' ) .
					 '<input type="color" value="' .
					 (array_key_exists ( $name, $settings ) ? $settings [$name] : "#ffffff") .
					 '" name="' . $name . '"></td>';
		}
		foreach ( array ("family" 
		) as $s ) {
			$name = "border_" . $s;
			echo '<td class="optionbox" colspan="1">' .
					 I18N::translate ( 'Border color' ) .
					 '<input type="color" value="' .
					 (array_key_exists ( $name, $settings ) ? $settings [$name] : "#ffffff") .
					 '" name="' . $name . '"></td>';
		}
		echo '</tr>';
		
		/*
		 * Box style - Box width
		 *
		 * Here the widths of the boxes are defined.
		 */
		echo '<tr>';
		foreach ( array ("male","female","unknown" 
		) as $s ) {
			$name = "box_width_" . $s;
			echo '<td class="optionbox" colspan="1">' .
					 I18N::translate ( 'Box width' ) .
					 '<input type="number" value="' .
					 (array_key_exists ( $name, $settings ) ? $settings [$name] : "120") .
					 '" name="' . $name . '"></td>';
		}
		$name = "box_width_family";
		echo '<td class="optionbox" colspan="1">' . I18N::translate ( 'Symbol' ) .
				 " " . I18N::translate ( 'width' ) . "/" .
				 I18N::translate ( 'height' ) . '<input type="number" value="' .
				 (array_key_exists ( $name, $settings ) ? $settings [$name] : "120") .
				 '" name="' . $name . '"></td></tr>';
		
		/*
		 * Box style - Border line width
		 *
		 * Here the widths of the border lines are defined.
		 */
		echo '<tr>';
		foreach ( array ("male","female","unknown","family" 
		) as $s ) {
			$name = "border_width_" . $s;
			echo '<td class="optionbox" colspan="1">' .
					 I18N::translate ( 'Border width' ) .
					 '<input type="number" value="' .
					 (array_key_exists ( $name, $settings ) ? $settings [$name] : "1") .
					 '" step="0.1" name="' . $name . '"></td>';
		}
		echo '</tr>';
		
		/*
		 * Box style - Font size
		 *
		 * Here the font sizes of the text are defined.
		 */
		echo '<tr>';
		foreach ( array ("male","female","unknown","family" 
		) as $s ) {
			$name = "font_size_" . $s;
			echo '<td class="optionbox" colspan="1">' .
					 I18N::translate ( 'Font size' ) .
					 '<input type="number" value="' .
					 (array_key_exists ( $name, $settings ) ? $settings [$name] : "10") .
					 '" step="1" name="' . $name . '"></td>';
		}
		echo '</tr>';
		
		/*
		 * Box style - default silhouettes
		 *
		 * Here the default silhouettes are defined.
		 */
		echo '<tr><td class="descriptionbox width30 wrap" rowspan="1">', I18N::translate ( 
				'Default portrait' ), '</td>';
		foreach ( array ("male","female","unknown" 
		) as $s ) {
			$name = "default_portrait_" . $s;
			echo '<td class="optionbox" colspan="1">
					<input type="text" size="30" value="' .
					 (array_key_exists ( $name, $settings ) ? $settings [$name] : "") .
					 '" name="' . $name . '"></td>';
		}
		
		echo '<td class="optionbox" colspan="1"/></tr>';
		
		/*
		 * Font type
		 *
		 * Here the font type is defined.
		 */
		$name = "font";
		$selected = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		
		$choicelist_font = array ("Times New Roman","Dialog","Franklin Gothic Book",
				"Bookman Old Style","Lucida Handwriting" 
		);
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				'Font' ), '</td><td class="optionbox" colspan="4">' .
				 '<select name="font">';
		foreach ( $choicelist_font as $o ) {
			echo '<option value="' . $o . '"';
			if ($selected == $o)
				echo 'selected';
			echo '>' . $o . '</option>';
		}
		;
		/**
		 * '<option value="Times New Roman" selected>Times New Roman</option>' .
		 * '<option value="Dialog">Dialog</option>' .
		 * '<option value="Franklin Gothic Book">Franklin Gothic Book</option>' .
		 * '<option value="Bookman Old Style">Bookman Old Style</option>' .
		 * '<option value="Lucida Handwriting">Lucida Handwriting</option>' .
		 */
		echo '</select></td></tr>';
		
		/*
		 * Edge line width
		 *
		 * Here the width of edge lines is defined.
		 */
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				'Line width of edge' ), '</td><td class="optionbox" colspan="4">
			<input type="number" value="' .
				 (array_key_exists ( "edge_line_width", $settings ) ? $settings ["edge_line_width"] : "1") .
				 '" step="0.1" name="edge_line_width" min="1" max="7"></td></tr>';
		
		/*
		 * Box type, line width and line color for links
		 */
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				'Box type and border width of links between sub branches' ); 
		
	 	$name = "node_style_link";
	 	$selected = array_key_exists ( $name, $settings ) ? $settings [$name] : "BevelNode";
	 		
	 	echo '<td class="optionbox"  colspan="1">' . '<select name="' . $name .
	 	'">';
	 	foreach ( $choicelist as $o ) {
	 		echo '<option value="' . $o . '"';
	 		if ($selected == $o)
	 			echo 'selected';
	 			echo '>' . $o . '</option>';
	 	}
	 	;
	 	echo '</select></td>';
		echo '</td><td class="optionbox" colspan="1">
			<input type="number" value="' .
				 (array_key_exists ( "border_width_link", $settings ) ? $settings ["border_width_link"] : "1") .
				 '" step="0.1" name="border_width_link" min="1" max="7"></td>';
		 $name = "border_link_color";
		 echo '<td class="optionbox" colspan="2">' . 
				 '<input type="color" value="' .
		 		(array_key_exists ( $name, $settings ) ? $settings [$name] : "#ffffff") .
		 		'" name="' . $name . '"></td></tr>';
				 			
				 
		// Submit button
		//echo '<tr><td class="topbottombar" colspan="6">', 
		//'<button name="mod_action" value="export_graphml">', I18N::translate ( 
		//		'Export Family Tree' ), '</button>', '</td></tr>';
		// echo '</form>';
		// new for post method
		echo '<tr><td class="topbottombar" colspan="6">',
		'<button type="submit" name="mod_action" value="export_graphml">', I18N::translate (
				'Export Family Tree' ), '</button>','</td></tr>';
		
		// download settings
		// echo '<form name="download" method="get" action="module.php">
		// <input type="hidden" name="mod" value=', $this->getName (), '>';
		// <input type="hidden" name="mod_action" value="export">
		echo '<tr><td class="topbottombar" colspan="6">', '<button name="mod_action" value="download_settings_graphml">', I18N::translate ( 
				'Download Settings' ), '</button></td></tr>';
		echo '</table></form>';
		// echo '</table></form>';
		
		// echo '</div>';
		
		// upload settings
		// echo '<div id="reportengine-page">';
		echo '<table class="facts_table width50">';
		echo '<tr><td class="descriptionbox width30 wrap" colspan="5">', I18N::translate ( 
				'Upload/Download Settings' ), '</td></tr>';
		// header line of the form
		//echo '<form name="upload" enctype="multipart/form-data" method="POST" 
		//		action="module.php?mod=' . $this->getName () .
		//		 '&mod_action=upload_settings_graphml">';
        echo '<form action=\''. $this->chartUrl($individual, ['export_book' => false]) . '\' name="upload" enctype="multipart/form-data" method="post">',
		    csrf_field();

        //echo '<form name="upload" enctype="multipart/form-data" method="POST" 
        //         action="$module->chartUrl($individual, array("mod_action" = "upload_settings_graphml"))">';
         // <input type="hidden" name="mod" value=', $this->getName (), '>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				'Read' ), '</td><td class="optionbox" colspan="4">', '<input name="uploadedfile" type="file"/>', '<button type="submit" name="mod_action" value="upload_settings_graphml">', I18N::translate ( 
				'Upload Settings' ), '</button></td></tr>';
		echo '</table></form>';
		
		/**
		 * // download settings
		 * echo '<form name="download" method="get" action="module.php">
		 * <input type="hidden" name="mod" value=', $this->getName (), '>';
		 * // <input type="hidden" name="mod_action" value="export">
		 * echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate (
		 * 'Write' ),
		 * '<td class="optionbox" colspan="4">',
		 * '<button name="mod_action" value="download_settings">', I18N::translate (
		 * 'Download Settings' ), '</button></td></tr>';
		 * echo '</table></form>';
		 */
		echo '</div>';
	}
	
	/**
	 * Generate a form to define the latexformat
	 *
	 * This function generates a form to define the export parameter
	 * and to trigger the export by submit.
	 *
	 * @param array $settings
	 *        	The setting in the form
	 */
	public function setParameterLatex($settings = NULL, $tree, $individual) {
		$help_text = addslashes($this->getHelpText("latex"));
		
		// fill read settings if not passed
		if (is_null ( $settings )) {
            $filename = "export_latex_settings.txt";
			$file_path_and_name = dirname(__FILE__) . DIRECTORY_SEPARATOR . "{$filename}";
			if (file_exists ( $file_path_and_name )) {
				$myfile = fopen ( $file_path_and_name, "r" );
				$settings = fread ( $myfile, filesize ( $file_path_and_name ) );
				$settings = unserialize ( base64_decode ( $settings ) );
			} else {
				$settings = array ();
			}
		}
		;
		
		// header line 
		echo '<div id="reportengine-page">
				<form action=\''. $this->chartUrl($individual, ['export_book' => true]) . '\' name="setupreport" enctype="multipart/form-data" method="post">',
                csrf_field();
		
		echo '<table class="facts_table width50">
		<tr><td class="topbottombar" colspan="7">', I18N::translate ( 
				'Export tree in latex format' ), '</td></tr>';

		/*
		 * Reference person
		 */
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				"Reference Individual" ), '</td>';
		echo '<td class="optionbox" colspan="6">';
		//echo '<label for="pid">' . I18N::translate('Enter an individual ID') . '</label>';
		//echo '<input class="pedigree_form" data-autocomplete-type="IFSRO" type="text" name="refid" id="pid" size="5" value="">';
        

		//echo ' ' . FunctionsPrint::printFindIndividualLink('pid');
        echo view('components/select-individual', ['name' => "refid", 'id' => 'pid', 'tree' => $tree, 'individual' => $individual]);
		echo '</td>';
		
		echo '<tr><td class="descriptionbox width30 wrap" rowspan="3">', I18N::translate ( 
				"Reference symbols" ), '</td>';
		foreach (array("father", "mother", "wife", "husband", "son", "daughter") as $n) {
			$name = 'ref_' . $n;
			echo '<td class="optionbox" colspan="2">',  I18N::translate('symbol for'),' ',
					I18N::translate($n), "\n";
			echo '<input type="text" size="15" value="' .
							(array_key_exists ( $name, $settings ) ? $settings [$name] : "") .
							'" name="' . $name . '"></td>';
                            if ($n == "mother" or $n == "husband") echo '</tr><tr>';
			
		};
		echo '</tr>';
		
		/*
		 * Preamble
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate ( 
				'Preamble' ), '</td></tr>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				"Preamble" ), '</td>';
		
		$name = "preamble";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");		
		$nrow = min(10,substr_count ( $s, "\n" ) + 1);
		
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
				 '" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';
		
		/*
		 * document title
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate ( 
				'Document Title' ), '</td></tr>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				"Title" ), '</td>';
		
		$name = "title";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		$nrow = min(10,substr_count ( $s, "\n" ) + 1);
		
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
				 '" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';
		
		/*
		 * Individual text
		 *
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate ( 
				'Template for individuals');

		echo '<span class="wt-icon-help" onclick="javascript:open(\'\', \'Help window\', \'height=600,width=800,resizable=yes\').document.write(\'<html>' . $help_text . '</html>\')"></span>';
		
                // Node text
		echo  '</td></tr><tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				"Section summary lable"), '</td>';
		
		$name = "individuals_summary_label_template";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		
		$nrow = substr_count ( $s, "\n" ) + 1;
		
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' .
				 $nrow . '" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';

                // Node text
		echo  '</td></tr><tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				"Node label"), '</td>';
		
		$name = "individuals_label_template";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		
		$nrow = substr_count ( $s, "\n" ) + 1;
		
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' .
				 $nrow . '" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';

		/*
		 * Epilog
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate (
				'Epilog' ), '</td></tr>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate (
				"Epilog" ), '</td>';
		
		$name = "epilog";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		
		$nrow = min(10,substr_count ( $s, "\n" ) + 1);
		
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
		'" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';
		
		/*
		 * seeds for branches
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate (
				'Seeds for branches' ), '</td></tr>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate (
				"Seeds" ), '</td>';
		
		$name = "branch_seeds";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		
		$nrow = min(10,substr_count ( $s, "\n" ) + 1);
		
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
		'" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';
		
		/*
		 * Replacement for fact abbreviations
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate (
				'Symbols to be used for facts' ), '</td></tr>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate (
				"Symbols" ), '</td>';
		
		$name = "symbols";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		
		$nrow = min(10,substr_count ( $s, "\n" ) + 1);
		
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
		'" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';
		
		/*
		 * include exclude media objects
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate (
				'Include and exclude media objects' ), '</td></tr>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate (
				"Include" ), '</td>';		
		$name = "include_media";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		$nrow = min(2,substr_count ( $s, "\n" ) + 1);
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
		'" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate (
				"Exclude" ), '</td>';
		$name = "exclude_media";
		$s = (array_key_exists ( $name, $settings ) ? $settings [$name] : "");
		$nrow = min(2,substr_count ( $s, "\n" ) + 1);
		echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
		'" cols="100" name="' . $name . '">';
		echo $s;
		echo '</textarea></td></tr>';
		
		/*
		 * default format for dates and places
		 *
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate (
				'Default formats' ), '</td></tr>';
		
		foreach ( array ("date","place" 
		) as $s ) {
			echo '<tr><td class="descriptionbox width30 wrap" colspan="1">', I18N::translate (
				'Default ' . $s . ' format' ), '</td>';

			$name = 'default_' . $s . '_format';
			echo '<td class="optionbox" colspan="6">
				<input type="text" size="30" value="' .
				(array_key_exists ( $name, $settings ) ? $settings [$name] : "") .
			 	'" name="' . $name . '"></td></tr>';
		}

		/*
		 * hierarchy for family tree and generation
		 *
		 */
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate (
				'Document hierarchy' ), '</td></tr>';
		
		foreach ( array ("branch","sub_branch","generation"
		) as $s ) {
			echo '<tr><td class="descriptionbox width30 wrap" colspan="1">', I18N::translate (
					'Hierarchy level for ' . $s), '</td>';
		
			$name = 'hierarchy_' . $s;
			echo '<td class="optionbox" colspan="6">
				<input type="text" size="30" value="' .
						(array_key_exists ( $name, $settings ) ? $settings [$name] : "") .
						'" name="' . $name . '"></td></tr>';
		}
		
		
		//echo '<td class="optionbox" colspan="1"/></tr>';
		
		
		
		// Submit button to export the family tree

		//echo '<tr><td class="topbottombar" colspan="7">', 
		//'<button name="mod_action" value="export_latex">', I18N::translate ( 
		//		'Export Family Tree' ), '</button>', '</td></tr>';
		// new for post method
		echo '<tr><td class="topbottombar" colspan="7">', 
                '<button type="submit" name="mod_action" value="export_latex">', I18N::translate (
                'Export Family Tree' ), '</button>','</td></tr>';
        /* '<form action=\'$module->chartUrl($individual, [\"mod_action\" => \"export_latex\"])\' method=\"post\">',
              '<?= csrf_field() ?>',
              '<input type="submit" value="', I18N::translate (
			 	'Export Family Tree' ), '"></form>','</td></tr>';
        */    
		// Button to download settings
		//echo '<form name="download" method="get" action="module.php">,
		//        <input type="submit" value="download">';
		// <input type="hidden" name="mod_action" value="export">
		echo '<tr><td class="topbottombar" colspan="7">', '<button name="mod_action" value="download_settings_latex">', I18N::translate ( 
				'Download Settings' ), '</button></td></tr>';
		echo '</table></form>';
		// echo '</table></form>';
		
		// echo '</div>';
		
		// upload settings
		// echo '<div id="reportengine-page">';
		echo '<table class="facts_table width50">';
		echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate ( 
				'Upload/Download Settings' ), '</td></tr>';
		// header line of the form
        echo '<form action=\''. $this->chartUrl($individual, ['export_book' => true]) . '\' name="upload" enctype="multipart/form-data" method="post">',
        csrf_field();

		//echo '<form name="upload" enctype="multipart/form-data" method="POST" 
        //        action="$module->chartUrl($individual)">';
        //echo 'csrf_field()';
		// <input type="hidden" name="mod" value=', $this->getName (), '>';
		
		echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate ( 
				'Read' ), '</td><td class="optionbox" colspan="6">', '<input name="uploadedfile" type="file"/>', '<button type="submit" name="mod_action" value="upload_settings_latex">', I18N::translate ( 
				'Upload Settings' ), '</button></td></tr>';
		echo '</table></form>';
		
		echo '</div>';
	}
}

return new ExportFamilyBook(Webtrees::make(ResponseFactoryInterface::class),
                            Webtrees::make(StreamFactoryInterface::class));
