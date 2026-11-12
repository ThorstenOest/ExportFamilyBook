<?php

/**
 * ExportFamilyBook module for Webtrees
 *
 */

//namespace haduloha\ExportFamilyBook;
require_once __DIR__ . '/moduleExport.php';


use Fisharebest\Webtrees\Webtrees;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
//use Fisharebest\Webtrees\Webtrees;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use League\Flysystem\FilesystemException;

/**
 * Main class for FamilyBookExport module
 */
class ExportFamilyBook extends AbstractModule implements ModuleCustomInterface, ModuleMenuInterface
{

    use ModuleCustomTrait;
    use ModuleMenuTrait;

    // Access level if Control panel > Modules > Menus has no entry for this module and tree: managers only.
    // webtrees 2.2 has no defaultAccessLevel(); AbstractModule::accessLevel() falls back to this property.
    protected int $access_level = Auth::PRIV_NONE;

    // Pages of the menu "Family book": export type => module action (getLatexAction, postLatexAction, ...)
    // Further pages (e.g. selection of images, branches) are added here and in getMenu().
    private const PAGE_ACTIONS = array(
        'latex' => 'Latex',
        'graphml' => 'Graphml',
    );

    // User setting (per tree) with the reference individual used last in the form
    private const PREF_LAST_REFID = 'EFB_REFID_';

    private ResponseFactoryInterface $response_factory;
    private StreamFactoryInterface $stream_factory;

    private $tree = null;

    // Format of the settings files
    private const SETTINGS_FORMAT = 'ExportFamilyBook-settings';
    private const SETTINGS_VERSION = 1;

    // Setting fields of the forms per export type (without the reference individual and symbols)
    private const SETTINGS_FIELDS = array(
        'latex' => array(
            'preamble', 'title', 'individuals_summary_label_template', 'individuals_label_template', 'epilog',
            'branch_seeds', 'symbols', 'include_media', 'exclude_media', 'default_date_format',
            'default_place_format', 'hierarchy_branch', 'hierarchy_sub_branch', 'hierarchy_generation',
        ),
        'graphml' => array(
            'individuals_label_template', 'individuals_description_template', 'families_label_template',
            'families_description_template', 'branch_seeds',
            'node_style_male', 'node_style_female', 'node_style_unknown', 'node_style_family',
            'color_male', 'color_female', 'color_unknown', 'color_family',
            'border_male', 'border_female', 'border_unknown', 'border_family',
            'box_width_male', 'box_width_female', 'box_width_unknown', 'box_width_family',
            'border_width_male', 'border_width_female', 'border_width_unknown', 'border_width_family',
            'font_size_male', 'font_size_female', 'font_size_unknown', 'font_size_family',
            'default_portrait_male', 'default_portrait_female', 'default_portrait_unknown',
            'font', 'edge_line_width', 'node_style_link', 'border_width_link', 'border_link_color',
        ),
    );

    // Folder for the default settings of a tree, relative to the webtrees data folder:
    // ExportFamilyBook/<tree>/<export type>/
    private const TREE_SETTINGS_FOLDER = 'ExportFamilyBook';

    // Long fields stored as text files of their own (field => file extension) in the folder of a tree.
    // The file contains the value as it is (no escaping), all other fields are stored in settings.json.
    private const SETTINGS_TEXT_FILES = array(
        'latex' => array(
            'preamble' => 'tex',
            'title' => 'tex',
            'individuals_summary_label_template' => 'tex',
            'individuals_label_template' => 'tex',
            'epilog' => 'tex',
            'branch_seeds' => 'txt',
            'symbols' => 'txt',
        ),
        'graphml' => array(
            'individuals_label_template' => 'html',
            'individuals_description_template' => 'txt',
            'families_label_template' => 'html',
            'families_description_template' => 'txt',
            'branch_seeds' => 'txt',
        ),
    );

    // Comma separated lists of media IDs stored as text files with one ID per line
    private const SETTINGS_LIST_FILES = array(
        'latex' => array('include_media', 'exclude_media'),
        'graphml' => array(),
    );

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
     * The URL of the page of an export type
     *
     * @param Tree   $tree
     * @param string $export_type 'latex' or 'graphml'
     * @param string $xref        reference individual to preselect in the form (optional)
     *
     * @return string
     */
    public function pageUrl(Tree $tree, string $export_type, string $xref = ''): string
    {
        $parameters = [
            'module' => $this->name(),
            'action' => self::PAGE_ACTIONS[$export_type],
            'tree'   => $tree->name(),
        ];
        if ($xref !== '') {
            $parameters['xref'] = $xref;
        }

        return route('module', $parameters);
    }

    /**
     * Title of the page of an export type (also the label of its menu entry)
     *
     * @param string $export_type 'latex' or 'graphml'
     * @return string
     */
    public function pageTitle(string $export_type): string
    {
        return $export_type === 'latex'
            ? I18N::translate('Book (LaTeX)')
            : I18N::translate('Family tree chart (GraphML)');
    }


