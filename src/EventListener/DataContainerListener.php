<?php

declare(strict_types=1);

/*
 * Node Bundle for Contao Open Source CMS.
 *
 * @copyright  Copyright (c) 2018, terminal42 gmbh
 * @author     terminal42 <https://terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\NodeBundle\EventListener;

use Contao\Backend;
use Contao\BackendUser;
use Contao\Controller;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\DataContainer;
use Contao\Environment;
use Contao\Image;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Contao\Validator;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Terminal42\NodeBundle\Model\NodeModel;
use Terminal42\NodeBundle\PermissionChecker;
use Terminal42\NodeBundle\Widget\NodePickerWidget;

class DataContainerListener
{
    const BREADCRUMB_SESSION_KEY = 'tl_node_node';

    /**
     * @var Connection
     */
    private $db;

    /**
     * @var ContaoFrameworkInterface
     */
    private $framework;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PermissionChecker
     */
    private $permissionChecker;

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * DataContainerListener constructor.
     *
     * @param Connection $db
     * @param ContaoFrameworkInterface $framework
     * @param LoggerInterface $logger
     * @param PermissionChecker $permissionChecker
     * @param SessionInterface $session
     */
    public function __construct(
        Connection $db,
        ContaoFrameworkInterface $framework,
        LoggerInterface $logger,
        PermissionChecker $permissionChecker,
        SessionInterface $session
    ) {
        $this->db = $db;
        $this->framework = $framework;
        $this->logger = $logger;
        $this->permissionChecker = $permissionChecker;
        $this->session = $session;
    }

    /**
     * On load callback.
     *
     * @param DataContainer $dc
     */
    public function onLoadCallback(DataContainer $dc): void
    {
        $this->addBreadcrumb($dc);
        $this->checkPermissions($dc);
        $this->toggleSwitchToEditFlag($dc);
    }

    /**
     * On paste button callback
     *
     * @param DataContainer $dc
     * @param array         $row
     * @param string        $table
     * @param bool          $cr
     * @param array|null    $clipboard
     *
     * @return string
     */
    public function onPasteButtonCallback(DataContainer $dc, array $row, string $table, bool $cr, $clipboard = null): string
    {
        /**
         * @var Backend $backendAdapter
         * @var Image $imageAdapter
         * @var System $systemAdapter
         */
        $backendAdapter = $this->framework->getAdapter(Backend::class);
        $imageAdapter = $this->framework->getAdapter(Image::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        $disablePA = false;
        $disablePI = false;

        // Disable all buttons if there is a circular reference
        if ($clipboard !== false && (($clipboard['mode'] === 'cut' && ($cr || $clipboard['id'] == $row['id'])) || ($clipboard['mode'] === 'cutAll' && ($cr || \in_array($row['id'], $clipboard['id']))))) {
            $disablePA = true;
            $disablePI = true;
        }

        // Disable paste into if the node is of content type
        if (!$disablePI && $row['type'] === NodeModel::TYPE_CONTENT) {
            $disablePI = true;
        }

        // Disable "paste after" button if the parent node is a root node and the user is not allowed
        if (!$disablePA && !$this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_ROOT) && (!$row['pid'] || (\in_array((int) $row['id'], $dc->rootIds, true)))) {
            $disablePA = true;
        }

        $return = '';

        // Return the buttons
        $imagePasteAfter = $imageAdapter->getHtml('pasteafter.svg', sprintf($GLOBALS['TL_LANG'][$table]['pasteafter'][1], $row['id']));
        $imagePasteInto = $imageAdapter->getHtml('pasteinto.svg', sprintf($GLOBALS['TL_LANG'][$table]['pasteinto'][1], $row['id']));

        if ($row['id'] > 0) {
            $return = $disablePA ? $imageAdapter->getHtml('pasteafter_.svg').' ' : '<a href="'.$backendAdapter->addToUrl('act='.$clipboard['mode'].'&amp;mode=1&amp;pid='.$row['id'].(!\is_array($clipboard['id']) ? '&amp;id='.$clipboard['id'] : '')).'" title="'.$stringUtilAdapter->specialchars(sprintf($GLOBALS['TL_LANG'][$table]['pasteafter'][1], $row['id'])).'" onclick="Backend.getScrollOffset()">'.$imagePasteAfter.'</a> ';
        }

        return $return.($disablePI ? $imageAdapter->getHtml('pasteinto_.svg').' ' : '<a href="'.$backendAdapter->addToUrl('act='.$clipboard['mode'].'&amp;mode=2&amp;pid='.$row['id'].(!\is_array($clipboard['id']) ? '&amp;id='.$clipboard['id'] : '')).'" title="'.$stringUtilAdapter->specialchars(sprintf($GLOBALS['TL_LANG'][$table]['pasteinto'][1], $row['id'])).'" onclick="Backend.getScrollOffset()">'.$imagePasteInto.'</a> ');
    }

    /**
     * On "edit" button callback
     *
     * @param array  $row
     * @param string $href
     * @param string $label
     * @param string $title
     * @param string $icon
     * @param string $attributes
     *
     * @return string
     */
    public function onEditButtonCallback(array $row, string $href, string $label, string $title, string $icon, string $attributes): string
    {
        return $this->generateButton($row, $href, $label, $title, $icon, $attributes, $row['type'] !== NodeModel::TYPE_FOLDER && $this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_CONTENT));
    }

    /**
     * On "edit header" button callback
     *
     * @param array  $row
     * @param string $href
     * @param string $label
     * @param string $title
     * @param string $icon
     * @param string $attributes
     *
     * @return string
     */
    public function onEditHeaderButtonCallback(array $row, string $href, string $label, string $title, string $icon, string $attributes): string
    {
        return $this->generateButton($row, $href, $label, $title, $icon, $attributes, $this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_EDIT));
    }

    /**
     * On "copy" button callback
     *
     * @param array  $row
     * @param string $href
     * @param string $label
     * @param string $title
     * @param string $icon
     * @param string $attributes
     * @param string $table
     *
     * @return string
     */
    public function onCopyButtonCallback(array $row, string $href, string $label, string $title, string $icon, string $attributes, string $table): string
    {
        if ($GLOBALS['TL_DCA'][$table]['config']['closed']) {
            return '';
        }

        return $this->generateButton($row, $href, $label, $title, $icon, $attributes, $this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_CREATE));
    }

    /**
     * On "copy childs" button callback
     *
     * @param array  $row
     * @param string $href
     * @param string $label
     * @param string $title
     * @param string $icon
     * @param string $attributes
     * @param string $table
     *
     * @return string
     */
    public function onCopyChildsButtonCallback(array $row, string $href, string $label, string $title, string $icon, string $attributes, string $table): string
    {
        if ($GLOBALS['TL_DCA'][$table]['config']['closed']) {
            return '';
        }

        $active = ($row['type'] === NodeModel::TYPE_FOLDER) && $this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_CREATE);

        // Make the button active only if there are subnodes
        if ($active) {
            $active = $this->db->fetchColumn("SELECT COUNT(*) FROM $table WHERE pid=?", [$row['id']]) > 0;
        }

        return $this->generateButton($row, $href, $label, $title, $icon, $attributes, $active);
    }

    /**
     * On "delete" button callback
     *
     * @param array  $row
     * @param string $href
     * @param string $label
     * @param string $title
     * @param string $icon
     * @param string $attributes
     *
     * @return string
     */
    public function onDeleteButtonCallback(array $row, string $href, string $label, string $title, string $icon, string $attributes): string
    {
        $active = true;

        // Allow delete if the user has permission
        if (!$this->permissionChecker->isUserAdmin()) {
            $rootIds = (array) func_get_arg(7);
            $active = $this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_DELETE);

            // If the node is a root one, check if the user has permission to manage it
            if ($active && \in_array((int) $row['id'], $rootIds, true)) {
                $active = $this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_ROOT);
            }
        }

        return $this->generateButton($row, $href, $label, $title, $icon, $attributes, $active);
    }

    /**
     * On label callback.
     *
     * @param array              $row
     * @param string             $label
     * @param DataContainer|null $dc
     * @param string             $imageAttribute
     * @param bool               $returnImage
     *
     * @return string
     */
    public function onLabelCallback(array $row, string $label, DataContainer $dc = null, string $imageAttribute = '', bool $returnImage = false): string
    {
        /**
         * @var Backend $backendAdapter
         * @var Image $imageAdapter
         * @var StringUtil $stringUtilAdapter
         * @var System $systemAdapter
         */
        $backendAdapter = $this->framework->getAdapter(Backend::class);
        $imageAdapter = $this->framework->getAdapter(Image::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $systemAdapter = $this->framework->getAdapter(System::class);

        $image = ($row['type'] === NodeModel::TYPE_CONTENT) ? 'articles.svg' : 'folderC.svg';

        // Return the image only
        if ($returnImage) {
            return $imageAdapter->getHtml($image, '', $imageAttribute);
        }

        $languages = [];
        $allLanguages = $systemAdapter->getLanguages();

        // Generate the languages
        foreach ($stringUtilAdapter->trimsplit(',', $row['languages']) as $language) {
            $languages[] = $allLanguages[$language];
        }

        return sprintf(
            '%s <a href="%s" title="%s">%s</a>%s',
            $imageAdapter->getHtml($image, '', $imageAttribute),
            $backendAdapter->addToUrl('nn='.$row['id']),
            $stringUtilAdapter->specialchars($GLOBALS['TL_LANG']['MSC']['selectNode']),
            $label,
            (count($languages) > 0) ? sprintf(' <span class="tl_gray" style="margin-left:3px;">[%s]</span>', implode(', ', $languages)) : ''
        );
    }

    /**
     * On languages options callback.
     *
     * @param DataContainer|null $dc
     *
     * @return array
     */
    public function onLanguagesOptionsCallback(): array
    {
        /** @var System $system */
        $system = $this->framework->getAdapter(System::class);

        return $system->getLanguages();
    }

    /**
     * On execute the post actions.
     *
     * @param string        $action
     * @param DataContainer $dc
     */
    public function onExecutePostActions($action, DataContainer $dc): void
    {
        if ('reloadNodePickerWidget' === $action) {
            $this->reloadNodePickerWidget($dc);
        }
    }

    /**
     * Generate the button
     *
     * @param array  $row
     * @param string $href
     * @param string $label
     * @param string $title
     * @param string $icon
     * @param string $attributes
     * @param bool   $active
     *
     * @return string
     */
    private function generateButton(array $row, string $href, string $label, string $title, string $icon, string $attributes, bool $active): string
    {
        /** @var Image $imageAdapter */
        $imageAdapter = $this->framework->getAdapter(Image::class);

        if (!$active) {
            return $imageAdapter->getHtml(preg_replace('/\.svg$/i', '_.svg', $icon)).' ';
        }

        /**
         * @var Backend $backendAdapter
         * @var StringUtil $stringUtilAdapter
         */
        $backendAdapter = $this->framework->getAdapter(Backend::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);

        return '<a href="'.$backendAdapter->addToUrl($href.'&amp;id='.$row['id']).'" title="'.$stringUtilAdapter->specialchars($title).'"'.$attributes.'>'.$imageAdapter->getHtml($icon, $label).'</a> ';
    }

    /**
     * Reload the node picker widget.
     *
     * @param DataContainer $dc
     */
    private function reloadNodePickerWidget(DataContainer $dc): void
    {
        /** @var Input $inputAdapter */
        $inputAdapter = $this->framework->getAdapter(Input::class);

        $id = $inputAdapter->get('id');
        $field = $dc->inputName = $inputAdapter->post('name');

        // Handle the keys in "edit multiple" mode
        if ('editAll' === $inputAdapter->get('act')) {
            $id = \preg_replace('/.*_([0-9a-zA-Z]+)$/', '$1', $field);
            $field = \preg_replace('/(.*)_[0-9a-zA-Z]+$/', '$1', $field);
        }

        $dc->field = $field;

        // The field does not exist
        if (!isset($GLOBALS['TL_DCA'][$dc->table]['fields'][$field])) {
            $this->logger->log(
                LogLevel::ERROR,
                \sprintf('Field "%s" does not exist in DCA "%s"', $field, $dc->table),
                ['contao' => new ContaoContext(__METHOD__, TL_ERROR)]
            );

            throw new BadRequestHttpException('Bad request');
        }

        $row = null;
        $value = null;

        // Load the value
        if ('overrideAll' !== $inputAdapter->get('act') && $id > 0 && $this->db->getSchemaManager()->tablesExist([$dc->table])) {
            $row = $this->db->fetchAssoc("SELECT * FROM {$dc->table} WHERE id=?", [$id]);

            // The record does not exist
            if (!$row) {
                $this->logger->log(
                    LogLevel::ERROR,
                    \sprintf('A record with the ID "%s" does not exist in table "%s"', $id, $dc->table),
                    ['contao' => new ContaoContext(__METHOD__, TL_ERROR)]
                );

                throw new BadRequestHttpException('Bad request');
            }

            $value = $row->$field;
            $dc->activeRecord = $row;
        }

        // Call the load_callback
        if (\is_array($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['load_callback'])) {
            /** @var System $systemAdapter */
            $systemAdapter = $this->framework->getAdapter(System::class);

            foreach ($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['load_callback'] as $callback) {
                if (\is_array($callback)) {
                    $value = $systemAdapter->importStatic($callback[0])->{$callback[1]}($value, $dc);
                } elseif (\is_callable($callback)) {
                    $value = $callback($value, $dc);
                }
            }
        }

        // Set the new value
        $value = $inputAdapter->post('value', true);

        // Convert the selected values
        if ($value) {
            /** @var StringUtil $stringUtilAdapter */
            $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
            $value = $stringUtilAdapter->trimsplit("\t", $value);
            $value = \serialize($value);
        }

        /** @var NodePickerWidget $strClass */
        $strClass = $GLOBALS['BE_FFL']['nodePicker'];

        /** @var NodePickerWidget $objWidget */
        $objWidget = new $strClass($strClass::getAttributesFromDca($GLOBALS['TL_DCA'][$dc->table]['fields'][$field], $dc->inputName, $value, $field, $dc->table, $dc));

        throw new ResponseException(new Response($objWidget->generate()));
    }

    /**
     * Toggle switchToEdit flag
     *
     * @param DataContainer $dc
     */
    private function toggleSwitchToEditFlag(DataContainer $dc): void
    {
        if (!$dc->id || !$this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_CONTENT)) {
            return;
        }

        $type = $this->db->fetchColumn('SELECT type FROM tl_node WHERE id=?', [$dc->id]);

        if ($type === NodeModel::TYPE_CONTENT) {
            $GLOBALS['TL_DCA'][$dc->table]['config']['switchToEdit'] = true;
        }
    }

    /**
     * Check the permissions
     *
     * @param DataContainer $dc
     */
    private function checkPermissions(DataContainer $dc): void
    {
        if ($this->permissionChecker->isUserAdmin()) {
            return;
        }

        /** @var Input $inputAdapter */
        $inputAdapter = $this->framework->getAdapter(Input::class);

        // Close the table if user is not allowed to create new records
        if (!$this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_CREATE)) {
            $GLOBALS['TL_DCA'][$dc->table]['config']['closed'] = true;
            $GLOBALS['TL_DCA'][$dc->table]['config']['notCopyable'] = true;
        }

        // Set the flag if user is not allowed to edit records
        if (!$this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_EDIT)) {
            $GLOBALS['TL_DCA'][$dc->table]['config']['notEditable'] = true;
        }

        // Set the flag if user is not allowed to delete records
        if (!$this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_DELETE)) {
            $GLOBALS['TL_DCA'][$dc->table]['config']['notDeletable'] = true;
        }

        $session = $this->session->all();

        // Filter allowed page IDs
        if (is_array($session['CURRENT']['IDS'])) {
            $session['CURRENT']['IDS'] = $this->permissionChecker->filterAllowedIds(
                $session['CURRENT']['IDS'],
                ($inputAdapter->get('act') === 'deleteAll') ? PermissionChecker::PERMISSION_DELETE : PermissionChecker::PERMISSION_EDIT
            );

            $this->session->replace($session);
        }

        // Limit the allowed roots for the user
        if (null !== ($roots = $this->permissionChecker->getUserAllowedRoots())) {
            if (isset($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root']) && is_array($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'])) {
                $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'] = array_intersect($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'], $roots);
            } else {
                $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'] = $roots;
            }

            // Allow root paste if the user has enough permission
            if ($this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_ROOT)) {
                $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['rootPaste'] = true;
            }

            // Check current action
            if (($action = $inputAdapter->get('act')) && $action !== 'paste') {
                switch ($action) {
                    case 'edit':
                        $nodeId = (int) $inputAdapter->get('id');

                        // Dynamically add the record to the user profile
                        if (!$this->permissionChecker->isUserAllowedNode($nodeId)) {
                            /** @var \Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface $sessionBag */
                            $sessionBag = $this->session->getbag('contao_backend');

                            $newRecords = $sessionBag->get('new_records');
                            $newRecords = \is_array($newRecords[$dc->table]) ? \array_map('intval', $newRecords[$dc->table]) : [];

                            if (\in_array($nodeId, $newRecords, true)) {
                                $this->permissionChecker->addNodeToAllowedRoots($nodeId);
                            }
                        }
                    // no break;

                    case 'copy':
                    case 'delete':
                    case 'show':
                        if (!isset($nodeId)) {
                            $nodeId = (int) $inputAdapter->get('id');
                        }

                        if (!$this->permissionChecker->isUserAllowedNode($nodeId)) {
                            throw new AccessDeniedException(\sprintf('Not enough permissions to %s news category ID %s.', $action, $nodeId));
                        }
                        break;

                    case 'editAll':
                    case 'deleteAll':
                    case 'overrideAll':
                        if (is_array($session['CURRENT']['IDS'])) {
                            $session['CURRENT']['IDS'] = \array_intersect($session['CURRENT']['IDS'], $roots);
                            $this->session->replace($session);
                        }
                        break;
                }
            }
        }
    }

    /**
     * Add a breadcrumb menu
     *
     * @param DataContainer $dc
     *
     * @throws \RuntimeException
     */
    private function addBreadcrumb(DataContainer $dc): void
    {
        /**
         * @var Controller $controllerAdapter
         * @var Environment $environmentAdapter
         * @var Input $inputAdapter
         * @var Validator $validatorAdapter
         */
        $controllerAdapter = $this->framework->getAdapter(Controller::class);
        $environmentAdapter = $this->framework->getAdapter(Environment::class);
        $inputAdapter = $this->framework->getAdapter(Input::class);
        $validatorAdapter = $this->framework->getAdapter(Validator::class);

        /** @var AttributeBagInterface $session */
        $session = $this->session->getBag('contao_backend');

        // Set a new node
        if (isset($_GET['nn'])) {
            // Check the path
            if ($validatorAdapter->isInsecurePath($inputAdapter->get('nn', true))) {
                throw new \RuntimeException('Insecure path ' . $inputAdapter->get('nn', true));
            }

            $session->set(self::BREADCRUMB_SESSION_KEY, $inputAdapter->get('nn', true));
            $controllerAdapter->redirect(preg_replace('/&nn=[^&]*/', '', $environmentAdapter->get('request')));
        }

        if (($nodeId = $session->get(self::BREADCRUMB_SESSION_KEY)) < 1) {
            return;
        }

        // Check the path
        if ($validatorAdapter->isInsecurePath($nodeId)) {
            throw new \RuntimeException('Insecure path ' . $nodeId);
        }

        $ids = [];
        $links = [];

        /**
         * @var Backend $backendAdapter
         * @var Image $imageAdapter
         * @var System $systemAdapter
         * @var BackendUser $user
         */
        $backendAdapter = $this->framework->getAdapter(Backend::class);
        $imageAdapter = $this->framework->getAdapter(Image::class);
        $stringUtilAdapter = $this->framework->getAdapter(StringUtil::class);
        $user = $this->framework->createInstance(BackendUser::class);

        // Generate breadcrumb trail
        if ($nodeId) {
            $id = $nodeId;

            do {
                $node = $this->db->fetchAssoc("SELECT * FROM {$dc->table} WHERE id=?", [$id]);

                if (!$node) {
                    // Currently selected node does not exist
                    if ((int) $id === (int) $nodeId) {
                        $session->set(self::BREADCRUMB_SESSION_KEY, 0);

                        return;
                    }

                    break;
                }

                $ids[] = $id;

                // No link for the active node
                if ((int) $node['id'] === (int) $nodeId) {
                    $links[] = $this->onLabelCallback($node, '', null, '', true) . ' ' . $node['name'];
                }
                else
                {
                    $links[] = $this->onLabelCallback($node, '', null, '', true) . ' <a href="' . $backendAdapter->addToUrl('nn='.$node['id']) . '" title="'.$stringUtilAdapter->specialchars($GLOBALS['TL_LANG']['MSC']['selectNode']).'">' . $node['name'] . '</a>';
                }

                // Do not show the mounted nodes
                if (!$user->isAdmin && $user->hasAccess($node['id'], 'nodeMounts')) {
                    break;
                }

                $id = $node['pid'];
            } while ($id > 0 && $node['type'] !== 'root');
        }

        // Check whether the node is mounted
        if (!$user->hasAccess($ids, 'nodeMounts')) {
            $session->set(self::BREADCRUMB_SESSION_KEY, 0);
            throw new AccessDeniedException('Node ID ' . $nodeId . ' is not mounted.');
        }

        // Limit tree
        $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'] = [$nodeId];

        // Add root link
        $links[] = $imageAdapter->getHtml($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['icon']) . ' <a href="' . $backendAdapter->addToUrl('nn=0') . '" title="'.$stringUtilAdapter->specialchars($GLOBALS['TL_LANG']['MSC']['selectAllNodes']).'">' . $GLOBALS['TL_LANG']['MSC']['filterAll'] . '</a>';
        $links = array_reverse($links);

        // Insert breadcrumb menu
        $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['breadcrumb'] .= '

<nav aria-label="' . $GLOBALS['TL_LANG']['MSC']['breadcrumbMenu'] . '">
  <ul id="tl_breadcrumb">
    <li>' . implode(' › </li><li>', $links) . '</li>
  </ul>
</nav>';
    }
}