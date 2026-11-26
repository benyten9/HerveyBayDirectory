<?php

declare(strict_types=1);

/**
 * Mosaic write path — through Mosaic's own editor model, never raw SQL.
 *
 * Mosaic keeps every document as revisioned MResources with fractional
 * orderings, generated class names and heal rules. Writing rows by hand would
 * skip all of that, so every write here goes the way Mosaic's own onboarding
 * wizard writes (Admin/Ajax/Library/Wizard/Milestones/MilestoneWizardCreatePages.php):
 *
 *   $ei = new FinishWizardEditorInstance($themeID); $ei->load();
 *   WizardFinishHelper::createTemplateMResource(...)            // page template
 *   new RevisionEnvelopeInserter(...)->create($manager, $place) // node tree
 *   $ei->pushToDB();                                            // one transaction
 *
 * Updates use the editor's commit path, DataGroup::applyCommitData(). Mosaic's
 * validators run on every value and DROP what they reject without saying so —
 * so each write reads back what Mosaic kept and reports the difference.
 *
 * dry_run builds the same thing in memory and never calls pushToDB. The one
 * Mosaic call that writes outside pushToDB (creating a template assigned to a
 * post) is never made on a dry run.
 */

if (!defined('ABSPATH')) {
    exit();
}

use Mosaic\Admin\Ajax\Library\Wizard\EditorInstance\FinishWizardEditorInstance;
use Mosaic\Admin\Ajax\Library\Wizard\Milestones\WizardFinishHelper;
use Mosaic\Common\FractionalIndex;
use Mosaic\EditorInstance\DocumentInstance\NodeFactoryManager;
use Mosaic\EditorInstance\MResources\Node\NodeMResourceInserterHelper;
use Mosaic\EditorInstance\UUIDMapper;
use Mosaic\NodeTypes\NodeAbstract\RevisionEnvelopeInserter\RevisionEnvelopeInserter;
use Mosaic\NodeTypes\NodeAbstract\RevisionEnvelopeInserter\RevisionEnvelopeInserterDataRecord;

/** A loaded editor over one Mosaic theme: masters, templates, components, classes, breakpoints. */
function nibwp_mosaic_editor(string $theme_id): FinishWizardEditorInstance
{
    $ei = new FinishWizardEditorInstance($theme_id);
    $ei->load();

    return $ei;
}

/** Element type registered on this install (Pro adds code/modal/popover; add-ons may add more). */
function nibwp_mosaic_type_known(string $type): bool
{
    $base = explode('/', $type, 2)[0];
    if ($base === '' || !class_exists(NodeFactoryManager::class)) {
        return false;
    }
    $factory = NodeFactoryManager::getInstance()->getType($base);

    return !($factory instanceof \Mosaic\NodeTypes\Unknown\UnknownElementTypeFactory);
}

/**
 * Shorthand → native, class names → ids, widget presets → Mosaic's own default
 * children. Every node gets a temporary id so what Mosaic kept can be matched
 * back to what was sent.
 *
 * @param array<string,mixed>  $node
 * @param array<string,string> $class_ids name → id
 * @param array<int,array<string,mixed>> $errors
 * @param array<int,array<string,mixed>> $notes
 * @return array<string,mixed>
 */
function nibwp_mosaic_prepare_node(array $node, array $class_ids, array &$errors, array &$notes, string $path = '', bool $presets = true): array
{
    $native = nibwp_mosaic_normalize_tree($node, $notes, $path);

    return nibwp_mosaic_prepare_native($native, $class_ids, $errors, $notes, $path === '' ? (string) ($native['type'] ?? '?') : $path, $presets);
}

function nibwp_mosaic_prepare_native(array $node, array $class_ids, array &$errors, array &$notes, string $path, bool $presets = true): array
{
    $type = (string) ($node['type'] ?? '');
    $catalog = nibwp_mosaic_catalog();

    if ($type === '') {
        $errors[] = ['path' => $path, 'message' => 'A node has no type.'];
    } elseif (in_array($type, $catalog['system'], true)) {
        $errors[] = ['path' => $path, 'message' => sprintf('"%s" is a node Mosaic owns. Never author it; insert into it instead.', $type)];
    } elseif (!nibwp_mosaic_type_known($type)) {
        $errors[] = ['path' => $path, 'message' => sprintf('"%s" is not an element type on this install. See nibwp/mosaic-list-elements.', $type)];
    }

    $data = (array) ($node['data'] ?? []);
    if (isset($data['style']['_classNames'])) {
        $names = (array) $data['style']['_classNames'];
        unset($data['style']['_classNames']);
        foreach ($names as $name) {
            if (!isset($class_ids[$name])) {
                $errors[] = ['path' => $path, 'message' => sprintf('No Mosaic class named "%s". Create it with nibwp/mosaic-create-class (or pass it in classes) first.', $name)];
                continue;
            }
            $data['style']['universalClasses'][$class_ids[$name]] = $class_ids[$name];
        }
        if ($data['style'] === []) {
            unset($data['style']);
        }
    }

    $children = (array) ($node['children'] ?? []);
    $meta = $catalog['elements'][$type] ?? null;
    if ($presets && $children === [] && !empty($meta['preset'])) {
        $preset = nibwp_mosaic_widget_preset($type);
        if ($preset !== null) {
            $children = (array) ($preset['children'] ?? []);
            $data = nibwp_mosaic_merge((array) ($preset['data'] ?? []), $data);
            $notes[] = ['path' => $path, 'message' => sprintf('%s built with Mosaic\'s default children; fill them in with nibwp/mosaic-update-node.', $type)];
        }
    }

    // An empty PHP array encodes as [] and Mosaic's record wants an object.
    $out = ['ID' => nibwp_mosaic_uuid(), 'type' => $type, 'data' => $data === [] ? new stdClass() : $data];
    $kids = [];
    foreach (array_values($children) as $i => $child) {
        if (is_array($child)) {
            $kids[] = nibwp_mosaic_prepare_native($child, $class_ids, $errors, $notes, $path . ' > ' . (string) ($child['type'] ?? '?') . '[' . $i . ']', $presets);
        }
    }
    if ($kids !== []) {
        $out['children'] = $kids;
    }

    return $out;
}