    public function boot(): void
    {
        // Register a namespace for our views.
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function title(): string
    {
        return I18N::translate('Export Family Book (Latex / GraphML)');
    }

    public function description(): string
    {
        return I18N::translate('This is a module to export a tree in Latex (as family book) or GraphML (as chart) format.');
    }

    /**
     * Translations of the texts of the menu and the pages
     *
     * @param string $language
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        if (str_starts_with($language, 'de')) {
            return [
                'Family book' => 'Familienbuch',
                'Book (LaTeX)' => 'Buch (LaTeX)',
                'Family tree chart (GraphML)' => 'Stammbaum-Grafik (GraphML)',
                'Go to %s' => 'Weiter zu %s',
            ];
        }

        return [];
    }

    /**
     * The default position of the menu "Family book" (after "Reports")
     *
     * @return int
     */
    public function defaultMenuOrder(): int
    {
        return 6;
    }

    /**
     * The menu "Family book" in the main navigation, with one entry per page
     *
     * @param Tree $tree
     * @return Menu|null
     */
    public function getMenu(Tree $tree): ?Menu
    {
        // webtrees filters the menus by the access level, too; check it here as well
        if ($this->accessLevel($tree, ModuleMenuInterface::class) < Auth::accessLevel($tree, Auth::user())) {
            return null;
        }

        // the individual of the current page (if any) becomes the reference individual of the form
        $request = Registry::container()->get(ServerRequestInterface::class);
        $xref    = Validator::attributes($request)->isXref()->string('xref', '');

        $submenus = array();
        foreach (array_keys(self::PAGE_ACTIONS) as $export_type) {
            $submenus[] = new Menu(
                $this->pageTitle($export_type),
                $this->pageUrl($tree, $export_type, $xref),
                'menu-familybook-' . $export_type,
                ['rel' => 'nofollow']
            );
        }

        // menu-report: icon of the reports menu in the standard themes
        return new Menu(I18N::translate('Family book'), '#', 'menu-report menu-familybook', ['rel' => 'nofollow'], $submenus);
    }

    /**
     * The reference individual preselected in the form
     *
     * Order: refid of the submitted form, xref of the URL, the individual used last in the form
     * (setting of the user per tree), the default individual of the user resp. of the tree
     * (Tree::significantIndividual). Individuals that the user may not see are skipped.
     *
     * @param ServerRequestInterface $request
     * @param Tree $tree
     * @return Individual|null
     */
    public function referenceIndividual(ServerRequestInterface $request, Tree $tree): ?Individual
    {
        $user = Auth::user();
        $body = $request->getParsedBody();
        $candidates = array(
            is_array($body) && is_string($body['refid'] ?? null) ? $body['refid'] : '',
            Validator::queryParams($request)->isXref()->string('xref', ''),
            Auth::check() ? $user->getPreference(self::PREF_LAST_REFID . $tree->id()) : '',
        );
        foreach ($candidates as $xref) {
            if ($xref !== '') {
                $individual = Registry::individualFactory()->make($xref, $tree);
                if ($individual instanceof Individual && $individual->canShow()) {
                    return $individual;
                }
            }
        }

        $individual = $tree->significantIndividual($user);

        return $individual->canShow() ? $individual : null;
    }

    /**
     * Remember the reference individual of the submitted form for the next visit (logged in users only)
     *
     * @param ServerRequestInterface $request
     * @param Tree $tree
     */
    private function rememberReferenceIndividual(ServerRequestInterface $request, Tree $tree): void
    {
        $body = $request->getParsedBody();
        $xref = is_array($body) && is_string($body['refid'] ?? null) ? $body['refid'] : '';
        if ($xref === '' || !Auth::check()) {
            return;
        }
        $individual = Registry::individualFactory()->make($xref, $tree);
        if ($individual instanceof Individual && $individual->canShow()) {
            Auth::user()->setPreference(self::PREF_LAST_REFID . $tree->id(), $individual->xref());
        }
    }

    /**
     * Enforce the access level set in Control panel > Modules > Menus.
     * webtrees only hides the menu; the module's actions must check it themselves.
     * Throws HttpAccessDeniedException if the current user may not use this module.
     */
    private function checkAccess(ServerRequestInterface $request, Tree $tree): void
    {
        $user = Validator::attributes($request)->user();
        Auth::checkComponentAccess($this, ModuleMenuInterface::class, $tree, $user);
    }

    /**
     * Page of the LaTeX export (family book)
     */
    public function getLatexAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->showPage($request, 'latex');
    }

