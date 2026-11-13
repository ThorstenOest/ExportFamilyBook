<?php

/**
 * ExportFamilyBook module for Webtrees: page "Branches"
 *
 * The list of branch seeds (setting branch_seeds) of the active profile as a table. The structure of the
 * list is common to all profiles, which branches are exported (column 7 include/exclude) belongs to the
 * profile (see moduleProfiles.php).
 *
 * Format of the list (read by ExportFamilyBookData::getSeeds()): the first two characters are the
 * separators (columns, lists). One branch per line, 3 to 7 columns:
 * start individual; name; direction; stop individuals; heading of the branch; heading per generation; include/exclude
 */

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Http\RequestHandlers\TomSelectIndividual;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

trait ExportFamilyBookBranches
{
    // The two lists of branch seeds: the book (LaTeX) and the chart (GraphML)
    private const BRANCH_LISTS = array('latex', 'graphml');

    // Allowed values of column 3 (as checked by the export)
    private const BRANCH_DIRECTIONS = array('both', 'ancestors', 'descendants');

    // Format of the file branches.json of a profile (the same as the sets of branches of version 2.2.0-4b)
    private const BRANCH_SET_FORMAT = 'ExportFamilyBook-branch-set';
    private const BRANCH_SET_VERSION = 1;

    // Upper limit for the number of lines sent by the form
    private const BRANCH_ROWS_MAX = 5000;

    /**
     * Page "Branches"
     */
    public function getBranchesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        $this->checkAccess($request, $tree);