/** Mosaic's own default structure for widgets that cannot work empty. */
function nibwp_mosaic_widget_preset(string $type): ?array
{
    $factories = [
        'accordion' => ['Mosaic\\NodeTypes\\Accordion\\AccordionElementTypeFactory', 'getAccordionElementDefaultData'],
        'tabs'      => ['Mosaic\\NodeTypes\\Tabs\\TabsElementTypeFactory', 'getTabsElementDefaultData'],
        'navbar'    => ['Mosaic\\NodeTypes\\Navbar\\NavbarElementTypeFactory', 'getNavbarElementDefaultData'],
    ];
    if (!isset($factories[$type]) || !method_exists($factories[$type][0], $factories[$type][1])) {
        return null;
    }
    $default = call_user_func($factories[$type]);
    $default = json_decode((string) json_encode($default), true);

    return is_array($default) ? $default : null;
}

/** The node manager + the element new content goes into by default, for one document. */
function nibwp_mosaic_open_document(FinishWizardEditorInstance $ei, string $document_type, string $document_id): array
{
    if ($document_type === 'template') {
        $doc = $ei->getTemplateMResourceManager()->getMResourceByID($document_id);
        if (!$doc) {
            throw new RuntimeException('No Mosaic template with id ' . $document_id . '.');
        }

        return ['doc' => $doc, 'manager' => $doc->getNodeMResourceManager(), 'root' => $doc->getTemplateInternalElement()];
    }
    if ($document_type === 'master') {
        $doc = $ei->getMasterMResourceManager()->getMResourceByID($document_id);
        if (!$doc) {
            throw new RuntimeException('No Mosaic master with id ' . $document_id . '.');
        }

        return ['doc' => $doc, 'manager' => $doc->getNodeMResourceManager(), 'root' => null];
    }
    if ($document_type === 'component') {
        $doc = $ei->getMResourceManager('component')->getMResourceByID($document_id);
        if (!$doc) {
            throw new RuntimeException('No Mosaic component with id ' . $document_id . '.');
        }
        nibwp_mosaic_open_component($doc);

        return ['doc' => $doc, 'manager' => $doc->getNodeMResourceManager(), 'root' => $doc->getComponentInternalElement()];
    }

    throw new RuntimeException('document_type must be template, master or component.');
}

/** A component's node and document managers are created on demand, not by load(). */
function nibwp_mosaic_open_component($component): void
{
    if (!$component->getNodeMResourceManager()) {
        $component->initNodeMResourceManager();
        $component->getNodeMResourceManager()->initSync();
    }
    if (!$component->getDocumentMResourceManager()) {
        $component->initDocumentMResourceManager();
        $component->getDocumentMResourceManager()->initSync();
    }
    $component->getEditorInstance()->heal(false);
}

/**
 * Where to put new nodes. relative_to + before/after, or parent_id + first/last,
 * or the document's content root.
 */
function nibwp_mosaic_placement(array $opened, string $parent_id, string $position, string $relative_to)
{
    $position = in_array($position, ['first', 'last', 'before', 'after'], true) ? $position : 'last';
    $manager = $opened['manager'];

    if ($relative_to !== '') {
        $rel = $manager->getMResourceByID($relative_to);
        if (!$rel) {
            throw new RuntimeException('relative_to ' . $relative_to . ' is not a node of this document.');
        }

        return $rel->createPlacementData(in_array($position, ['before', 'after'], true) ? $position : 'after');
    }
    if ($parent_id !== '') {
        $parent = $manager->getMResourceByID($parent_id);
        if (!$parent) {
            throw new RuntimeException('parent_id ' . $parent_id . ' is not a node of this document.');
        }

        return $parent->createPlacementData($position === 'first' ? 'first' : 'last');
    }
    if ($opened['root'] === null) {
        throw new RuntimeException('A master has no single content root. Pass parent_id or relative_to (read the master with nibwp/mosaic-get-tree).');
    }

    return $opened['root']->createPlacementData($position === 'first' ? 'first' : 'last');
}