    /**
     * Forms of the LaTeX page (export, download, upload, save as default)
     */
    public function postLatexAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleForm($request, 'latex');
    }

    /**
     * Page of the GraphML export (family tree chart)
     */
    public function getGraphmlAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->showPage($request, 'graphml');
    }

    /**
     * Forms of the GraphML page (export, download, upload, save as default)
     */
    public function postGraphmlAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleForm($request, 'graphml');
    }

    /**
     * Old address of the module as a chart (.../Chart/<tree>?xref=...&export_book=0|1):
     * redirect to the page of the export type
     */
    public function getChartAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        $this->checkAccess($request, $tree);

        $export_type = Validator::queryParams($request)->string('export_book', '1') === '0' ? 'graphml' : 'latex';
        $xref = Validator::queryParams($request)->isXref()->string('xref', '');

        return redirect($this->pageUrl($tree, $export_type, $xref));
    }

    /**
     * Forms of old pages (old address): redirect to the page of the export type
     */
    public function postChartAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->getChartAction($request);
    }

    /**
     * Show the page of an export type
     *
     * @param ServerRequestInterface $request
     * @param string $export_type 'latex' or 'graphml'
     * @param array|null $settings settings of the form (null: default settings)
     * @return ResponseInterface
     */
    private function showPage(ServerRequestInterface $request, string $export_type, ?array $settings = null): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        $this->checkAccess($request, $tree);
        $individual = $this->referenceIndividual($request, $tree);

        $other_type = $export_type === 'latex' ? 'graphml' : 'latex';

        // open resources\views\page.phtml and pass parameter to the page
        return $this->viewResponse($this->name() . '::page', [
            'tree' => $tree,
            'individual' => $individual,
            'title' => I18N::translate('Family book') . ' – ' . $this->pageTitle($export_type),
            'module' => $this,
            'export_type' => $export_type,
            'other_url' => $this->pageUrl($tree, $other_type, $individual === null ? '' : $individual->xref()),
            'other_title' => $this->pageTitle($other_type),
            'settings' => $settings
        ]);
    }

    /**
     * Handle the forms of a page. The button pressed is passed in mod_action.
     *
     * @param ServerRequestInterface $request
     * @param string $page_type export type of the page, 'latex' or 'graphml'
     * @return ResponseInterface
     */
    private function handleForm(ServerRequestInterface $request, string $page_type): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        $this->checkAccess($request, $tree);
        $this->rememberReferenceIndividual($request, $tree);

        $parsedBodys = $request->getParsedBody();

        // set settings to null as default value 
        $settings = null;

        // mod_action value is set by the definition od the submit button
        if (array_key_exists('mod_action', $parsedBodys)) {
            $mod_action = $parsedBodys['mod_action'];
        } else {
            $mod_action = "";
        };
        // each page handles only the actions of its own export type
        if (!is_string($mod_action) || !str_ends_with($mod_action, '_' . $page_type)) {
            $mod_action = "";
        }

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
                // download the formula data into a local file (JSON, only the setting fields of the form)
                $export_type = ($mod_action == 'download_settings_graphml') ? 'graphml' : 'latex';
                $download_filename = "export_{$export_type}_settings.json";

                $stream   = $this->stream_factory->createStream($this->encodeSettings($export_type, $_POST));
                $response = $this->response_factory->createResponse()
                    ->withBody($stream)
                    ->withHeader('content-type', 'application/json; charset=UTF-8')
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

                    // Read the content of the uploaded file (JSON or the old base64/serialize format)
                    $export_type = ($mod_action == 'upload_settings_graphml') ? 'graphml' : 'latex';
                    try {
                        $settings = $this->decodeSettings($export_type, (string) file_get_contents($uploaded_file));
                    } catch (UnexpectedValueException $ex) {
                        FlashMessages::addMessage(e(I18N::translate('The settings file could not be read: %s', $ex->getMessage())), 'danger');
                    }
                }
                // exit;
                $response = $this->showPage($request, $page_type, $settings);
                break;
            case "save_tree_settings_graphml":
            case "save_tree_settings_latex":
                // save the form as default settings of the tree (files in the data folder)
                $export_type = ($mod_action == 'save_tree_settings_graphml') ? 'graphml' : 'latex';
                $settings = $this->settingsFromForm($export_type, $parsedBodys);
                $user = Validator::attributes($request)->user();
                if (!Auth::isManager($tree, $user)) {
                    FlashMessages::addMessage(e(I18N::translate('Only managers of the family tree can save the default settings of the tree.')), 'danger');
                } else {
                    $folder = $this->treeSettingsFolder($tree, $export_type);
                    try {
                        $this->writeTreeSettings($export_type, $folder, $settings);
                        FlashMessages::addMessage(e(I18N::translate('The settings have been saved as default of the tree in %s.', Registry::filesystem()->dataName() . $folder)), 'success');
                    } catch (FilesystemException | JsonException $ex) {
                        FlashMessages::addMessage(e(I18N::translate('The settings could not be saved in %s: %s', Registry::filesystem()->dataName() . $folder, $ex->getMessage())), 'danger');
                    }
                }
                $response = $this->showPage($request, $page_type, $settings);
                break;
            default:
                $response = $this->showPage($request, $page_type, $settings);
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
    public function getHelpText($s)
    {
        $help_array["individuals"] = array(
            array(
                'GivenName',
                '',
                I18N::translate('position list of given names') . ', \'.\' ' .
                    I18N::translate('for abbreviation'),
                '@GivenName&1,2,3.@'
            ),
            array('SurName', '', '-', '@SurName@'),
            array('NickName', '', '-', '@NickName@'),
            array(
                'BirthDate, DeathDate, FeFactDate', '',
                I18N::translate('PHP date format specification'),
                '@DeathDate&%j.%n.%Y@'
            ),
            array(
                'BirthPlace, DeathPlace, FeFactPlace', '',
                I18N::translate('list of positions, exclusion followed after') . ' /',
                '@DeathPlace&2,3/USA@'
            ),
            array(
                'FactXXXX', '',
                I18N::translate('position in the ordered fact list'),
                '@FactOCCU&1,2,-1@'
            ),
            array(
                'Portrait', '',
                'fallback ' . I18N::translate('or') . ' silhouette',
                '@Portrait&fallback@'
            ),
            array('Gedcom', '', '-', '@Gedcom@'),
            array(
                'Reference',
                I18N::translate('Link to the reference individual'),
                '', '@Reference@'
            ),
            array(
                'Link',
                I18N::translate('Link to second branch with individuum'),
                '', '@Link@'
            ),
            array(
                'Remove', '',
                I18N::translate('String to be removed'),
                '@Remove&,@'
            ),
            array(
                'Replace', '',
                I18N::translate('String to be replaced & replacement'),
                '@Replace&:&\\@'
            ),
            array(
                'Ancestor',
                I18N::translate('Ancestors and descendants of ancestors'),
                I18N::translate('Symbol for ancestors & symbols for descendants of ancestors'),
                '@Ancestor&*&**@'
            ),
            array(
                'ForeachNAME',
                I18N::translate('Foreach loop over all names of an individual'),
                'Type', '@ForeachNAME&_MARNM@'
            ),
            array(
                'ForeachFAMS',
                I18N::translate('Foreach loop over families where individual is a spouse'),
                '-', '@ForeachFAMS@'
            ),
            array(
                'ForeachFAMC',
                I18N::translate('Foreach loop over families where individual is a child'),
                '-', '@ForeachFAMC@'
            ),
            array(
                'ForeachFactOuter',
                I18N::translate('Foreach loop over fact types given as format'),
                I18N::translate('Comma separated list of ordered facts & comma separated list of facts to be neglected (all everything excluded except the ordered list)'), '@ForeachFactOuter&OCCU,EDUC&all@'
            ),
            array(
                'ForeachFactInner',
                I18N::translate('Foreach loop over facts within in ForeachFactOuter loop'),
                '-', '@ForeachFactInner@'
            ),
            array(
                'FeFactType, FeAttributeType',
                I18N::translate('Returns the fact type within a ForeachFactOuter loop'),
                'IfExists (nothing is returned if no facts exists)&prefix&postfix',
                '@FeFactType&IfExists&\underline{&}:@'
            ),
            array(
                'FeFactValue',
                I18N::translate('Returns the fact value within a ForeachFactInner loop'),
                '-', '@FeFactValue@'
            ),
            array(
                'FeStepparents',
                I18N::translate('Returns if a child is adopted in a ForeachFAMC loop'),
                '-', '@FeStepparents@'
            ),
            array(
                'FeStepchild',
                I18N::translate('Returns if a child is adopted in a ForeachChildren loop'),
                '-', '@FeStepchild@'
            ),
            array(
                'ForeachMedia',
                I18N::translate('Foreach loop over media object'),
                '1. Comma separated list of types, 2. Comma separated list of formats, 3. unique if no duplicates',
                '@ForeachMedia&photo&jpg,png@'
            ),
            array(
                'FeMediaFile',
                I18N::translate('Returns the media file name within a ForeachMedia loop'),
                'NoExtension if extension should be removed', '@FeMediaFile&NoExtension@'
            ),
            array(
                'FeMediaCaption',
                I18N::translate('Returns the media title within a ForeachMedia loop'),
                '-', '@FeMediaCaption@'
            ),
            array(
                'ForeachReference',
                I18N::translate('Foreach loop over references'),
                '-',
                '@ForeachReferences@'
            ),
            array(
                'FeReferenceName',
                I18N::translate('Returns the reference id within a ForeachReference loop'),
                '-', '@FeReferenceName@'
            ),
            array(
                'Counter',
                I18N::translate('Counter in foreach loop'),
                '-', '@Counter@'
            )
        );
        $help_array["families"] = array(
            array(
                'MarriageDate',
                '',
                I18N::translate('PHP date format specification'),
                '@MarriageDate&%j.%n.%Y@'
            ),
            array(
                'MarriagePlace',
                '',
                I18N::translate('list of positions, exclusion followed after') . ' /',
                '@MarriagePlace&2,3/USA@'
            ),
            array(
                'Marriage',
                '',
                '-',
                '@Marriage@'
            ),
            array(
                'FactXXXX',
                '',
                I18N::translate('position in the ordered fact list'),
                '@FactOCCU&1,2,-1@'
            ),
            array(
                'Gedcom',
                '',
                '-',
                '@Gedcom@'
            ),
            array(
                'Remove',
                '',
                I18N::translate('String to be removed'),
                '@Remove&,@'
            )
        );
        $help_array["latex"] = $help_array["individuals"];
        array_push(
            $help_array["latex"],
            array(
                'FatherGivenName, MotherGivenName, SpouseGivenName',
                '',
                I18N::translate('position list of given names') . ', \'.\' ' .
                    I18N::translate('for abbreviation'),
                '@GivenName&1,2,3.@'
            ),
            array(
                'FatherSurName, MotherSurName, SpouseSurName',
                '',
                '-',
                '@SurName@'
            ),
            array(
                'Id, FatherId, MotherId, SpouseId',
                '',
                'no, gen_no or xref',
                '@Id&gen_no@'
            ),
            array(
                '',
                '',
                I18N::translate(''),
                '@@'
            )
        );

        $help_text =
            '<table class=\'facts_table width50\'>' .
            '<td class=\'optionbox\' colspan=\'5\'>' . I18N::translate(
                'List of allowed keywords to be used in the templates.'
            ) . ' ' .
            I18N::translate(
                'The first four characters within a template define the brackets used to group tag areas and the identifier for a tag and the format part, e.g. {}@&.'
            ) .
            'Text inside brackets are only included if the text includes a resolved tag.' .
            'Double brackets will hide if resolved tags to the outside.' .
            '<br><br><table border=\'1\'>' .
            '<tr><th>' . I18N::translate('Tag') . '</th><th>' .
            I18N::translate('Description') . '</th><th>' .
            I18N::translate('Format') . '</th><th>' .
            I18N::translate('Example given identifier @&') . '</th></tr>';

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
     * Wrap a help text into a JavaScript string literal for document.write()
     *
     * The literal contains no quotes, ampersands or angle brackets, so it can be
     * placed into an HTML attribute (after e()) or a script block.
     *
     * @param string $help_text
     * @return string
     */
    public function helpScript(string $help_text): string
    {
        return json_encode('<html>' . $help_text . '</html>', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }


    /**
     * Names of the setting fields in the form of an export type
     *
     * Only these fields are written to and read from a settings file.
     * Control fields like _csrf or mod_action are not settings.
     *
     * @param string $export_type 'latex' or 'graphml'
     * @return array<string>
     */
    public function settingsFields(string $export_type): array
    {
        return array_merge(
            array('refid', 'ref_father', 'ref_mother', 'ref_wife', 'ref_husband', 'ref_son', 'ref_daughter'),
            self::SETTINGS_FIELDS[$export_type]
        );
    }


    /**
     * Encode the settings of the form as JSON
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param array $post the submitted form data
     * @return string
     */
    public function encodeSettings(string $export_type, array $post): string
    {
        $settings = $this->settingsFromForm($export_type, $post);

        return json_encode(
            array(
                'format' => self::SETTINGS_FORMAT,
                'version' => self::SETTINGS_VERSION,
                'export_type' => $export_type,
                'settings' => (object) $settings,
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }


    /**
     * Decode a settings file
     *
     * Reads the JSON format and the old format (base64 encoded serialized POST data).
     * The result contains only the setting fields of the export type.
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param string $content the content of the settings file
     * @return array<string,string>
     * @throws UnexpectedValueException if the file is no valid settings file
     */
    public function decodeSettings(string $export_type, string $content): array
    {
        // Remove the Byte Order Mark and surrounding white space
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content));

        if (str_starts_with($content, '{')) {
            try {
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $ex) {
                throw new UnexpectedValueException(I18N::translate('invalid JSON (%s)', $ex->getMessage()));
            }
            if (!is_array($data) || ($data['format'] ?? null) !== self::SETTINGS_FORMAT) {
                throw new UnexpectedValueException(I18N::translate('this is no settings file of this module'));
            }
            if (($data['version'] ?? null) !== self::SETTINGS_VERSION) {
                throw new UnexpectedValueException(I18N::translate('unsupported version'));
            }
            if (($data['export_type'] ?? null) !== $export_type) {
                throw new UnexpectedValueException(I18N::translate('the file contains settings for another export type'));
            }
            $settings = $data['settings'] ?? null;
        } else {
            // old format: objects are never allowed (protection against object injection)
            $decoded = base64_decode($content, true);
            $settings = ($decoded === false) ? false : @unserialize($decoded, ['allowed_classes' => false]);
        }

        if (!is_array($settings)) {
            throw new UnexpectedValueException(I18N::translate('this is no settings file of this module'));
        }

        $result = array();
        foreach ($this->settingsFields($export_type) as $name) {
            if (array_key_exists($name, $settings)) {
                if (!is_string($settings[$name])) {
                    throw new UnexpectedValueException(I18N::translate('the value of %s is no text', $name));
                }
                $result[$name] = $settings[$name];
            }
        }

        return $result;
    }


    /**
     * Read the default settings shown when the form is opened
     *
     * The files of the tree in the data folder (see readTreeSettings) are used first.
     * A field without a file there gets its value from the module folder (see readModuleSettings).
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param Tree|null $tree
     * @return array<string,string>
     */
    public function readDefaultSettings(string $export_type, ?Tree $tree = null): array
    {
        $settings = $this->readModuleSettings($export_type);
        if ($tree !== null) {
            $settings = array_merge($settings, $this->readTreeSettings($export_type, $this->treeSettingsFolder($tree, $export_type)));
        }

        return $settings;
    }


    /**
     * Read the default settings from the module folder
     *
     * The first existing file is used:
     * - export_*_settings.local.json: own settings of the user (not part of the repository)
     * - export_*_settings.txt: old format, created by the user with an earlier version
     * - export_*_settings.json: general example of the repository
     *
     * @param string $export_type 'latex' or 'graphml'
     * @return array<string,string>
     */
    public function readModuleSettings(string $export_type): array
    {
        foreach (array('local.json', 'txt', 'json') as $extension) {
            $file = __DIR__ . DIRECTORY_SEPARATOR . "export_{$export_type}_settings.{$extension}";
            if (is_file($file)) {
                try {
                    return $this->decodeSettings($export_type, (string) file_get_contents($file));
                } catch (UnexpectedValueException $ex) {
                    FlashMessages::addMessage(e(I18N::translate('The settings file could not be read: %s', basename($file) . ': ' . $ex->getMessage())), 'danger');
                    return array();
                }
            }
        }

        return array();
    }


    /**
     * The setting fields of a submitted form (only text values of the whitelist)
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param array $post the submitted form data
     * @return array<string,string>
     */
    public function settingsFromForm(string $export_type, array $post): array
    {
        $settings = array();
        foreach ($this->settingsFields($export_type) as $name) {
            if (array_key_exists($name, $post) && is_string($post[$name])) {
                $settings[$name] = $post[$name];
            }
        }

        return $settings;
    }


    /**
     * Name of the folder of a tree: the tree name if it is safe, otherwise a safe name with a hash
     *
     * Only letters, digits, '_' and '-' are kept, so the name can never leave the parent folder.
     *
     * @param string $tree_name
     * @return string
     */
    public function treeFolderName(string $tree_name): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $tree_name);
        if ($safe !== $tree_name || $safe === '') {
            $safe .= '-' . substr(hash('sha256', $tree_name), 0, 8);
        }

        return $safe;
    }


    /**
     * Folder with the default settings of a tree, relative to the webtrees data folder
     *
     * @param Tree $tree
     * @param string $export_type 'latex' or 'graphml'
     * @return string e.g. ExportFamilyBook/tree1/latex
     */
    public function treeSettingsFolder(Tree $tree, string $export_type): string
    {
        return self::TREE_SETTINGS_FOLDER . '/' . $this->treeFolderName($tree->name()) . '/' .
            ($export_type === 'graphml' ? 'graphml' : 'latex');
    }


    /**
     * Value of a text file: line endings as sent by a form (CRLF), without a final line break
     *
     * Files may use LF or CRLF and start with a UTF-8 byte order mark.
     *
     * @param string $content
     * @return string
     */
    private function decodeTextFile(string $content): string
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(array("\r\n", "\r"), "\n", $content);
        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }

        return str_replace("\n", "\r\n", $content);
    }


    /**
     * Content of a text file for a value: LF line endings and a final line break
     *
     * @param string $value
     * @return string
     */
    private function encodeTextFile(string $value): string
    {
        return str_replace(array("\r\n", "\r"), "\n", $value) . "\n";
    }


    /**
     * Value of a list file: the IDs separated by commas
     *
     * One ID per line, commas are allowed as well. Empty lines, spaces and
     * text after '#' are ignored ('#' is no valid character of an ID).
     *
     * @param string $content
     * @return string
     */
    private function decodeListFile(string $content): string
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $items = array();
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = (string) preg_replace('/#.*/', '', $line);
            foreach (explode(',', $line) as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $items[] = $item;
                }
            }
        }

        return implode(',', $items);
    }


    /**
     * Content of a list file for a value: one ID per line
     *
     * @param string $value IDs separated by commas
     * @return string
     */
    private function encodeListFile(string $value): string
    {
        $value = $this->decodeListFile($value);

        return ($value === '') ? '' : str_replace(',', "\n", $value) . "\n";
    }


    /**
     * Read the default settings of a tree from its folder in the data folder
     *
     * settings.json contains the short fields, each long field has a text file of its own,
     * each list of media IDs a list file. A missing file gives no value for its fields.
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param string $folder folder relative to the data folder
     * @return array<string,string>
     */
    public function readTreeSettings(string $export_type, string $folder): array
    {
        $data = Registry::filesystem()->data();
        $settings = array();
        $file = $folder . '/settings.json';
        try {
            if (!$data->directoryExists($folder)) {
                return array();
            }
            if ($data->fileExists($file)) {
                try {
                    $settings = $this->decodeSettings($export_type, $data->read($file));
                } catch (UnexpectedValueException $ex) {
                    FlashMessages::addMessage(e(I18N::translate('The settings file could not be read: %s', $file . ': ' . $ex->getMessage())), 'danger');
                }
            }
            foreach (self::SETTINGS_TEXT_FILES[$export_type] as $name => $extension) {
                $file = $folder . '/' . $name . '.' . $extension;
                if ($data->fileExists($file)) {
                    $settings[$name] = $this->decodeTextFile($data->read($file));
                }
            }
            foreach (self::SETTINGS_LIST_FILES[$export_type] as $name) {
                $file = $folder . '/' . $name . '.txt';
                if ($data->fileExists($file)) {
                    $settings[$name] = $this->decodeListFile($data->read($file));
                }
            }
        } catch (FilesystemException $ex) {
            FlashMessages::addMessage(e(I18N::translate('The settings file could not be read: %s', $file . ': ' . $ex->getMessage())), 'danger');
        }

        return $settings;
    }


    /**
     * Write the settings of the form as default settings of a tree
     *
     * Only files whose value changed are written. So comments in a list file and
     * the line endings chosen by the user are kept as long as the value is the same.
     * README.txt is only written if it does not exist.
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param string $folder folder relative to the data folder
     * @param array<string,string> $settings
     * @throws FilesystemException
     * @throws JsonException
     */
    public function writeTreeSettings(string $export_type, string $folder, array $settings): void
    {
        $data = Registry::filesystem()->data();
        $data->createDirectory($folder);

        $text_files = self::SETTINGS_TEXT_FILES[$export_type];
        $list_files = array_flip(self::SETTINGS_LIST_FILES[$export_type]);
        $short = array_diff_key($settings, $text_files, $list_files);

        // settings.json with the short fields
        $file = $folder . '/settings.json';
        $old = null;
        if ($data->fileExists($file)) {
            try {
                $old = $this->decodeSettings($export_type, $data->read($file));
            } catch (UnexpectedValueException $ex) {
                $old = null;
            }
        }
        if ($old !== $short) {
            $data->write($file, $this->encodeSettings($export_type, $short));
        }

        foreach ($settings as $name => $value) {
            if (array_key_exists($name, $text_files)) {
                $file = $folder . '/' . $name . '.' . $text_files[$name];
                $content = $this->encodeTextFile($value);
                $decode = $this->decodeTextFile(...);
            } elseif (array_key_exists($name, $list_files)) {
                $file = $folder . '/' . $name . '.txt';
                $content = $this->encodeListFile($value);
                $decode = $this->decodeListFile(...);
            } else {
                continue;
            }
            if (!$data->fileExists($file) || $decode($data->read($file)) !== $decode($content)) {
                $data->write($file, $content);
            }
        }

        if (!$data->fileExists($folder . '/README.txt')) {
            $data->write($folder . '/README.txt', $this->treeSettingsReadme($export_type));
        }
    }


    /**
     * Table row with the button to save the form as default settings of the tree (managers only)
     *
     * @param Tree $tree
     * @param string $export_type 'latex' or 'graphml'
     * @param int $colspan
     * @return string
     */
    private function saveTreeSettingsRow(Tree $tree, string $export_type, int $colspan): string
    {
        if (!Auth::isManager($tree)) {
            return '';
        }

        return '<tr><td class="topbottombar" colspan="' . $colspan . '">' .
            '<button type="submit" name="mod_action" value="save_tree_settings_' . $export_type . '">' .
            e(I18N::translate('Save as default for this tree')) . '</button>' .
            '</td></tr>';
    }


    /**
     * Text of README.txt in the folder of a tree
     *
     * @param string $export_type 'latex' or 'graphml'
     * @return string
     */
    private function treeSettingsReadme(string $export_type): string
    {
        $text_files = array();
        foreach (self::SETTINGS_TEXT_FILES[$export_type] as $name => $extension) {
            $text_files[] = '  ' . $name . '.' . $extension;
        }
        $list_files = array();
        foreach (self::SETTINGS_LIST_FILES[$export_type] as $name) {
            $list_files[] = '  ' . $name . '.txt';
        }

        $lines = array(
            "ExportFamilyBook: default settings of this family tree ($export_type export)",
            '',
            'The module reads these files when its page is opened for this tree.',
            'The button "Save as default for this tree" (managers of the tree only) writes them.',
            'You can edit them with a text editor: UTF-8, LF or CRLF line endings.',
            '',
            'settings.json',
            '  The short options, in the format of "Download Settings".',
            '',
            'Long fields, one file each. The file contains the text exactly as in the form,',
            'without any escaping. One line break at the end of the file is ignored.',
            ($export_type === 'latex')
                ? 'In branch_seeds.txt and symbols.txt the first characters are the separators, as in the form.'
                : 'In branch_seeds.txt the first two characters are the separators, as in the form.',
        );
        $lines = array_merge($lines, $text_files, array(''));
        if ($list_files !== array()) {
            $lines = array_merge(
                $lines,
                array(
                    'Lists of media IDs: one ID per line (commas are allowed as well).',
                    'Empty lines and text after # are ignored.',
                ),
                $list_files,
                array('')
            );
        }
        array_push(
            $lines,
            'If a file is missing, the value of that field is taken from the module folder:',
            "export_{$export_type}_settings.local.json, else export_{$export_type}_settings.txt, else export_{$export_type}_settings.json.",
            'To clear a field, keep its file and leave it empty.',
            '',
            'When saving, only files whose value has changed are written; README.txt is never overwritten.',
            'Comments in a list file are lost when the list is changed in the form and saved.',
        );

        return implode("\n", $lines) . "\n";
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
    public function setParameterGraphml(?array $settings, Tree $tree, ?Individual $individual)
    {

        // fill read settings if not passed
        if (is_null($settings)) {
            $settings = $this->readDefaultSettings('graphml', $tree);
        };

        // header
        echo '<div id="reportengine-page">
            <form action=\'' . e($this->pageUrl($tree, 'graphml', $individual?->xref() ?? '')) . '\' name="setupreport" enctype="multipart/form-data" method="post">',
        csrf_field();

        echo '<table class="facts_table width50">
		<tr><td class="topbottombar" colspan="7">', I18N::translate(
            'Export tree in graphml format'
        ), '</td></tr>';

        /*
		 * Reference person
		 */
        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Reference Individual"
        ), '</td>';
        echo '<td class="optionbox" colspan="5">';
        echo view('components/select-individual', ['name' => "refid", 'id' => 'pid', 'tree' => $tree, 'individual' => $individual]);
        echo '</td>';

        echo '<tr><td class="descriptionbox width30 wrap" rowspan="3">', I18N::translate(
            "Reference symbols"
        ), '</td>';
        foreach (array("father", "mother", "wife", "husband", "son", "daughter") as $n) {
            $name = 'ref_' . $n;
            echo '<td class="optionbox" colspan="2">',  I18N::translate('symbol for'), ' ',
            I18N::translate($n), "\n";
            echo '<input type="text" size="15" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "") .
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

        foreach (array(
            "individuals", "families"
        ) as $s1) {
            $help_text = $this->helpScript($this->getHelpText($s1));
            echo '<tr><td class="descriptionbox width30 wrap" colspan="5">', I18N::translate(
                'Template for ' . $s1
            );
            echo '<span class="wt-icon-help" onclick="javascript:open(\'\', \'Help window\', \'height=600,width=800,resizable=yes\').document.write(' . e($help_text) . ')"></span>';
            echo '</td></tr>';

            foreach (array(
                "label", "description"
            ) as $s2) {
                echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
                    "Node " . $s2
                ), '</td>';

                $name = $s1 . "_" . $s2 . "_template";
                $s = array_key_exists($name, $settings) ? $settings[$name] : "";
                $nrow = substr_count($s, "\n") + 1;

                echo '<td class="optionbox" colspan="4">' . '<textarea rows="' .
                    $nrow . '" cols="100" name="' . $name . '">';
                echo e($s);
                echo '</textarea></td></tr>';
            }
        }


        /*
		 * seeds for branches
		 */

        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Seeds for branches'
        ), '</td></tr>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Seeds"
        ), '</td>';

        $name = "branch_seeds";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");

        $nrow = min(10, substr_count($s, "\n") + 1);

        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
            '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        /*
		 * Box style header
		 *
		 * This is the header line for the block which defines the box styles.
		 * Different box styles can be defines for
		 * individuals (male, femal, unknown sex) and families.
		 */
        echo '<tr><td class="descriptionbox width30 wrap" rowspan="7">', I18N::translate(
            'Box style'
        ), '</td>';
        echo '<td class="descriptionbox width30 wrap"  colspan="1">', I18N::translate(
            'Male'
        ), '</td>';
        echo '<td class="descriptionbox width30 wrap"  colspan="1">', I18N::translate(
            'Female'
        ), '</td>';
        echo '<td class="descriptionbox width30 wrap"  colspan="1">', I18N::translate(
            'Unknown sex'
        ), '</td>';
        echo '<td class="descriptionbox width30 wrap"  colspan="1">', I18N::translate(
            'Family'
        ), '</td></tr>';

        /*
		 * Box style - box type
		 *
		 * Here the types of the boxes are defined.
		 */

        $choicelist = array(
            "BevelNode2", "Rectangle", "RoundRect", "BevelNode",
            "BevelNodeWithShadow", "BevelNode3", "ShinyPlateNode",
            "ShinyPlateNodeWithShadow", "ShinyPlateNode2", "ShinyPlateNode3"
        );
        echo '<tr>';
        foreach (array(
            "male", "female", "unknown"
        ) as $s) {
            $name = "node_style_" . $s;
            // $selected = $settings[$name];
            $selected = array_key_exists($name, $settings) ? $settings[$name] : "BevelNode";

            echo '<td class="optionbox"  colspan="1">' . '<select name="' . $name .
                '">';
            foreach ($choicelist as $o) {
                echo '<option value="' . $o . '"';
                if ($selected == $o)
                    echo 'selected';
                echo '>' . $o . '</option>';
            };
            echo '</select></td>';
        }

        $name = "node_style_family";
        // $selected = $settings[$name];
        $selected = array_key_exists($name, $settings) ? $settings[$name] : "diamond";

        $choicelist_family = array(
            "rectangle", "roundrectangle", "ellipse",
            "parallelogram", "hexagon", "triangle", "rectangle3d", "octagon3d",
            "diamond", "trapezoid", "trapezoid2"
        );

        echo '<td class="optionbox"  colspan="1">' .
            '<select name="node_style_family">';
        foreach ($choicelist_family as $o) {
            echo '<option value="' . $o . '"';
            if ($selected == $o)
                echo 'selected';
            echo '>' . $o . '</option>';
        };
        echo '</select></td></tr>';

        /*
		 * Box style - Fill color
		 *
		 * Here the fill colors of the boxes are defined.
		 */
        echo '<tr><td class="optionbox" colspan="1">' .
            I18N::translate('Fill color') . '<input type="color" value="' .
            e(array_key_exists("color_male", $settings) ? $settings["color_male"] : "#ccccff") .
            '" name="color_male"></td>';
        echo '<td class="optionbox" colspan="1">' .
            I18N::translate('Fill color') . '<input type="color" value="' .
            e(array_key_exists("color_female", $settings) ? $settings["color_female"] : "#ffcccc") .
            '" name="color_female"></td>';
        echo '<td class="optionbox" colspan="1">' .
            I18N::translate('Fill color') . '<input type="color" value="' .
            e(array_key_exists("color_unknown", $settings) ? $settings["color_unknown"] : "#ffffff") .
            '" name="color_unknown"></td>';
        echo '<td class="optionbox" colspan="1">' .
            I18N::translate('Fill color') . '<input type="color" value="' .
            e(array_key_exists("color_family", $settings) ? $settings["color_family"] : "#ffffff") .
            '" name="color_family"></td></tr>';

        /*
		 * Box style - Border color
		 *
		 * Here the border colors of the boxes are defined.
		 */
        echo '<tr>';
        foreach (array(
            "male", "female", "unknown"
        ) as $s) {
            $name = "border_" . $s;
            echo '<td class="optionbox" colspan="1">' .
                I18N::translate('Border color') .
                '<input type="color" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "#ffffff") .
                '" name="' . $name . '"></td>';
        }
        foreach (array(
            "family"
        ) as $s) {
            $name = "border_" . $s;
            echo '<td class="optionbox" colspan="1">' .
                I18N::translate('Border color') .
                '<input type="color" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "#ffffff") .
                '" name="' . $name . '"></td>';
        }
        echo '</tr>';

        /*
		 * Box style - Box width
		 *
		 * Here the widths of the boxes are defined.
		 */
        echo '<tr>';
        foreach (array(
            "male", "female", "unknown"
        ) as $s) {
            $name = "box_width_" . $s;
            echo '<td class="optionbox" colspan="1">' .
                I18N::translate('Box width') .
                '<input type="number" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "120") .
                '" name="' . $name . '"></td>';
        }
        $name = "box_width_family";
        echo '<td class="optionbox" colspan="1">' . I18N::translate('Symbol') .
            " " . I18N::translate('width') . "/" .
            I18N::translate('height') . '<input type="number" value="' .
            e(array_key_exists($name, $settings) ? $settings[$name] : "120") .
            '" name="' . $name . '"></td></tr>';

        /*
		 * Box style - Border line width
		 *
		 * Here the widths of the border lines are defined.
		 */
        echo '<tr>';
        foreach (array(
            "male", "female", "unknown", "family"
        ) as $s) {
            $name = "border_width_" . $s;
            echo '<td class="optionbox" colspan="1">' .
                I18N::translate('Border width') .
                '<input type="number" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "1") .
                '" step="0.1" name="' . $name . '"></td>';
        }
        echo '</tr>';

        /*
		 * Box style - Font size
		 *
		 * Here the font sizes of the text are defined.
		 */
        echo '<tr>';
        foreach (array(
            "male", "female", "unknown", "family"
        ) as $s) {
            $name = "font_size_" . $s;
            echo '<td class="optionbox" colspan="1">' .
                I18N::translate('Font size') .
                '<input type="number" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "10") .
                '" step="1" name="' . $name . '"></td>';
        }
        echo '</tr>';

        /*
		 * Box style - default silhouettes
		 *
		 * Here the default silhouettes are defined.
		 */
        echo '<tr><td class="descriptionbox width30 wrap" rowspan="1">', I18N::translate(
            'Default portrait'
        ), '</td>';
        foreach (array(
            "male", "female", "unknown"
        ) as $s) {
            $name = "default_portrait_" . $s;
            echo '<td class="optionbox" colspan="1">
					<input type="text" size="30" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "") .
                '" name="' . $name . '"></td>';
        }

        echo '<td class="optionbox" colspan="1"/></tr>';

        /*
		 * Font type
		 *
		 * Here the font type is defined.
		 */
        $name = "font";
        $selected = (array_key_exists($name, $settings) ? $settings[$name] : "");

        $choicelist_font = array(
            "Times New Roman", "Dialog", "Franklin Gothic Book",
            "Bookman Old Style", "Lucida Handwriting"
        );

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            'Font'
        ), '</td><td class="optionbox" colspan="4">' .
            '<select name="font">';
        foreach ($choicelist_font as $o) {
            echo '<option value="' . $o . '"';
            if ($selected == $o)
                echo 'selected';
            echo '>' . $o . '</option>';
        };
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
        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            'Line width of edge'
        ), '</td><td class="optionbox" colspan="4">
			<input type="number" value="' .
            e(array_key_exists("edge_line_width", $settings) ? $settings["edge_line_width"] : "1") .
            '" step="0.1" name="edge_line_width" min="1" max="7"></td></tr>';

        /*
		 * Box type, line width and line color for links
		 */
        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            'Box type and border width of links between sub branches'
        );

        $name = "node_style_link";
        $selected = array_key_exists($name, $settings) ? $settings[$name] : "BevelNode";

        echo '<td class="optionbox"  colspan="1">' . '<select name="' . $name .
            '">';
        foreach ($choicelist as $o) {
            echo '<option value="' . $o . '"';
            if ($selected == $o)
                echo 'selected';
            echo '>' . $o . '</option>';
        };
        echo '</select></td>';
        echo '</td><td class="optionbox" colspan="1">
			<input type="number" value="' .
            e(array_key_exists("border_width_link", $settings) ? $settings["border_width_link"] : "1") .
            '" step="0.1" name="border_width_link" min="1" max="7"></td>';
        $name = "border_link_color";
        echo '<td class="optionbox" colspan="2">' .
            '<input type="color" value="' .
            e(array_key_exists($name, $settings) ? $settings[$name] : "#ffffff") .
            '" name="' . $name . '"></td></tr>';


        // Submit button
        //echo '<tr><td class="topbottombar" colspan="6">', 
        //'<button name="mod_action" value="export_graphml">', I18N::translate ( 
        //		'Export Family Tree' ), '</button>', '</td></tr>';
        // echo '</form>';
        // new for post method
        echo '<tr><td class="topbottombar" colspan="6">',
        '<button type="submit" name="mod_action" value="export_graphml">', I18N::translate(
            'Export Family Tree'
        ), '</button>', '</td></tr>';

        // download settings
        // echo '<form name="download" method="get" action="module.php">
        // <input type="hidden" name="mod" value=', $this->getName (), '>';
        // <input type="hidden" name="mod_action" value="export">
        echo '<tr><td class="topbottombar" colspan="6">', '<button name="mod_action" value="download_settings_graphml">', I18N::translate(
            'Download Settings'
        ), '</button></td></tr>';
        echo $this->saveTreeSettingsRow($tree, 'graphml', 6);
        echo '</table></form>';
        // echo '</table></form>';

        // echo '</div>';

        // upload settings
        // echo '<div id="reportengine-page">';
        echo '<table class="facts_table width50">';
        echo '<tr><td class="descriptionbox width30 wrap" colspan="5">', I18N::translate(
            'Upload/Download Settings'
        ), '</td></tr>';
        // header line of the form
        //echo '<form name="upload" enctype="multipart/form-data" method="POST" 
        //		action="module.php?mod=' . $this->getName () .
        //		 '&mod_action=upload_settings_graphml">';
        echo '<form action=\'' . e($this->pageUrl($tree, 'graphml', $individual?->xref() ?? '')) . '\' name="upload" enctype="multipart/form-data" method="post">',
        csrf_field();

        //echo '<form name="upload" enctype="multipart/form-data" method="POST" 
        //         action="$module->chartUrl($individual, array("mod_action" = "upload_settings_graphml"))">';
        // <input type="hidden" name="mod" value=', $this->getName (), '>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            'Read'
        ), '</td><td class="optionbox" colspan="4">', '<input name="uploadedfile" type="file"/>', '<button type="submit" name="mod_action" value="upload_settings_graphml">', I18N::translate(
            'Upload Settings'
        ), '</button></td></tr>';
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
    public function setParameterLatex(?array $settings, Tree $tree, ?Individual $individual)
    {
        $help_text = $this->helpScript($this->getHelpText("latex"));

        // fill read settings if not passed
        if (is_null($settings)) {
            $settings = $this->readDefaultSettings('latex', $tree);
        };

        // header line 
        echo '<div id="reportengine-page">
				<form action=\'' . e($this->pageUrl($tree, 'latex', $individual?->xref() ?? '')) . '\' name="setupreport" enctype="multipart/form-data" method="post">',
        csrf_field();

        echo '<table class="facts_table width50">
		<tr><td class="topbottombar" colspan="7">', I18N::translate(
            'Export tree in latex format'
        ), '</td></tr>';

        /*
		 * Reference person
		 */
        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Reference Individual"
        ), '</td>';
        echo '<td class="optionbox" colspan="6">';
        //echo '<label for="pid">' . I18N::translate('Enter an individual ID') . '</label>';
        //echo '<input class="pedigree_form" data-autocomplete-type="IFSRO" type="text" name="refid" id="pid" size="5" value="">';


        //echo ' ' . FunctionsPrint::printFindIndividualLink('pid');
        echo view('components/select-individual', ['name' => "refid", 'id' => 'pid', 'tree' => $tree, 'individual' => $individual]);
        echo '</td>';

        echo '<tr><td class="descriptionbox width30 wrap" rowspan="3">', I18N::translate(
            "Reference symbols"
        ), '</td>';
        foreach (array("father", "mother", "wife", "husband", "son", "daughter") as $n) {
            $name = 'ref_' . $n;
            echo '<td class="optionbox" colspan="2">',  I18N::translate('symbol for'), ' ',
            I18N::translate($n), "\n";
            echo '<input type="text" size="15" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "") .
                '" name="' . $name . '"></td>';
            if ($n == "mother" or $n == "husband") echo '</tr><tr>';
        };
        echo '</tr>';

        /*
		 * Preamble
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Preamble'
        ), '</td></tr>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Preamble"
        ), '</td>';

        $name = "preamble";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");
        $nrow = min(10, substr_count($s, "\n") + 1);

        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
            '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        /*
		 * document title
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Document Title'
        ), '</td></tr>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Title"
        ), '</td>';

        $name = "title";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");
        $nrow = min(10, substr_count($s, "\n") + 1);

        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
            '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        /*
		 * Individual text
		 *
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Template for individuals'
        );

        echo '<span class="wt-icon-help" onclick="javascript:open(\'\', \'Help window\', \'height=600,width=800,resizable=yes\').document.write(' . e($help_text) . ')"></span>';

        // Node text
        echo  '</td></tr><tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Section summary lable"
        ), '</td>';

        $name = "individuals_summary_label_template";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");

        $nrow = substr_count($s, "\n") + 1;

        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' .
            $nrow . '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        // Node text
        echo  '</td></tr><tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Node label"
        ), '</td>';

        $name = "individuals_label_template";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");

        $nrow = substr_count($s, "\n") + 1;

        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' .
            $nrow . '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        /*
		 * Epilog
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Epilog'
        ), '</td></tr>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Epilog"
        ), '</td>';

        $name = "epilog";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");

        $nrow = min(10, substr_count($s, "\n") + 1);

        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
            '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        /*
		 * seeds for branches
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Seeds for branches'
        ), '</td></tr>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Seeds"
        ), '</td>';

        $name = "branch_seeds";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");

        $nrow = min(10, substr_count($s, "\n") + 1);

        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
            '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        /*
		 * Replacement for fact abbreviations
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Symbols to be used for facts'
        ), '</td></tr>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Symbols"
        ), '</td>';

        $name = "symbols";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");

        $nrow = min(10, substr_count($s, "\n") + 1);

        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
            '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        /*
		 * include exclude media objects
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Include and exclude media objects'
        ), '</td></tr>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Include"
        ), '</td>';
        $name = "include_media";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");
        $nrow = min(2, substr_count($s, "\n") + 1);
        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
            '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            "Exclude"
        ), '</td>';
        $name = "exclude_media";
        $s = (array_key_exists($name, $settings) ? $settings[$name] : "");
        $nrow = min(2, substr_count($s, "\n") + 1);
        echo '<td class="optionbox" colspan="6">' . '<textarea rows="' . $nrow .
            '" cols="100" name="' . $name . '">';
        echo e($s);
        echo '</textarea></td></tr>';

        /*
		 * default format for dates and places
		 *
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Default formats'
        ), '</td></tr>';

        foreach (array(
            "date", "place"
        ) as $s) {
            echo '<tr><td class="descriptionbox width30 wrap" colspan="1">', I18N::translate(
                'Default ' . $s . ' format'
            ), '</td>';

            $name = 'default_' . $s . '_format';
            echo '<td class="optionbox" colspan="6">
				<input type="text" size="30" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "") .
                '" name="' . $name . '"></td></tr>';
        }

        /*
		 * hierarchy for family tree and generation
		 *
		 */
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Document hierarchy'
        ), '</td></tr>';

        foreach (array(
            "branch", "sub_branch", "generation"
        ) as $s) {
            echo '<tr><td class="descriptionbox width30 wrap" colspan="1">', I18N::translate(
                'Hierarchy level for ' . $s
            ), '</td>';

            $name = 'hierarchy_' . $s;
            echo '<td class="optionbox" colspan="6">
				<input type="text" size="30" value="' .
                e(array_key_exists($name, $settings) ? $settings[$name] : "") .
                '" name="' . $name . '"></td></tr>';
        }


        //echo '<td class="optionbox" colspan="1"/></tr>';



        // Submit button to export the family tree

        //echo '<tr><td class="topbottombar" colspan="7">', 
        //'<button name="mod_action" value="export_latex">', I18N::translate ( 
        //		'Export Family Tree' ), '</button>', '</td></tr>';
        // new for post method
        echo '<tr><td class="topbottombar" colspan="7">',
        '<button type="submit" name="mod_action" value="export_latex">', I18N::translate(
            'Export Family Tree'
        ), '</button>', '</td></tr>';
        /* '<form action=\'$module->chartUrl($individual, [\"mod_action\" => \"export_latex\"])\' method=\"post\">',
              '<?= csrf_field() ?>',
              '<input type="submit" value="', I18N::translate (
			 	'Export Family Tree' ), '"></form>','</td></tr>';
        */
        // Button to download settings
        //echo '<form name="download" method="get" action="module.php">,
        //        <input type="submit" value="download">';
        // <input type="hidden" name="mod_action" value="export">
        echo '<tr><td class="topbottombar" colspan="7">', '<button name="mod_action" value="download_settings_latex">', I18N::translate(
            'Download Settings'
        ), '</button></td></tr>';
        echo $this->saveTreeSettingsRow($tree, 'latex', 7);
        echo '</table></form>';
        // echo '</table></form>';

        // echo '</div>';

        // upload settings
        // echo '<div id="reportengine-page">';
        echo '<table class="facts_table width50">';
        echo '<tr><td class="descriptionbox width30 wrap" colspan="7">', I18N::translate(
            'Upload/Download Settings'
        ), '</td></tr>';
        // header line of the form
        echo '<form action=\'' . e($this->pageUrl($tree, 'latex', $individual?->xref() ?? '')) . '\' name="upload" enctype="multipart/form-data" method="post">',
        csrf_field();

        //echo '<form name="upload" enctype="multipart/form-data" method="POST" 
        //        action="$module->chartUrl($individual)">';
        //echo 'csrf_field()';
        // <input type="hidden" name="mod" value=', $this->getName (), '>';

        echo '<tr><td class="descriptionbox width30 wrap">', I18N::translate(
            'Read'
        ), '</td><td class="optionbox" colspan="6">', '<input name="uploadedfile" type="file"/>', '<button type="submit" name="mod_action" value="upload_settings_latex">', I18N::translate(
            'Upload Settings'
        ), '</button></td></tr>';
        echo '</table></form>';

        echo '</div>';
    }
}

return new ExportFamilyBook(
    Registry::container()->get(ResponseFactoryInterface::class),
    Registry::container()->get(StreamFactoryInterface::class)
);