        return $this->branchesPage($request, $tree, $this->branchListType($request), null, array(), null);
    }

    /**
     * Form of the page "Branches" (managers only): save the list of the active profile
     */
    public function postBranchesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        $this->checkAccess($request, $tree);
        $user = Validator::attributes($request)->user();
        $list = $this->branchListType($request);
        $url = $this->branchesUrl($request, $tree, $list);

        if (!Auth::isManager($tree, $user)) {
            FlashMessages::addMessage(e(I18N::translate('Only managers of the family tree can change the branches.')), 'danger');
            return redirect($url);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : array();
        $post = static fn (string $name): string => is_string($body[$name] ?? null) ? $body[$name] : '';

        if (!$this->checkFormProfile($request, $tree)) {
            return redirect($url);
        }

        try {
            if ($post('mod_action') === 'save_branches') {
                return $this->saveBranchesForm($request, $tree, $list, $post);
            }
        } catch (FilesystemException | JsonException $ex) {
            FlashMessages::addMessage(e(I18N::translate('The branches could not be saved: %s', $ex->getMessage())), 'danger');
        }

        return redirect($url);
    }

    /**
     * The list shown on the page: 'latex' (book) or 'graphml' (chart), parameter "list"
     */
    private function branchListType(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();
        $list = is_array($body) && is_string($body['list'] ?? null)
            ? $body['list']
            : Validator::queryParams($request)->string('list', 'latex');

        return in_array($list, self::BRANCH_LISTS, true) ? $list : 'latex';
    }

    /**
     * URL of the page "Branches" with a list
     */
    private function branchesUrl(ServerRequestInterface $request, Tree $tree, string $list): string
    {
        $xref = Validator::queryParams($request)->isXref()->string('xref', '');

        return $this->pageUrl($tree, 'branches', $xref, $list === 'latex' ? array() : array('list' => $list));
    }

    /**
     * Show the page
     *
     * @param ServerRequestInterface $request
     * @param Tree $tree
     * @param string $list 'latex' or 'graphml'
     * @param array|null $rows rows of a form that could not be saved (null: rows of the list)
     * @param array<string,array<string>> $errors errors per row ID
     * @param array<string,string>|null $headings default headings of a form that could not be saved
     * @return ResponseInterface
     */
    private function branchesPage(ServerRequestInterface $request, Tree $tree, string $list, ?array $rows, array $errors, ?array $headings): ResponseInterface
    {
        $user = Validator::attributes($request)->user();
        $unsaved = ($rows !== null);
        $value = $this->currentSeedList($tree, $list);
        $parsed = $this->parseSeedList($value);
        $states = $this->branchStates($parsed['items']);

        $profile = $this->profileBarData($request, $tree, 'branches', $list);
        $active = $profile['active'];
        // branches that are not in the selection of the profile yet (added later): they use the common state
        $new_keys = array();
        $entries = $this->readProfileBranches($tree, $active)[$list] ?? null;
        if ($entries !== null && !in_array('branch_seeds', $this->profileOwnFields($list, $tree, $active), true)) {
            $new_keys = array_map('strval', array_keys(array_diff_key($states, $entries)));
        }

        if ($rows === null) {
            $rows = $this->branchRowsForPage($parsed, $tree);
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['errors'] = $errors[$row['id']] ?? array();
            $rows[$index]['new'] = ($row['type'] === 'branch' && str_starts_with($row['id'], 'o')
                && in_array($this->branchRowKey($rows, $index), $new_keys, true));
        }

        $settings = $this->readProfileSettings('latex', $tree, $active);
        $headings ??= array(
            'h5' => $settings['hierarchy_sub_branch'] ?? '',
            'h6' => $settings['hierarchy_generation'] ?? '',
        );

        $xref = Validator::queryParams($request)->isXref()->string('xref', '');

        return $this->viewResponse($this->name() . '::branches', array(
            'title' => I18N::translate('Family book') . ' – ' . $this->pageTitle('branches'),
            'tree' => $tree,
            'list' => $list,
            'can_save' => Auth::isManager($tree, $user),
            'valid' => $parsed['valid'],
            'raw_value' => $parsed['valid'] ? '' : $value,
            'sep1' => $parsed['sep1'],
            'sep2' => $parsed['sep2'],
            'rows' => $rows,
            'people' => $this->branchPeople($tree, $rows),
            'base' => md5($value),
            'unsaved' => $unsaved,
            'headings' => $headings,
            'profile' => $profile,
            'module_name' => $this->name(),
            'form_url' => $this->pageUrl($tree, 'branches', $xref, $list === 'latex' ? array() : array('list' => $list)),
            'tab_urls' => array(
                'latex' => $this->pageUrl($tree, 'branches', $xref),
                'graphml' => $this->pageUrl($tree, 'branches', $xref, array('list' => 'graphml')),
            ),
            'page_urls' => array(
                'latex' => $this->pageUrl($tree, 'latex', $xref),
                'graphml' => $this->pageUrl($tree, 'graphml', $xref),
            ),
            'search_url' => route(TomSelectIndividual::class, array('tree' => $tree->name(), 'at' => '')),
        ));
    }

    /**
     * The current list of a type of the active profile (data folder of the tree, else module folder)
     */
    private function currentSeedList(Tree $tree, string $list): string
    {
        return $this->readProfileSettings($list, $tree, $this->activeProfile($tree))['branch_seeds'] ?? '';
    }

    /**
     * Split a list of branch seeds into its lines
     *
     * Lines are kept as they are. A line is a branch if the export reads it as a branch without an error and
     * its IDs are valid. Other lines (comments, lines without separator, lines with an error) are kept as "raw".
     *
     * @param string $value
     * @return array{valid: bool, sep1: string, sep2: string, eol: string, lead: string|null, items: array<int,array>, trail: array<string>}
     *         lead: empty line directly after the separators (or null), trail: empty lines at the end
     */
    public function parseSeedList(string $value): array
    {
        $result = array('valid' => true, 'sep1' => ';', 'sep2' => ',', 'eol' => "\r\n", 'lead' => null, 'items' => array(), 'trail' => array());
        if ($value === '') {
            // new list: the branches start in the line after the separators
            $result['lead'] = '';
            return $result;
        }
        $separator = static fn (string $c): bool => preg_match('/^[\x21-\x7E]$/', $c) === 1 && !ctype_alnum($c);
        if (strlen($value) < 2 || !$separator($value[0]) || !$separator($value[1]) || $value[0] === $value[1]) {
            $result['valid'] = false;
            return $result;
        }

        $result['sep1'] = $value[0];
        $result['sep2'] = $value[1];
        $rest = substr($value, 2);
        if (!str_contains($rest, "\r\n") && str_contains($rest, "\n")) {
            $result['eol'] = "\n";
        }
        $lines = explode("\n", $rest);
        foreach ($lines as $i => $line) {
            if (str_ends_with($line, "\r")) {
                $lines[$i] = substr($line, 0, -1);
            }
        }
        if (count($lines) > 1 && trim($lines[0]) === '') {
            $result['lead'] = array_shift($lines);
        }
        while ($lines !== array() && trim(end($lines)) === '') {
            array_unshift($result['trail'], array_pop($lines));
        }
        foreach ($lines as $line) {
            $result['items'][] = $this->parseSeedLine($line, $result['sep1'], $result['sep2']);
        }

        return $result;
    }

    /**
     * One line of the list
     *
     * @param string $line
     * @param string $sep1
     * @param string $sep2
     * @return array type 'branch' (with the columns), 'blank' or 'raw' (with a reason)
     */
    private function parseSeedLine(string $line, string $sep1, string $sep2): array
    {
        $row = trim($line);
        if ($row === '') {
            return array('type' => 'blank', 'line' => $line);
        }
        $raw = static fn (string $reason): array => array('type' => 'raw', 'line' => $line, 'reason' => $reason);
        if (str_starts_with($row, '#')) {
            return $raw('comment');
        }
        // the export: explode the trimmed line, ignore lines with one column, stop with an error at < 3 or > 7 columns
        $cols = explode($sep1, $row);
        if (count($cols) < 2) {
            return $raw('ignored');
        }
        if (count($cols) < 3 || count($cols) > 7) {
            return $raw('columns');
        }
        if (!in_array($cols[2], self::BRANCH_DIRECTIONS, true)) {
            return $raw('direction');
        }
        if (count($cols) === 7 && !in_array(strtolower($cols[6]), array('include', 'exclude'), true)) {
            return $raw('include');
        }
        if (!$this->isSeedXref($cols[0])) {
            return $raw('xref');
        }
        $stops = array();
        foreach (explode($sep2, $cols[3] ?? '') as $stop) {
            if ($stop === '') {
                continue;
            }
            if (!$this->isSeedXref($stop)) {
                return $raw('xref');
            }
            $stops[] = $stop;
        }

        return array(
            'type' => 'branch',
            'line' => $line,
            'xref' => $cols[0],
            'name' => $cols[1],
            'direction' => $cols[2],
            'stops' => $stops,
            'h5' => $cols[4] ?? '',
            'h6' => $cols[5] ?? '',
            'include' => strtolower($cols[6] ?? 'include') === 'include',
        );
    }

    /**
     * Is this a valid ID of an individual?
     */
    private function isSeedXref(string $xref): bool
    {
        return preg_match('/^' . Gedcom::REGEX_XREF . '$/', $xref) === 1;
    }

    /**
     * The text of a list (inverse of parseSeedList)
     *
     * @param array $parsed see parseSeedList()
     * @param array<string> $lines the lines between the leading and the trailing empty lines
     * @return string
     */
    public function serializeSeedList(array $parsed, array $lines): string
    {
        $all = array_merge($parsed['lead'] === null ? array() : array($parsed['lead']), $lines, $parsed['trail']);

        return $parsed['sep1'] . $parsed['sep2'] . implode($parsed['eol'], $all);
    }

    /**
     * The line of a new or changed branch
     *
     * Trailing columns with default values (empty, include) are left out; a branch that is not
     * exported needs all 7 columns. So switching a branch off and on again restores its line.
     *
     * @param array $row branch (see parseSeedLine)
     * @param string $sep1
     * @param string $sep2
     * @return string
     */
    private function seedLine(array $row, string $sep1, string $sep2): string
    {
        $values = array(
            $row['xref'], $row['name'], $row['direction'], implode($sep2, $row['stops']),
            $row['h5'], $row['h6'], $row['include'] ? 'include' : 'exclude',
        );
        $needed = !$row['include'] ? 7 : ($row['h6'] !== '' ? 6 : ($row['h5'] !== '' ? 5 : ($row['stops'] !== array() ? 4 : 3)));

        return implode($sep1, array_slice($values, 0, $needed));
    }

    /**
     * Is a branch unchanged compared with its original line?
     */
    private function sameBranch(array $a, array $b): bool
    {
        foreach (array('xref', 'name', 'direction', 'stops', 'h5', 'h6', 'include') as $key) {
            if ($a[$key] !== $b[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rows of the page for the lines of the list
     *
     * @param array $parsed see parseSeedList()
     * @param Tree $tree
     * @return array<int,array>
     */
    private function branchRowsForPage(array $parsed, Tree $tree): array
    {
        $rows = array();
        foreach ($parsed['items'] as $index => $item) {
            $row = array('id' => 'o' . $index, 'type' => $item['type']);
            if ($item['type'] === 'branch') {
                $row += array_intersect_key($item, array_flip(array('xref', 'name', 'direction', 'stops', 'h5', 'h6', 'include')));
            } else {
                $row['line'] = $item['line'];
                $row['reason'] = $item['reason'] ?? '';
            }
            $rows[] = $row;
        }

        return $this->addBranchWarnings($rows, $tree);
    }

    /**
     * Warnings per row: unknown individuals, start individual of several branches
     *
     * @param array<int,array> $rows
     * @param Tree $tree
     * @return array<int,array>
     */
    private function addBranchWarnings(array $rows, Tree $tree): array
    {
        $count = array();
        foreach ($rows as $row) {
            if ($row['type'] === 'branch') {
                $count[$row['xref']] = ($count[$row['xref']] ?? 0) + 1;
            }
        }
        foreach ($rows as $index => $row) {
            $warnings = array();
            if ($row['type'] === 'branch') {
                if ($row['xref'] !== '' && !$this->individualExists($tree, $row['xref'])) {
                    $warnings[] = I18N::translate('The start individual %s does not exist. The export skips this branch.', $row['xref']);
                }
                foreach ($row['stops'] as $stop) {
                    if (!$this->individualExists($tree, $stop)) {
                        $warnings[] = I18N::translate('The stop individual %s does not exist.', $stop);
                    }
                }
                if ($row['xref'] !== '' && $count[$row['xref']] > 1) {
                    $warnings[] = I18N::translate('This individual is the start of several branches.');
                }
            }
            $rows[$index]['warnings'] = $warnings;
        }

        return $rows;
    }

    /**
     * Does an individual with this ID exist in the tree?
     */
    private function individualExists(Tree $tree, string $xref): bool
    {
        return $this->isSeedXref($xref) && Registry::individualFactory()->make($xref, $tree) instanceof Individual;
    }

    /**
     * Names of the individuals of the rows (plain text with the life span) and the links to them
     *
     * @param Tree $tree
     * @param array<int,array> $rows
     * @return array<string,array{label: string, url: string}>
     */
    private function branchPeople(Tree $tree, array $rows): array
    {
        $plain = static fn (string $html): string => trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $people = array();
        foreach ($rows as $row) {
            if ($row['type'] !== 'branch') {
                continue;
            }
            foreach (array_merge(array($row['xref']), $row['stops']) as $xref) {
                if (array_key_exists($xref, $people) || !$this->isSeedXref($xref)) {
                    continue;
                }
                $individual = Registry::individualFactory()->make($xref, $tree);
                if (!$individual instanceof Individual) {
                    continue;
                }
                $people[$xref] = $individual->canShow()
                    ? array('label' => $plain($individual->fullName()) . ' (' . $plain($individual->lifespan()) . ')', 'url' => $individual->url())
                    : array('label' => I18N::translate('Private'), 'url' => '');
            }
        }

        return $people;
    }

    /**
     * Save the table (and with mod_action save_set the selection as a set)
     *
     * @param ServerRequestInterface $request
     * @param Tree $tree
     * @param string $list
     * @param callable $post value of a form field
     * @return ResponseInterface
     * @throws FilesystemException
     * @throws JsonException
     */
    private function saveBranchesForm(ServerRequestInterface $request, Tree $tree, string $list, callable $post): ResponseInterface
    {
        $url = $this->branchesUrl($request, $tree, $list);
        $current = $this->currentSeedList($tree, $list);
        if (!hash_equals(md5($current), $post('base'))) {
            FlashMessages::addMessage(e(I18N::translate('The list of branches has been changed in the meantime. Please load the page again and repeat your changes.')), 'danger');
            return redirect($url);
        }
        $parsed = $this->parseSeedList($current);
        if (!$parsed['valid']) {
            FlashMessages::addMessage(e(I18N::translate('The list of branches cannot be changed on this page because it does not start with two separators.')), 'danger');
            return redirect($url);
        }

        // default headings of the book: only if the form contains them
        $body = $request->getParsedBody();
        $headings = ($list === 'latex' && is_array($body) && array_key_exists('default_h5', $body) && array_key_exists('default_h6', $body))
            ? array('h5' => $post('default_h5'), 'h6' => $post('default_h6'))
            : null;
        $result = $this->seedLinesFromForm($parsed, $post('rows'), $tree);
        if ($result === null) {
            FlashMessages::addMessage(e(I18N::translate('The form data is invalid.')), 'danger');
            return redirect($url);
        }
        $errors = $result['errors'];
        foreach ($headings ?? array() as $value) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                $errors['defaults'] = array(I18N::translate('A heading must not contain a line break.'));
            }
        }

        if ($errors !== array()) {
            $messages = array();
            foreach ($errors as $row_errors) {
                $messages = array_merge($messages, $row_errors);
            }
            FlashMessages::addMessage(e(I18N::translate('Nothing has been saved. Please correct the marked rows: %s', implode(' ', array_unique($messages)))), 'danger');
            $rows = $this->addBranchWarnings($result['rows'], $tree);
            return $this->branchesPage($request, $tree, $list, $rows, $errors, $headings);
        }

        $changed = $this->saveSeedList($tree, $list, $this->serializeSeedList($parsed, $result['lines']), $current);
        if ($headings !== null) {
            $changed = $this->saveDefaultHeadings($tree, $headings) || $changed;
        }

        FlashMessages::addMessage(e($changed
            ? I18N::translate('The branches have been saved.')
            : I18N::translate('The branches have not changed.')), 'success');

        return redirect($url);
    }

    /**
     * Lines of the list from the rows sent by the form
     *
     * Rows are sent as JSON: {id, type} for lines that the page does not change (raw lines, empty lines),
     * {id, type: 'branch', xref, name, direction, stops, h5, h6, include} for branches.
     * IDs "o<n>" are lines of the list (index of parseSeedList()), "n<n>" new rows.
     * An unchanged branch keeps its original line. Only changed values are checked.
     *
     * @param array $parsed see parseSeedList()
     * @param string $json
     * @param Tree $tree
     * @return array{lines: array<string>, rows: array<int,array>, errors: array<string,array<string>>}|null null: invalid data
     */
    private function seedLinesFromForm(array $parsed, string $json, Tree $tree): ?array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $ex) {
            return null;
        }
        if (!is_array($data) || !array_is_list($data) || count($data) > self::BRANCH_ROWS_MAX) {
            return null;
        }

        $sep1 = $parsed['sep1'];
        $sep2 = $parsed['sep2'];
        $lines = array();
        $rows = array();
        $errors = array();
        $seen = array();
        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['type'] ?? null)
                || preg_match('/^[on]\d{1,5}$/', $item['id']) !== 1 || array_key_exists($item['id'], $seen)) {
                return null;
            }
            $id = $item['id'];
            $seen[$id] = true;
            $orig = str_starts_with($id, 'o') ? ($parsed['items'][(int) substr($id, 1)] ?? null) : null;
            if (str_starts_with($id, 'o') && $orig === null) {
                return null;
            }

            if ($item['type'] !== 'branch') {
                // lines that the page does not change: only existing lines of the same type
                if ($orig === null || $orig['type'] !== $item['type']) {
                    return null;
                }
                $lines[] = $orig['line'];
                $rows[] = array('id' => $id, 'type' => $orig['type'], 'line' => $orig['line'], 'reason' => $orig['reason'] ?? '');
                continue;
            }
            if ($orig !== null && $orig['type'] !== 'branch') {
                return null;
            }

            $row = array();
            foreach (array('xref', 'name', 'direction', 'h5', 'h6') as $key) {
                if (!is_string($item[$key] ?? null)) {
                    return null;
                }
                $row[$key] = $item[$key];
            }
            if (!is_bool($item['include'] ?? null) || !is_array($item['stops'] ?? null) || !array_is_list($item['stops'])) {
                return null;
            }
            $row['include'] = $item['include'];
            $row['stops'] = array();
            foreach ($item['stops'] as $stop) {
                if (!is_string($stop)) {
                    return null;
                }
                if ($stop !== '' && !in_array($stop, $row['stops'], true)) {
                    $row['stops'][] = $stop;
                }
            }
            if ($orig === null || $row['name'] !== $orig['name']) {
                $row['name'] = trim($row['name']);
            }

            $row_errors = $this->branchRowErrors($row, $orig, $sep1, $sep2, $tree);
            if ($row_errors !== array()) {
                $errors[$id] = $row_errors;
            }
            $lines[] = ($orig !== null && $this->sameBranch($row, $orig))
                ? $orig['line']
                : $this->seedLine($row, $sep1, $sep2);
            $rows[] = array('id' => $id, 'type' => 'branch') + $row;
        }

        // the same start individual in several branches: an error if one of them is new or changed
        $by_xref = array();
        foreach ($rows as $row) {
            if ($row['type'] === 'branch') {
                $by_xref[$row['xref']][] = $row['id'];
            }
        }
        foreach ($by_xref as $xref => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $new = false;
            foreach ($ids as $id) {
                $orig = str_starts_with($id, 'o') ? $parsed['items'][(int) substr($id, 1)] : null;
                $new = $new || $orig === null || $orig['xref'] !== $xref;
            }
            if ($new) {
                foreach ($ids as $id) {
                    $errors[$id][] = I18N::translate('The individual %s is the start of several branches.', $xref);
                }
            }
        }

        return array('lines' => $lines, 'rows' => $rows, 'errors' => $errors);
    }

    /**
     * Check the changed values of a branch
     *
     * @param array $row
     * @param array|null $orig original branch (null: new row)
     * @param string $sep1
     * @param string $sep2
     * @param Tree $tree
     * @return array<string> error messages
     */
    private function branchRowErrors(array $row, ?array $orig, string $sep1, string $sep2, Tree $tree): array
    {
        $errors = array();
        $changed = static fn (string $key): bool => $orig === null || $row[$key] !== $orig[$key];
        $separators = static fn (string $value): bool => str_contains($value, $sep1) || preg_match('/[\r\n]/', $value) === 1;

        if ($changed('xref')) {
            if ($row['xref'] === '') {
                $errors[] = I18N::translate('Please select the start individual.');
            } elseif (!$this->individualExists($tree, $row['xref']) || str_contains($row['xref'], $sep1)) {
                $errors[] = I18N::translate('The start individual %s does not exist.', $row['xref']);
            }
        }
        if ($changed('name')) {
            if ($row['name'] === '') {
                $errors[] = I18N::translate('Please enter the name of the branch.');
            } elseif ($separators($row['name'])) {
                $errors[] = I18N::translate('The name “%1$s” must not contain the character %2$s (separator of the list) or a line break.', $row['name'], $sep1);
            }
        }
        if (!in_array($row['direction'], self::BRANCH_DIRECTIONS, true)) {
            $errors[] = I18N::translate('The direction “%s” is invalid.', $row['direction']);
        }
        foreach ($row['stops'] as $stop) {
            if (($orig === null || !in_array($stop, $orig['stops'], true))
                && (!$this->individualExists($tree, $stop) || str_contains($stop, $sep1) || str_contains($stop, $sep2))) {
                $errors[] = I18N::translate('The stop individual %s does not exist.', $stop);
            }
        }
        foreach (array('h5', 'h6') as $key) {
            if ($changed($key) && $separators($row[$key])) {
                $errors[] = I18N::translate('The heading “%1$s” must not contain the character %2$s (separator of the list) or a line break.', $row[$key], $sep1);
            }
        }

        return $errors;
    }

    /**
     * Save the list of a type of the active profile (only if it has changed)
     *
     * Standard: branch_seeds.txt of the tree. Other profiles: the structure goes to the common list,
     * which branches are exported to the profile (unless the profile has a list of its own).
     *
     * @throws FilesystemException
     * @throws JsonException
     */
    private function saveSeedList(Tree $tree, string $list, string $new, string $current): bool
    {
        $profiles = $this->readProfiles($tree);
        $id = $this->activeProfile($tree, $profiles);
        if ($id !== '' && !in_array('branch_seeds', $this->profileOwnFields($list, $tree, $id), true)) {
            // the selection of the profile is saved even if the list is the same (branches added later)
            $common = $this->readDefaultSettings($list, $tree)['branch_seeds'] ?? '';
            $split = $this->splitSeedList($new, $common);
            $changed = $this->writeProfileBranches($tree, $id, $list, $split['states'], $this->profileName($id, $profiles));
            $new = $split['common'];
            if ($new === $common) {
                return $changed && $split['states'] !== null;
            }
            $folder = $this->treeSettingsFolder($tree, $list);
        } else {
            if ($new === $current) {
                return false;
            }
            $folder = $this->profileSettingsFolder($tree, $id, $list);
        }
        $data = Registry::filesystem()->data();
        $data->createDirectory($folder);
        $data->write($folder . '/branch_seeds.txt', $this->encodeTextFile($new));
        if (!$data->fileExists($folder . '/README.txt') && $folder === $this->treeSettingsFolder($tree, $list)) {
            $data->write($folder . '/README.txt', $this->treeSettingsReadme($list));
        }

        return true;
    }

    /**
     * Save the default headings of the book (settings hierarchy_sub_branch and hierarchy_generation)
     *
     * @param Tree $tree
     * @param array{h5: string, h6: string} $headings
     * @return bool false if nothing has changed
     * @throws FilesystemException
     * @throws JsonException
     */
    private function saveDefaultHeadings(Tree $tree, array $headings): bool
    {
        $id = $this->activeProfile($tree);
        $settings = $this->readProfileSettings('latex', $tree, $id);
        $new = array('hierarchy_sub_branch' => $headings['h5'], 'hierarchy_generation' => $headings['h6']);
        if (($settings['hierarchy_sub_branch'] ?? '') === $new['hierarchy_sub_branch']
            && ($settings['hierarchy_generation'] ?? '') === $new['hierarchy_generation']) {
            return false;
        }

        // only these two values of settings.json change (in the profile if they are its own values, else common)
        $data = Registry::filesystem()->data();
        $own = $this->profileOwnFields('latex', $tree, $id);
        $folder = (in_array('hierarchy_sub_branch', $own, true) || in_array('hierarchy_generation', $own, true))
            ? $this->profileSettingsFolder($tree, $id, 'latex')
            : $this->treeSettingsFolder($tree, 'latex');
        $file = $folder . '/settings.json';
        $short = array();
        if ($data->fileExists($file)) {
            try {
                $short = $this->decodeSettings('latex', $data->read($file));
            } catch (UnexpectedValueException $ex) {
                throw new JsonException(I18N::translate('The settings file could not be read: %s', $ex->getMessage()));
            }
        }
        $data->createDirectory($folder);
        $data->write($file, $this->encodeSettings('latex', array_merge($short, $new)));
        if (!$data->fileExists($folder . '/README.txt') && $folder === $this->treeSettingsFolder($tree, 'latex')) {
            $data->write($folder . '/README.txt', $this->treeSettingsReadme('latex'));
        }

        return true;
    }

    /**
     * The branches of a list in their order, with a key that identifies them in a set
     *
     * The key is the ID of the start individual; if it starts several branches, "#2", "#3" ... is added.
     *
     * @param array<int,array> $items lines (see parseSeedList) or rows
     * @return array<string,array{name: string, include: bool}> key => branch
     */
    private function branchStates(array $items): array
    {
        $states = array();
        foreach ($items as $index => $item) {
            if ($item['type'] === 'branch') {
                $states[$this->branchRowKey($items, $index)] = array('name' => $item['name'], 'include' => $item['include']);
            }
        }

        return $states;
    }

    /**
     * Key of a branch in a set (see branchStates)
     */
    private function branchRowKey(array $items, int $index): string
    {
        $xref = $items[$index]['xref'];
        $n = 0;
        foreach ($items as $i => $item) {
            if ($i > $index) {
                break;
            }
            if ($item['type'] === 'branch' && $item['xref'] === $xref) {
                $n++;
            }
        }

        return $n > 1 ? $xref . '#' . $n : $xref;
    }

    /**
     * Read a file with the selection of branches (branches.json of a profile, or a set of version 2.2.0-4b).
     * A file that cannot be read is returned as broken.
     */
    private function decodeBranchSet(string $content, string $id): array
    {
        $set = array('name' => $id, 'saved' => '', 'lists' => array('latex' => array(), 'graphml' => array()), 'broken' => true);
        try {
            $json = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $content), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $ex) {
            return $set;
        }
        if (!is_array($json) || ($json['format'] ?? null) !== self::BRANCH_SET_FORMAT || ($json['version'] ?? null) !== self::BRANCH_SET_VERSION) {
            return $set;
        }
        if (is_string($json['name'] ?? null) && trim($json['name']) !== '') {
            $set['name'] = trim($json['name']);
        }
        $set['saved'] = is_string($json['saved'] ?? null) ? $json['saved'] : '';
        foreach (self::BRANCH_LISTS as $list) {
            $entries = $json[$list] ?? array();
            if (!is_array($entries)) {
                return $set;
            }
            $count = array();
            foreach ($entries as $entry) {
                if (!is_array($entry) || !is_string($entry['start'] ?? null) || !is_bool($entry['export'] ?? null)) {
                    return $set;
                }
                $count[$entry['start']] = ($count[$entry['start']] ?? 0) + 1;
                $key = $count[$entry['start']] > 1 ? $entry['start'] . '#' . $count[$entry['start']] : $entry['start'];
                $set['lists'][$list][$key] = array(
                    'name' => is_string($entry['branch'] ?? null) ? $entry['branch'] : '',
                    'include' => $entry['export'],
                );
            }
        }
        $set['broken'] = false;

        return $set;
    }

    /**
     * Short title of a list for messages
     */
    private function branchListTitle(string $list): string
    {
        return $this->pageTitle($list);
    }
}