/**
 * Insert prepared roots one after another. Returns the new root ids, a map
 * temporary id → new id, and what Mosaic did not keep.
 *
 * @param array<int,array<string,mixed>> $roots prepared by nibwp_mosaic_prepare_node()
 */
function nibwp_mosaic_insert_roots(FinishWizardEditorInstance $ei, array $opened, $placement, array $roots): array
{
    $manager = $opened['manager'];
    $ids = [];
    $mapper = new UUIDMapper();
    $given = [];

    foreach ($roots as $root) {
        nibwp_mosaic_collect_given($root, $given);
        $tree = json_decode((string) json_encode($root));
        $data = NodeMResourceInserterHelper::convertNodeDataTreeToRevisionEnvelopeInserterData($tree);
        $inserter = new RevisionEnvelopeInserter($ei, $placement->getRelativeElementMResource()->getMResourceManager(), new RevisionEnvelopeInserterDataRecord($data), [], $mapper);
        $created = $inserter->create($manager, $placement);
        if (!$created) {
            throw new RuntimeException('Mosaic refused to place "' . (string) ($root['type'] ?? '?') . '" there (the placement rules of the parent element do not allow it).');
        }
        $ids[] = $created->getID();
        $placement = $created->createPlacementData('after');
    }

    $map = [];
    $dropped = [];
    foreach ($given as $tmp => $entry) {
        $new = $mapper->hasID($tmp) ? $mapper->getID($tmp) : '';
        $map[$tmp] = $new;
        $m = $new !== '' ? $manager->getMResourceByID($new) : null;
        if (!$m) {
            $dropped[] = ['node' => $entry['path'], 'path' => '*', 'given' => $entry['type'], 'stored' => null];
            continue;
        }
        foreach (nibwp_mosaic_diff($entry['data'], $m->getData()->export()) as $d) {
            $dropped[] = ['node' => $entry['path'], 'id' => $new] + $d;
        }
    }

    return ['ids' => $ids, 'map' => $map, 'dropped' => $dropped];
}

/** @param array<string,array<string,mixed>> $out */
function nibwp_mosaic_collect_given(array $node, array &$out, string $path = ''): void
{
    $path = $path === '' ? (string) $node['type'] : $path;
    $out[(string) $node['ID']] = ['path' => $path, 'type' => (string) $node['type'], 'data' => (array) ($node['data'] ?? [])];
    foreach ((array) ($node['children'] ?? []) as $i => $child) {
        nibwp_mosaic_collect_given($child, $out, $path . ' > ' . $child['type'] . '[' . $i . ']');
    }
}

/** The first master, or a new default shell: document > body > main > template slot. */
function nibwp_mosaic_ensure_master(FinishWizardEditorInstance $ei, string $master_id, array &$notes)
{
    $mm = $ei->getMasterMResourceManager();
    if ($master_id !== '') {
        $master = $mm->getMResourceByID($master_id);
        if (!$master) {
            throw new RuntimeException('No Mosaic master with id ' . $master_id . '.');
        }

        return $master;
    }
    $masters = $mm->getMasterMResources();
    if (!empty($masters)) {
        return reset($masters);
    }

    $record = $mm->createRevisionRecord((object) [
        'ID'       => nibwp_mosaic_uuid(),
        'name'     => 'Default',
        'ordering' => FractionalIndex::generateKeyBetween(null, null),
        'version'  => MOSAIC_VERSION,
    ], true);
    $master = $mm->getFactory()->create($record, false);
    $mm->addMResource($master);
    $master->initSync();

    $nm = $master->getNodeMResourceManager();
    $make = static function ($manager, $parent, string $type, array $data = []) {
        return NodeMResourceInserterHelper::createNodeMResource($manager, (object) [
            'ID'         => nibwp_mosaic_uuid(),
            'parentType' => $parent->getMResourceType(),
            'parentID'   => $parent->getID(),
            'ordering'   => $parent->generateLastOrderingForNode(),
            'version'    => '',
            'type'       => $type,
            'data'       => (object) $data,
        ]);
    };
    $root = $make($nm, $master, 'master-root');
    $doc = $make($root->getMResourceManager(), $root, 'document');
    $body = $make($doc->getMResourceManager(), $doc, 'body');
    $main = $make($body->getMResourceManager(), $body, 'div', ['tagName' => 'main', 'name' => 'Main']);
    $make($main->getMResourceManager(), $main, 'template-external');
    $notes[] = ['path' => 'master', 'message' => 'The theme had no master, so a plain one was created (document > body > main > template slot). Add a header and footer to it with nibwp/mosaic-insert-nodes.'];

    return $master;
}

