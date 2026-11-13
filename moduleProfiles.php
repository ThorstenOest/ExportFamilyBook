<?php

/**
 * ExportFamilyBook module for Webtrees: book profiles
 *
 * A profile ("Book for Anna") keeps what makes an individual book: the reference individual, the title,
 * which branches are exported and the image selection. All other settings are common to all books.
 *
 * Files in the data folder (see README.md):
 *   ExportFamilyBook/<tree>/latex/, graphml/          the profile "Standard" = the common settings of all books
 *   ExportFamilyBook/<tree>/profiles/<id>/profile.json name and reference individual of a profile
 *   ExportFamilyBook/<tree>/profiles/<id>/latex/       own values of the profile (same file names as latex/;
 *   ExportFamilyBook/<tree>/profiles/<id>/graphml/     a missing file means: the common value is used)
 *   ExportFamilyBook/<tree>/profiles/<id>/branches.json which branches are exported (book and chart)
 */

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

trait ExportFamilyBookProfiles
{
    // Folder of the profiles in the folder of a tree (next to latex/ and graphml/)
    private const PROFILES_FOLDER = 'profiles';

    // Files of a profile
    private const PROFILE_FILE = 'profile.json';
    private const PROFILE_BRANCHES_FILE = 'branches.json';

    private const PROFILE_FORMAT = 'ExportFamilyBook-profile';
    private const PROFILE_VERSION = 1;
    private const PROFILE_NAME_LENGTH = 80;

    // Valid IDs (folder names) of profiles. The profile "Standard" has the ID ''.
    private const PROFILE_ID_REGEX = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/';

    // User setting (per tree) with the active profile; visitors: session
    private const PREF_PROFILE = 'EFB_PROFILE_';

    // File in the profiles folder: the old sets of branches (branch_sets/) that have become profiles
    private const PROFILES_MIGRATED_FILE = 'migrated_branch_sets.txt';

    // Fields that a new profile gets as values of its own (copied from the profile it is made from)
    private const PROFILE_OWN_DEFAULT = array(
        'latex' => array('title', 'include_media', 'exclude_media'),
        'graphml' => array(),
    );

    /** @var array<int,true> trees whose old sets have been checked in this request */
    private array $profiles_migrated = array();

    /** @var array{id: string, own: array<string>}|null profile of the form that is rendered */
    private ?array $form_profile = null;

    /**
     * Address of the profile actions: redirect to the book page
     */
    public function getProfilesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        $this->checkAccess($request, $tree);