/** The theme template path for a post type, as Mosaic names it. */
function nibwp_mosaic_path_for_post_type(string $post_type): string
{
    $path = $post_type === 'post' ? 'single-post.php' : ($post_type === 'page' ? 'single-page.php' : 'single-' . $post_type . '.php');
    $dictionary = 'Mosaic\\Compatibility\\WordPress\\TemplatePathsDictionary\\TemplatePathsDictionary';
    if (class_exists($dictionary)) {
        $supported = $dictionary::getInstance()->getSupportedPaths();
        if (!in_array($path, $supported, true)) {
            $path = in_array('single.php', $supported, true) ? 'single.php' : 'index.php';
        }
    }

    return $path;
}

/**
 * Prepare a list of shorthand roots against a theme's classes.
 *
 * @return array{roots: array<int,array<string,mixed>>, errors: array<int,array<string,mixed>>, notes: array<int,array<string,mixed>>}
 */
function nibwp_mosaic_prepare_roots(array $roots, string $theme_id, array $extra_class_ids = []): array
{
    $errors = [];
    $notes = [];
    $class_ids = array_merge(nibwp_mosaic_class_ids_by_name($theme_id), $extra_class_ids);
    $out = [];
    foreach ($roots as $i => $root) {
        $out[] = nibwp_mosaic_prepare_node($root, $class_ids, $errors, $notes, (string) ($root['type'] ?? '?') . '[' . $i . ']');
    }

    $components = array_column(nibwp_mosaic_list_components($theme_id), 'id');
    $check = static function (array $n) use (&$check, &$errors, $components): void {
        $t = (string) ($n['type'] ?? '');
        if (str_starts_with($t, 'component-instance/') && !in_array(substr($t, 19), $components, true)) {
            $errors[] = ['path' => $t, 'message' => 'No component with that id in this theme. See nibwp/mosaic-list-components.'];
        }
        foreach ((array) ($n['children'] ?? []) as $c) {
            $check($c);
        }
    };
    foreach ($out as $n) {
        $check($n);
    }

    return ['roots' => $out, 'errors' => $errors, 'notes' => $notes];
}

function nibwp_mosaic_error(Throwable $e): WP_Error
{
    return new WP_Error('nibwp_mosaic_error', $e->getMessage(), ['where' => basename($e->getFile()) . ':' . $e->getLine()]);
}

/**
 * Create (or reuse) a page and give it its own Mosaic template, optionally with content.
 *
 * @param array<string,mixed> $args {theme_id, post_id, title, post_type, status, master_id, tree, dry_run}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_mosaic_write_page(array $args)
{
    $theme_id = nibwp_mosaic_resolve_theme((string) ($args['theme_id'] ?? ''));
    if ($theme_id instanceof WP_Error) {
        return $theme_id;
    }
    $dry_run = !empty($args['dry_run']);
    $post_id = (int) ($args['post_id'] ?? 0);
    $title = trim((string) ($args['title'] ?? ''));
    $post_type = (string) ($args['post_type'] ?? 'page');

    if ($post_id > 0) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('nibwp_mosaic_no_post', __('No post with that id.', 'nibwp'));
        }
        $existing = nibwp_mosaic_template_for_post($theme_id, $post_id);
        if ($existing !== '') {
            return new WP_Error(
                'nibwp_mosaic_page_has_template',
                __('That post already has its own Mosaic template. Add to it with nibwp/mosaic-insert-nodes instead of creating another.', 'nibwp'),
                ['template_id' => $existing]
            );
        }
        $post_type = (string) $post->post_type;
        $title = $title !== '' ? $title : (string) $post->post_title;
    } elseif ($title === '') {
        return new WP_Error('nibwp_mosaic_no_title', __('Give post_id of an existing page, or a title for a new one.', 'nibwp'));
    }

    $prepared = nibwp_mosaic_prepare_roots(nibwp_mosaic_roots($args['tree'] ?? []), $theme_id);
    if ($prepared['errors'] !== []) {
        return new WP_Error('nibwp_mosaic_invalid_tree', __('The tree has errors; nothing was written.', 'nibwp'), ['errors' => $prepared['errors'], 'notes' => $prepared['notes']]);
    }

    try {
        // Dry pass: a scratch template with no post, never pushed. Proves the tree places and shows what Mosaic keeps.
        $ei = nibwp_mosaic_editor($theme_id);
        $notes = $prepared['notes'];
        $master = nibwp_mosaic_ensure_master($ei, (string) ($args['master_id'] ?? ''), $notes);
        $path = nibwp_mosaic_path_for_post_type($post_type);
        $scratch = WizardFinishHelper::createTemplateMResource($ei, $master, $title, $path, null, null);
        $opened = ['doc' => $scratch, 'manager' => $scratch->getNodeMResourceManager(), 'root' => $scratch->getTemplateInternalElement()];
        $result = $prepared['roots'] !== [] ? nibwp_mosaic_insert_roots($ei, $opened, nibwp_mosaic_placement($opened, '', 'last', ''), $prepared['roots']) : ['ids' => [], 'dropped' => []];

        if ($dry_run) {
            return [
                'dry_run'   => true,
                'theme_id'  => $theme_id,
                'master_id' => $master->getID(),
                'path'      => $path,
                'post'      => $post_id > 0 ? ['id' => $post_id, 'existing' => true] : ['title' => $title, 'post_type' => $post_type, 'status' => (string) ($args['status'] ?? 'draft')],
                'dropped'   => $result['dropped'],
                'notes'     => $notes,
            ];
        }

        $created_post = 0;
        if ($post_id <= 0) {
            $post_id = wp_insert_post([
                'post_title'  => $title,
                'post_type'   => $post_type,
                'post_status' => in_array((string) ($args['status'] ?? 'draft'), ['draft', 'publish', 'private', 'pending'], true) ? (string) $args['status'] : 'draft',
                'post_content' => '',
            ], true);
            if ($post_id instanceof WP_Error) {
                return $post_id;
            }
            $created_post = (int) $post_id;
        }

        $ei = nibwp_mosaic_editor($theme_id);
        $notes = $prepared['notes'];
        $master = nibwp_mosaic_ensure_master($ei, (string) ($args['master_id'] ?? ''), $notes);
        $template = WizardFinishHelper::createTemplateMResource($ei, $master, $title, $path, null, (string) $post_id);
        $opened = ['doc' => $template, 'manager' => $template->getNodeMResourceManager(), 'root' => $template->getTemplateInternalElement()];
        $result = $prepared['roots'] !== [] ? nibwp_mosaic_insert_roots($ei, $opened, nibwp_mosaic_placement($opened, '', 'last', ''), $prepared['roots']) : ['ids' => [], 'dropped' => []];
        $ei->pushToDB();
    } catch (Throwable $e) {
        if (!empty($created_post)) {
            wp_delete_post($created_post, true);
        }

        return nibwp_mosaic_error($e);
    }

    return [
        'theme_id'    => $theme_id,
        'post_id'     => (int) $post_id,
        'edit_url'    => get_edit_post_link((int) $post_id, 'raw'),
        'view_url'    => get_permalink((int) $post_id),
        'template_id' => $template->getID(),
        'master_id'   => $master->getID(),
        'root_ids'    => $result['ids'],
        'dropped'     => $result['dropped'],
        'notes'       => $notes,
        'live'        => nibwp_mosaic_live_theme_id() === $theme_id,
    ];
}

/**
 * Insert a tree into a template, master or component.
 *
 * @param array<string,mixed> $args {theme_id, document_type, document_id, post_id, tree, parent_id, position, relative_to, dry_run}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_mosaic_write_insert(array $args)
{
    $target = nibwp_mosaic_resolve_target($args);
    if ($target instanceof WP_Error) {
        return $target;
    }
    [$theme_id, $document_type, $document_id] = $target;

    $roots = nibwp_mosaic_roots($args['tree'] ?? []);
    if ($roots === []) {
        return new WP_Error('nibwp_mosaic_no_tree', __('tree is empty.', 'nibwp'));
    }
    $prepared = nibwp_mosaic_prepare_roots($roots, $theme_id);
    if ($prepared['errors'] !== []) {
        return new WP_Error('nibwp_mosaic_invalid_tree', __('The tree has errors; nothing was written.', 'nibwp'), ['errors' => $prepared['errors'], 'notes' => $prepared['notes']]);
    }

    try {
        $ei = nibwp_mosaic_editor($theme_id);
        $opened = nibwp_mosaic_open_document($ei, $document_type, $document_id);
        $placement = nibwp_mosaic_placement($opened, (string) ($args['parent_id'] ?? ''), (string) ($args['position'] ?? 'last'), (string) ($args['relative_to'] ?? ''));
        $result = nibwp_mosaic_insert_roots($ei, $opened, $placement, $prepared['roots']);
        if (empty($args['dry_run'])) {
            $ei->pushToDB();
        }
    } catch (Throwable $e) {
        return nibwp_mosaic_error($e);
    }

    return [
        'dry_run'       => !empty($args['dry_run']),
        'theme_id'      => $theme_id,
        'document_type' => $document_type,
        'document_id'   => $document_id,
        'root_ids'      => !empty($args['dry_run']) ? [] : $result['ids'],
        'dropped'       => $result['dropped'],
        'notes'         => $prepared['notes'],
    ];
}

/**
 * theme + document from {document_type, document_id} or {post_id}.
 *
 * @return array{0:string,1:string,2:string}|WP_Error
 */