        return redirect($this->pageUrl($tree, 'latex'));
    }

    /**
     * Forms of the profile bar: switch (all users of the module), copy, rename, delete (managers only)
     */
    public function postProfilesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);
        $this->checkAccess($request, $tree);
        $user = Validator::attributes($request)->user();

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : array();
        $post = static fn (string $name): string => is_string($body[$name] ?? null) ? $body[$name] : '';

        // back to the page of the bar
        $page = in_array($post('return'), array('latex', 'media', 'branches', 'graphml'), true) ? $post('return') : 'latex';
        $xref = preg_match('/^[A-Za-z0-9_-]{1,20}$/', $post('xref')) === 1 ? $post('xref') : '';
        $url = $this->pageUrl($tree, $page, $xref, ($page === 'branches' && $post('list') === 'graphml') ? array('list' => 'graphml') : array());

        try {
            $profiles = $this->readProfiles($tree);
            $id = $post('profile_id');
            if ($id !== '' && !array_key_exists($id, $profiles)) {
                FlashMessages::addMessage(e(I18N::translate('This profile does not exist.')), 'danger');
                return redirect($url);
            }
            $action = $post('mod_action');
            if ($action === 'select_profile') {
                $this->setActiveProfile($tree, $id);
                FlashMessages::addMessage(e(I18N::translate('Active profile: %s', $this->profileName($id, $profiles))), 'success');
                return redirect($url);
            }
            if (!Auth::isManager($tree, $user)) {
                FlashMessages::addMessage(e(I18N::translate('Only managers of the family tree can change the profiles.')), 'danger');
                return redirect($url);
            }
            switch ($action) {
                case 'copy_profile':
                    $refid = $id === '' ? ($this->referenceIndividual($request, $tree)?->xref() ?? '') : null;
                    $new_id = $this->createProfile($tree, $post('new_name'), $id, $profiles, $refid);
                    if ($new_id !== null) {
                        $this->setActiveProfile($tree, $new_id);
                        FlashMessages::addMessage(e(I18N::translate('The profile “%s” has been created and is now active.', trim($post('new_name')))), 'success');
                    }
                    break;
                case 'rename_profile':
                    $this->renameProfile($tree, $id, $post('new_name'), $profiles);
                    break;
                case 'delete_profile':
                    $this->deleteProfile($tree, $id, $profiles);
                    break;
            }
        } catch (FilesystemException | JsonException $ex) {
            FlashMessages::addMessage(e(I18N::translate('The profiles could not be changed: %s', $ex->getMessage())), 'danger');
        }

        return redirect($url);
    }

    /**
     * Folder of a tree with the settings of both export types and the profiles, relative to the data folder
     */
    private function treeBaseFolder(Tree $tree): string
    {
        return self::TREE_SETTINGS_FOLDER . '/' . $this->treeFolderName($tree->name());
    }

    /**
     * Folder of the profiles of a tree
     */
    private function profilesFolder(Tree $tree): string
    {
        return $this->treeBaseFolder($tree) . '/' . self::PROFILES_FOLDER;
    }

    /**
     * Folder of a profile ('' = Standard: the folder of the tree)
     */
    private function profileFolder(Tree $tree, string $id): string
    {
        if ($id === '') {
            return $this->treeBaseFolder($tree);
        }
        if (preg_match(self::PROFILE_ID_REGEX, $id) !== 1) {
            throw new InvalidArgumentException('invalid profile ID');
        }

        return $this->profilesFolder($tree) . '/' . $id;
    }

    /**
     * Folder with the settings of an export type of a profile ('' = Standard, see treeSettingsFolder)
     */
    public function profileSettingsFolder(Tree $tree, string $id, string $export_type): string
    {
        if ($id === '') {
            return $this->treeSettingsFolder($tree, $export_type);
        }

        return $this->profileFolder($tree, $id) . '/' . ($export_type === 'graphml' ? 'graphml' : 'latex');
    }

    /**
     * The profiles of a tree (without Standard), sorted by name
     *
     * Old sets of branches are converted to profiles the first time (see migrateBranchSets).
     *
     * @param Tree $tree
     * @return array<string,array{name: string, refid: string, saved: string, broken: bool}> ID => profile
     * @throws FilesystemException
     */
    public function readProfiles(Tree $tree): array
    {
        $this->migrateBranchSets($tree);

        $data = Registry::filesystem()->data();
        $folder = $this->profilesFolder($tree);
        if (!$data->directoryExists($folder)) {
            return array();
        }
        $profiles = array();
        foreach ($data->listContents($folder, false) as $entry) {
            $id = basename($entry->path());
            if (!$entry->isDir() || preg_match(self::PROFILE_ID_REGEX, $id) !== 1) {
                continue;
            }
            $profiles[$id] = $this->decodeProfile($data->fileExists($folder . '/' . $id . '/' . self::PROFILE_FILE)
                ? $data->read($folder . '/' . $id . '/' . self::PROFILE_FILE) : '', $id);
        }
        uasort($profiles, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $profiles;
    }

    /**
     * Read profile.json. A file that cannot be read gives a profile with the ID as its name.
     *
     * @return array{name: string, refid: string, saved: string, broken: bool}
     */
    private function decodeProfile(string $content, string $id): array
    {
        $profile = array('name' => $id, 'refid' => '', 'saved' => '', 'broken' => true);
        try {
            $json = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $content), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $ex) {
            return $profile;
        }
        if (!is_array($json) || ($json['format'] ?? null) !== self::PROFILE_FORMAT || ($json['version'] ?? null) !== self::PROFILE_VERSION) {
            return $profile;
        }
        if (is_string($json['name'] ?? null) && trim($json['name']) !== '') {
            $profile['name'] = trim($json['name']);
        }
        if (is_string($json['refid'] ?? null) && $this->isSeedXref($json['refid'])) {
            $profile['refid'] = $json['refid'];
        }
        $profile['saved'] = is_string($json['saved'] ?? null) ? $json['saved'] : '';
        $profile['broken'] = false;

        return $profile;
    }

    /**
     * Write profile.json (and README.txt of the profiles folder)
     *
     * @param array{name: string, refid: string} $profile
     * @throws FilesystemException
     * @throws JsonException
     */
    private function writeProfileFile(Tree $tree, string $id, array $profile): void
    {
        $data = Registry::filesystem()->data();
        $folder = $this->profileFolder($tree, $id);
        $data->createDirectory($folder);
        $data->write($folder . '/' . self::PROFILE_FILE, json_encode(array(
            'format' => self::PROFILE_FORMAT,
            'version' => self::PROFILE_VERSION,
            'name' => $profile['name'],
            'refid' => $profile['refid'],
            'saved' => date('Y-m-d H:i:s'),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        if (!$data->fileExists($this->profilesFolder($tree) . '/README.txt')) {
            $data->write($this->profilesFolder($tree) . '/README.txt', $this->profilesReadme());
        }
    }

    /**
     * Display name of a profile
     *
     * @param string $id '' = Standard
     * @param array $profiles see readProfiles()
     */
    public function profileName(string $id, array $profiles): string
    {
        return $id === '' ? I18N::translate('Standard') : ($profiles[$id]['name'] ?? $id);
    }

    /**
     * The active profile of the current user ('' = Standard). A profile that no longer exists means Standard.
     *
     * The choice is a setting of the user (per tree), for visitors it is kept in the session.
     */
    public function activeProfile(Tree $tree, ?array $profiles = null): string
    {
        $key = self::PREF_PROFILE . $tree->id();
        $id = Auth::check() ? Auth::user()->getPreference($key) : (string) Session::get($key, '');
        if ($id === '') {
            return '';
        }
        try {
            $profiles ??= $this->readProfiles($tree);
        } catch (FilesystemException $ex) {
            return '';
        }

        return array_key_exists($id, $profiles) ? $id : '';
    }

    /**
     * Remember the active profile of the current user
     */
    private function setActiveProfile(Tree $tree, string $id): void
    {
        $key = self::PREF_PROFILE . $tree->id();
        if (Auth::check()) {
            Auth::user()->setPreference($key, $id);
        } else {
            Session::put($key, $id);
        }
    }

    /**
     * The profile ID sent by a form must be the active profile (the form was made for it)
     *
     * @return bool false: a message has been added
     */
    private function checkFormProfile(ServerRequestInterface $request, Tree $tree): bool
    {
        $body = $request->getParsedBody();
        $posted = is_array($body) && is_string($body['profile'] ?? null) ? $body['profile'] : '';
        if ($posted !== $this->activeProfile($tree)) {
            FlashMessages::addMessage(e(I18N::translate('The active profile has been switched in the meantime. Nothing has been saved. Please load the page again.')), 'danger');
            return false;
        }

        return true;
    }

    /**
     * The settings of a profile: the module folder, then the common settings (Standard), then the own values
     * of the profile (field by field). The branches of the profile are switched on and off as stored in it.
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param Tree $tree
     * @param string $id '' = Standard
     * @return array<string,string>
     */
    public function readProfileSettings(string $export_type, Tree $tree, string $id): array
    {
        $settings = $this->readDefaultSettings($export_type, $tree);
        if ($id === '') {
            return $settings;
        }
        $own = $this->readTreeSettings($export_type, $this->profileSettingsFolder($tree, $id, $export_type));
        unset($own['refid']);
        $settings = array_merge($settings, $own);
        if (!array_key_exists('branch_seeds', $own)) {
            $entries = $this->readProfileBranches($tree, $id)[$export_type] ?? null;
            $settings['branch_seeds'] = $this->composeSeedList($settings['branch_seeds'] ?? '', $entries);
        }

        return $settings;
    }

    /**
     * The fields that have a value of their own in a profile (by the files of its folder)
     *
     * @return array<string>
     */
    public function profileOwnFields(string $export_type, Tree $tree, string $id): array
    {
        if ($id === '') {
            return array();
        }

        return array_keys($this->readTreeSettings($export_type, $this->profileSettingsFolder($tree, $id, $export_type)));
    }

    /**
     * Long fields that can be values of a profile of its own (tick "only this profile" in the form)
     *
     * @return array<string>
     */
    public function profileLongFields(string $export_type): array
    {
        $fields = array_merge(array_keys(self::SETTINGS_TEXT_FILES[$export_type]), self::SETTINGS_LIST_FILES[$export_type]);

        return array_values(array_diff($fields, array('branch_seeds')));
    }

    /**
     * Which branches a profile exports
     *
     * @return array<string,array<string,array{name: string, include: bool}>>|null list => key => state (see branchStates);
     *         null: no file (the profile uses the common selection)
     */
    private function readProfileBranches(Tree $tree, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $data = Registry::filesystem()->data();
        $file = $this->profileFolder($tree, $id) . '/' . self::PROFILE_BRANCHES_FILE;
        if (!$data->fileExists($file)) {
            return null;
        }
        $set = $this->decodeBranchSet($data->read($file), $id);

        return $set['broken'] ? null : $set['lists'];
    }

    /**
     * Save which branches of a list a profile exports (the other list stays as it is)
     *
     * @param array<string,array{name: string, include: bool}>|null $states null: remove the list from the file
     * @return bool false: nothing has changed
     * @throws FilesystemException
     * @throws JsonException
     */
    private function writeProfileBranches(Tree $tree, string $id, string $list, ?array $states, string $name): bool
    {
        $lists = $this->readProfileBranches($tree, $id) ?? array();
        if ($states === null) {
            unset($lists[$list]);
        } else {
            $lists[$list] = $states;
        }
        $json = array(
            'format' => self::BRANCH_SET_FORMAT,
            'version' => self::BRANCH_SET_VERSION,
            'name' => $name,
            'saved' => date('Y-m-d H:i:s'),
        );
        foreach (self::BRANCH_LISTS as $type) {
            if (!array_key_exists($type, $lists)) {
                continue;
            }
            $entries = array();
            foreach ($lists[$type] as $key => $state) {
                $entries[] = array('start' => explode('#', (string) $key)[0], 'branch' => $state['name'], 'export' => $state['include']);
            }
            $json[$type] = $entries;
        }
        $data = Registry::filesystem()->data();
        $file = $this->profileFolder($tree, $id) . '/' . self::PROFILE_BRANCHES_FILE;
        $content = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        // unchanged: keep the file (and its date)
        if ($data->fileExists($file) && $this->decodeBranchSet($data->read($file), $id)['lists'] == $this->decodeBranchSet($content, $id)['lists']) {
            return false;
        }
        $data->createDirectory($this->profileFolder($tree, $id));
        $data->write($file, $content);

        return true;
    }

    /**
     * The list of branches of a profile: the common list with the branches switched on and off as in the profile
     *
     * Branches that are not in the profile (added later) keep the state of the common list.
     *
     * @param string $common the common list
     * @param array<string,array{name: string, include: bool}>|null $entries see readProfileBranches()
     * @return string
     */
    public function composeSeedList(string $common, ?array $entries): string
    {
        if ($entries === null) {
            return $common;
        }
        $parsed = $this->parseSeedList($common);
        if (!$parsed['valid']) {
            return $common;
        }
        $lines = array();
        foreach ($parsed['items'] as $index => $item) {
            if ($item['type'] !== 'branch') {
                $lines[] = $item['line'];
                continue;
            }
            $key = $this->branchRowKey($parsed['items'], $index);
            $include = array_key_exists($key, $entries) ? $entries[$key]['include'] : $item['include'];
            $lines[] = ($include === $item['include'])
                ? $item['line']
                : $this->seedLine(array_merge($item, array('include' => $include)), $parsed['sep1'], $parsed['sep2']);
        }

        return $this->serializeSeedList($parsed, $lines);
    }

    /**
     * Split the list of a profile into the common list and the selection of the profile (inverse of composeSeedList)
     *
     * The common list gets the structure (branches, names, stop individuals, order, other lines) and keeps the
     * state include/exclude of its branches; new branches are switched on there. Unchanged branches keep their line.
     *
     * @param string $new the list of the profile
     * @param string $common the current common list
     * @return array{common: string, states: array<string,array{name: string, include: bool}>|null} states null: no valid list
     */
    public function splitSeedList(string $new, string $common): array
    {
        $parsed = $this->parseSeedList($new);
        if (!$parsed['valid']) {
            return array('common' => $new, 'states' => null);
        }
        $old = $this->parseSeedList($common);
        $old_items = array();
        if ($old['valid']) {
            foreach ($old['items'] as $index => $item) {
                if ($item['type'] === 'branch') {
                    $old_items[$this->branchRowKey($old['items'], $index)] = $item;
                }
            }
        }
        $lines = array();
        foreach ($parsed['items'] as $index => $item) {
            if ($item['type'] !== 'branch') {
                $lines[] = $item['line'];
                continue;
            }
            $key = $this->branchRowKey($parsed['items'], $index);
            $old_item = $old_items[$key] ?? null;
            $include = $old_item['include'] ?? true;
            $common_item = array_merge($item, array('include' => $include));
            if ($old_item !== null && $this->sameBranch($common_item, $old_item)) {
                $lines[] = $old_item['line'];
            } elseif ($item['include'] === $include) {
                $lines[] = $item['line'];
            } else {
                $lines[] = $this->seedLine($common_item, $parsed['sep1'], $parsed['sep2']);
            }
        }

        return array(
            'common' => ($old['valid'] && $parsed['sep1'] . $parsed['sep2'] === $old['sep1'] . $old['sep2'] && $lines === $this->seedListLines($old))
                ? $common
                : $this->serializeSeedList($parsed, $lines),
            'states' => $this->branchStates($parsed['items']),
        );
    }

    /**
     * The lines of a parsed list between the leading and trailing empty lines
     *
     * @return array<string>
     */
    private function seedListLines(array $parsed): array
    {
        return array_map(static fn (array $item): string => $item['line'], $parsed['items']);
    }

    /**
     * Save the settings of a form in a profile
     *
     * Standard: as before, all fields in the folders latex/ resp. graphml/ of the tree.
     * Other profiles: the fields with a value of their own go to the folder of the profile, all other
     * fields to the common settings. Which branches are exported goes to the profile, the list of branches
     * itself (structure) to the common settings. The reference individual goes to profile.json.
     *
     * @param string $export_type 'latex' or 'graphml'
     * @param Tree $tree
     * @param string $id profile ('' = Standard)
     * @param array<string,string> $settings fields of the form (see settingsFromForm)
     * @param array<string>|null $own_long long fields ticked as "only this profile" (null: no change)
     * @param array $profiles see readProfiles()
     * @throws FilesystemException
     * @throws JsonException
     */
    public function saveProfileSettings(string $export_type, Tree $tree, string $id, array $settings, ?array $own_long, array $profiles): void
    {
        if ($id === '') {
            $this->writeTreeSettings($export_type, $this->treeSettingsFolder($tree, $export_type), $settings);
            return;
        }

        $folder = $this->profileSettingsFolder($tree, $id, $export_type);
        $long = $this->profileLongFields($export_type);
        $own = $this->profileOwnFields($export_type, $tree, $id);
        if ($own_long !== null) {
            // a long field without the tick uses the common value again: the value of the form (the old
            // value of the profile) must not overwrite the common value
            foreach (array_diff(array_intersect($own, $long), $own_long) as $released) {
                unset($settings[$released]);
            }
            $own = array_merge(array_diff($own, $long), array_intersect($own_long, $long));
        }
        $own = array_flip(array_diff($own, array('refid')));

        $refid = $settings['refid'] ?? null;
        unset($settings['refid']);

        // branches: the structure is common, the selection belongs to the profile
        $name = $this->profileName($id, $profiles);
        if (array_key_exists('branch_seeds', $settings) && !array_key_exists('branch_seeds', $own)) {
            $common_seeds = $this->readDefaultSettings($export_type, $tree)['branch_seeds'] ?? '';
            $split = $this->splitSeedList($settings['branch_seeds'], $common_seeds);
            $settings['branch_seeds'] = $split['common'];
            $this->writeProfileBranches($tree, $id, $export_type, $split['states'], $name);
        } elseif (array_key_exists('branch_seeds', $own)) {
            // the profile has a list of its own (made by hand): it contains the selection itself
            $this->writeProfileBranches($tree, $id, $export_type, null, $name);
        }

        $this->writeProfileOwnSettings($export_type, $folder, array_intersect_key($settings, $own), array_diff($long, array_keys($own)));
        $common = array_diff_key($settings, $own);
        if ($common !== array()) {
            $this->writeTreeSettings($export_type, $this->treeSettingsFolder($tree, $export_type), $common, true);
        }

        $profile = $profiles[$id];
        if (is_string($refid) && $refid !== '' && $this->isSeedXref($refid)) {
            $profile['refid'] = $refid;
        }
        $this->writeProfileFile($tree, $id, $profile);
    }

    /**
     * Write the values of a profile of its own; remove the files of long fields that are no longer its own
     *
     * @param string $export_type
     * @param string $folder folder of the profile and export type
     * @param array<string,string> $settings own values
     * @param array<string> $remove long fields that use the common value
     * @throws FilesystemException
     * @throws JsonException
     */
    private function writeProfileOwnSettings(string $export_type, string $folder, array $settings, array $remove): void
    {
        $data = Registry::filesystem()->data();
        $text_files = self::SETTINGS_TEXT_FILES[$export_type];
        $list_files = array_flip(self::SETTINGS_LIST_FILES[$export_type]);
        $short = array_diff_key($settings, $text_files, $list_files);
        if ($short !== array()) {
            // only short fields that are already in settings.json of the profile (made by hand)
            $this->writeTreeSettings($export_type, $folder, $short, true);
        }
        foreach ($settings as $name => $value) {
            if (array_key_exists($name, $text_files) || array_key_exists($name, $list_files)) {
                $this->writeTreeSettings($export_type, $folder, array($name => $value), true);
            }
        }
        foreach ($remove as $name) {
            $file = $folder . '/' . $name . '.' . ($text_files[$name] ?? 'txt');
            if ($data->fileExists($file)) {
                $data->delete($file);
            }
        }
    }

    /**
     * Create a profile as a copy of another one
     *
     * A copy of Standard gets the values of PROFILE_OWN_DEFAULT and the current selection of branches as values
     * of its own. A copy of another profile gets all its files.
     *
     * @param Tree $tree
     * @param string $name
     * @param string $source ID of the profile to copy ('' = Standard)
     * @param array $profiles see readProfiles()
     * @param string|null $refid reference individual of the new profile (null: the one of the source)
     * @return string|null ID of the new profile, null: invalid name (a message has been added)
     * @throws FilesystemException
     * @throws JsonException
     */
    private function createProfile(Tree $tree, string $name, string $source, array $profiles, ?string $refid): ?string
    {
        $name = trim($name);
        $error = $this->profileNameError($name, $profiles, null);
        if ($error !== '') {
            FlashMessages::addMessage(e($error), 'danger');
            return null;
        }
        $id = $this->profileIdForName($name, $profiles);
        $data = Registry::filesystem()->data();
        $folder = $this->profileFolder($tree, $id);

        if ($source !== '') {
            $this->copyFolder($this->profileFolder($tree, $source), $folder);
            if ($data->fileExists($folder . '/' . self::PROFILE_BRANCHES_FILE)) {
                $lists = $this->readProfileBranches($tree, $id) ?? array();
                foreach ($lists as $list => $states) {
                    $this->writeProfileBranches($tree, $id, $list, $states, $name);
                }
            }
        } else {
            foreach (self::PROFILE_OWN_DEFAULT as $export_type => $fields) {
                $this->copyCommonFields($tree, $export_type, $fields, $this->profileSettingsFolder($tree, $id, $export_type));
            }
            foreach (self::BRANCH_LISTS as $list) {
                $parsed = $this->parseSeedList($this->readDefaultSettings($list, $tree)['branch_seeds'] ?? '');
                if ($parsed['valid']) {
                    $this->writeProfileBranches($tree, $id, $list, $this->branchStates($parsed['items']), $name);
                }
            }
        }
        $this->writeProfileFile($tree, $id, array(
            'name' => $name,
            'refid' => $refid ?? ($source === '' ? '' : $profiles[$source]['refid']),
        ));

        return $id;
    }

    /**
     * Copy common fields into the folder of a profile: the file as it is, or the value from the module folder
     *
     * @throws FilesystemException
     * @throws JsonException
     */
    private function copyCommonFields(Tree $tree, string $export_type, array $fields, string $folder): void
    {
        $data = Registry::filesystem()->data();
        $common = $this->treeSettingsFolder($tree, $export_type);
        $values = $this->readDefaultSettings($export_type, $tree);
        foreach ($fields as $name) {
            $file_name = $name . '.' . (self::SETTINGS_TEXT_FILES[$export_type][$name] ?? 'txt');
            if ($data->fileExists($common . '/' . $file_name)) {
                $data->createDirectory($folder);
                $data->write($folder . '/' . $file_name, $data->read($common . '/' . $file_name));
            } else {
                $this->writeTreeSettings($export_type, $folder, array($name => $values[$name] ?? ''), true);
            }
        }
    }

    /**
     * Copy all files of a folder (recursively)
     *
     * @throws FilesystemException
     */
    private function copyFolder(string $from, string $to): void
    {
        $data = Registry::filesystem()->data();
        $data->createDirectory($to);
        foreach ($data->listContents($from, true) as $entry) {
            $relative = substr($entry->path(), strlen($from) + 1);
            if ($entry->isDir()) {
                $data->createDirectory($to . '/' . $relative);
            } else {
                $data->write($to . '/' . $relative, $data->read($entry->path()));
            }
        }
    }

    /**
     * Rename a profile (its folder gets the new name, too)
     *
     * @throws FilesystemException
     * @throws JsonException
     */
    private function renameProfile(Tree $tree, string $id, string $name, array $profiles): void
    {
        $name = trim($name);
        if ($id === '') {
            FlashMessages::addMessage(e(I18N::translate('The profile “%s” cannot be renamed.', $this->profileName('', $profiles))), 'danger');
            return;
        }
        $error = $this->profileNameError($name, $profiles, $id);
        if ($error !== '') {
            FlashMessages::addMessage(e($error), 'danger');
            return;
        }
        $old_name = $profiles[$id]['name'];
        $profile = $profiles[$id];
        $profile['name'] = $name;
        $new_id = $this->profileIdForName($name, array_diff_key($profiles, array($id => true)));
        if (strtolower($new_id) !== strtolower($id)) {
            $this->copyFolder($this->profileFolder($tree, $id), $this->profileFolder($tree, $new_id));
            Registry::filesystem()->data()->deleteDirectory($this->profileFolder($tree, $id));
            if ($this->activeProfile($tree, $profiles) === $id) {
                $this->setActiveProfile($tree, $new_id);
            }
        } else {
            $new_id = $id;
        }
        $this->writeProfileFile($tree, $new_id, $profile);
        $lists = $this->readProfileBranches($tree, $new_id);
        foreach ($lists ?? array() as $list => $states) {
            $this->writeProfileBranches($tree, $new_id, $list, $states, $name);
        }
        FlashMessages::addMessage(e(I18N::translate('The profile “%1$s” has been renamed to “%2$s”.', $old_name, $name)), 'success');
    }

    /**
     * Delete a profile (its folder). Standard cannot be deleted.
     *
     * @throws FilesystemException
     */
    private function deleteProfile(Tree $tree, string $id, array $profiles): void
    {
        if ($id === '') {
            FlashMessages::addMessage(e(I18N::translate('The profile “%s” cannot be deleted.', $this->profileName('', $profiles))), 'danger');
            return;
        }
        if ($this->activeProfile($tree, $profiles) === $id) {
            $this->setActiveProfile($tree, '');
        }
        Registry::filesystem()->data()->deleteDirectory($this->profileFolder($tree, $id));
        FlashMessages::addMessage(e(I18N::translate('The profile “%s” has been deleted.', $profiles[$id]['name'])), 'success');
    }

    /**
     * Folder name (ID) for the name of a new profile
     *
     * Umlauts are written as ae, oe, ue, ss, other letters without accents; all other characters become '_'.
     * So the name can never leave the profiles folder. An ID that exists already gets "-2", "-3", ...
     *
     * @param string $name
     * @param array $profiles existing profiles
     * @return string
     */
    public function profileIdForName(string $name, array $profiles): string
    {
        $base = strtr($name, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'));
        if (class_exists('Transliterator')) {
            $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                $base = (string) $transliterator->transliterate($base);
            }
        }
        $base = (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $base);
        $base = trim(substr(trim($base, '_-'), 0, 60), '_-');
        if ($base === '') {
            $base = 'profile';
        }
        $taken = array_map('strtolower', array_map('strval', array_keys($profiles)));
        $id = $base;
        for ($n = 2; in_array(strtolower($id), $taken, true); $n++) {
            $id = $base . '-' . $n;
        }

        return $id;
    }

    /**
     * Error message for the name of a profile ('' if the name is valid)
     *
     * @param string $name trimmed name
     * @param array $profiles existing profiles
     * @param string|null $except ID of the profile that is renamed
     */
    private function profileNameError(string $name, array $profiles, ?string $except): string
    {
        if ($name === '') {
            return I18N::translate('Please enter a name for the profile.');
        }
        if (mb_strlen($name) > self::PROFILE_NAME_LENGTH || preg_match('/\p{C}/u', $name) !== 0) {
            return I18N::translate('The name of a profile may have up to %s characters and no control characters.', I18N::number(self::PROFILE_NAME_LENGTH));
        }
        if (in_array(mb_strtolower($name), array(mb_strtolower(I18N::translate('Standard')), 'standard'), true)) {
            return I18N::translate('A profile with the name “%s” already exists.', $name);
        }
        foreach ($profiles as $id => $profile) {
            if ($id !== $except && mb_strtolower($profile['name']) === mb_strtolower($name)) {
                return I18N::translate('A profile with the name “%s” already exists.', $profile['name']);
            }
        }

        return '';
    }

    /**
     * Convert the sets of branches of the old page "Branches and sets" (folder branch_sets/) to profiles
     *
     * Runs once per set: the names of the converted set files are listed in profiles/migrated_branch_sets.txt.
     * The set files stay as they are. A profile made from a set exports the branches of the set and gets the
     * current title and image selection of Standard as values of its own (as a new profile).
     */
    private function migrateBranchSets(Tree $tree): void
    {
        if (array_key_exists($tree->id(), $this->profiles_migrated)) {
            return;
        }
        $this->profiles_migrated[$tree->id()] = true;

        $data = Registry::filesystem()->data();
        $sets_folder = $this->treeBaseFolder($tree) . '/branch_sets';
        $done_file = $this->profilesFolder($tree) . '/' . self::PROFILES_MIGRATED_FILE;
        try {
            if (!$data->directoryExists($sets_folder)) {
                return;
            }
            $done = $data->fileExists($done_file) ? array_filter(array_map('trim', explode("\n", $data->read($done_file)))) : array();
            $files = array();
            foreach ($data->listContents($sets_folder, false) as $entry) {
                $file = basename($entry->path());
                if ($entry->isFile() && preg_match('/^[A-Za-z0-9_-]{1,200}\.json$/', $file) === 1 && !in_array($file, $done, true)) {
                    $files[] = $file;
                }
            }
            if ($files === array()) {
                return;
            }
            sort($files);
            $profiles = $this->readProfilesWithoutMigration($tree);
            foreach ($files as $file) {
                $set = $this->decodeBranchSet($data->read($sets_folder . '/' . $file), substr($file, 0, -5));
                if ($set['broken']) {
                    continue;
                }
                $name = $set['name'];
                for ($n = 2; $this->profileNameError($name, $profiles, null) !== '' && $n < 100; $n++) {
                    $name = $set['name'] . ' (' . $n . ')';
                }
                $id = $this->profileIdForName($name, $profiles);
                foreach (self::PROFILE_OWN_DEFAULT as $export_type => $fields) {
                    $this->copyCommonFields($tree, $export_type, $fields, $this->profileSettingsFolder($tree, $id, $export_type));
                }
                foreach (self::BRANCH_LISTS as $list) {
                    $this->writeProfileBranches($tree, $id, $list, $set['lists'][$list], $name);
                }
                $this->writeProfileFile($tree, $id, array('name' => $name, 'refid' => ''));
                $profiles[$id] = array('name' => $name, 'refid' => '', 'saved' => '', 'broken' => false);
                $done[] = $file;
                $data->write($done_file, implode("\n", $done) . "\n");
            }
        } catch (FilesystemException | JsonException $ex) {
            if (Auth::isManager($tree)) {
                FlashMessages::addMessage(e(I18N::translate('The old sets of branches could not be converted to profiles: %s', $ex->getMessage())), 'danger');
            }
        }
    }

    /**
     * The profiles without converting old sets (used by the conversion itself)
     *
     * @throws FilesystemException
     */
    private function readProfilesWithoutMigration(Tree $tree): array
    {
        $done = $this->profiles_migrated;
        $this->profiles_migrated[$tree->id()] = true;
        $profiles = $this->readProfiles($tree);
        $this->profiles_migrated = $done + $this->profiles_migrated;

        return $profiles;
    }

    /**
     * Data of the profile bar shown at the top of the pages (view profile_bar.phtml)
     *
     * @param ServerRequestInterface $request
     * @param Tree $tree
     * @param string $page 'latex', 'media', 'branches' or 'graphml'
     * @param string $list list of the page "Branches"
     * @return array<string,mixed>
     */
    public function profileBarData(ServerRequestInterface $request, Tree $tree, string $page, string $list = 'latex'): array
    {
        try {
            $profiles = $this->readProfiles($tree);
        } catch (FilesystemException $ex) {
            FlashMessages::addMessage(e(I18N::translate('The profiles could not be read: %s', $ex->getMessage())), 'danger');
            $profiles = array();
        }
        $active = $this->activeProfile($tree, $profiles);
        $options = array('' => $this->profileName('', $profiles));
        foreach ($profiles as $id => $profile) {
            $options[$id] = $profile['broken'] ? I18N::translate('%s (cannot be read)', $profile['name']) : $profile['name'];
        }
        $refid = $active === '' ? '' : $profiles[$active]['refid'];
        $reference = null;
        if ($refid !== '') {
            $individual = Registry::individualFactory()->make($refid, $tree);
            $reference = ($individual instanceof Individual && $individual->canShow())
                ? html_entity_decode(strip_tags($individual->fullName()), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : I18N::translate('Private');
        }

        return array(
            'profiles_url' => route('module', array('module' => $this->name(), 'action' => 'Profiles', 'tree' => $tree->name())),
            'options' => $options,
            'active' => $active,
            'active_name' => $this->profileName($active, $profiles),
            'reference' => $reference,
            'own_fields' => $active === '' ? array() : array_merge(
                array_map(fn (string $f): string => $this->profileFieldLabel('latex', $f), $this->profileOwnFields('latex', $tree, $active)),
                array_map(fn (string $f): string => $this->profileFieldLabel('graphml', $f), $this->profileOwnFields('graphml', $tree, $active))
            ),
            'can_manage' => Auth::isManager($tree, Validator::attributes($request)->user()),
            'page' => $page,
            'list' => $list,
            'xref' => Validator::queryParams($request)->isXref()->string('xref', ''),
        );
    }

    /**
     * Readable name of a field for the profile bar
     */
    private function profileFieldLabel(string $export_type, string $field): string
    {
        $prefix = $export_type === 'graphml' ? I18N::translate('Chart') . ': ' : '';

        return $prefix . match ($field) {
            'title' => I18N::translate('Title'),
            'include_media', 'exclude_media' => I18N::translate('Image selection'),
            'preamble' => I18N::translate('Preamble'),
            'epilog' => I18N::translate('Epilog'),
            'symbols' => I18N::translate('Symbols'),
            'branch_seeds' => I18N::translate('Seeds'),
            default => $field,
        };
    }

    /**
     * Tick "only this profile" for a long field of the form (empty for Standard)
     *
     * @param string $name field
     * @return string HTML
     */
    public function profileFieldToggle(string $name): string
    {
        if ($this->form_profile === null || $this->form_profile['id'] === '') {
            return '';
        }
        $checked = in_array($name, $this->form_profile['own'], true) ? ' checked' : '';
        $id = 'efb-own-' . $name;

        return '<div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="own_fields[]" value="' . e($name) . '" id="' . e($id) . '"' . $checked . '>' .
            '<label class="form-check-label" for="' . e($id) . '">' . e(I18N::translate('only this profile')) . '</label></div>';
    }

    /**
     * Hidden fields and buttons of the export form to save the profile (managers only)
     *
     * @param Tree $tree
     * @param string $export_type 'latex' or 'graphml'
     * @param int $colspan
     * @return string HTML
     */
    private function saveProfileRow(Tree $tree, string $export_type, int $colspan): string
    {
        $id = $this->form_profile['id'] ?? '';
        $html = '<tr hidden><td colspan="' . $colspan . '"><input type="hidden" name="profile" value="' . e($id) . '">';
        if ($id !== '') {
            $html .= '<input type="hidden" name="own_fields_shown" value="1">';
        }
        $html .= '</td></tr>';
        if (!Auth::isManager($tree)) {
            return $html;
        }
        try {
            $name = $this->profileName($id, $this->readProfiles($tree));
        } catch (FilesystemException $ex) {
            $name = $id;
        }

        return $html . '<tr><td class="topbottombar" colspan="' . $colspan . '">' .
            '<button type="submit" name="mod_action" value="save_tree_settings_' . $export_type . '">' .
            e(I18N::translate('Save profile “%s”', $name)) . '</button> ' .
            '<label class="ms-3" for="efb-new-profile">' . e(I18N::translate('Save as new profile:')) . '</label> ' .
            '<input type="text" name="new_profile_name" id="efb-new-profile" maxlength="' . self::PROFILE_NAME_LENGTH . '" placeholder="' .
            e(I18N::translate('e.g. Book for …')) . '"> ' .
            '<button type="submit" name="mod_action" value="save_new_profile_' . $export_type . '">' .
            e(I18N::translate('Save as new profile')) . '</button>' .
            '<div class="small text-muted">' . e($id === ''
                ? I18N::translate('The profile Standard contains the common settings of all profiles.')
                : I18N::translate('Fields with the tick “only this profile”, the reference individual and the selection of branches are saved in this profile. All other settings are common to all profiles.')) . '</div>' .
            '</td></tr>';
    }

    /**
     * Save the export form in the active profile or in a new profile (button of saveProfileRow)
     *
     * @param ServerRequestInterface $request
     * @param Tree $tree
     * @param string $export_type
     * @param array $body the submitted form
     * @param bool $new true: create a new profile from the form
     * @return void messages are added
     */
    private function saveProfileForm(ServerRequestInterface $request, Tree $tree, string $export_type, array $body, bool $new): void
    {
        if (!Auth::isManager($tree, Validator::attributes($request)->user())) {
            FlashMessages::addMessage(e(I18N::translate('Only managers of the family tree can change the profiles.')), 'danger');
            return;
        }
        if (!$this->checkFormProfile($request, $tree)) {
            return;
        }
        $settings = $this->settingsFromForm($export_type, $body);
        $own = null;
        if (($body['own_fields_shown'] ?? '') === '1') {
            $own = array_values(array_filter(is_array($body['own_fields'] ?? null) ? $body['own_fields'] : array(), 'is_string'));
        }
        try {
            $profiles = $this->readProfiles($tree);
            $id = $this->activeProfile($tree, $profiles);
            if ($new) {
                $name = is_string($body['new_profile_name'] ?? null) ? trim($body['new_profile_name']) : '';
                $new_id = $this->createProfile($tree, $name, $id, $profiles, $settings['refid'] ?? '');
                if ($new_id === null) {
                    return;
                }
                if ($id === '') {
                    // a copy of Standard: the fields ticked in the form are its own values, too
                    $own = array_merge(self::PROFILE_OWN_DEFAULT[$export_type], $own ?? array());
                }
                $profiles = $this->readProfiles($tree);
                $this->saveProfileSettings($export_type, $tree, $new_id, $settings, $own, $profiles);
                $this->setActiveProfile($tree, $new_id);
                FlashMessages::addMessage(e(I18N::translate('The profile “%s” has been created and is now active.', $name)), 'success');
            } else {
                $this->saveProfileSettings($export_type, $tree, $id, $settings, $own, $profiles);
                FlashMessages::addMessage(e(I18N::translate('The profile “%s” has been saved.', $this->profileName($id, $profiles))), 'success');
            }
        } catch (FilesystemException | JsonException $ex) {
            FlashMessages::addMessage(e(I18N::translate('The settings could not be saved: %s', $ex->getMessage())), 'danger');
        }
    }

    /**
     * Text of README.txt in the profiles folder
     */
    private function profilesReadme(): string
    {
        return implode("\n", array(
            'ExportFamilyBook: profiles of this family tree',
            '',
            'A profile (e.g. "Book for Anna") keeps what makes an individual book. The pages of the module',
            'write these folders; you can edit the files with a text editor (UTF-8).',
            '',
            '<profile>/profile.json     name and reference individual ("refid") of the profile:',
            '    { "format": "' . self::PROFILE_FORMAT . '", "version": ' . self::PROFILE_VERSION . ', "name": "Book for Anna", "refid": "I1", "saved": "..." }',
            '<profile>/latex/           values of the profile of its own, with the same file names as ../latex',
            '<profile>/graphml/         (title.tex, exclude_media.txt, ...). A missing file means: the common',
            '                           value of ../latex resp. ../graphml (profile "Standard") is used.',
            '<profile>/branches.json    which branches are exported (book "latex" and chart "graphml"):',
            '    { "start": "I1", "branch": "Name of the branch", "export": true }',
            '    The list of branches itself is common (../latex/branch_seeds.txt). Branches that are not in',
            '    this file use the state of the common list.',
            '',
            'The folder name is derived from the name of the profile; the name shown is the one in profile.json.',
            self::PROFILES_MIGRATED_FILE . ' lists the sets of ../branch_sets that have been converted to profiles.',
        )) . "\n";
    }
}