function nibwp_mosaic_resolve_target(array $args)
{
    $theme_id = nibwp_mosaic_resolve_theme((string) ($args['theme_id'] ?? ''));
    if ($theme_id instanceof WP_Error) {
        return $theme_id;
    }
    $post_id = (int) ($args['post_id'] ?? 0);
    if ($post_id > 0) {
        $template = nibwp_mosaic_template_for_post($theme_id, $post_id);
        if ($template === '') {
            return new WP_Error(
                'nibwp_mosaic_no_page_template',
                __('That post has no Mosaic template of its own. Give it one with nibwp/mosaic-create-page (post_id).', 'nibwp')
            );
        }

        return [$theme_id, 'template', $template];
    }
    $type = (string) ($args['document_type'] ?? 'template');
    $id = (string) ($args['document_id'] ?? '');
    if ($id === '' || !in_array($type, ['template', 'master', 'component'], true)) {
        return new WP_Error('nibwp_mosaic_no_target', __('Pass post_id, or document_type (template, master or component) with document_id.', 'nibwp'));
    }

    return [$theme_id, $type, $id];
}

/**
 * Change one node: merge a data patch (shorthand allowed), or set its words.
 *
 * @param array<string,mixed> $args {node_id, theme_id, data, style, css, tag, name, classes, text, replace_style, dry_run}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_mosaic_write_update(array $args)
{
    $node_id = (string) ($args['node_id'] ?? '');
    $where = $node_id !== '' ? nibwp_mosaic_locate_node($node_id, (string) ($args['theme_id'] ?? '')) : null;
    if (!$where) {
        return new WP_Error('nibwp_mosaic_no_node', __('No Mosaic node with that id.', 'nibwp'));
    }
    if (in_array($where['type'], nibwp_mosaic_catalog()['system'], true)) {
        return new WP_Error('nibwp_mosaic_system_node', __('That node belongs to Mosaic itself and is not edited directly.', 'nibwp'));
    }

    $shorthand = ['type' => $where['type']];
    foreach (['data', 'style', 'css', 'tag', 'name', 'classes', 'cssClasses', 'url', 'target', 'alt', 'image', 'svg'] as $key) {
        if (array_key_exists($key, $args)) {
            $shorthand[$key] = $args[$key];
        }
    }
    $errors = [];
    $notes = [];
    $patch = nibwp_mosaic_prepare_node($shorthand, nibwp_mosaic_class_ids_by_name($where['theme_id']), $errors, $notes, '', false)['data'] ?? [];
    if ($errors !== []) {
        return new WP_Error('nibwp_mosaic_invalid_patch', __('The change has errors; nothing was written.', 'nibwp'), ['errors' => $errors]);
    }

    try {
        $ei = nibwp_mosaic_editor($where['theme_id']);
        $opened = nibwp_mosaic_open_document($ei, $where['document_type'], $where['document_id']);
        $node = $opened['manager']->getMResourceByID($node_id);
        if (!$node) {
            throw new RuntimeException('The node is not loaded in its document.');
        }
        $before = $node->getData()->export();
        $old = json_decode((string) json_encode($before), true) ?: [];
        if (!empty($args['replace_style']) && isset($patch['style'])) {
            unset($old['style']);
        }
        $new = nibwp_mosaic_merge($old, $patch);
        $node->getData()->applyCommitData($before, json_decode((string) json_encode($new)));
        $dropped = nibwp_mosaic_diff($patch, $node->getData()->export());

        $text_note = null;
        if (isset($args['text']) && is_string($args['text'])) {
            $text_note = nibwp_mosaic_set_words($opened['manager'], $where, $node_id, (string) $args['text']);
        }
        if (empty($args['dry_run'])) {
            $ei->pushToDB();
        }
    } catch (Throwable $e) {
        return nibwp_mosaic_error($e);
    }

    return [
        'dry_run' => !empty($args['dry_run']),
        'node_id' => $node_id,
        'data'    => json_decode((string) json_encode($node->getData()->export()), true),
        'dropped' => $dropped,
        'text'    => $text_note,
        'notes'   => $notes,
    ];
}

/** Put new words in the first wysiwyg-text under a node; remove the other runs so the words are not doubled. */
function nibwp_mosaic_set_words($manager, array $where, string $node_id, string $text): string
{
    $rows = nibwp_mosaic_document_rows($where['theme_id'], $where['document_type'], $where['document_id']);
    $tree = nibwp_mosaic_rows_to_tree($rows);
    $found = nibwp_mosaic_find_in_tree($tree, $node_id);
    $runs = [];
    $collect = static function (array $n) use (&$collect, &$runs): void {
        if ($n['type'] === 'wysiwyg-text') {
            $runs[] = $n['id'];
        }
        foreach ((array) ($n['children'] ?? []) as $c) {
            $collect($c);
        }
    };
    if ($found) {
        $collect($found);
    }
    if ($runs === []) {
        return 'The node holds no text runs; nothing was changed. Insert a text element into it instead.';
    }
    $first = $manager->getMResourceByID($runs[0]);
    $before = $first->getData()->export();
    $after = json_decode((string) json_encode($before), true);
    $after['text'] = $text;
    $first->getData()->applyCommitData($before, json_decode((string) json_encode($after)));
    foreach (array_slice($runs, 1) as $extra) {
        $m = $manager->getMResourceByID($extra);
        if ($m) {
            $m->delete();
        }
    }

    return count($runs) > 1 ? 'Words set; ' . (count($runs) - 1) . ' other run(s) with their own formatting were removed.' : 'Words set.';
}

function nibwp_mosaic_find_in_tree(array $trees, string $id): ?array
{
    foreach ($trees as $n) {
        if (($n['id'] ?? '') === $id) {
            return $n;
        }
        $hit = nibwp_mosaic_find_in_tree((array) ($n['children'] ?? []), $id);
        if ($hit) {
            return $hit;
        }
    }

    return null;
}

/**
 * Remove a node and everything inside it.
 *
 * @return array<string,mixed>|WP_Error
 */
function nibwp_mosaic_write_delete(array $args)
{
    $node_id = (string) ($args['node_id'] ?? '');
    $where = $node_id !== '' ? nibwp_mosaic_locate_node($node_id, (string) ($args['theme_id'] ?? '')) : null;
    if (!$where) {
        return new WP_Error('nibwp_mosaic_no_node', __('No Mosaic node with that id.', 'nibwp'));
    }
    if (in_array($where['type'], nibwp_mosaic_catalog()['system'], true)) {
        return new WP_Error('nibwp_mosaic_system_node', __('That node belongs to Mosaic itself and cannot be removed.', 'nibwp'));
    }
    $ids = nibwp_mosaic_descendant_ids($where['theme_id'], $where['document_type'], $where['document_id'], $node_id);
    $ids[] = $node_id;

    if (!empty($args['dry_run'])) {
        return ['dry_run' => true, 'would_remove' => count($ids), 'node_type' => $where['type']];
    }

    try {
        $ei = nibwp_mosaic_editor($where['theme_id']);
        $opened = nibwp_mosaic_open_document($ei, $where['document_type'], $where['document_id']);
        foreach ($ids as $id) {
            $m = $opened['manager']->getMResourceByID($id);
            if ($m) {
                $m->delete();
            }
        }
        $ei->pushToDB();
    } catch (Throwable $e) {
        return nibwp_mosaic_error($e);
    }

    return ['removed' => count($ids), 'node_type' => $where['type'], 'document_type' => $where['document_type'], 'document_id' => $where['document_id']];
}

/**
 * Create a universal class (Mosaic's global, reusable style), or update its styles.
 *
 * @param array<string,mixed> $args {theme_id, name, label, style, css, states, update, dry_run}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_mosaic_write_class(array $args)
{
    $theme_id = nibwp_mosaic_resolve_theme((string) ($args['theme_id'] ?? ''));
    if ($theme_id instanceof WP_Error) {
        return $theme_id;
    }
    $name = trim((string) ($args['name'] ?? ''));
    if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
        return new WP_Error('nibwp_mosaic_bad_class_name', __('A class name is lowercase letters, digits and hyphens, starting with a letter (e.g. "card", "btn-primary").', 'nibwp'));
    }

    $notes = [];
    $states = isset($args['states']) && is_array($args['states']) ? $args['states'] : [];
    if (isset($args['style']) && is_array($args['style'])) {
        $states = nibwp_mosaic_merge($states, (array) (nibwp_mosaic_style_from_shorthand($args['style'], $notes, $name)['states'] ?? []));
    }
    if (isset($args['css']) && is_string($args['css']) && trim($args['css']) !== '') {
        $existing = (string) ($states['&']['_']['customDeclarations'] ?? '');
        $states['&']['_']['customDeclarations'] = trim($existing . "\n" . nibwp_mosaic_clean_declarations($args['css']));
    }

    $existing_id = nibwp_mosaic_class_ids_by_name($theme_id)[$name] ?? '';
    if ($existing_id !== '' && empty($args['update'])) {
        return new WP_Error('nibwp_mosaic_class_exists', __('A class with that name exists. Reuse it, or pass update: true to change its styles.', 'nibwp'), ['id' => $existing_id]);
    }

    try {
        $ei = nibwp_mosaic_editor($theme_id);
        $ucm = $ei->getMResourceManager('universalClass');
        if ($existing_id !== '') {
            $class = $ucm->getMResourceByID($existing_id);
            $before = $class->getData()->export();
            $new = json_decode((string) json_encode($before), true);
            $new['states'] = nibwp_mosaic_merge((array) ($new['states'] ?? []), $states);
            if (isset($args['label'])) {
                $new['label'] = (string) $args['label'];
            }
            $class->getData()->applyCommitData($before, json_decode((string) json_encode($new)));
        } else {
            $classes = $ucm->getMResourcesMap();
            $last = '';
            foreach ($classes as $c) {
                $o = (string) ($c->getHierarchy()->ordering ?? '');
                $last = strcmp($o, $last) > 0 ? $o : $last;
            }
            $data = ['name' => $name, 'states' => $states === [] ? new stdClass() : $states, 'localStates' => []];
            if (!empty($args['label'])) {
                $data['label'] = (string) $args['label'];
            }
            $record = $ucm->createRevisionRecord(json_decode((string) json_encode([
                'ID'       => nibwp_mosaic_uuid(),
                'ordering' => FractionalIndex::generateKeyBetween($last !== '' ? $last : null, null),
                'version'  => '',
                'data'     => $data,
            ])), true);
            $class = $ucm->getFactory()->create($record, false);
            $ucm->addMResource($class);
            $class->initSync();
        }
        $kept = json_decode((string) json_encode($class->getData()->export()), true);
        $dropped = nibwp_mosaic_diff(['states' => $states], ['states' => $kept['states'] ?? []]);
        if (empty($args['dry_run'])) {
            $ei->pushToDB();
        }
    } catch (Throwable $e) {
        return nibwp_mosaic_error($e);
    }

    return [
        'dry_run' => !empty($args['dry_run']),
        'id'      => $class->getID(),
        'name'    => $name,
        'updated' => $existing_id !== '',
        'apply'   => ['classes' => [$name], 'native' => ['style' => ['universalClasses' => [$class->getID() => $class->getID()]]]],
        'dropped' => $dropped,
        'notes'   => $notes,
    ];
}

/**
 * Create a reusable component from a tree. Place it anywhere with
 * {"type": "component-instance/<id>"}.
 *
 * @param array<string,mixed> $args {theme_id, name, category, tree, dry_run}
 * @return array<string,mixed>|WP_Error
 */
function nibwp_mosaic_write_component(array $args)
{
    $theme_id = nibwp_mosaic_resolve_theme((string) ($args['theme_id'] ?? ''));
    if ($theme_id instanceof WP_Error) {
        return $theme_id;
    }
    $name = trim((string) ($args['name'] ?? ''));
    if ($name === '') {
        return new WP_Error('nibwp_mosaic_no_name', __('A component needs a name.', 'nibwp'));
    }
    $roots = nibwp_mosaic_roots($args['tree'] ?? []);
    if ($roots === []) {
        return new WP_Error('nibwp_mosaic_no_tree', __('tree is empty.', 'nibwp'));
    }
    $prepared = nibwp_mosaic_prepare_roots($roots, $theme_id);
    if ($prepared['errors'] !== []) {
        return new WP_Error('nibwp_mosaic_invalid_tree', __('The tree has errors; nothing was written.', 'nibwp'), ['errors' => $prepared['errors']]);
    }
    $category_name = trim((string) ($args['category'] ?? '')) ?: 'NibWP';

    try {
        $ei = nibwp_mosaic_editor($theme_id);
        $ccm = $ei->getMResourceManager('componentCategory');
        $category = null;
        $last_cat = '';
        foreach ($ccm->getMResourcesMap() as $c) {
            $d = $c->getData()->export();
            $o = (string) ($c->getHierarchy()->ordering ?? '');
            $last_cat = strcmp($o, $last_cat) > 0 ? $o : $last_cat;
            if (isset($d->name) && (string) $d->name === $category_name && ($d->currentStatus ?? 'publish') === 'publish') {
                $category = $c;
            }
        }
        if (!$category) {
            $record = $ccm->createRevisionRecord((object) [
                'ID'         => nibwp_mosaic_uuid(),
                'parentType' => '',
                'parentID'   => '',
                'ordering'   => FractionalIndex::generateKeyBetween($last_cat !== '' ? $last_cat : null, null),
                'version'    => '',
                'data'       => (object) ['name' => $category_name],
            ], true);
            $category = $ccm->getFactory()->create($record, false);
            $ccm->addMResource($category);
            $category->initSync();
        }

        $cm = $ei->getMResourceManager('component');
        $last = '';
        foreach ($cm->getMResourcesMap() as $c) {
            $o = (string) ($c->getHierarchy()->ordering ?? '');
            $last = strcmp($o, $last) > 0 ? $o : $last;
        }
        $record = $cm->createRevisionRecord((object) [
            'ID'         => nibwp_mosaic_uuid(),
            'name'       => $name,
            'parentType' => 'componentCategory',
            'parentID'   => $category->getID(),
            'ordering'   => FractionalIndex::generateKeyBetween($last !== '' ? $last : null, null),
            'version'    => '',
            'path'       => 'index.php',
        ], true);
        $component = $cm->getFactory()->create($record, false);
        $cm->addMResource($component);
        $component->initSync();
        nibwp_mosaic_open_component($component);

        $opened = ['doc' => $component, 'manager' => $component->getNodeMResourceManager(), 'root' => $component->getComponentInternalElement()];
        $result = nibwp_mosaic_insert_roots($ei, $opened, nibwp_mosaic_placement($opened, '', 'last', ''), $prepared['roots']);
        if (empty($args['dry_run'])) {
            $ei->pushToDB();
        }
    } catch (Throwable $e) {
        return nibwp_mosaic_error($e);
    }

    return [
        'dry_run'       => !empty($args['dry_run']),
        'id'            => $component->getID(),
        'name'          => $name,
        'category'      => $category_name,
        'instance'      => ['type' => 'component-instance/' . $component->getID()],
        'dropped'       => $result['dropped'],
        'notes'         => $prepared['notes'],
    ];
}
